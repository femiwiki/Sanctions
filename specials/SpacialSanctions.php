<?php

use Flow\Api\ApiFlowNewTopic;
use Flow\Container;
use Flow\Model\UUID;

class SpacialSanctions extends SpecialPage {

	//protected $linkRenderer = null;
	protected $mDb;
	protected $mTargetName;
	protected $mTargetId;
	protected $mRevisionId;

	public function __construct() {
		parent::__construct( 'Sanctions' );

		$this->mTargetName = null;
		$this->mTargetId = null;
		$this->mRevisionId = null;
	}

	public function execute( $subpage ) {
		$output = $this->getOutput();

		$this->setParameter( $subpage );

		$this->setHeaders();
		$this->outputHeader();

		//Request가 있었다면 그것만을 처리하고 제재한 목록은 보여주지 않습니다.
		if($this->HandleRequestsIfExist($output)) return;

		//대상자가 있다면 
		if($this->mTargetName != null) {
			$output->setPageTitle( $this->msg( 'sanctions-title-with-target', $this->mTargetName ) );
			$output->setSubTitle('< '.Linker::link($this->getTitle(),'모든 제재안 보기'));
		}

		$output->addModuleStyles( 'ext.sanctions' );

		$pager = new SanctionsPager($this->getContext(), $this->mTargetName);
		$pager->doQuery();
		$output->addHTML($pager->getBody());

		if(SanctionsUtils::hasVoteRight($this->getUser()))
			$output->addHTML($this->makeForm());
		else if( $this->getUser()->isAnon () )
			$output->addWikiText('제재 절차 참여를 위한 몇 가지 조건이 있습니다. [[페미위키:제재 정책]]을 참고해 주세요.');
		else
			$output->addWikiText('현재 '.$this->getUser()->getName().' 님께는 제재 절차에 잠여할 수 있는 권한이 없습니다. [[페미위키:제재 정책]]을 참고해 주세요.');
	}

	function setParameter( $subpage ) {
		$parts = explode( '/', $subpage, 2 );

		$targetName = count( $parts ) > 0 ? $parts[0] : null;

		if( $targetName == null ) return;
		
		$targetId = self::findUserIdFromName( $targetName );

		if( $targetId == null) return;

		$this->mTargetName = str_replace("_", " ", $targetName);
		$this->mTargetId = $targetId;
		$this->mRevisionId = count( $parts ) > 1 ? $parts[1] : null;
	}

