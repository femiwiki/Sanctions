<?php

namespace MediaWiki\Extension\Sanctions;

use Exception;
use Flow\Model\PostRevision;
use Flow\Model\UUID;
use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use stdClass;
use User;
use Wikimedia\Rdbms\IDatabase;

class Vote {

	/** @var Sanction */
	private $sanction;

	/** @var User */
	private $user;

	/** @var int */
	private $period;

	/**
	 * @param stdClass $row A row from the sanctions_vote table
	 * @return Vote
	 */
	public static function newFromRow( $row ) {
		$vote = new Vote();
		$vote->loadFromRow( $row );
		return $vote;
	}

	/**
	 * Initialize this object from a row from the sanctions_vote table.
	 *
	 * @param stdClass $row Row from the user table to load.
	 */
	protected function loadFromRow( $row ) {
		if ( !is_object( $row ) ) {
			throw new InvalidArgumentException( '$row must be an object' );
		}

		if ( isset( $row->stv_user ) ) {
			$userFactory = MediaWikiServices::getInstance()->getUserFactory();
			$this->user = $userFactory->newFromId( (int)$row->stv_user );
		}
		if ( isset( $row->stv_topic,  ) ) {
			$uuid = UUID::create( $row->stv_topic );
			$this->sanction = Sanction::newFromUUID( $uuid );
		}
		if ( isset( $row->stv_period ) ) {
			$this->user = $userFactory->newFromId( (int)$row->stv_period );
		}
	}

	/**
	 * @param string $timestamp
	 * @param IDatabase|null $dbw
	 */
	public function insert( $timestamp, IDatabase $dbw = null ) {
		$dbw = $dbw ?: wfGetDB( DB_PRIMARY );

		$dbw->insert(
			'sanctions_vote',
			[
				'stv_topic' => $this->sanction->getTopicUUID()->getBinary(),
				'stv_user' => $this->user->getId(),
				'stv_period' => $this->period,
				'stv_last_update_timestamp' => $timestamp
			]
		);
		$this->updateLastTouched( $timestamp, $dbw );
	}

	/**
	 * @param PostRevision $post
	 * @param string $timestamp
	 * @param IDatabase|null $dbw
	 */
	public function updateByPostRevision( PostRevision $post, $timestamp, IDatabase $dbw = null ) {
		$dbw = $dbw ?: wfGetDB( DB_PRIMARY );
		$period = self::extractPeriodFromReply( $post->getContentRaw() );
		$dbw->update(
			'sanctions_vote',
			[
				'stv_period' => $period,
				'stv_last_update_timestamp' => $timestamp,
			],
			[
				'stv_topic' => $this->sanction->getTopicUUID()->getBinary(),
				'stv_user' => $this->user,
			]
		);
		$this->updateLastTouched( $timestamp, $dbw );
	}

	/**
	 * Update the time of the sanction.
	 * @param string $timestamp
	 * @param IDatabase $dbw
	 */
	public function updateLastTouched( $timestamp, IDatabase $dbw ) {
		$dbw->update(
			'sanctions',
			[ 'st_last_update_timestamp' => $timestamp ],
			[ 'st_id' => $this->sanction->getId() ]
		);
	}

	/**
	 * @param string $content
	 * @return int|null
	 */
	public static function extractPeriodFromReply( $content ) {
		global $wgFlowContentFormat;

		if ( $wgFlowContentFormat == 'html' ) {
			$agreementWithDayRegex = '/<span class="sanction-vote-agree-period">(\d+)<\/span>/';
			$agreementRegex = '"sanction-vote-agree"';
			$disagreementRegex = '"sanction-vote-disagree"';
		} else {
			$agreementTemplateTitle = wfMessage( 'sanctions-agree-template-title' )->inContentLanguage()->text();
			$agreementTemplateTitle = preg_quote( $agreementTemplateTitle );
			$agreementWithDayRegex = "/\{\{${agreementTemplateTitle}\|(\d+)\}\}/";
			$agreementRegex = '{{' . $agreementTemplateTitle . '}}';
			$disagreementRegex = wfMessage( 'sanctions-disagree-template-title' )->inContentLanguage()->text();
			$disagreementRegex = '{{' . $disagreementRegex . '}}';
		}

		if ( strpos( $content, $disagreementRegex ) !== false ) {
			return 0;
		}
		if ( preg_match( $agreementWithDayRegex, $content, $matches ) == 1 ) {
			return (int)$matches[1];
		}
		// If the affirmative opinion is without explicit length, it would be considered as a day.
		if ( strpos( $content, $agreementRegex ) !== false ) {
			return 1;
		}
		return null;
	}

	/**
	 * @return int
	 */
	public function getPeriod() {
		return $this->period;
	}

	/**
	 * @param int $period
	 */
	public function setPeriod( int $period ) {
		$this->period = $period;
	}

	/**
	 * @param Sanction $sanction
	 */
	public function setSanction( Sanction $sanction ) {
		$this->sanction = $sanction;
	}

	/**
	 * @param User $user
	 */
	public function setUser( User $user ) {
		$this->user = $user;
	}

	/**
	 * @param PostRevision $post
	 * @return never
	 *
	 * @throws Exception
	 */
	public function loadFromPostRevision( PostRevision $post ) {
		throw new Exception( 'Not implemented' );
	}
}
