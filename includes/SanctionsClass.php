<?php

use Flow\Api\ApiFlowNewTopic;
use Flow\Api\ApiFlowEditTopicSummary;
use Flow\Container;
use Flow\Model\UUID;

class Sanctions {
	/**
	 * @var Integer
	 */
	protected $mId;
	/**
	 * @var UUID
	 */
	protected $mTopicUUID;

	/**
	 * @var User
	 */
	protected $mTarget;

	/**
	 * @var String
	 */

	protected $mTargetOriginalName;

	/**
	 * @var 
	 */
	protected $mExpiry;

	/**
	 * @var Bool
	 */
	protected $mIsHandled;

	/**
	 * @var Bool
	 */
	protected $mIsEmergency;

	/**
	 * @var array
	 */
	protected $mVotes = null;

	/**
	 * 중간 개표, votes를 넘겨 받아 이를 DB에 추가하거나 갱신합니다.
	 */
	public static function countVotes( array $votes = null ) {
		$dbIsTouched = false;
		foreach ( $votes as $vote ) {
			$period = $db->selectField(
				'sanctions_vote',
				['stv_period'],
				[
					'stv_topic' => $this->mTopicUUID,
					'stv_user'=> $vote->userId
				]
			);
			if( $period === false ) {
				$db->insert(
					'sanctions_vote',
					[
						'stv_topic' => $this->mTopicUUID,
						'stv_user' => $vote->userId,
						'stv_period' => $vote->stv_period
					]
				);
				$dbIsTouched = true;
			}
			else if ( $period == $vote->stv_period ) {
				$db->update(
					'sanctions_vote',
					[
						'stv_period' => $vote->stv_period
					],
					[
						'stv_topic' => $this->mTopicUUID,
						'stv_user' => $vote->userId
					]
				);
				$dbIsTouched = true;
			}
		}

		if ( $dbIsTouched ) {
			$this->immediateRejectionIfNeeded();
			$this->updateTopicSummary();
		}
	}

	public function immediateRejectionIfNeeded() {
		if( $this->NeedToImmediateRejection() )
			return $this->immediateRejection();
	}

	// 부결 조건인 3인 이상의 반대를 검사합니다.
	public function NeedToImmediateRejection() {
		$votes = $this->getVotes();

		$period = 0;
		$agree = 0;
		foreach( $votes as $vote ) {
			$period += $vote['period'];
			if( $period > 0 ) $agree++;
		}

		if( count( $votes ) >= 3 && $period === 0 )
			return true;
		return false;
	}

	public function immediateRejection() {
		// @todo 긴급 절차였다면 임시 조치를 해제합니다.

		// 제재안이 처리되었음을 데이터베이스에 표시합니다.
		$db = wfGetDB( DB_MASTER );

		$res = $db->replace(
			'sanctions',
			[ 'st_handled' => 1 ],
			[ 'st_id' => $this->mid ]
		);
		
		$this->updateTopicSummary();
	}

	public function execute() {
		if ( !$this->isExpired() || $this->isHandled )
			return false;

		// @todo 적절한 처리

		// 데이터베이스에 반영합니다.
		$db = wfGetDB( DB_MASTER );

		$res = $db->replace(
			'sanctions',
			[ 'st_handled' => 1 ],
			[ 'st_id' => $this->mid ]
		);
	}

	/**
	 * 제재 기간을 반환합니다. 
	 */
	public function getPeriod( $getAnyway = false ) {
		$votes = $this->votes;
		$count = count( $votes );

		//표가 하나도 없다면 0일입니다.
		if ( $count === 0 ) return 0;

		$period = 0;
		$agree = 0;
		foreach ( $votes as $vote ) {
			$period += $vote['period'];
			if ( $period > 0 ) $agree++;
		}

		// 가결 여부를 구합니다. 가결 조건은 다음과 같습니다.
	 	// - 3인 이상이 의견을 내고 2/3 이상이 찬성한 경우
	 	// - 1인 이상, 3인 미만이 의견을 내고 반대가 없는 경우
		$passed = ( $count >= 3 && $agree >= $count*2/3 )
			|| ( /* $count > 0 && 위에서 검사하여 생략 */ $count < 3 && $agree == $count );
		
		if ( $passed || $getAnyway)
			return ceil( $period/$count );
		return 0;
	}