	function HandleRequestsIfExist($output) {
		$request = $this->getRequest();

		if(!$request->wasPosted()) return false;
		
		$this->mdb = wfGetDB( DB_MASTER );

		$result = $request->getVal( 'result' );
		switch($result) {
			//제재안 올리기
			case 'write':
		$targetName = $request->getVal( 'target' );
				if(!$targetName) {
					$output->addWikiText('사용자명이 입력되지 않았습니다.' );
					break;
				}
				$target = User::newFromName($targetName);
				$targetId = $target->getId();

				if($targetId === 0) {
					$output->addWikiText('"'.$targetName.'"라는 이름의 사용자가 존재하지 않습니다.' );
					break;
				}

				//제재안 주제를 만듭니다.
				$content = '<small>[['.$this->getTitle()->getFullText().'|제재안 목록]]</small>'.PHP_EOL.PHP_EOL;
				$content .= $request->getVal( 'content' )?:'내용이 작성되지 않았습니다.';

				$request->setVal('action','flow');
				$request->setVal('submodule','new-topic');
				$request->setVal('nttopic','[[사용자:'.$targetName.']]');
				$request->setVal('ntcontent',$content);

				$main = new ApiMain($request, true);
				$api = new ApiFlowNewTopic($main, 'new-topic');
				$api->setPage(Title::newFromText("페미위키토론:제재안에 대한 의결"));
				$api->execute();

				//DB를 씁니다.
				$topicPage = $api->getResultData()['main']['new-topic']['committed']['topiclist']['topic-page'];
				$topicId = $api->getResultData()['main']['new-topic']['committed']['topiclist']['topic-id'];

				if($topicId == null) {
					$output->addWikiText( '제재안 작성에 실패하였습니다.' );
					break;
				}
				
				$data = array(
					'st_target'=> $targetId,
					'st_topic'=> UUID::create($topicId)->getBinary(),
					'st_expiry'=> wfTimestamp(TS_MW, time() + (60 * 60 * 24 * (float)$this->msg( 'sanctions-voting-period' )->text())),
					'st_original_name'=> $request->getBool( 'hasInsultingName' )?$targetName:''
				);

				$this->mdb->insert( 'sanctions', $data, __METHOD__ );
				$output->addWikiText( '[[사용자:'.$request->getVal( 'target' ).']] 님에 대한 [['.$topicPage.']]가 생성되었습니다.' );
			break;

			//제재안 절차 변경( 일반 <-> 긴급 )
			case 'toggle-emergency':
				//차단 권한이 없다면 절차를 변경할 수 없습니다.
				if(!$this->getUser()->isAllowed('block')) break;

				$sanctionId = $request->getVal( 'sanctionId' );
				$row = $this->mdb->selectRow(
					'sanctions',
					['st_target', 'st_topic','st_emergency', 'st_original_name', 'st_expiry'],
					[ 'st_id' => $sanctionId ]
				);
				$target = User::newFromId($row->st_target);
				$emergency = $row->st_emergency;
				$sanctionExpiry = $row->st_expiry;
				$originalName = $row->st_original_name;
				$insultingName = $originalName != null;

				//이미 만료된 제재안은 절차를 변경할 수 없습니다.
				if($sanctionExpiry < wfTimestamp(TS_MW)) break;

				//현재 제재안이 일반 절차여서 긴급으로 전환할 때
				if( !$emergency ) {
					if( $insultingName ){
						if( $target->getName() != $originalName ) {
							$output->addWikiText( '사용자명이 이미 바뀌어 있어 긴급 변경을 할 수 없었습니다.' );
							break;
						} else {
							$rename = new RenameuserSQL(
								$target->getName(),
								'임시사용자명'.wfTimestamp(TS_MW),
								$target->getId(),
								$this->getUser(),
								[ 'reason' => '긴급 절차 전환' ]
							);
							if ( !$rename->rename() ) {
								$output->addWikiText( '사용자명 변경 실패' );
							}
							$output->addWikiText( '사용자명을 바꾸었습니다.' );
						}
					}
					else {
						//의결 만료 기간까지 차단하기
						//차단되어 있을 않을 경우, 혹은 이미 차단되어 있다면 기간을 비교하여
						//이 제재안의 의결 종료 시간이 차단 해제 시간보다 뒤라면 늘려 차단합니다.
						if( !$target->isBlocked() || (int)($target->getBlock()->getExpiry()) < (int)$sanctionExpiry ) {
							if($target->isBlocked())
								$target->getBlock()->delete();
							
							$blockOptions = [
				                'address' => $target->getName(),
				                'user' => $target->getId(),
				                'reason' => '[[주제:'.UUID::create($row->st_topic)->getAlphadecimal().']]',
				                'expiry' => (int)$sanctionExpiry,
				                'by' => $this->getUser()->getId(),
				                'allowUsertalk' => true,
				                'enableAutoblock' => true
				            ];
				            $block = new Block( $blockOptions );
				  	        $block->insert();

							$output->addWikiText( '[[사용자:'.$target->getName().']] 님을 긴급 차단했습니다. 긴급 차단은 의결 기간이 끝나면 같이 해제됩니다.' );
						} else 
							$output->addWikiText( '이미 차단되어 있는 [[사용자:'.$target->getName().']] 님의 차단 상태는 변경되지 않았습니다.' );
					}
				}
				else {
					//현재 제재안이 긴급 절차여서 일반으로 전환할 때
					if($insultingName){
						if( $target->getName() == $originalName ) {
							$output->addWikiText( '사용자명이 이미 되돌려져 있습니다.' );
						} else {
							$rename = new RenameuserSQL(
								$target->getName(),
								$originalName,
								$target->getId(),
								$this->getUser(),
								[ 'reason' => '일반 절차 전환' ]
							);
							if ( !$rename->rename() ) {
								$output->addWikiText( '사용자명 변경 실패' );
							}
							$output->addWikiText( '사용자명을 복구하였습니다.' );
						}
					}
					else {
						// 현재 차단이 이 제재안에 의한 것일 때에는 차단을 해제하도록 합니다.
						// @todo 차단 기록을 살펴 이 제재안과 무관한 차단 기록이 있다면 기간을 비교하여 
						// 이 제재안의 의결 종료 기간이 차단 해제 시간보다 뒤라면 차단 기간을 줄입니다.
						if( $target->isBlocked() && $target->getBlock()->getExpiry() == $sanctionExpiry ) {
							$target->getBlock()->delete();
						}
					}
				}

				if( $emergency )
					$output->addWikiText( '절차를 일반으로 바꾸었습니다.' );
				else
					$output->addWikiText( '절차를 긴급으로 바꾸었습니다.' );

				//DB에 적힌 절차를 바꿔 갱신합니다.
				$this->mdb->update(
					'sanctions',
					['st_emergency' => $emergency?0:1 ],
					[ 'st_id' => $sanctionId ]
				);
			break;

			//결과에 따른 제재안 집행
			case 'execute':
				$sanctionId = $request->getVal( 'sanctionId' );
				$row = $this->mdb->selectRow(
					'sanctions',
					['st_target', 'st_topic','st_emergency', 'st_original_name', 'st_expiry'],
					[ 'st_id' => $sanctionId ]
				);
				$target = $row->st_target;
				$emergency = $row->st_emergency;
				$sanctionExpiry = $row->st_expiry;
				$insultingName = $row->st_original_name != null;
				$passed = $this->sanctionIsPassed($sanctionId);

				if( $passed && !$emergency ){
					//가결이면서 일반 절차일 때 제재를 집행합니다.
					if( $insultingName ){
						if( $target->getName() != $originalName ) {
							$output->addWikiText( '사용자명이 이미 다른 것으로 바뀌어 있어 제재안만을 제거하였습니다.' );
						} else {
							$rename = new RenameuserSQL(
								$target->getName(),
								'임시사용자명'.wfTimestamp(TS_MW),
								$target->getId(),
								$this->getUser(),
								[ 'reason' => '긴급 절차 전환' ]
							);
							if ( !$rename->rename() ) {
								$output->addWikiText( '사용자명 변경 실패' );
							}
							$output->addWikiText( '사용자명을 변경하고 제재안을 제거하였습니다.' );
						}
					} else {
						//차단되어 있을 않을 경우, 혹은 이미 차단되어 있다면 기간을 비교하여
						//이 제재안에 따라 결정된 차단 종료 시간이 기존 차단 해제 시간보다 뒤라면 늘려 차단합니다.
						if( !$target->isBlocked() || (int)($target->getBlock()->getExpiry()) < (int)$sanctionExpiry ) {
							$target->getBlock()->delete();
							
							$blockOptions = [
				                'address' => $target->getName(),
				                'user' => $target->getId(),
				                'reason' => '[[주제:'.UUID::create($row->st_topic)->getAlphadecimal().']]',
				                'expiry' => wfTimestamp(TS_MW, time()+(60*60*24*$passed)),
				                'byText' => '제재안 의결'
				            ];
				            $block = new Block( $blockOptions );
				  	        $block->insert();
						}
						//기간이 더 긴 다른 차단이 있을 경우 아무것도 하지 않습니다.
					}
				}
				else if( !$passed && $emergency ){
					//부결이면서 긴급 절차일 때는 작동중인 임시 제재가 있는지 확인하고 해제합니다.
					if( $insultingName ){
						if( $target->getName() == $originalName ) {
							$output->addWikiText( '사용자명이 이미 되돌려져 있습니다.' );
						} else {
							$rename = new RenameuserSQL(
								$target->getName(),
								$originalName,
								$target->getId(),
								$this->getUser(),
								[ 'reason' => '일반 절차 전환' ]
							);
							if ( !$rename->rename() ) {
								$output->addWikiText( '사용자명 변경 실패' );
								break;
							}
							$output->addWikiText( '부결된 제재안을 삭제하고 사용자명을 원래대로 되돌렸습니다.' );
						}
					} else {
						// 현재 차단이 이 제재안에 의한 것일 때에는 차단을 해제합니다.
						// @todo 차단 기록을 살펴 이 제재안과 무관한 차단 기록이 있다면 기간을 비교하여 
						// 이 제재안의 의결 종료 기간이 차단 해제 시간보다 뒤라면 차단 기간을 줄입니다.
						// 즉 긴급 절차로 인해 다른 짧은 차단이 덮어 씌였다면 짧은 차단을 복구합니다.
						if( $target->isBlocked() && $target->getBlock()->getExpiry() == $sanctionExpiry )
							$target->getBlock()->delete();
					}
				} else if ( $passed && $emergency ) {
					// 가결이면서 긴급 절차일 때
					if( $insultingName ){
						if( $target->getName() != $originalName ) {
							$output->addWikiText( '사용자명이 이미 다른 것으로 바뀌어 있어 제재안만을 제거하였습니다.' );
						} else {
							$rename = new RenameuserSQL(
								$target->getName(),
								'임시사용자명'.wfTimestamp(TS_MW),
								$target->getId(),
								$this->getUser(),
								[ 'reason' => '긴급 절차 전환' ]
							);
							if ( !$rename->rename() ) {
								$output->addWikiText( '사용자명 변경 실패' );
							}
							$output->addWikiText( '제재안을 제거하고 사용자명을 변경하였습니다.' );
						}
					} else {
						//긴급 절차로 인한 차단이 남아있다면 제거하고
						// 의결된 기간 만큼 차단합니다.
						if( $target->isBlocked() && $target->getBlock()->getExpiry() == $sanctionExpiry )
							$target->getBlock()->delete();

						$blockOptions = [
					                'address' => $target->getName(),
					                'user' => $target->getId(),
					                'reason' => '[[주제:'.UUID::create($row->st_topic)->getAlphadecimal().']]',
					                'expiry' => wfTimestamp(TS_MW, time()+(60*60*24*$passed)),
					                'byText' => '제재안 의결'
					            ];
			            $block = new Block( $blockOptions );
			  	        $block->insert();
			  	    }
				}
				else
					//부결이면서 일반 절차일 때는 아무걼도 하지 않습니다.	
					$output->addWikiText( '제재안이 부결되어 있어 바로 제거되었습니다.' );

				$this->mdb->delete(
					'sanctions',
					[ 'st_id' => $sanctionId ]
				);
				$this->mdb->delete(
					'sanctions_vote',
					[ 'stv_topic' => $sanctionId ]
				);
			break;
		}

		$output->addWikiText( '[[특수:제재안목록]] 문서로 돌아갑니다.' );
		
		return true;
	}

