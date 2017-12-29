<?php

use Flow\Api\ApiFlowNewTopic;
use Flow\Api\ApiFlowEditTopicSummary;
use Flow\Model\UUID;

class Sanction {
	/**
	 * @var Integer
	 */
	protected $mId;
	/**
	 * @var UUID
	 */
	protected $mTopic;

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

	public static function write( $user, $target, $forInsultingName, $content ) {
		$targetId = $target->getId();
		$targetName = $target->getName();

		if ( $targetId === 0 )
			return false;

		//만일 동일 사용자명에 대한 부적절한 사용자명 변경 건의안이 이미 있다면 중복 작성을 막습니다.
		$db = wfGetDB ( DB_MASTER );
		if ( $forInsultingName && $db->selectRowCount(
			'sanctions',
			'*',
			[
				'st_original_name' => $targetName,
				'st_handled' => 0
			]
		) > 0 )
			return false;

		// 제재안 주제를 만듭니다.
		$topicTitle = '[[사용자:'.$targetName.']] 님에 대한 ';
		$topicTitle .= $forInsultingName ? '부적절한 사용자명 변경 건의' : '편집 차단 건의';
		$newTopic = new WebRequest();

		$newTopic->setVal( 'page', '페미위키토론:제재안에 대한 의결');
		$newTopic->setVal( 'token', $user->getEditToken() );
		$newTopic->setVal( 'action', 'flow' );
		$newTopic->setVal( 'submodule', 'new-topic' );
		$newTopic->setVal( 'nttopic', $topicTitle );
		$newTopic->setVal( 'ntcontent', $content );

		$main = new ApiMain( $newTopic, true );
		$api = new ApiFlowNewTopic( $main, 'new-topic' );
		$api->setPage( Title::newFromText( "페미위키토론:제재안에 대한 의결" ) );
		$api->execute();

		$topicTitleText = $api->getResultData()['main']['new-topic']['committed']['topiclist']['topic-page'];
		$topicId = $api->getResultData()['main']['new-topic']['committed']['topiclist']['topic-id'];

		if ( $topicId == null ) {
			return false;
		}

		// DB를 씁니다.
		$votingPeriod = (float)wfMessage( 'sanctions-voting-period' )->text();
		$expiry = wfTimestamp( TS_MW, time() + ( 60*60*24 * $votingPeriod ) );

		$uuid = UUID::create( $topicId )->getBinary();
		$data = array(
			'st_target'=> $targetId,
			'st_topic'=> $uuid,
			'st_expiry'=> $expiry,
			'st_original_name'=> $forInsultingName ? $targetName : ''
		);

		$db = wfGetDB( DB_MASTER );
		$db->insert( 'sanctions', $data, __METHOD__ );

		$sanctionId = $db->selectField(
			'sanctions',
			'st_id',
			[ 'st_topic' => $uuid ]
		);

		if ( $sanctionId === false )
			return false;

		$sanction = self::newFromId( $sanctionId );

		if ( $sanction === false )
			return false;

		if ( !$sanction->updateTopicSummary() )
			; // @todo 뭐해야하지

		return $sanction;
	}

