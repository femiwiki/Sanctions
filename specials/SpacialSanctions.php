<?php

use Flow\Api\ApiFlowNewTopic;
use Flow\Api\ApiFlowEditTopicSummary;
use Flow\Model\UUID;

class SpacialSanctions extends SpecialPage {
	protected $mDb;
	protected $mTargetName = null;
	protected $mTargetId = null;
	protected $mRevisionId = null;

	public function __construct() {
		parent::__construct( 'Sanctions' );
	}

	public function execute( $subpage ) {
		$output = $this->getOutput();

		$this->setParameter( $subpage );

		$this->setHeaders();
		$this->outputHeader();

		// Request가 있었다면 그것만을 처리하고 제재한 목록은 보여주지 않습니다.
		if ( $this->HandleRequestsIfExist($output) ) return;

		$output->addModuleStyles( 'ext.sanctions' );

		//대상자가 있다면 제목을 변경하고 전체 목록을 보는 링크를 추가합니다.
		if ( $this->mTargetName != null ) {
			$output->setPageTitle( $this->msg( 'sanctions-title-with-target', $this->mTargetName ) );
			$output->setSubTitle( '< '.Linker::link( $this->getTitle(),'모든 제재안 보기' ) );
		}

		$pager = new SanctionsPager( $this->getContext(), $this->mTargetName );
		$pager->doQuery();
		$output->addHTML( $pager->getBody() );

		$reason = array();
		if ( SanctionsUtils::hasVoteRight( $this->getUser(), $reason ) )
			$output->addHTML( $this->makeForm() );
		else { 
			if ( $this->getUser()->isAnon () )
				$output->addWikiText( '다음의 이유로 제재 절차 참여를 위한 조건이 맞지 않습니다. [[페미위키:제재 정책]]을 참고해 주세요.' );
			else
				$output->addWikiText( '다음의 이유로 현재 '.$this->getUser()->getName().' 님께서는 제재 절차에 잠여할 수 없습니다. [[페미위키:제재 정책]]을 참고해 주세요.' );

			if ( count( $reason ) > 0 ) $output->addWikiText( '* '.implode( PHP_EOL.'* ', $reason ) );
		}
	}

	function setParameter( $subpage ) {
		$parts = explode( '/', $subpage, 2 );

		$targetName = count( $parts ) > 0 ? $parts[0] : null;

		if ( $targetName == null ) return;
		
		$target = User::newFromName( $targetName );
		if( $target == false ) return;

		$targetId = User::newFromName( $targetName )->getId();

		if ( $targetId == null ) return;

		$this->mTargetName = str_replace( "_", " ", $targetName );
		$this->mTargetId = $targetId;
		$this->mRevisionId = count( $parts ) > 1 ? $parts[1] : null;
	}

	function HandleRequestsIfExist($output) {
		$request = $this->getRequest();

		if ( !$request->wasPosted() ) return false;

		$result = $request->getVal( 'result' );

		if( !$this->getUser()->matchEditToken( $request->getVal( 'token' ), 'sanctions' ) ) {
			$output->addWikiText( '토큰이 올바르지 않아 실패하였습니다.' );
		} else switch($result) {
			case 'write':
				//제재안 올리기
				$user = $this->getUser();
				$targetName = $request->getVal( 'target' );
				$forInsultingName = $request->getBool( 'forInsultingName' );
				$content = $request->getVal( 'content' )? : '내용이 입력되지 않았습니다.';
		
				if ( !$targetName ) {
					$output->addWikiText('사용자명이 입력되지 않았습니다.' );
					break;
				}

				$target = User::newFromName( $targetName );
				
				if ( $target->getId() === 0 ) {
					$output->addWikiText( '"'.$targetName.'"라는 이름의 사용자가 존재하지 않습니다.' );
					break;
				}
				
				$sanction = Sanction::write( $this->getUser(), $target, $forInsultingName, $content );

				if ( $sanction === false ) {
					$output->addWikiText( '제재안 작성에 실패하였습니다.' );
					break;
				}

				$topicTitleText = $sanction->getTopic()->getFullText();
				$output->addWikiText( '제재안 [['.$topicTitleText.']]가 작성되었습니다.' );
				break;
			break;
			case 'toggle-emergency':
				//제재안 절차 변경( 일반 <-> 긴급 )
				//차단 권한이 없다면 절차를 변경할 수 없습니다.
				if ( !$this->getUser()->isAllowed( 'block' ) ) {
					$output->addWikiText('잘못된 접근입니다.' );
					break;
				}

				$sanctionId = $request->getVal( 'sanctionId' );
				$sanction = Sanction::newFromId( $sanctionId );

				if ( !$sanction || !$sanction->toggleEmergency() ) {
					$output->addWikiText('절차 변경에 실패하였습니다.' );
					break;
				}

				if ( $sanction->isEmergency() )
					$output->addWikiText( '절차를 긴급으로 바꾸었습니다.' );
				else
					$output->addWikiText( '절차를 일반으로 바꾸었습니다.' );
			break;
			case 'execute':
				//결과에 따른 제재안 집행
				$user = $this->getUser();
				if ( !SanctionsUtils::hasVoteRight( $user ) ) {
					$output->addWikiText('잘못된 접근입니다.' );
					break;
				}

				$sanctionId = $request->getVal( 'sanctionId' );
				$sanction = Sanction::newFromId( $sanctionId );

				if ( !$sanction->execute() ) {
					$output->addWikiText('제재안 집행에 실패하였습니다.' );
					break;
				}
				$output->addWikiText( '제재안을 처리하였습니다.' );
			break;
		}

		$output->addWikiText( '[[특수:제재안목록]] 문서로 돌아갑니다.' );
		return true;
	}

	function makeForm() {
		$content = '';
		if( $this->mRevisionId != null ) {
			$revision = Revision::newFromId( $this->mRevisionId );

			$content .= '* [[특수:차이/'.$revision->getPrevious()->getId().'/'.$this->mRevisionId.'|'.$revision->getTitle()->getFullText().']]'
						. PHP_EOL.PHP_EOL.'('.$this->msg( 'sanctions-content-placeholder' )->text().')';
		}

		$out = '';
		$out .= Xml::element(
             'h2',
             [],
             $this->msg( 'sanctions-sactions-form-header' )->text()
         );
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
				'부적절한 사용자명', 'forInsultingName', 'forInsultingName', $this->mRevisionId == null && $this->mTargetName != null, [] )			. 
			Xml::textarea( 'content', $content, 40, 7, ['placeholder' => $this->msg( 'sanctions-content-placeholder' )->text()] ).
			
			Html::submitButton(
				$this->msg( 'sanctions-submit' )->text(),
				['id'=>'submit-button'], [ 'mw-ui-progressive' ]
			) .
			Html::hidden(
				'token',
				$this->getUser()->getEditToken( 'sanctions' )
			) .
			Html::hidden(
				'result',
				'write'
			)
		);

		return $out;
	}

	protected function getGroupName() {
		return 'users';
	}
}