	function makeForm() {
		$out = '';

		$out .= Xml::element(
                 'h2',
                 [],
                 $this->msg( 'sanctions-sactions-form-header' )->text()
             );

		$content = '';
		if($this->mRevisionId != null) {
			$revision = Revision::newFromId($this->mRevisionId);

			$content .= '* [[특수:차이/'.$revision->getPrevious()->getId().'/'.$this->mRevisionId.'|'.$revision->getTitle()->getFullText().']]'
						. PHP_EOL.PHP_EOL.'('.$this->msg( 'sanctions-content-placeholder' )->text().')';
		}

		$out .= Xml::tags(
			'form',
			[
				'method' => 'post',
				'action' => $this->getTitle()->getFullURL(),
				'id' => 'sanctionsForm'
			],
			'대상: '.
			Xml::input(
				'target', 10, $this->mTargetName, [ 'class' => 'mw-ui-input-inline' ] ) .
			' '.
			Xml::checkLabel(
				'부적절한 사용자명', 'hasInsultingName', 'hasInsultingName', $this->mRevisionId == null && $this->mTargetName != null, [] )			. 
			Xml::textarea( 'content', $content, 40, 7, ['placeholder' => $this->msg( 'sanctions-content-placeholder' )->text()] ).
			
			Html::submitButton(
				$this->msg( 'sanctions-submit' )->text(),
				['id'=>'submit-button'], [ 'mw-ui-progressive' ]
			) .
			Html::hidden(
				'token',
				$this->getUser()->getEditToken( array( 'sanctions' ) )
			) .
			Html::hidden(
				'result',
				'write'
			)
		);

		return $out;
	}

