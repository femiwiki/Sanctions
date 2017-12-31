<?php

use Flow\Container;
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

	/**
	 * @var array
	 */
	protected $mDb;

	protected $mIsPassed;

	/**
	 * 제재안을 새로 만들어 저장합니다.
	 * @param $user User 제재안을 쓸 사람
	 * @param $target User 제재안에 쓰인 제재가 필요한 사람
	 * @param $forInsultingName Bool 제재안이 부적절한 사용자명에 의한 것인지의 여부
	 * @param $content String 제재안의 내용(wikitext 스타일)
	 * @return Sanction 작성된 제재안.
	 */
	public static function write( $user, $target, $forInsultingName, $content ) {
		$targetId = $target->getId();
		$targetName = $target->getName();

		// 대상자의 아이디가 없다면(가입자가 아니라면) 실패합니다.
		if ( $targetId === 0 )
			return false;

		// 제재안 주제를 만듭니다.
		$discussionPageName = wfMessage( 'sanctions-discussion-page-name' )->text(); //페미위키토론:제재안에 대한 의결
		$topicTitle = '[[사용자:'.$targetName.']] 님에 대한 ';
		$topicTitle .= $forInsultingName ? '부적절한 사용자명 변경 건의' : '편집 차단 건의';
		$factory = Container::get( 'factory.loader.workflow' );
		$page = Title::newFromText( $discussionPageName );
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
		if ( !count( $blocksToCommit ) )
			return false;

		$commitMetadata = $loader->commit( $blocksToCommit );

		// $topicTitleText = $commitMetadata['topiclist']['topic-page'];
		$topicId = $commitMetadata['topiclist']['topic-id'];

		if ( $topicId == null ) {
			return false;
		}

		// DB를 씁니다.
		$votingPeriod = (float)wfMessage( 'sanctions-voting-period' )->text();
		$now = wfTimestamp( TS_MW );
		$expiry = wfTimestamp( TS_MW, time() + ( 60*60*24 * $votingPeriod ) );

		$uuid = UUID::create( $topicId )->getBinary();
		$data = array(
			'st_target'=> $targetId,
			'st_topic'=> $uuid,
			'st_expiry'=> $expiry,
			'st_original_name'=> $forInsultingName ? $targetName : '',
			'st_last_update_timestamp' => $now
		);

		$db = wfGetDB( DB_MASTER );
		$db->insert( 'sanctions', $data, __METHOD__ );

		$sanction = new self();
		$sanction->loadFrom( 'st_topic', $uuid );
		
		if ( !$sanction )
			return false;

		if ( !$sanction->updateTopicSummary() )
			; // @todo 뭐해야하지

		return $sanction;
	}

	public function toggleEmergency() {
		//이미 만료된 제재안은 절차를 변경할 수 없습니다.
		if ( $this->isExpired() ) return false;

		$this->checkNewVotes();

		$emergency = $this->mIsEmergency;
		$toEmergency = !$emergency;

		if( $toEmergency )
			$this->takeTemporaryMeasure();
		else {
			$reason = '[[주제:'.$this->mTopic->getAlphadecimal().'|제재안]] 일반 절차 전환에 따른 임시 조치 해제';
			$this->removeTemporaryMeasure( $reason );
		}

		$emergency = !$emergency;
		$this->mIsEmergency = $emergency;

		//DB에 적힌 절차를 바꿔 갱신합니다.
		$id = $this->mId;
		$db = $this->getDb();
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
	 * 제재안의 의결에 따라 차단이나 사용자명 변경을 합니다.
	 * @return Bool 성공
	 */
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
			$blockExpiry = wfTimestamp( TS_MW, time() + ( 60*60*24 * $period ) );
			if ( $target->isBlocked() ) {
				// 이 제재안에 따라 결정된 차단 종료 시간이 기존 차단 해제 시간보다 뒤라면 제거합니다.
				if ( $target->getBlock()->getExpiry() < $blockExpiry )
					self::unblock( $target, false );
				else
					return true;
			}
			
			self::doBlock( $target, $blockExpiry, $reason, true );
			return true;
  	    }
  	}

	/**
	 * (긴급 절차의)임시 조치를 정식 제재로 교체합니다.
	 * @return Bool 성공
	 */
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
			return true;
		}
	}

	/**
	 * 임시 조치를 취합니다
	 * @return Bool 성공
	 */
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
			//이미 차단되어 있다면 기간을 비교하여
			//이 제재안의 의결 종료 시간이 차단 해제 시간보다 뒤라면 늘려 차단합니다.
			if( $target->isBlocked() && $target->getBlock()->getExpiry() < $expiry )
				self::unblock( $target, false );

			$blockExpiry = $expiry;
			self::doBlock( $target, $blockExpiry, $reason, false );
		}
	}

	/**
	 * 임시 조치를 해제합니다.
	 * @param $reason String 해제 이유입니다.
	 */
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
	 * 이 제재안에 대한 투표가 있거나, 기존에 표를 쓴 사용자가 의견을 바꾸었을 때 호출됩니다.
	 */
	public function onVotesChanged() {
		$this->mIsPassed = null;
		$this->immediateRejectionIfNeeded();
		$this->updateTopicSummary();
	}

	/**
	 * 즉시 부결 조건을 만족하는지 확인하고 실행합니다.
	 */
	public function immediateRejectionIfNeeded() {
		if( $this->NeedToImmediateRejection() )
			return $this->immediateRejection();
	}

	// 부결 조건인 3인 이상의 반대를 검사합니다.
	public function NeedToImmediateRejection() {
		$votes = $this->getVotes();

		$sumPeriod = 0;
		foreach( $votes as $userId => $period ) {
			$sumPeriod += $period;
		}

		if( count( $votes ) >= 3 && $sumPeriod === 0 )
			return true;
		return false;
	}

	public function immediateRejection() {
		// 긴급 절차였다면 임시 조치를 해제합니다.
		if ( $this->mIsEmergency ) {
			$reason = '[[주제:'.$this->mTopic->getAlphadecimal().'|제재안]] 부결에 따른 임시 조치 해제';
			$this->removeTemporaryMeasure( $reason );
		}

		// 제재안이 처리되었음을 데이터베이스에 표시합니다.
		$db = $this->getDb();
		$now = wfTimestamp( TS_MW );
		$res = $db->update(
			'sanctions',
			[
				'st_expiry' => wfTimestamp( TS_MW ),
				'st_last_update_timestamp' => $now
			],
			[ 'st_id' => $this->mId ]
		);
		$db->delete(
			'sanctions_vote',
			[ 'stv_topic' => $this->mTopic->getBinary() ]
		);
		
		$this->updateTopicSummary();
	}

	// @todo 실패할 경우 false를 반환하기
	public function execute() {
		if ( !$this->isExpired() || $this->mIsHandled )
			return false;
		$this->mIsHandled = true;

		$this->checkNewVotes();

		$id = $this->mId;
		$emergency = $this->mIsEmergency;
		$passed = $this->isPassed();
		$topic = $this->mTopic;

		if ( $passed && !$emergency )
			$this->justTakeMeasure();
		elseif ( !$passed && $emergency ) {
			$reason = '[[주제:'.$this->mTopic->getAlphadecimal().'|제재안]] 부결에 따른 임시 조치 해제';
			$this->removeTemporaryMeasure( $reason );
		}
		else if ( $passed && $emergency )
			$this->replaceTemporaryMeasure();

		// 데이터베이스에 반영합니다.
		$db = $this->getDb();
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

	// @todo 이미 작성된 주제 요약이 있을 때는 (etsprev_revision을 비웠기 때문에) 제대로 작동하지 않습니다. 
	public function updateTopicSummary() {
		try {
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
					'etsprev_revision' => '',
					'summary' => $this->getSanctionSummary(),
					'format' => 'wikitext'
				], 
				'topic' => []
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
		} catch ( Exception $e ) {
			return false;
		}

		return count( $commitMetadata ) > 0;
	}

	/**
	 * @todo 표결 현황 추가
	 */
	public function getSanctionSummary() {
		$summary = self::getSanctionSummaryHeader();

		$summary .= '* 의결 종료: '.MWTimestamp::getLocalInstance( $this->mExpiry )->getTimestamp( TS_ISO_8601 );
		// @todo
	
		return $summary;
	}

	// @todo $value는 $row의 값으로 갱신하지 않기
	public function loadFrom( $name, $value ) {
		$db = $this->getDb();

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

	/**
	 * 제재 기간을 반환합니다. 
	 * @param $getAnyway Bool 참이라면 가결/부결에 무관하게 평균 제재 기간만을 반환합니다.
	 * @return Integer
	 */
	public function getPeriod( $getAnyway = false ) {
		$votes = $this->getvotes();
		$count = count( $votes );

		// 표가 하나도 없다면 0일입니다.
		if ( $count === 0 ) return 0;

		$sumPeriod = 0;
		$agree = 0;
		foreach ( $votes as $userId => $period ) {
			$sumPeriod += $period;
			if ( $period > 0 ) $agree++;
		}

		if ( $getAnyway )
			return ceil( $sumPeriod/$count );

		// 가결 여부를 구합니다. 가결 조건은 다음과 같습니다.
	 	// - 3인 이상이 의견을 내고 2/3 이상이 찬성한 경우
	 	// - 1인 이상, 3인 미만이 의견을 내고 반대가 없는 경우
		$passed = ( $count >= 3 && $agree >= $count*2/3 )
			|| ( /* $count > 0 && 위에서 검사하여 생략 */ $count < 3 && $agree == $count );
		
		if ( $passed )
			return ceil( $sumPeriod / $count );
		return 0;
	}

	public function isPassed() {
		if ( $this->mIsPassed === null ) {
			$votes = $this->getVotes();
			$count = count( $votes );

			if ( $count === 0 ) return false;

			$agree = 0;
			foreach ( $votes as $userId => $period ) {
				if ( $period > 0 ) $agree++;
			}

			$this->mIsPassed = ( $count >= 3 && $agree >= $count*2/3 )
			|| ( /* $count > 0 && 위에서 검사하여 생략 */ $count < 3 && $agree == $count );
		}

		return $this->mIsPassed;
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

			$db = $this->getDb();
			$res = $db->select(
				'sanctions_vote',
				'*',
				[
					'stv_topic' => $this->mTopic->getBinary()
				]
			);
			// ResultWrapper를 array로 바꾸기 @todo 제대로 된 방법으로 고치기
			foreach ( $res as $row )
				$this->mVotes[$row->stv_user] = $row->stv_period;
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

	/**
	 * 어떤 사용자에 대한 부적절한 사용자명 변경 건의가 있는지를 확인합니다.
	 * @param $user User
	 * @return Bool
	 */
	public static function existingSanctionForInsultingNameOf( $user ) {
		$db = wfGetDB ( DB_MASTER );
		$targetId = $user->getId();

		$row = $db->selectRow(
			'sanctions',
			'*',
			[
				'st_target' => $targetId,
				'st_original_name IS NOT NULL',
				'st_expiry > '. wfTimestamp( TS_MW )
			]
		);
		if ( $row !== false )
			return self::newFromId( $row->st_id) ;
		return null;
	}

	/**
	 * @return Bool 새로 반영된 표가 있는지 여부
	 */
	public function checkNewVotes() {
		// 닫힌 제재안은 검사하지 않습니다.
		if ( $this->isExpired() ) return false;

		$uuid = $this->getTopicUUID();
		$db = $this->getDb();

		// 마지막으로 체크한 이후로 토픽이 변경된 적이 없으면 검사하지 않습니다.
		$topicLastUpdate = $db->selectField(
			'flow_workflow',
			'workflow_last_update_timestamp',
			[
				'workflow_id' => $uuid->getBinary(),
				'workflow_type' => 'topic'
			]
		);
		$id = $this->mId;
		$sanctionLastUpdate = $db->selectField(
			'sanctions',
			'st_last_update_timestamp',
			[
				'st_id' => $id
			]
		);
		if ( $topicLastUpdate <= $sanctionLastUpdate ) {
			return false;
		}

		// 이 제재안 주제에 작성된 모든 리플의 모든 리비전을 가져옵니다. // @todo 모든 리비전을 가져오고 싶진 않은데
		$res = $db->select(
			[
				'flow_workflow',
				'flow_tree_node',
				'flow_tree_revision',
				'flow_revision'
			],
			[
				'rev_id',
				'rev_user_id',
				'rev_content'
			],
			[
				'workflow_id' => $uuid->getBinary()
			],
			__METHOD__,
			[ 'DISTINCT' ],
			[
				'flow_tree_node' => [ 'INNER JOIN', 'workflow_id = tree_ancestor_id' ],
				'flow_tree_revision' => [ 'INNER JOIN', 'tree_descendant_id = tree_rev_descendant_id' ],
				'flow_revision' => [ 'INNER JOIN', 'tree_rev_id = rev_id' ],
			]
		);

		$votes = [];
		// 유효표(제재 절차 잠여 요건을 만족하는 사람의 표)와 무효표를 따지지 않고 우선 세어서 배열에 담습니다.
		foreach ( $res as $row ) {
			$timestamp = UUID::create( $row->rev_id )->getTimestamp();
			$userId = $row->rev_user_id;
			$content = $row->rev_content;

			// post에 의견이 담겨있는지 검사합니다.
			// 각 의견의 구분은 위키의 틀 안에 적어둔 태그를 사용합니다.
			$period = 0;
			if ( preg_match( '/<span class="sanction-vote-agree-period">(\d+)<\/span>/', $content, $matches ) != 0 && count( $matches ) > 0 ) {
				$period = (int)$matches[1];
			} elseif ( strpos( $content, '"sanction-vote-agree"' ) !== false ) {
				// 찬성만 하고 날짜를 적지 않았다면 1일로 처리합니다.
				$period = 1;
			} elseif ( strpos( $content, '"sanction-vote-disagree"' ) !== false ) {
				$period = 0;
			}
			else {
				continue;
			}

			// 이 의견이 해당 사용자가 남긴 가장 마지막 의견이 아니라면 무시합니다.
			if( isset( $votes[$userId] ) && $votes[$userId]['stv_last_update_timestamp'] > $timestamp )
				continue;

			//배열에 저장합니다.
			$votes[$userId] = [
				'stv_period' => $period,
				'stv_last_update_timestamp' => $timestamp
			];
		}

		// 무효표를 버립니다
		foreach ( $votes as $userId => $vote ) {
			if( !SanctionsUtils::hasVoteRight( User::newFromId( $userId ) ) )
				unset( $votes[$userId] );
		}

		// 유효표가 하나도 없을 경우 아무것도 하지 않습니다.
		if ( !count( $votes ) ) return false;
		
		$dbIsTouched = false;
		foreach ( $votes as $userId => $vote ) {
			$previous = $db->selectRow(
				'sanctions_vote',
				[
					'stv_period',
					'stv_last_update_timestamp'
				],
				[
					'stv_topic' => $uuid->getBinary(),
					'stv_user' => $userId
				]
			);
			if ( $previous == false ) {
				$db->insert(
					'sanctions_vote',
					[
						'stv_topic' => $uuid->getBinary(),
						'stv_user' => $userId,
						'stv_period' => $vote['stv_period'],
						'stv_last_update_timestamp' => $vote['stv_last_update_timestamp']
					]
				);
				$dbIsTouched = true;
			} else if ( $previous->stv_last_update_timestamp < $vote['stv_last_update_timestamp'] && $previous->stv_period != $vote['stv_period'] ) {
				$db->update(
					'sanctions_vote',
					[
						'stv_period' => $vote['stv_period'],
						'stv_last_update_timestamp' => $vote['stv_last_update_timestamp'],						
					],
					[
						'stv_topic' => $uuid->getBinary(),
						'stv_user' => $userId
					]
				);
				$dbIsTouched = true;
			}
		}

		if ( $dbIsTouched ) {
			// 제재안의 시간을 갱신합니다.
			$db->update(
				'sanctions',
				[
					'st_last_update_timestamp' => $sanctionLastUpdate
				],
				[
					'st_id' => $id
				]
			);
			$this->onVotesChanged();
		}

		return true;
	}

	public static function getSanctionSummaryHeader() {
		return '';
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
		else if ( is_string( $UUID ) ) {
			$UUID = UUID::create( strtolower($UUID) )->getBinary();
		}

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

	protected function getDb() {
		if ( !$this->mDb )
			$this->mDb = wfGetDB( DB_MASTER );
		return $this->mDb;
	}

	protected static function getBot() {
		$botName = '제재안';
		$bot = User::newSystemUser( $botName, [ 'steal' => true ] );
		$bot->addGroup( 'sysop' );

		return $bot;
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
		$logParams['5::duration'] = MWTimestamp::getInstance( $expiry )->getTimestamp( TS_ISO_8601 ); // 뭐가 있는지 그냥 이러면 현지 시각으로 잘 나옵니다
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
}