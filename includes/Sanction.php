<?php

namespace MediaWiki\Extension\Sanctions;

use EchoEvent;
use Flow\Model\UUID;
use MediaWiki\Block\AbstractBlock;
use MediaWiki\MediaWikiServices;
use stdClass;
use Title;
use User;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\Rdbms\DBError;

class Sanction {
	/** @var int */
	public $mId;

	/** @var User */
	protected $mAuthor;

	/** @var UUID */
	protected $mWorkflowId;

	/** @var User */
	protected $mTarget;

	/** @var string */
	protected $mTargetOriginalName;

	/** @var string */
	protected $mExpiry;

	/** @var bool */
	protected $mIsHandled;

	/** @var bool */
	protected $mIsEmergency;

	/** @var array|null */
	protected $mVotes = null;

	/** @var bool $mIsPassed, $mVoteNumber and $mAgreeVote are valid when $mCounted is true. */
	protected $mCounted = false;

	/** @var bool */
	protected $mIsPassed;

	/** @var int */
	protected $mVoteNumber;

	/** @var int */
	protected $mAgreeVote;

	/** @var string TS_MW timestamp from the DB */
	public $mTouched;

	/**
	 * Create a new sanction and write it to database
	 *
	 * @param User $author Who impose the sanction
	 * @param User $target
	 * @param bool $forInsultingName
	 * @param string $content Description of the sanction(written in wikitext)
	 * @return Sanction|bool
	 */
	public static function write( User $author, User $target, $forInsultingName, $content ) {
		$targetId = $target->getId();
		$targetName = $target->getName();

		// Exit if ID of target does not exist that means they are anonymous user.
		if ( $targetId === 0 ) {
			return false;
		}

		// Create a Flow topic
		$discussionPageName = wfMessage( 'sanctions-discussion-page-name' )->inContentLanguage()->text();
		$discussionPage = Title::newFromText( $discussionPageName );
		$topic = wfMessage( 'sanctions-topic-title', [
			"[[Special:redirect/user/${targetId}|${targetName}]]",
			wfMessage( $forInsultingName ? 'sanctions-type-insulting-name' : 'sanctions-type-block' )
				->inContentLanguage()->text()
		] )->inContentLanguage()->text();

		$uuid = FlowUtil::createTopic( $discussionPage, $author, $topic, $content );

		// Write to DB
		$votingPeriod = (float)wfMessage( 'sanctions-voting-period' )->text();
		$expiry = wfTimestamp( TS_MW, time() + ( ExpirationAwareness::TTL_DAY * $votingPeriod ) );

		$data = (object)[
			'st_author' => $author->getId(),
			'st_target' => $targetId,
			'st_topic' => $uuid->getBinary(),
			'st_expiry' => $expiry,
			'st_original_name' => $forInsultingName ? $targetName : '',
			'st_last_update_timestamp' => wfTimestamp( TS_MW )
		];

		$sanction = self::newFromRow( $data );
		$id = $sanction->insert();
		if ( $id === null ) {
			return false;
		}
		$sanction->updateTopicSummary();

		EchoEvent::create( [
			'type' => 'sanctions-proposed',
			'title' => $sanction->getWorkflow(),
			'extra' => [
				'target-id' => $targetId,
				'is-for-insulting-name' => $forInsultingName,
			],
			'agent' => $author,
		] );

		return $sanction;
	}

	/**
	 * @param User|null $user
	 * @return bool
	 */
	public function toggleEmergency( $user = null ) {
		// Prevent expired sanctions changes
		if ( $this->isExpired() ) {
			return false;
		}

		$emergency = $this->mIsEmergency;
		$toEmergency = !$emergency;

		if ( $toEmergency ) {
			$this->takeTemporaryMeasure( $user );
		} else {
			$reason = wfMessage(
					'sanctions-log-remove-temporary-measure',
					$this->mWorkflowId->getAlphadecimal()
				)->inContentLanguage()->text();

			$this->removeTemporaryMeasure( $reason, $user );
		}

		$emergency = !$emergency;
		$this->mIsEmergency = $emergency;

		// Update DB
		$id = $this->mId;
		$db = wfGetDB( DB_PRIMARY );
		$now = wfTimestamp( TS_MW );
		$db->update(
			'sanctions',
			[
				'st_emergency' => $emergency ? 1 : 0,
				'st_last_update_timestamp' => $now
			],
			[ 'st_id' => $id ]
		);

		return true;
	}

