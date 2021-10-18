<?php

namespace MediaWiki\Extension\Sanctions;

use EchoEvent;
use Flow\Container;
use Flow\Import\Converter;
use Flow\Import\EnableFlow\EnableFlowWikitextConversionStrategy;
use Flow\Import\SourceStore\NullImportSourceStore;
use Flow\Model\UUID;
use ManualLogEntry;
use MediaWiki\Block\CompositeBlock;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Extension\Sanctions\Hooks\SanctionsHookRunner;
use MediaWiki\MediaWikiServices;
use MovePage;
use MWTimestamp;
use Psr\Log\NullLogger;
use RenameuserSQL;
use RequestContext;
use stdClass;
use Title;
use User;
use Wikimedia\IPUtils;

class Sanction {
	/** @var int */
	protected $mId;

	/** @var User */
	protected $mAuthor;

	/** @var UUID */
	protected $mTopic;

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

	/** @var array */
	protected $mVotes = null;

	/** @var bool $mIsPassed, $mVoteNumber and $mAgreeVote are valid when $mCounted is true. */
	protected $mCounted = false;

	/** @var bool */
	protected $mIsPassed;

	/** @var int */
	protected $mVoteNumber;

	/** @var int */
	protected $mAgreeVote;

	/**
	 * Create a new sanction and write it to database
	 *
	 * @param User $user Who impose the sanction
	 * @param User $target
	 * @param bool $forInsultingName
	 * @param string $content Description of the sanction(written in wikitext)
	 * @return Sanction|bool
	 */
	public static function write( User $user, User $target, $forInsultingName, $content ) {
		$authorId = $user->getId();

		$targetId = $target->getId();
		$targetName = $target->getName();

		// Exit if ID of target does not exist that means they are anonymous user.
		if ( $targetId === 0 ) {
			return false;
		}

		// Create a Flow topic
		$discussionPageName = wfMessage( 'sanctions-discussion-page-name' )->inContentLanguage()->text();

		$topicTitle = wfMessage( 'sanctions-topic-title', [
			"[[Special:redirect/user/${targetId}|${targetName}]]",
			wfMessage( $forInsultingName ? 'sanctions-type-insulting-name' : 'sanctions-type-block' )
				->inContentLanguage()->text()
		] )->inContentLanguage()->text();

		$factory = Container::get( 'factory.loader.workflow' );
		$page = Title::newFromText( $discussionPageName );
		if ( $page->getContentModel() != CONTENT_MODEL_FLOW_BOARD ) {
			if ( !self::convertToFlow( $page ) ) {
				return false;
			}
		}
		$loader = $factory->createWorkflowLoader( $page );
		$blocks = $loader->getBlocks();
		$action = 'new-topic';
		$params = [
			'topiclist' => [
				'page' => $discussionPageName,
				'token' => $user->getEditToken(),
				'action' => 'flow',
				'submodule' => 'new-topic',
				'topic' => $topicTitle,
				'content' => $content
			]
		];
		$context = RequestContext::getMain();
		$blocksToCommit = $loader->handleSubmit(
			$context,
			$action,
			$params
		);
		if ( !count( $blocksToCommit ) ) {
			return false;
		}

		$commitMetadata = $loader->commit( $blocksToCommit );

		// $topicTitleText = $commitMetadata['topiclist']['topic-page'];
		$topicId = $commitMetadata['topiclist']['topic-id'];

		if ( $topicId == null ) {
			return false;
		}

		// Write to DB
		$votingPeriod = (float)wfMessage( 'sanctions-voting-period' )->text();
		$now = wfTimestamp( TS_MW );
		$expiry = wfTimestamp( TS_MW, time() + ( 60 * 60 * 24 * $votingPeriod ) );

		$uuid = UUID::create( $topicId )->getBinary();
		$data = [
			'st_author' => $authorId,
			'st_target' => $targetId,
			'st_topic' => $uuid,
			'st_expiry' => $expiry,
			'st_original_name' => $forInsultingName ? $targetName : '',
			'st_last_update_timestamp' => $now
		];

		$db = wfGetDB( DB_PRIMARY );
		$db->insert( 'sanctions', $data, __METHOD__ );

		$sanction = new self();

		if ( !$sanction->loadFrom( 'st_topic', $uuid ) ) {
			return false;
		}

		if ( !$sanction->updateTopicSummary() ) {
			// @todo
		}

		EchoEvent::create( [
			'type' => 'sanctions-proposed',
			'title' => $sanction->getTopic(),
			'extra' => [
				'target-id' => $targetId,
				'is-for-insulting-name' => $forInsultingName,
			],
			'agent' => $user,
		] );

		return $sanction;
	}

