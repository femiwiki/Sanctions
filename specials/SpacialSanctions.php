<?php


class SpacialSanctions extends SpecialPage {
	protected $mTargetName = null;
	protected $mTargetId = null;
	protected $mOldRevisionId = null;
	protected $mNewRevisionId = null;

	public function __construct() {
		parent::__construct( 'Sanctions' );
	}

	/**
	 * @param string $subpage
	 */
	public function execute( $subpage ) {
		$output = $this->getOutput();

		$this->setParameter( $subpage );

		$this->setHeaders();
		$this->outputHeader();

		// Request가 있었다면 처리합니다. (리다이렉트할 경우 true를 반환합니다)
		if ( $this->handleRequestsIfExist( $output ) ) {
			return;

		}

		$output->addModuleStyles( 'ext.sanctions.special.sanctions.styles' );
		$output->addModules( 'ext.sanctions.special.sanctions' );

		// 대상자가 있다면 제목을 변경하고 전체 목록을 보는 링크를 추가합니다.
		if ( $this->mTargetName != null ) {
			$output->setPageTitle( $this->msg( 'sanctions-title-with-target', $this->mTargetName ) );
			$output->setSubTitle( '< ' . Linker::link(
				$this->getTitle(),
				wfMessage( 'sanctions-show-all-sanctions-link' )->text()
			) );
		}

		$pager = new SanctionsPager( $this->getContext(), $this->mTargetName );
		$pager->doQuery();
		$output->addHTML( $pager->getBody() );

		$reason = [];
		if ( SanctionsUtils::hasVoteRight( $this->getUser(), $reason ) ) {
			$output->addHTML( $this->makeForm() );
		} else {
			if ( $this->getUser()->isAnon() ) {
				$message = wfMessage( 'sanctions-unable-create-new' )->text();
			} else {
				$message = wfMessage( 'sanctions-unable-create-new-logged-in', $this->getUser()->getName() )
					->text();
			}

			if ( count( $reason ) > 0 ) {
				$message .= PHP_EOL . '* ' . implode( PHP_EOL . '* ', $reason );
			}

			$output->addWikiTextAsInterface( $message );
		}
	}

	/**
	 * @param string $subpage
	 */
	private function setParameter( $subpage ) {
		$parts = explode( '/', $subpage, 3 );

		$targetName = null;
		$oldRevisionId = null;
		$newRevisionId = null;

		switch ( count( $parts ) ) {
		case 0:
			return;
		case 1:
			$targetName = $parts[0];
			break;
		case 2:
			$targetName = $parts[0];
			$newRevisionId = $parts[1];
			break;
		case 3:
			list( $targetName, $oldRevisionId, $newRevisionId ) = $parts;
			break;
		}

		$target = User::newFromName( $targetName );
		if ( !$target ) { return;
		}
		$targetId = $target->getId();
		if ( !$targetId ) { return;
		}

		$this->mTargetName = $targetName;
		$this->mTargetId = $targetId;

		if ( count( $parts ) == 1 ) { return;
		}

		// Fetch newRivisionId
		$newRevisionId = $parts[ count( $parts ) - 1 ];

		$newRevision = Revision::newFromId( $newRevisionId );
		if ( !$newRevision ) {
			$newRevisionId = null;
			return;
		}

		// Fetch oldRivisionId
		if ( count( $parts ) == 3 ) {
			$oldRevisionId = $parts[1];
			$oldRevision = Revision::newFromId( $oldRevisionId );
			if ( !$oldRevision ) {
				if ( $newRevision->getPrevious() ) {
					$oldRevisionId = $newRevision->getPrevious()->getId();
				} else {
					$oldRevisionId = null;
				}
			}
		} else {
			if ( $newRevision->getPrevious() ) {
				$oldRevisionId = $newRevision->getPrevious()->getId();
			} else {
				$oldRevisionId = null;
			}
		}

		$this->mOldRevisionId = $oldRevisionId;
		$this->mNewRevisionId = $newRevisionId;
	}