	/**
	 * Block the user or rename the username by result of the sanction.
	 * @param AbstractBlock|null $oldBlock
	 * @return bool true when success.
	 */
	public function justTakeMeasure( $oldBlock = null ) {
		$target = $this->mTarget;
		$isForInsultingName = $this->isForInsultingName();
		$reason = wfMessage(
			'sanctions-log-take-measure',
			$this->mWorkflowId->getAlphadecimal()
		)->inContentLanguage()->text();

		if ( $isForInsultingName ) {
			$targetName = $target->getName();
			$originalName = $this->mTargetOriginalName;

			if ( $targetName != $originalName ) {
				return true;
			}

			$renameIsDone = Utils::doRename(
				$targetName,
				wfMessage( 'sanctions-temporary-username', wfTimestamp( TS_MW ) )
					->inContentLanguage()->text(),
				$target,
				Utils::getBot(),
				$reason
			);
			if ( !$renameIsDone ) {
				return false;
			}
			return true;
		} else {
			$period = $this->getPeriod();
			$blockExpiry = wfTimestamp( TS_MW, time() + ( ExpirationAwareness::TTL_DAY * $period ) );

			// If the expiry of the block determined by this sanction is later than the existing expiry,
			// remove it.
			if ( $oldBlock ) {
				if ( $oldBlock->getExpiry() < $blockExpiry ) {
					Utils::unblock( $target, false, null, null, $oldBlock );
				} else {
					return true;
				}
			}

			Utils::doBlock( $target, $blockExpiry, $reason, true );
			return true;
		}
	}

	/**
	 * Replace the temporary measure that is created by the emergency process.
	 *
	 * @param AbstractBlock|null $oldBlock
	 * @return bool Return true when success
	 */
	public function replaceTemporaryMeasure( $oldBlock ) {
		$target = $this->mTarget;
		$isForInsultingName = $this->isForInsultingName();
		$reason = wfMessage( 'sanctions-log-take-measure', $this->mWorkflowId->getAlphadecimal() )
			->inContentLanguage()->text();

		if ( $isForInsultingName ) {
			return true;
		} else {
			$blockExpiry = wfTimestamp( TS_MW, time() + ( ExpirationAwareness::TTL_DAY * $this->getPeriod() ) );
			if ( $oldBlock ) {
				// If the expiry of the block determined by this sanction is later than the existing expiry,
				// remove it.
				if ( $oldBlock->getExpiry() < $blockExpiry ) {
					Utils::unblock( $target, false );
				} else {
					return true;
				}
			}

			Utils::doBlock( $target, $blockExpiry, $reason, true );
			return true;
		}
	}

	/**
	 * @param User|null $user
	 * @return bool
	 */
	public function takeTemporaryMeasure( $user = null ) {
		$target = $this->mTarget;
		$insultingName = $this->isForInsultingName();
		$reason = wfMessage(
			'sanctions-log-take-temporary-measure',
			$this->mWorkflowId->getAlphadecimal()
		)->inContentLanguage()->text();

		if ( $insultingName ) {
			$originalName = $this->mTargetOriginalName;

			if ( $target->getName() == $originalName ) {
				Utils::doRename(
					$target->getName(),
					wfMessage( 'sanctions-temporary-username', wfTimestamp( TS_MW ) )
						->inContentLanguage()->text(),
					$target,
					$user,
					$reason
				);
			}
		} else {
			$expiry = $this->getExpiry();
			// Block until voting expires.
			// If already blocked, compare the ranges and extend it if the expiry for this sanction is
			// after the unblock time.
			if ( $target->getBlock() !== null && $target->getBlock()->getExpiry() < $expiry ) {
				Utils::unblock( $target, false );
			}

			$blockExpiry = $expiry;
			Utils::doBlock( $target, $blockExpiry, $reason, false, $user );
		}
		return true;
	}

	/**
	 * @param string $reason
	 * @param User|null $user
	 * @param AbstractBlock|null $block
	 *
	 * @return bool
	 */
	public function removeTemporaryMeasure( $reason, $user = null, $block = null ) {
		$target = $this->mTarget;
		$isForInsultingName = $this->isForInsultingName();

		if ( $isForInsultingName ) {
			$targetName = $target->getName();
			$originalName = $this->mTargetOriginalName;

			if ( $targetName == $originalName ) {
				return true;
			} else {
				if ( !Utils::doRename( $targetName, $originalName, $target, $user, $reason ) ) {
					return false;
				}
				return true;
			}
		} else {
			// If the current block is due to this sanction, unblock it.
			// @todo If an emergency procedure overwrote another short block, recover the short block. In
			// other words, look at the block record and if there is a block record that is not related to
			// this sanction, compare the time periods and reduce the block period if the expiry
			// of this sanction is later than the unblock time.
			if ( $block && $block->getExpiry() == $this->mExpiry ) {
				Utils::unblock( $target, true, $reason, $user == null ? Utils::getBot() : $user, $block );
			}
			return true;
		}
	}