	/**
	 * 중간 개표, votes를 넘겨 받아 이를 DB에 추가하거나 갱신합니다.
	 */
	public static function countVotes( $sanction, array $votes = null ) {
		$db = wfGetDB( DB_MASTER );

		$dbIsTouched = false;
		foreach ( $votes as $vote ) {
			$period = $db->selectField(
				'sanctions_vote',
				['stv_period'],
				[
					'stv_topic' => $sanction->getTopicUUID()->getBinary(),
					'stv_user'=> $vote['user']
				]
			);
			if( $period === false ) {
				$db->insert(
					'sanctions_vote',
					[
						'stv_topic' => $sanction->getTopicUUID()->getBinary(),
						'stv_user' => $vote['user'],
						'stv_period' => $vote['period']
					]
				);
				$dbIsTouched = true;
			}
			else if ( $period == $vote['period'] ) {
				$db->update(
					'sanctions_vote',
					[
						'stv_period' => $vote['period']
					],
					[
						'stv_topic' => $sanction->getTopicUUID()->getBinary(),
						'stv_user' => $vote['user']
					]
				);
				$dbIsTouched = true;
			}
		}

		if ( $dbIsTouched ) {
			$sanction->immediateRejectionIfNeeded();
			$sanction->updateTopicSummary();
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
		// 긴급 절차였다면 임시 조치를 해제합니다.
		if ( $this->mIsEmergency )
			$this->removeTemporaryMeasure('제재안 부결에 따른 임시 조치 해제');

		// 제재안이 처리되었음을 데이터베이스에 표시합니다.
		$db = wfGetDB( DB_MASTER );

		$res = $db->update(
			'sanctions',
			[ 'st_expiry' => wfTimestamp( TS_MW ) ],
			[ 'st_id' => $this->mId ]
		);
		
		$this->updateTopicSummary();
	}

	// @todo 실패할 경우 false를 반환하기
	public function execute() {
		if ( !$this->isExpired() || $this->mIsHandled )
			return false;
		$this->mIsHandled = true;

		$id = $this->mId;
		$emergency = $this->mIsEmergency;
		$passed = $this->isPassed();
		$topic = $this->mTopic;

		if ( $passed && !$emergency )
			$this->justTakeMeasure();
		elseif ( !$passed && $emergency )
			$this->removeTemporaryMeasure( '제재안 부결에 따른 임시 조치 해제' );
		else if ( $passed && $emergency )
			$this->replaceTemporaryMeasure();

		// 데이터베이스에 반영합니다.
		$db = wfGetDB( DB_MASTER );

		$res = $db->update(
			'sanctions',
			[ 'st_handled' => 1 ],
			[ 'st_id' => $id ]
		);
		$db->delete(
			'sanctions_vote',
			[ 'stv_topic' => $topic ]
		);

		return true;
	}

	public function toggleEmergency() {
		//이미 만료된 제재안은 절차를 변경할 수 없습니다.
		if ( $this->isExpired() ) return false;

		$id = $this->mId;
		$target = $this->mTarget;
		$emergency = $this->mIsEmergency;
		$toEmergency = !$emergency;
		$expiry = $this->mExpiry;
		$insultingName = $this->isForInsultingName();

		if( $toEmergency )
			$this->takeTemporaryMeasure();
		else {
			$reason = '[[주제:'.$this->mTopic->getAlphadecimal().'|제재안]] 일반 절차 전환에 따른 임시 조치 해제';
			$this->removeTemporaryMeasure( $reason );
		}

		$this->mIsEmergency = !$emergency;

		//DB에 적힌 절차를 바꿔 갱신합니다.
		$db = wfGetDB ( DB_MASTER );
		$db->update(
			'sanctions',
			['st_emergency' => $emergency?0:1 ],
			[ 'st_id' => $id ]
		);

		return true;
	}

	public function justTakeMeasure() {
		$target = $this->mTarget;
		$targetId = $target->getId();
		$isForInsultingName = $this->isForInsultingName();
		$reason = '[[주제:'.$this->mTopic->getAlphadecimal().'|제재안]]의 가결';

		if ( $isForInsultingName ) {
			$targetName = $target->getName();
			$originalName = $this->mTargetOriginalName;

			if ( $targetName != $originalName )
				return true;
			
			$rename = new RenameuserSQL(
				$targetName,
				'임시사용자명'.wfTimestamp(TS_MW),
				$targetId,
				$this->getBot(),
				[ 'reason' => $reason ]
			);
			if ( !$rename->rename() )
				return false;
			return true;
		} else {
			$period = $this->getPeriod();
			$blockExpiry = $wfTimestamp( TS_MW, time() + ( 60*60*24 * $period ) );
			if ( $target->isBlocked() ) {
				// 이 제재안에 따라 결정된 차단 종료 시간이 기존 차단 해제 시간보다 뒤라면 제거합니다.
				if ( $target->getBlock()->getExpiry() < $blockExpiry )
					self::unblock( $target, false );
				else
					return true;
			}
			
			self::doBlock( $target, $blockExpiry, $reason, true );
  	    }
  	}

	public function replaceTemporaryMeasure() {
		$target = $this->mTarget;
		$isForInsultingName = $this->isForInsultingName();
		$reason = '[[주제:'.$this->mTopic->getAlphadecimal().'|제재안]]의 가결';

		if ( $isForInsultingName ) {
			return true;
		} else {
			$blockExpiry = wfTimestamp( TS_MW, time() + ( 60*60*24 * $this->getPeriod() ) );
			if ( $target->isBlocked() ) {
				// 이 제재안에 따라 결정된 차단 종료 시간이 기존 차단 해제 시간보다 뒤라면 제거합니다.
				if ( $target->getBlock()->getExpiry() < $blockExpiry )
					unblock( $target, false );
				else
					return true;
			}

			self::doBlock( $target, $blockExpiry, $reason, true );
		}
	}

	public function takeTemporaryMeasure() {
		$target = $this->mTarget;
		$insultingName = $this->isForInsultingName();
		$reason = '[[주제:'.$this->mTopic->getAlphadecimal().'|제재안]]의 긴급 절차 전환';

		if( $insultingName ) {
			$originalName = $this->mTargetOriginalName;

			if( $target->getName() == $originalName ) {
				$rename = new RenameuserSQL(
					$target->getName(),
					'임시사용자명'.wfTimestamp(TS_MW),
					$target->getId(),
					$this->getBot(),
					[ 'reason' => $reason ]
				);
				if ( !$rename->rename() ) {
					return false;
				}
			}
		}
		else {
			$expiry = $this->mExpiry;
			//의결 만료 기간까지 차단하기
			//차단되어 있을 않을 경우, 혹은 이미 차단되어 있다면 기간을 비교하여
			//이 제재안의 의결 종료 시간이 차단 해제 시간보다 뒤라면 늘려 차단합니다.
			if( !$target->isBlocked() || $target->getBlock()->getExpiry() < $expiry ) {
				if($target->isBlocked())
					self::unblock( $target, false );

				$blockExpiry = $expiry;
				self::doBlock( $target, $blockExpiry, $reason, false );
			}
		}
	}

	public function removeTemporaryMeasure( $reason ) {
		$target = $this->mTarget;
		$isForInsultingName = $this->isForInsultingName();

		if( $isForInsultingName ){
			$targetName = $target->getName();
			$originalName = $this->mTargetOriginalName;

			if( $targetName == $originalName ) {
				return true;
			} else {
				$rename = new RenameuserSQL(
					$targetName,
					$originalName,
					$target->getId(),
					$this->getBot(),
					[ 'reason' => $reason ]
				);
				if ( !$rename->rename() )
					return false;
				return true;
			}
		}
		else {
			// 현재 차단이 이 제재안에 의한 것일 때에는 차단을 해제합니다.
			// @todo 긴급 절차로 인해 다른 짧은 차단이 덮어 씌였다면 짧은 차단을 복구합니다.
			// 즉 차단 기록을 살펴 이 제재안과 무관한 차단 기록이 있다면 기간을 비교하여 
			// 이 제재안의 의결 종료 기간이 차단 해제 시간보다 뒤라면 차단 기간을 줄입니다.
			if( $target->isBlocked() && $target->getBlock()->getExpiry() == $this->mExpiry )
				self::unblock( $target, true, $reason );
			return true;
		}
	}

	/**
	 * 제재 기간을 반환합니다. 
	 */
	public function getPeriod( $getAnyway = false ) {
		$votes = $this->getvotes();
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
		
		if ( $passed || $getAnyway )
			return ceil( $period/$count );
		return 0;
	}

	public function isPassed() {
		$votes = $this->getVotes();
		$count = count( $votes );

		if ( $count === 0 ) return false;

		$agree = 0;
		foreach ( $votes as $vote ) {
			if ( $vote['period'] > 0 ) $agree++;
		}

		return ( $count >= 3 && $agree >= $count*2/3 )
			|| ( /* $count > 0 && 위에서 검사하여 생략 */ $count < 3 && $agree == $count );
	}

	// @todo 이미 작성된 주제 요약이 있을 때 (etsprev_revision을 비웠기 때문에)제대로 작동하지 않습니다. 
	public function updateTopicSummary() {
		try {
			$topicTitleText = $this->getTopic()->getFullText();
			$topicTitle = Title::newFromText( $topicTitleText );

			$EditTopicSummary = new WebRequest();
			$EditTopicSummary->setVal( 'page', '$topicTitleText');
			$EditTopicSummary->setVal( 'token', User::newFromName( 'Admin' )->getEditToken() );
			$EditTopicSummary->setVal( 'action','flow' );
			$EditTopicSummary->setVal( 'submodule','edit-topic-summary' );
			$EditTopicSummary->setVal( 'etsprev_revision', '' );
			$EditTopicSummary->setVal( 'etssummary', $this->getSanctionSummary() );
			$EditTopicSummary->setVal( 'etsformat','wikitext' );

			$main = new ApiMain( $EditTopicSummary, true );
			$api = new ApiFlowEditTopicSummary( $main, 'edit-topic-summary' );
			$api->setPage( $topicTitle );
			$api->execute();

			return $api->getResultData()['main']['edit-topic-summary']['status']
				&& $api->getResultData()['main']['edit-topic-summary']['committed'];
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * @todo 제재안이 만료되었다면 만료되었다는 사실을 추가합니다.
	 */
	public function getSanctionSummary() {
		$summary = self::getSanctionSummaryHeader();

		$summary .= '* 의결 종료: '.MWTimestamp::getLocalInstance( $this->mExpiry )->getTimestamp( TS_ISO_8601 );
		// @todo
	
		return $summary;
	}

	public static function getSanctionSummaryHeader() {
		return '<small>< [[특수:제재안목록|전체 제재안 목록보기]]</small>'.PHP_EOL; // @todo 뭐라그래
	}

	public static function newFromId( string $id ) {
		$rt = new self();
		if ( $rt->loadFrom( 'st_id', $id ) )
			return $rt;
		return false;
	}

	public static function newFromUUID( $UUID ) {
		if ( $UUID instanceof UUID )
			$UUID = $UUID->getBinary();

		$rt = new self();
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

		return self::newFromId( $sanctionId );
	}

	// @todo $value는 $res의 값으로 갱신하지 않기
	public function loadFrom( $name, $value ) {
		$db = wfGetDB( DB_MASTER );

		$row = $db->selectRow(
			'sanctions',
			'*',
			[ $name => $value ]
		);

		if ( $row === false )
			return false;

		try {
			$this->mId = $row->st_id;
			$topicUUIDBinary = $row->st_topic;
			$this->mTopic = UUID::create( $topicUUIDBinary );
			$this->mTarget = User::newFromId( $row->st_target );
			$this->mTargetOriginalName = $row->st_original_name;
			$this->mExpiry = $row->st_expiry;
			$this->mIsHandled = $row->st_handled;
			$this->mIsEmergency = $row->st_emergency;

			return true;
		} catch ( InvalidInputException $e ) {
			return false;
		}
	}

	protected static function doBlock( $target, $expiry, $reason, $preventEditOwnUserTalk = true ) {
		$bot = self::getBot();

		$block = new Block();
		$block->setTarget( $target );
		$block->setBlocker( $bot );
		$block->mReason = $reason;
		$block->isHardblock( true );
		$block->isAutoblocking( true );
		$block->prevents( 'createaccount', true );
		$block->prevents( 'editownusertalk', $preventEditOwnUserTalk );
		$block->mExpiry = $expiry;

		$success = $block->insert();

		if ( !$success ) return false;

		$logParams = array();
		$logParams['5::duration'] = $expiry;
		$flags = array( 'nocreate' );
		if ( !$block->isAutoblocking() && !IP::isIPAddress( $target ) ) {
			// Conditionally added same as SpecialBlock
			$flags[] = 'noautoblock';
		}
		$logParams['6::flags'] = implode( ',', $flags );

		$logEntry = new ManualLogEntry( 'block', 'block' );
		$logEntry->setTarget( Title::makeTitle( NS_USER, $target ) );
		$logEntry->setComment( $reason );
		$logEntry->setPerformer( $bot );
		$logEntry->setParameters( $logParams );
		$blockIds = array_merge( array( $success['id'] ), $success['autoIds'] );
		$logEntry->setRelations( array( 'ipb_id' => $blockIds ) );
        $logId = $logEntry->insert();
        $logEntry->publish( $logId );

		return true;
	}

	protected static function unblock( $target, $withLog = false, $reason = null ) {
		$block = $target->getBlock();

		if ( $block == null || !$block->delete() )
			return false;

		// SpecialUnblock.php에 있던 것과 같은 내용입니다.
		if ( $block->getType() == Block::TYPE_AUTO ) {
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
	        $logEntry->setPerformer( $bot );
	        $logId = $logEntry->insert();
	        $logEntry->publish( $logId );
	    }
	}

	public function isVotable() {
		return !$this->isExpired() && !$this->isHandled();
	}

	public function isExpired() {
		return $this->mExpiry < wfTimestamp( TS_MW );
	}

	public function isHandled() {
		return $this->mIsHandled;
	}

	public function isEmergency() {
		return $this->mIsEmergency;
	}

	public function getId() {
		return $this->mId;
	}

	public function getExpiry() {
		return $this->mExpiry;
	}

	public function getTarget() {
		return $this->mTarget;
	}

	public function getVotes() {
		if ( $this->mVotes === null ) {
			$this->mVotes = array();

			$db = wfGetDB( DB_MASTER );
			$res = $db->select(
				'sanctions_vote',
				'*',
				[
					'stv_topic' => $this->mTopic->getBinary()
				]
			);
			// ResultWrapper를 array로 바꾸기 @todo 제대로 된 방법으로 고치기
			foreach ( $res as $row )
				$this->mVotes[] = [
					'user' => $row->stv_user,
					'period' => $row->stv_period
				];
		}

		return $this->mVotes;
	}

	public function isForInsultingName() {
		return $this->mTargetOriginalName != null;
	}

	public function getTargetOriginalName() {
		return $this->mTargetOriginalName;
	}

	/**
	 * @return Title
	 */
	public function getTopic() {
		$UUIDText = $this->mTopic->getAlphadecimal();

		return Title::newFromText( '주제:'.$UUIDText ); // @todo 이건 아닌것 같음
	}

	public function getTopicUUID() {
		return $this->mTopic;
	}

	protected static function getBot() {
		$botName = '제재안';
		$bot = User::newSystemUser( $botName, [ 'steal' => true ] );
		$bot->addGroup( 'sysop' );

		return $bot;
	}
}