	/**
	 * @return true를 반환하면 다른 내용을 보여주지 않습니다.
	 */
	private function handleRequestsIfExist( $output ) {
		$request = $this->getRequest();

		if ( $request->getVal( 'showResult' ) == true ) {
			$error = $request->getVal( 'errorCode' );
			if ( $error !== null ) {
				$output->addHTML(
					Html::rawelement(
						'div',
						[ 'class' => 'sanction-execute-result' ],
						self::makeErrorMessage(
							$request->getVal( 'errorCode' ),
							$request->getVal( 'uuid' ),
							$request->getVal( 'targetName' )
						)
					)
				);
			} else {
				$output->addHTML(
					Html::rawelement(
						'div',
						[ 'class' => 'sanction-execute-result' ],
						self::makeMessage(
							$request->getVal( 'code' ),
							$request->getVal( 'uuid' ),
							$request->getVal( 'targetName' )
						)
					)
				);
			}

			return false;
		}

		if ( !$request->wasPosted() ) { return false;
		}

		$action = $request->getVal( 'sanction-action' );

		$query = []; // showResult, code, errorCode, uuid, targetName
		// code
		// 0    작성 성공
		// 1    긴급 절차 전환 성공
		// 2    일반 절차 전환 성공
		// 3    집행
		// error code
		// 기타 문제
		// 000    토큰
		// 001 권한 오류
		// 002 제재안 작성 실패
		// 003    전환 실패
		// 입력 문제
		// 100    사용자명 미입력
		// 101 사용자 없음
		// 102 중복된 부적절한 사용자명 변경 건의
		if ( !$this->getUser()->matchEditToken( $request->getVal( 'token' ), 'sanctions' ) ) {
			list( $query['showResult'], $query['errorCode'] ) = [ true, 0 ];
			// '토큰이 일치하지 않습니다.'
		} else { switch ( $action ) {
			case 'write':
				// 제재안 올리기
				$user = $this->getUser();
				$targetName = $request->getVal( 'target' );
				$forInsultingName = $request->getBool( 'forInsultingName' );
				$content = $request->getVal( 'content' ) ?:
					wfMessage( 'sanctions-topic-no-description' )->text();

				if ( !$targetName ) {
					list( $query['showResult'], $query['errorCode'] ) = [ true, 100 ];
					// '사용자명이 입력되지 않았습니다.'
					break;
				}

				$target = User::newFromName( $targetName );

				if ( $target->getId() === 0 ) {
					list( $query['showResult'], $query['errorCode'], $query['targetName'] )
					= [ true, 101, $targetName ];
					// '"'.$targetName.'"라는 이름의 사용자가 존재하지 않습니다.'
					break;
				}

				// 만일 동일 사용자명에 대한 부적절한 사용자명 변경 건의안이 이미 있다면 중복 작성을 막습니다.
				if ( $forInsultingName ) {
					$existingSanction = Sanction::existingSanctionForInsultingNameOf( $target );
					if ( $existingSanction != null ) {
						list(
							$query['showResult'],
							$query['errorCode'],
							$query['targetName'],
							$query['uuid']
						) = [
							true,
							102,
							$targetName,
							$existingSanction->getTopicUUID()->getAlphaDecimal()
						];
						break;
					}
				}

				$sanction = Sanction::write( $user, $target, $forInsultingName, $content );

				if ( !$sanction ) {
					list( $query['showResult'], $query['errorCode'] ) = [ true, 2 ];
					// '제재안 작성에 실패하였습니다.'
					break;
				}

				$topicTitleText = $sanction->getTopic()->getFullText();
				list( $query['showResult'], $query['code'], $query['uuid'] )
					= [ true, 0, $sanction->getTopicUUID()->getAlphaDecimal() ];
				// '제재안 '.Linker::link( $sanction->getTopic() ).'가 작성되었습니다.'
				break;
			case 'toggle-emergency':
				// 제재안 절차 변경( 일반 <-> 긴급 )
				$user = $this->getUser();

				// 차단 권한이 없다면 절차를 변경할 수 없습니다.
				if ( !$user->isAllowed( 'block' ) ) {
					list( $query['showResult'], $query['errorCode'] ) = [ true, 1 ];
					// '권한이 없습니다.'
					break;
				}

				$sanctionId = $request->getVal( 'sanctionId' );
				$sanction = Sanction::newFromId( $sanctionId );

				if ( !$sanction || !$sanction->toggleEmergency( $user ) ) {
					list( $query['showResult'], $query['errorCode'], $query['uuid'] )
					= [ true, 3, $sanction->getTopicUUID()->getAlphaDecimal() ];
					// '절차 변경에 실패하였습니다.'
					break;
				}
				if ( $sanction->isEmergency() ) {
					list( $query['showResult'], $query['code'], $query['uuid'] )
					= [ true, 1, $sanction->getTopicUUID()->getAlphaDecimal() ];
					// '절차를 긴급으로 바꾸었습니다.'
				} else {
					list( $query['showResult'], $query['code'], $query['uuid'] )
					= [ true, 2, $sanction->getTopicUUID()->getAlphaDecimal() ];
					// '절차를 일반으로 바꾸었습니다.'
				}
				break;
			case 'execute':
				// 결과에 따른 제재안 집행
				$user = $this->getUser();
				if ( !SanctionsUtils::hasVoteRight( $user ) ) {
					list( $query['showResult'], $query['errorCode'] ) = [ true, 1 ];
					// '권한이 없습니다.'
					break;
				}

				$sanctionId = $request->getVal( 'sanctionId' );
				$sanction = Sanction::newFromId( $sanctionId );

				if ( !$sanction->execute() ) {
					list( $query['showResult'], $query['errorCode'], $query['uuid'] )
						= [ true, 4, $sanction->getTopicUUID()->getAlphaDecimal() ];
					// '제재안 집행에 실패하였습니다.'
					break;
				}
				list( $query['showResult'], $query['code'], $query['uuid'] )
					= [ true, 3, $sanction->getTopicUUID()->getAlphaDecimal() ];
				// '제재안을 처리하였습니다.'
				break;
		}
		}

		$output->redirect( $this->getTitle()->getLocalURL( $query ) );

		return true;
	}