	/**
	 * Called when there is a vote for the sanction, or when an existing voter changes opinion.
	 */
	public function onVotesChanged() {
		$this->countVotes( true );
		if ( $this->isNeededToImmediateRejection() ) {
			$this->execute( true );
		} else {
			$this->updateTopicSummary();
		}
	}

	/**
	 * Examine opposition of three or more negative terms.
	 * @return bool
	 */
	public function isNeededToImmediateRejection() {
		$agree = $this->mAgreeVote;
		$count = $this->mVoteNumber;

		Utils::getLogger()->debug( "agree: $agree, count: $count" );

		if ( $count - $agree >= 3 ||
			( $agree == 0 && array_key_exists( $this->mAuthor->getId(), $this->mVotes )
				&& $this->mVotes[$this->mAuthor->getId()] == 0 ) ) {
			return true;
		}
	}

	/**
	 * @todo Return false on failure
	 * @param bool $force Execute anyway even if not expired.
	 * @param AbstractBlock|null $oldBlock
	 * @return bool
	 */
	public function execute( bool $force = false, $oldBlock = null ) {
		if ( !$force && ( !$this->isExpired() || $this->mIsHandled ) ) {
			return false;
		}
		$this->mIsHandled = true;

		$id = $this->mId;
		$emergency = $this->mIsEmergency;
		$passed = $this->isPassed();

		if ( $passed && !$emergency ) {
			$this->justTakeMeasure( $oldBlock );
		} elseif ( !$passed && $emergency ) {
			$reason = wfMessage(
					'sanctions-log-immediate-rejection',
					$this->getWorkflowId()->getAlphadecimal()
				)->inContentLanguage()->text();
			$this->removeTemporaryMeasure( $reason, Utils::getBot(), $oldBlock );
		} elseif ( $passed && $emergency ) {
			$this->replaceTemporaryMeasure( $oldBlock );
		}

		// Update the topic summary
		$this->updateTopicSummary( $force );

		// Write to DB
		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->update(
			'sanctions',
			[
				'st_handled' => 1,
				'st_last_update_timestamp' => wfTimestamp( TS_MW )
			],
			[ 'st_id' => $id ],
			__METHOD__
		);
		/** @var VoteStore $voteStore */
		$voteStore = MediaWikiServices::getInstance()->getService( 'VoteStore' );
		if ( !$voteStore->deleteOn( $this, $dbw ) ) {
			Utils::getLogger()->warning( "Deleting votes on {$this->getId()} failed" );
		}

		return true;
	}

	/**
	 * @param bool $done if it is true, the sanction would be considered to be done and check for
	 *   expiration would not be done.
	 * @return bool
	 */
	public function updateTopicSummary( bool $done = false ) {
		$this->countVotes();

		$agree = $this->mAgreeVote;
		$count = $this->mVoteNumber;
		$done = $done || $this->isExpired();

		if ( $count == 0 ) {
			$statusText = wfMessage( 'sanctions-topic-summary-status-rejected' );
			$reasonText = wfMessage( 'sanctions-topic-summary-reason-no-participants' );
		} elseif ( $count < 3 ) {
			if ( $agree == 0 && array_key_exists( $this->mAuthor->getId(), $this->mVotes )
				&& $this->mVotes[$this->mAuthor->getId()] == 0 ) {
				$statusText = wfMessage( 'sanctions-topic-summary-status-rejected' );
				$reasonText = wfMessage( 'sanctions-topic-summary-reason-canceled-by-author' );
			} elseif ( $agree == $count ) {
				$statusText = wfMessage( 'sanctions-topic-summary-status-passed' );
				$reasonText = wfMessage( 'sanctions-topic-summary-reason-less-than-three-and-all-agreed' );
			} else {
				$statusText = wfMessage( 'sanctions-topic-summary-status-rejected' );
				$reasonText = wfMessage( 'sanctions-topic-summary-reason-less-than-three-and-not-all-agreed' );
			}
		} else {
			if ( $count == 3 && $agree == 0 ) {
				$statusText = wfMessage( 'sanctions-topic-summary-status-immediate-rejection' );
				$reasonText = wfMessage( 'sanctions-topic-summary-reason-immediate-rejection' );

			} elseif ( $agree >= $count * 2 / 3 ) {
				$statusText = wfMessage( 'sanctions-topic-summary-status-passed' );
				$reasonText = wfMessage( 'sanctions-topic-summary-reason-more-than-three-and-agreed',
					(string)$agree );
			} else {
				$statusText = wfMessage( 'sanctions-topic-summary-status-rejected' );
				$reasonText = wfMessage( 'sanctions-topic-summary-reason-more-than-three-and-not-agreed',
					(string)$agree );
			}
		}

		$statusText = $statusText->inContentLanguage()->text();
		$reasonText = $reasonText->inContentLanguage()->text();
		if ( $reasonText ) {
			$reasonText = " ($reasonText)";
		}

		if ( !$this->isForInsultingName() ) {
			$period = $this->getPeriod();
			if ( $period > 0 ) {
				$statusText = wfMessage(
					'sanctions-topic-summary-result-prediction',
					$statusText,
					(string)$period
				)->inContentLanguage()->text();
			}
		}

		$lines = [];
		if ( $done ) {
			$lines[] = wfMessage( 'sanctions-topic-summary-status-label', $statusText )
					->inContentLanguage()->text() . $reasonText;
		} else {
			$lines[] = wfMessage(
				'sanctions-topic-summary-result-prediction-format',
				wfMessage( 'sanctions-topic-summary-status-label', $statusText )
					->inContentLanguage()->text()
			)->inContentLanguage()->text() . $reasonText;
			if ( !( $count == 3 && $agree == 0 ) ) {
				$lines[] .= wfMessage(
					'sanctions-topic-summary-deadline',
					MediaWikiServices::getInstance()->getContentLanguage()->formatExpiry( $this->mExpiry )
				)->inContentLanguage()->text();
			}
		}

		$summary = '';
		foreach ( $lines as $line ) {
			$summary .= "* $line\n";
		}

		return FlowUtil::updateSummary(
			$this->getWorkflow(),
			$this->getWorkflowId(),
			Utils::getBot(),
			$summary
		);
	}