	public function updateTopicSummary() {
		$EditTopicSummary = new WebRequest();

		$EditTopicSummary->setVal( 'token', User::newFromId( 0 ) ); // Admin @todo 제재안 의결 사용자로 교체
		$EditTopicSummary->setVal( 'action','flow' );
		$EditTopicSummary->setVal( 'submodule','edit-topic-summary' );
		$EditTopicSummary->setVal( 'etsprev_revision', '' );
		$EditTopicSummary->setVal( 'etssummary', $this->getSanctionSummary() );
		$EditTopicSummary->setVal( 'etsformat','wikitext' );

		$main = new ApiMain( $EditTopicSummary, true );
		$api = new ApiFlowEditTopicSummary( $main, 'edit-topic-summary' );
		$api->setPage( Title::newFromText( $topicTitleText ) );
		$api->execute();

		return $api->getResultData()['main']['edit-topic-summary']['status']
			&& $api->getResultData()['main']['edit-topic-summary']['committed'];
	}

	/**
	 * @todo 제재안이 만료되었다면 만료되었다는 사실을 추가합니다.
	 */
	public static function getSanctionSummary() {
		$summary = SpacialSanctions::getSanctionSummaryHeader();

		$summary += '* 의결 종료 시간: '.null;
		// @todo
	
		return $summary;
	}

	public static function getSanctionSummaryHeader() {
		return '<small>[['.$this->getTitle().'전체 제재안 목록보기]]</small>';
	}

	public static function newFromId( string $id ) {
		$rt = new Self();
		if ( $rt->loadFrom( 'st_id', $id ) )
			return $rt;
		return false;
	}

	public static function newFromUUID( $UUID ) {
		if ( $UUID instanceof UUID )
			$UUID = $UUID->getBinary();

		$rt = new Self();
		if ( $rt->loadFrom( 'st_topic', $UUID ) )
			return $rt;
		return false;
	}

	public static function newFromVoteId( $vote ) {
		$db = wfGetDB( DB_MASTER );

		$sanctionId = $db->selectField(
			'sanctions_vote',
			'stv_topic',
			[ 'stv_id' => $vote ]
		);

		return Self::newFromId( $sanctionId );
	}

	// @todo $value는 $res의 값으로 갱신하지 않기
	public function loadFrom( $name, $value ) {
		$db = wfGetDB( DB_MASTER );

		$res = $db->select(
			'sanctions',
			'*',
			[ $name => $value ]
		);

		try {
			$this->mId = $res->st_id;
			$this->mTopicUUIDText = $res->st_topic;
			$this->mTopicUUID = UUID::create( strtolower( $this->mTopicUUIDText ) );
			$this->mTarget = User::newFromId( $res->st_target );
			$this->mTargetOriginalName = $res->st_original_name;
			$this->mExpiry = $res->st_expiry;
			$this->mIsHandled = $res->st_handled;
			$this->mIsEmergency = $res->st_emergency;
		} catch ( InvalidInputException $e ) {
			return false;
		}
	}

	public function isVotable() {
		return !isExpired() && !isHandled();
	}

	public function isExpired() {
		return $this->mExpiry < wfTimestamp( TS_MW );
	}

	public function isHandled() {
		return $this->mIsHandled;
	}

	public function getVotes() {
		if ( $this->mVotes === null ) {
			$this->mVotes = $db->select(
				'sanctions_vote',
				'*',
				[
					'stv_topic' => $this->mTopicUUID
				]
			);

		return $this->mVotes;
	}
}