	/**
	 * @param int $errorCode
	 * @param UUID $uuid
	 * @param string $targetName
	 * @return string Error Message
	 */
	protected static function makeErrorMessage( $errorCode, $uuid, $targetName ) {
		$link = $uuid ? Linker::link( Sanction::newFromUUID( $uuid )->getTopic() ) : '';
		switch ( $errorCode ) {
		case 0:
			return wfMessage( "sanctions-submit-error-invalid-token" )->text();
		case 1:
			return wfMessage( "sanctions-submit-error-no-permission" )->text();
		case 2:
			return wfMessage( "sanctions-submit-error-failed-to-add-topic" )->text();
		case 3:
			return wfMessage( "sanctions-submit-error-failed-to-toggle-process" )->text();
		case 4:
			return wfMessage( "sanctions-submit-error-failed-to-execute" )->text();
		case 100:
			return wfMessage( "sanctions-submit-error-no-username" )->text();
		case 101:
			return wfMessage( "sanctions-submit-error-invaild-username", $targetName )->text();
		case 102:
			return wfMessage(
					"sanctions-submit-error-insulting-report-already-exist", [ $targetName, $link ]
				)->text();
		default:
			return wfMessage( "sanctions-submit-error-other", $errorCode )->text();
		}
	}

	/**
	 * @param int $code
	 * @param UUID $uuid
	 * @param string $targetName
	 * @return string Message
	 */
	protected static function makeMessage( $code, $uuid, $targetName ) {
		$link = $uuid ? Linker::link( Sanction::newFromUUID( $uuid )->getTopic() ) : '';
		switch ( $code ) {
		case 0:
			return wfMessage( "sanctions-submit-massage-added-topic", $link )->text();
		case 1:
			return wfMessage( "sanctions-submit-massage-switched-emergency", $link )->text();
		case 2:
			return wfMessage( "sanctions-submit-massage-switched-normal", $link )->text();
		case 3:
			return wfMessage( "sanctions-submit-massage-executed-sanction", $link )->text();
		default:
			return wfMessage( "sanctions-submit-massage-other", $code )->text();
		}
	}

	protected function makeForm() {
		$content = '';

		$content .= $this->makeDiffLink();

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
			'action' => $this->getPageTitle()->getFullURL(),
			'id' => 'sanctionsForm'
			],
			wfMessage( 'sanctions-form-target' )->text() .
			Xml::input(
				'target', 10, $this->mTargetName, [ 'class' => 'mw-ui-input-inline' ]
			) .
			' ' .
			Xml::checkLabel(
				wfMessage( 'sanctions-form-for-insulting-name' )->text(),
				'forInsultingName',
				'forInsultingName',
				$this->mNewRevisionId == null && $this->mTargetName != null,
				[]
			) .
			Xml::textarea(
				'content',
				$content,
				40,
				7,
				[ 'placeholder' => $this->msg( 'sanctions-content-placeholder' )->text() ]
			) .
			Html::submitButton(
				$this->msg( 'sanctions-submit' )->text(),
				[ 'class' => 'submit-button' ], [ 'mw-ui-progressive' ]
			) .
			Html::hidden(
				'token',
				$this->getUser()->getEditToken( 'sanctions' )
			) .
			Html::hidden(
				'sanction-action',
				'write'
			)
		);

		return $out;
	}

	protected function makeDiffLink() {
		$newRevisionId = $this->mNewRevisionId;

		if ( $newRevisionId == null ) { return '';
		}

		$newRevision = Revision::newFromId( $newRevisionId );
		$oldRevisionId = $this->mOldRevisionId;

		$rt = '';
		if ( $oldRevisionId != null ) {
			$rt = wfMessage( 'sanctions-topic-diff', [
				$oldRevisionId,
				$newRevisionId,
				$newRevision->getTitle()->getFullText()
			] )->inContentLanguage()->text();
		} else {
			$rt = wfMessage( 'sanctions-topic-revision', [
				$newRevisionId,
				$newRevision->getTitle()->getFullText()
			] )->inContentLanguage()->text();
		}

		return $rt;
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'users';
	}
}