	/**
	 * @todo Do not renew $value with a value of $row
	 * @param string $name
	 * @param string $value
	 * @return bool
	 *
	 * @deprecated Use self::loadFromRow() instead
	 */
	public function loadFrom( $name, $value ) {
		$db = wfGetDB( DB_REPLICA );

		$row = $db->selectRow(
			'sanctions',
			'*',
			[ $name => $value ]
		);

		if ( $row === false ) {
			return false;
		}

		return $this->loadFromRow( $row );
	}

	/**
	 * @param stdClass $row
	 * @return Sanction
	 */
	public static function newFromRow( $row ) {
		$sanction = new Sanction();
		$sanction->loadFromRow( $row );
		return $sanction;
	}

	/**
	 * @param stdClass $row
	 * @return bool
	 */
	protected function loadFromRow( $row ) {
		if ( !is_object( $row ) ) {
			throw new \InvalidArgumentException( '$row must be an object' );
		}

		$userFactory = MediaWikiServices::getInstance()->getUserFactory();

		if ( isset( $row->st_id ) ) {
			$this->mId = $row->st_id;
		}
		if ( isset( $row->st_author ) ) {
			$this->mAuthor = $userFactory->newFromId( (int)$row->st_author );
		}
		if ( isset( $row->st_topic ) ) {
			$topicUUIDBinary = $row->st_topic;
			$this->mWorkflowId = UUID::create( $topicUUIDBinary );
		}
		if ( isset( $row->st_target ) ) {
			$this->mTarget = $userFactory->newFromId( (int)$row->st_target );
		}
		if ( isset( $row->st_original_name ) ) {
			$this->mTargetOriginalName = $row->st_original_name;
		}
		if ( isset( $row->st_expiry ) ) {
			$this->mExpiry = $row->st_expiry;
		}
		if ( isset( $row->st_handled ) ) {
			$this->mIsHandled = $row->st_handled;
		}
		if ( isset( $row->st_emergency ) ) {
			$this->mIsEmergency = $row->st_emergency;
		}

		return true;
	}

	/**
	 * @return int|null ID of the sanction
	 */
	public function insert() {
		$dbw = wfGetDB( DB_PRIMARY );

		$data = [
			'st_author' => $this->mAuthor->getId(),
			'st_target' => $this->mTarget->getId(),
			'st_topic' => $this->mWorkflowId->getBinary(),
			'st_expiry' => $this->mExpiry,
			'st_original_name' => $this->mTargetOriginalName,
			'st_last_update_timestamp' => $dbw->timestamp( $this->mTouched ),
		];

		try {
			$dbw->insert( 'sanctions', $data, __METHOD__ );
		} catch ( DBError $e ) {
			Utils::getLogger()->warning( $e->getMessage() );
			return null;
		}
		$this->mId = $dbw->insertId();
		return $this->mId;
	}