	/**
	 * @param Title $title
	 * @return bool
	 */
	public static function convertToFlow( $title ) {
		$logger = new NullLogger;
		$user = self::getBot();
		if ( $title->exists( Title::GAID_FOR_UPDATE ) ) {
			$converter = new Converter(
				wfGetDB( DB_PRIMARY ),
				Container::get( 'importer' ),
				$logger,
				$user,
				new EnableFlowWikitextConversionStrategy(
					MediaWikiServices::getInstance()->getParser(),
					new NullImportSourceStore(),
					$logger,
					$user
				)
			);

			try {
				$converter->convert( $title );
			} catch ( \Exception $e ) {
				return false;
			}
		} else {
			$loaderFactory = Container::get( 'factory.loader.workflow' );
			$occupationController = Container::get( 'occupation_controller' );

			$creationStatus = $occupationController->safeAllowCreation( $title, $user, false );
			if ( !$creationStatus->isGood() ) {
				return false;
			}

			$loader = $loaderFactory->createWorkflowLoader( $title );
			$blocks = $loader->getBlocks();

			$action = 'edit-header';
			$params = [
				'header' => [
					'content' => '',
					'format' => 'wikitext',
				],
			];

			$blocksToCommit = $loader->handleSubmit(
				clone RequestContext::getMain(),
				$action,
				$params
			);

			foreach ( $blocks as $block ) {
				if ( $block->hasErrors() ) {
					$errors = $block->getErrors();

					foreach ( $errors as $errorKey ) {
						Utils::getLogger()->warning( $block->getErrorMessage( $errorKey ) );
					}
					return false;
				}
			}

			$loader->commit( $blocksToCommit );
		}
		return true;
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
					$this->mTopic->getAlphadecimal()
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
	 *
	 * @return bool true when success.
	 */
	public function justTakeMeasure() {
		$target = $this->mTarget;
		$isForInsultingName = $this->isForInsultingName();
		$reason = wfMessage(
			'sanctions-log-take-measure',
			$this->mTopic->getAlphadecimal()
		)->inContentLanguage()->text();

		if ( $isForInsultingName ) {
			$targetName = $target->getName();
			$originalName = $this->mTargetOriginalName;

			if ( $targetName != $originalName ) {
				return true;
			}

			$renameIsDone = self::doRename(
				$targetName,
				wfMessage( 'sanctions-temporary-username', wfTimestamp( TS_MW ) )
					->inContentLanguage()->text(),
				$target,
				$this->getBot(),
				$reason
			);
			if ( !$renameIsDone ) {
				return false;
			}
			return true;
		} else {
			$period = $this->getPeriod();
			$blockExpiry = wfTimestamp( TS_MW, time() + ( 60 * 60 * 24 * $period ) );
			if ( $target->isBlocked() ) {
				// If the expiry of the block determined by this sanction is later than the existing expiry,
				// remove it.
				if ( $target->getBlock()->getExpiry() < $blockExpiry ) {
					self::unblock( $target, false );
				} else {
					return true;
				}
			}

			self::doBlock( $target, $blockExpiry, $reason, true );
			return true;
		}
	}

	/**
	 * Replace the temporary measure that is created by the emergency process.
	 *
	 * @return bool Return true when success
	 */
	public function replaceTemporaryMeasure() {
		$target = $this->mTarget;
		$isForInsultingName = $this->isForInsultingName();
		$reason = wfMessage( 'sanctions-log-take-measure', $this->mTopic->getAlphadecimal() )
			->inContentLanguage()->text();

		if ( $isForInsultingName ) {
			return true;
		} else {
			$blockExpiry = wfTimestamp( TS_MW, time() + ( 60 * 60 * 24 * $this->getPeriod() ) );
			if ( $target->isBlocked() ) {
				// If the expiry of the block determined by this sanction is later than the existing expiry,
				// remove it.
				if ( $target->getBlock()->getExpiry() < $blockExpiry ) {
					self::unblock( $target, false );
				} else {
					return true;
				}
			}

			self::doBlock( $target, $blockExpiry, $reason, true );
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
			$this->mTopic->getAlphadecimal()
		)->inContentLanguage()->text();

		if ( $insultingName ) {
			$originalName = $this->mTargetOriginalName;

			if ( $target->getName() == $originalName ) {
				self::doRename(
					$target->getName(),
					wfMessage( 'sanctions-temporary-username', wfTimestamp( TS_MW ) )
						->inContentLanguage()->text(),
					$target,
					$user,
					$reason
				);
			}
		} else {
			$expiry = $this->mExpiry;
			// Block until voting expires.
			// If already blocked, compare the ranges and extend it if the expiry for this sanction is
			// after the unblock time.
			if ( $target->isBlocked() && $target->getBlock()->getExpiry() < $expiry ) {
				self::unblock( $target, false );
			}

			$blockExpiry = $expiry;
			self::doBlock( $target, $blockExpiry, $reason, false, $user );
		}
		return true;
	}

	/**
	 * @param string $reason
	 * @param User|null $user
	 *
	 * @return bool
	 */
	public function removeTemporaryMeasure( $reason, $user = null ) {
		$target = $this->mTarget;
		$isForInsultingName = $this->isForInsultingName();

		if ( $isForInsultingName ) {
			$targetName = $target->getName();
			$originalName = $this->mTargetOriginalName;

			if ( $targetName == $originalName ) {
				return true;
			} else {
				if ( !self::doRename( $targetName, $originalName, $target, $user, $reason ) ) {
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
			if ( $target->isBlocked() && $target->getBlock()->getExpiry() == $this->mExpiry ) {
				self::unblock( $target, true, $reason, $user == null ? $this->getBot() : $user );
			}
			return true;
		}
	}

	/**
	 * Called when there is a vote for the sanction, or when an existing voter changes opinion.
	 */
	public function onVotesChanged() {
		$this->countVotes( true );
		$this->immediateRejectionIfNeeded();
		$this->updateTopicSummary();
	}

	/**
	 * Immediately check and run the rejection condition.
	 * @return bool
	 */
	public function immediateRejectionIfNeeded() {
		if ( $this->needToImmediateRejection() ) {
			return $this->immediateRejection();
		}
	}

	/**
	 * Examine opposition of three or more negative terms.
	 * @return bool
	 */
	public function needToImmediateRejection() {
		$agree = $this->mAgreeVote;
		$count = $this->mVoteNumber;

		wfDebugLog( 'Sanctions', "\n\n\n" . "agree = $agree\ncount = $count" );

		if ( $count - $agree >= 3 ||
			( $agree == 0 && array_key_exists( $this->mAuthor->getId(), $this->mVotes )
				&& $this->mVotes[$this->mAuthor->getId()] == 0 ) ) {
			return true;
		}
	}

	public function immediateRejection() {
		// The votes disappears If the sanction is rejected, so write a summary of the topic before.
		$this->countVotes( true );
		$this->updateTopicSummary();

		$this->mExpiry = wfTimestamp( TS_MW );

		// If it was an emergency, remove the temporary measure.
		if ( $this->mIsEmergency ) {
			$reason = wfMessage(
					'sanctions-log-immediate-rejection',
					$this->mTopic->getAlphadecimal()
				)->inContentLanguage()->text();
			$this->removeTemporaryMeasure( $reason );
		}

		// Write to the database that sanctions have been processed.
		$db = wfGetDB( DB_PRIMARY );
		$now = wfTimestamp( TS_MW );
		$res = $db->update(
			'sanctions',
			[
				'st_expiry' => wfTimestamp( TS_MW ),
				'st_last_update_timestamp' => $now
			],
			[ 'st_id' => $this->mId ]
		);
	}

	/**
	 * @todo Return false on failure
	 * @return bool
	 */
	public function execute() {
		if ( !$this->isExpired() || $this->mIsHandled ) {
			return false;
		}
		$this->mIsHandled = true;

		$id = $this->mId;
		$emergency = $this->mIsEmergency;
		$passed = $this->isPassed();
		$topic = $this->mTopic;

		if ( $passed && !$emergency ) {
			$this->justTakeMeasure();
		} elseif ( !$passed && $emergency ) {
			$reason = wfMessage(
					'sanctions-log-immediate-rejection',
					$this->mTopic->getAlphadecimal()
				)->inContentLanguage()->text();
			$this->removeTemporaryMeasure( $reason, $this->getBot() );
		} elseif ( $passed && $emergency ) {
			$this->replaceTemporaryMeasure();
		}

		// Update the topic summary
		$this->updateTopicSummary();

		// Write to DB
		$db = wfGetDB( DB_PRIMARY );
		$now = wfTimestamp( TS_MW );
		$res = $db->update(
			'sanctions',
			[
				'st_handled' => 1,
				'st_last_update_timestamp' => $now
			],
			[ 'st_id' => $id ]
		);
		$db->delete(
			'sanctions_vote',
			[ 'stv_topic' => $topic->getBinary() ]
		);

		return true;
	}

	/**
	 * @todo If topic summary is already created (because etsprev_revision is empty), it will not work.
	 * @return bool
	 */
	public function updateTopicSummary() {
		$db = wfGetDB( DB_REPLICA );
		$row = $db->selectRow(
			'flow_revision',
			[
				'*'
			],
			[
				'rev_type_id' => $this->mTopic->getBinary(),
				'rev_type' => 'post-summary'
			],
			__METHOD__,
			[
				'LIMIT' => 1,
				'ORDER BY' => 'rev_id DESC'
			]
		);
		$previousIdText = $row == null
			? null
			: UUID::create( $row->rev_id )->getAlphadecimal();

		$factory = Container::get( 'factory.loader.workflow' );

		$topicTitleText = $this->getTopic()->getFullText();
		$topicTitle = Title::newFromText( $topicTitleText );
		$topicId = $this->mTopic;
		$loader = $factory->createWorkflowLoader( $topicTitle, $topicId );
		$blocks = $loader->getBlocks();
		$action = 'edit-topic-summary';
		$params = [
			'topicsummary' => [
				'page' => $topicTitleText,
				'token' => self::getBot()->getEditToken(),
				'action' => 'flow',
				'submodule' => 'edit-topic-summary',
				'prev_revision' => $previousIdText,
				'summary' => $this->getSanctionSummary(),
				'format' => 'wikitext'
			]
		];
		$context = clone RequestContext::getMain();

		// $loggedUser = $context->getUser();
		$context->setUser( self::getBot() );
		$blocksToCommit = $loader->handleSubmit(
			$context,
			$action,
			$params
		);
		// $context->setUser( $loggedUser );
		if ( !count( $blocksToCommit ) ) {
			return false;
		}
		$commitMetadata = $loader->commit( $blocksToCommit );

		return count( $commitMetadata ) > 0;
	}

	/**
	 * @todo Add more detailed counting information
	 * @return string
	 */
	public function getSanctionSummary() {
		$this->countVotes();
		$agree = $this->mAgreeVote;
		$count = $this->mVoteNumber;
		$expired = $this->isExpired();
		$passed = $this->isPassed();

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

		$summary = [];
		$summary[] = (
				$expired ?
				wfMessage( 'sanctions-topic-summary-status-label', $statusText )
					->inContentLanguage()->text() :
				wfMessage(
					'sanctions-topic-summary-result-prediction-format',
					wfMessage( 'sanctions-topic-summary-status-label', $statusText )
						->inContentLanguage()->text()
				)->inContentLanguage()->text()
			) .
			( $reasonText ? ' (' . $reasonText . ')' : '' );
		if ( !$expired && !( $count == 3 && $agree == 0 ) ) {
			$summary[] .= wfMessage(
				'sanctions-topic-summary-deadline',
				MediaWikiServices::getInstance()->getContentLanguage()->formatExpiry( $this->mExpiry )
			)->inContentLanguage()->text();
		}

		$prefix = '* ';
		$suffix = PHP_EOL;

		return implode( [
			$this->getSanctionSummaryHeader(),
			$prefix,
			implode( $suffix . $prefix, $summary ),
			$suffix
		] );
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

		if ( isset( $row->st_id ) ) {
			$this->mId = $row->st_id;
		}
		if ( isset( $row->st_author ) ) {
			$this->mAuthor = User::newFromId( $row->st_author );
		}
		if ( isset( $row->st_topic ) ) {
			$topicUUIDBinary = $row->st_topic;
			$this->mTopic = UUID::create( $topicUUIDBinary );
		}
		if ( isset( $row->st_target ) ) {
			$this->mTarget = User::newFromId( $row->st_target );
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
	 * @param bool $getAnyway If true, return only the average sanction period, regardless of whether
	 * it is approved or rejected.
	 * @return int
	 */
	public function getPeriod( $getAnyway = false ) {
		$votes = $this->getvotes();
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

	/**
	 * @return int
	 */
	public function getId() {
		return $this->mId;
	}

	/**
	 * @return User
	 */
	public function getAuthor() {
		return $this->mAuthor;
	}

	/**
	 * @return string
	 */
	public function getExpiry() {
		return $this->mExpiry;
	}

	/**
	 * @return User
	 */
	public function getTarget() {
		return $this->mTarget;
	}

	/**
	 * @return array
	 */
	public function getVotes() {
		if ( $this->mVotes === null ) {
			$this->mVotes = [];

			$db = wfGetDB( DB_REPLICA );
			$res = $db->select(
				'sanctions_vote',
				'*',
				[
					'stv_topic' => $this->mTopic->getBinary()
				]
			);
			// Convert the wrapped result to an array
			foreach ( $res as $row ) {
				$this->mVotes[$row->stv_user] = $row->stv_period;
			}
		}

		return $this->mVotes;
	}

	public function isForInsultingName() {
		return $this->mTargetOriginalName != null;
	}

	/**
	 * @return string
	 */
	public function getTargetOriginalName() {
		return $this->mTargetOriginalName;
	}

	/**
	 * @return Title
	 */
	public function getTopic() {
		$UUIDText = $this->mTopic->getAlphadecimal();

		// @todo Replace with better way?
		return Title::newFromText( 'Topic:' . $UUIDText );
	}

	/**
	 * @return UUID
	 */
	public function getTopicUUID() {
		return $this->mTopic;
	}

	/**
	 * Find out if there is an inappropriate username change suggestion for the user.
	 *
	 * @param User $user
	 * @return Sanction|null
	 */
	public static function existingSanctionForInsultingNameOf( $user ) {
		$db = wfGetDB( DB_REPLICA );
		$targetId = $user->getId();

		$row = $db->selectRow(
			'sanctions',
			'*',
			[
				'st_target' => $targetId,
				"st_original_name <> ''",
				'st_expiry > ' . wfTimestamp( TS_MW )
			]
		);
		if ( $row !== false ) {
			return self::newFromId( $row->st_id );
		}
		return null;
	}

	/**
	 * @param string $to
	 * @param string $content
	 * @return bool
	 * @deprecated Use FlowUtil::replyTo() instead.
	 */
	public function replyTo( $to, $content ) {
		$topicTitleText = $this->getTopic()->getFullText();
		$topicTitle = Title::newFromText( $topicTitleText );
		$topicId = $this->mTopic;

		/** @var \Flow\WorkflowLoaderFactory $factory */
		$factory = Container::get( 'factory.loader.workflow' );

		$loader = $factory->createWorkflowLoader( $topicTitle, $topicId );
		$blocks = $loader->getBlocks();
		$action = 'reply';
		$params = [
			'topic' => [
				'page' => $topicTitleText,
				'token' => self::getBot()->getEditToken(),
				'action' => 'flow',
				'submodule' => 'reply',
				'replyTo' => $to,
				'content' => $content,
				'format' => 'wikitext'
			]
		];
		$context = RequestContext::getMain();
		$context->setUser( self::getBot() );
		$blocksToCommit = $loader->handleSubmit(
			$context,
			$action,
			$params
		);
		if ( !count( $blocksToCommit ) ) {
			return false;
		}
		$loader->commit( $blocksToCommit );
	}

	/**
	 * @return string
	 */
	public function getSanctionSummaryHeader() {
		return '';
	}

	/**
	 * @param string $id
	 * @return Sanction|bool
	 */
	public static function newFromId( $id ) {
		$rt = new self();
		if ( $rt->loadFrom( 'st_id', $id ) ) {
			return $rt;
		}
		return false;
	}

	/**
	 * @param UUID|string $UUID
	 * @return Sanction|bool
	 */
	public static function newFromUUID( $UUID ) {
		if ( $UUID instanceof UUID ) {
			$UUID = $UUID->getBinary();
		} elseif ( is_string( $UUID ) ) {
			$UUID = UUID::create( strtolower( $UUID ) )->getBinary();
		}

		$rt = new self();
		if ( $rt->loadFrom( 'st_topic', $UUID ) ) {
			return $rt;
		}
		return false;
	}

	/**
	 * @param UUID $vote
	 * @return Sanction|bool
	 */
	public static function newFromVoteId( $vote ) {
		$db = wfGetDB( DB_REPLICA );

		$sanctionId = $db->selectField(
			'sanctions_vote',
			'stv_topic',
			[ 'stv_id' => $vote ]
		);

		return self::newFromId( $sanctionId );
	}

	/**
	 * @return User
	 * @deprecated Use Utils:getBot() instead
	 */
	public static function getBot() {
		return Utils::getBot();
	}

	/**
	 * Rename the given User.
	 * This funcion includes some code that originally are in SpecialRenameuser.php
	 *
	 * @param string $oldName
	 * @param string $newName
	 * @param User $target
	 * @param User $renamer
	 * @param string $reason
	 * @return bool
	 */
	protected static function doRename( $oldName, $newName, $target, $renamer, $reason ) {
		$bot = self::getBot();
		$targetId = $target->idForName();
		$oldUser = User::newFromName( $oldName );
		$newUser = User::newFromName( $newName );
		$oldUserPageTitle = Title::makeTitle( NS_USER, $oldName );
		$newUserPageTitle = Title::makeTitle( NS_USER, $newName );

		if ( $targetId === 0 || $newUser->idForName() !== 0 ) {
			return false;
		}

		// Give other affected extensions a chance to validate or abort
		$hookRunner = new SanctionsHookRunner(
			MediaWikiServices::getInstance()->getHookContainer()
		);
		if ( $hookRunner->onRenameUserAbort( $targetId, $oldName, $newName ) ) {
			return false;
		}

		// Do the heavy lifting...
		$rename = new RenameuserSQL(
			$oldName,
			$newName,
			$targetId,
			$renamer,
			[ 'reason' => $reason ]
		);
		if ( !$rename->rename() ) {
			return false;
		}

		// If this user is renaming his/herself, make sure that Title::moveTo()
		// doesn't make a bunch of null move edits under the old name!
		if ( $renamer->getId() === $targetId ) {
			$renamer->setName( $newName );
		}

		// Move any user pages
		if ( $bot->isAllowed( 'move' ) ) {
			$dbr = wfGetDB( DB_REPLICA );
			$pages = $dbr->select(
				'page',
				[ 'page_namespace', 'page_title' ],
				[
					'page_namespace' => [ NS_USER, NS_USER_TALK ],
					$dbr->makeList(
						[
							'page_title ' . $dbr->buildLike( $oldUserPageTitle->getDBkey() . '/', $dbr->anyString() ),
							'page_title = ' . $dbr->addQuotes( $oldUserPageTitle->getDBkey() ),
						], LIST_OR
					),
				],
				__METHOD__
			);
			foreach ( $pages as $row ) {
				$oldPage = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
				$newPage = Title::makeTitleSafe(
					$row->page_namespace,
					preg_replace( '!^[^/]+!', $newUserPageTitle->getDBkey(), $row->page_title )
				);

				$movePage = new MovePage( $oldPage, $newPage );

				if ( !$movePage->isValidMove() ) {
					return false;
				} else {
					$success = $movePage->move(
						$bot,
						wfMessage(
							'renameuser-move-log',
							$oldUserPageTitle->getText(),
							$newUserPageTitle->getText()
						)->inContentLanguage()->text(),
						true
					);

					if ( !$success->isGood() ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * @param User $target
	 * @param string $expiry
	 * @param string $reason
	 * @param bool $preventEditOwnUserTalk
	 * @param User|null $user
	 *
	 * @return bool
	 */
	protected static function doBlock( $target, $expiry, $reason,
			$preventEditOwnUserTalk = true, $user = null ) {
		$bot = self::getBot();

		$block = new DatabaseBlock();
		$block->setTarget( $target );
		$block->setBlocker( $bot );
		$block->setReason( $reason );
		$block->isHardblock( true );
		$block->isAutoblocking( boolval( wfMessage( 'sanctions-autoblock' )->text() ) );
		$block->isCreateAccountBlocked( true );
		$block->isUsertalkEditAllowed( $preventEditOwnUserTalk );
		$block->setExpiry( $expiry );

		$success = $block->insert();

		if ( !$success ) {
			return false;
		}

		$logParams = [];
		$time = MWTimestamp::getInstance( $expiry );
		// Even if done as below, it comes out in local time.
		$logParams['5::duration'] = $time->getTimestamp( TS_ISO_8601 );
		$flags = [ 'nocreate' ];
		if ( !$block->isAutoblocking() && !IPUtils::isIPAddress( $target ) ) {
			// Conditionally added same as SpecialBlock
			$flags[] = 'noautoblock';
		}
		$logParams['6::flags'] = implode( ',', $flags );

		$logEntry = new ManualLogEntry( 'block', 'block' );
		$logEntry->setTarget( Title::makeTitle( NS_USER, $target ) );
		$logEntry->setComment( $reason );
		$logEntry->setPerformer( $user == null ? $bot : $user );
		$logEntry->setParameters( $logParams );
		$blockIds = array_merge( [ $success['id'] ], $success['autoIds'] );
		$logEntry->setRelations( [ 'ipb_id' => $blockIds ] );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId );

		return true;
	}

	/**
	 * @param User $target
	 * @param bool $withLog
	 * @param string|null $reason
	 * @param User|null $user
	 * @return bool
	 */
	protected static function unblock( $target, $withLog = false, $reason = null, $user = null ) {
		$block = $target->getBlock();

		if ( $block != null ) {
			if ( $block instanceof CompositeBlock ) {
				foreach ( $block->getOriginalBlocks() as $originalBlock ) {
					if ( $originalBlock instanceof DatabaseBlock ) {
						'@phan-var DatabaseBlock $originalBlock';
						return $originalBlock->delete();
					}
				}
			} elseif ( $block instanceof DatabaseBlock ) {
				'@phan-var DatabaseBlock $block';
				return $block->delete();
			}
		}

		// Below's the same thing that is on SpecialUnblock SpecialUnblock.php
		if ( $block->getType() == DatabaseBlock::TYPE_AUTO ) {
			$page = Title::makeTitle( NS_USER, '#' . $block->getId() );
		} else {
			$page = $block->getTarget() instanceof User
			? $block->getTarget()->getUserPage()
			: Title::makeTitle( NS_USER, $block->getTarget() );
		}

		if ( $withLog ) {
			$bot = self::getBot();

			$logEntry = new ManualLogEntry( 'block', 'unblock' );
			$logEntry->setTarget( $page );
			$logEntry->setComment( $reason );
			$logEntry->setPerformer( $user == null ? $bot : $user );
			$logId = $logEntry->insert();
			$logEntry->publish( $logId );
		}
	}
}