	static function findUserIdFromName( $name ) {
		$targetUser = User::newFromName( $name );

		if( $targetUser == false) return null;

		$targetId = $targetUser->getId();

		if( $targetId == 0) return null;
		return $targetId;
	}

	/**
	 * 다음 경우 제재 기간을 반환합니다. 
	 * - 3인 이상이 의견을 내고 2/3 이상이 찬성한 경우
	 * - 1인 이상, 3인 미만이 의견을 내고 반대가 없는 경우.
	 * 부결되었을 경우 false를 반환합니다.
	 * 즉시 부결은 이 함수에서 처리하지 않습니다.
	 * @param $id 제재안의 id
	 */
	protected function sanctionIsPassed( $id ) {
		$rows = $this->mdb->select(
			'sanctions_vote',
			'stv_period',
			[ 'stv_id' => $id ]
		);

		$agree = 0;
		$count = 0;
		$period = 0;
		foreach( $rows as $row ) {
			$count++;
			if ( $row->stv_period != 0 )
				$agree++;
			$period += $row->stv_period;
		}

		if($count == 0)
			return false;
		
		$period = ceil($period/$count);
		if(
			( $count >= 3 && $agree >= $count )
			|| ( $count > 0 && $count < 3 && $agree == $count)
		)
			return $period;
		return false;
	}

	protected function getGroupName() {
		return 'users';
	}
}