	/**
	 * @param bool $getAnyway If true, return only the average sanction period, regardless of whether
	 * it is approved or rejected.
	 * @return int
	 */
	public function getPeriod( $getAnyway = false ) {
		$votes = $this->getVotes();
		$count = count( $votes );

		// If there is no vote, it is 0 days.
		if ( $count === 0 ) {
			return 0;
		}

		$sumPeriod = 0;
		$agree = 0;
		$maxBlockPeriod = (float)wfMessage( 'sanctions-max-block-period' )->text();
		foreach ( $votes as $userId => $period ) {
			$sumPeriod += $period > $maxBlockPeriod ? $maxBlockPeriod : $period;
			if ( $period > 0 ) {
				$agree++;
			}
		}

		if ( $getAnyway ) {
			return ceil( $sumPeriod / $count );
		}

		// Determine whether passed or not. The voting conditions are as follows:
		// - If three or more people give their opinions and two-thirds or more agree to them
		// - If at least one person and less than three people express their opinions and have no
		// objections
		$passed = ( $count >= 3 && $agree >= $count * 2 / 3 )
		|| ( $count < 3 && $agree == $count );

		if ( $passed ) {
			return ceil( $sumPeriod / $count );
		}
		return 0;
	}

	/**
	 * @param bool $reset
	 */
	protected function countVotes( $reset = false ) {
		if ( $this->mCounted && !$reset ) {
			return;
		}
		$this->mCounted = true;

		$votes = $this->getVotes();
		$count = count( $votes );

		if ( $count === 0 ) {
			$this->mIsPassed = false;
			$this->mAgreeVote = 0;
			$this->mVoteNumber = 0;

			return;
		}

		$agree = 0;
		foreach ( $votes as $userId => $period ) {
			if ( $period > 0 ) {
				$agree++;
			}
		}

		$this->mIsPassed =
			( $count >= 3 && $agree >= $count * 2 / 3 ) ||
			( $count < 3 && $agree == $count );
		$this->mAgreeVote = $agree;
		$this->mVoteNumber = $count;
	}

	public function isPassed() {
		$this->countVotes();

		$agree = $this->mAgreeVote;
		$count = $this->mVoteNumber;

		return ( $count >= 3 && $agree >= $count * 2 / 3 )
		|| ( $count > 0 && $count < 3 && $agree == $count );
	}

	public function isExpired() {
		// return false;
		return $this->mExpiry <= wfTimestamp( TS_MW );
	}

	public function isHandled() {
		return $this->mIsHandled;
	}

	public function isEmergency() {
		return $this->mIsEmergency;
	}

	/** @return int */
	public function getId() {
		return $this->mId;
	}

	/** @param int $id */
	public function setId( $id ) {
		$this->mId = $id;
	}

	/** @return User */
	public function getAuthor() {
		return $this->mAuthor;
	}

	/** @param User $user */
	public function setAuthor( User $user ) {
		$this->mAuthor = $user;
	}

	/** @return string */
	public function getExpiry() {
		return $this->mExpiry;
	}

	/** @param string $expiry */
	public function setExpiry( $expiry ) {
		$this->mExpiry = (string)$expiry;
	}

	/** @return User */
	public function getTarget() {
		return $this->mTarget;
	}

	/** @param User $user */
	public function setTarget( User $user ) {
		$this->mTarget = $user;
	}

	/** @return array */
	public function getVotes() {
		if ( $this->mVotes === null ) {
			$this->mVotes = [];

			$db = wfGetDB( DB_REPLICA );
			$res = $db->select(
				'sanctions_vote',
				'*',
				[
					'stv_topic' => $this->mWorkflowId->getBinary()
				]
			);
			// Convert the wrapped result to an array
			foreach ( $res as $row ) {
				$this->mVotes[$row->stv_user] = $row->stv_period;
			}
		}

		return $this->mVotes;
	}

	/** @return bool */
	public function isForInsultingName() {
		return $this->mTargetOriginalName != null;
	}

	/** @return string */
	public function getTargetOriginalName() {
		return $this->mTargetOriginalName;
	}

	/** @param string $name */
	public function setTargetOriginalName( $name ) {
		$this->mTargetOriginalName = $name;
	}

	/** @return Title */
	public function getWorkflow() {
		$UUIDText = $this->mWorkflowId->getAlphadecimal();

		// TODO Maybe there is a better way?
		return Title::newFromText( $UUIDText, NS_TOPIC );
	}

	/** @return UUID */
	public function getWorkflowId() {
		return $this->mWorkflowId;
	}

	/** @param UUID $uuid */
	public function setWorkflowId( UUID $uuid ) {
		$this->mWorkflowId = $uuid;
	}
}
