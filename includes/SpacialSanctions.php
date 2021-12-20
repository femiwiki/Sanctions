<?php

namespace MediaWiki\Extension\Sanctions;

use Flow\Model\UUID;
use Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\User\UserFactory;
use OutputPage;
use RequestContext;
use SpecialPage;
use TemplateParser;

class SpacialSanctions extends SpecialPage {
	/** @var string */
	protected $targetName;

	/** @var int */
	protected $mTargetId;

	/** @var int|null */
	protected $mOldRevisionId;

	/** @var int */
	protected $mNewRevisionId;

	/** @var SanctionStore */
	protected $sanctionStore;

	/** @var UserFactory */
	protected $userFactory;

	/** @var RevisionLookup */
	protected $revLookup;

	/** @var LinkRenderer */
	private $linkRenderer;

	/** @var TemplateParser */
	private $templateParser;

	/**
	 * @param SanctionStore $sanctionStore
	 * @param UserFactory $userFactory
	 * @param RevisionLookup $revisionLookup
	 * @param LinkRenderer $linkRenderer
	 */
	public function __construct(
		SanctionStore $sanctionStore,
		UserFactory $userFactory,
		RevisionLookup $revisionLookup,
		LinkRenderer $linkRenderer
	) {
		parent::__construct( 'Sanctions' );

		$this->sanctionStore = $sanctionStore;
		$this->userFactory = $userFactory;
		$this->revLookup = $revisionLookup;
		$this->linkRenderer = $linkRenderer;

		$this->templateParser = new TemplateParser( __DIR__ . '/templates' );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
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

		$this->executeExpiredSanctions();

		$output->addModuleStyles( 'mediawiki.special' );
		$output->addModuleStyles( 'ext.sanctions.special.sanctions.styles' );
		$output->addModules( 'ext.sanctions.special.sanctions' );

		// 대상자가 있다면 제목을 변경하고 전체 목록을 보는 링크를 추가합니다.
		if ( $this->targetName ) {
			$output->setPageTitle( $this->msg( 'sanctions-title-with-target', $this->targetName ) );
			$output->setSubTitle( '< ' . $this->linkRenderer->makeLink(
				$this->getPageTitle(),
				$this->msg( 'sanctions-show-all-sanctions-link' )->text()
			) );
		}

		$data = [];

		$pager = new SanctionsPager(
			$this->getContext(),
			$this->sanctionStore,
			$this->userFactory,
			$this->linkRenderer,
			$this->targetName
		);
		$pager->doQuery();
		$data['html-body'] = $pager->getBody();

		$reason = [];
		if ( Utils::hasVoteRight( $this->getUser(), $reason ) ) {
			$data['data-form'] = [
				'content' => $this->makeDiffLink(),
				'header' => $this->msg( 'sanctions-sactions-form-header' )->text(),
				'action' => $this->getPageTitle()->getFullURL(),
				'target-label' => $this->msg( 'sanctions-form-target' )->text(),
				'target-name' => $this->targetName,
				'is-for-insulting-name' => $this->mNewRevisionId == null && $this->targetName != null,
				'label-insulting-name' => $this->msg( 'sanctions-form-for-insulting-name' )->text(),
				'textarea-placeholder' => $this->msg( 'sanctions-content-placeholder' )->text(),
				'submit-label' => $this->msg( 'sanctions-submit' )->text(),
				'token' => RequestContext::getMain()->getCsrfTokenSet()->getToken( 'sanctions' )->toString(),
			];
		} else {
			if ( $this->getUser()->isAnon() ) {
				$data['sanctions-unable-create-description'] = $this->msg( 'sanctions-unable-create-new' )->parse();
			} else {
				$username = $this->getUser()->getName();
				$description = $this->msg( 'sanctions-unable-create-new-logged-in', $username )->parse();
				$data['sanctions-unable-create-description'] = $description;
			}

			$data['data-reasons-disabled-participation'] = $reason;
		}

		$output->addHTML( $this->templateParser->processTemplate( 'SpecialSanctions', $data ) );
	}

	/**
	 * @param string $subpage
	 */
	private function setParameter( $subpage ) {
		$revLookup = $this->revLookup;

		$parts = explode( '/', $subpage, 3 );

		$targetName = '';
		$oldRevisionId = 0;
		$newRevisionId = 0;

		switch ( count( $parts ) ) {
		case 0:
			return;
		case 1:
			$targetName = (string)$parts[0];
			break;
		case 2:
			$targetName = (string)$parts[0];
			$newRevisionId = (int)$parts[1];
			break;
		case 3:
			$targetName = (string)$parts[0];
			$oldRevisionId = (int)$parts[1];
			$newRevisionId = (int)$parts[2];
			break;
		}

		$target = $this->userFactory->newFromName( $targetName );
		if ( !$target ) {
			return;
		}
		$targetId = $target->getId();
		if ( !$targetId ) {
			return;
		}

		$this->targetName = $targetName;
		$this->mTargetId = $targetId;

		if ( count( $parts ) == 1 ) {
			return;
		}

		// Fetch newRevisionId
		$newRevisionId = (int)$parts[ count( $parts ) - 1 ];

		$newRevision = $revLookup->getRevisionById( $newRevisionId );
		if ( !$newRevision ) {
			$newRevisionId = null;
			return;
		}

		// Fetch oldRevisionId
		if ( count( $parts ) == 3 ) {
			$oldRevisionId = (int)$parts[1];
			$oldRevision = $revLookup->getRevisionById( $oldRevisionId );
			if ( !$oldRevision ) {
				$preRev = $revLookup->getPreviousRevision( $newRevision );
				if ( $preRev ) {
					$oldRevisionId = $preRev->getId();
				} else {
					$oldRevisionId = null;
				}
			}
		} else {
			$preRev = $revLookup->getPreviousRevision( $newRevision );
			if ( $preRev ) {
				$oldRevisionId = $preRev->getId();
			} else {
				$oldRevisionId = null;
			}
		}

		$this->mOldRevisionId = $oldRevisionId;
		$this->mNewRevisionId = $newRevisionId;
	}

	/**
	 * @param OutputPage $output
	 * @return bool true를 반환하면 다른 내용을 보여주지 않습니다.
	 * @suppress SecurityCheck-XSS
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
						$this->makeErrorMessage(
							(int)$request->getVal( 'errorCode' ),
							$request->getVal( 'uuid' ) ? UUID::create( $request->getVal( 'uuid' ) ) : null,
							$request->getVal( 'targetName' )
						)
					)
				);
			} else {
				$output->addHTML(
					Html::rawelement(
						'div',
						[ 'class' => 'sanction-execute-result' ],
						$this->makeMessage(
							(int)$request->getVal( 'code' ),
							$request->getVal( 'uuid' ) ? UUID::create( $request->getVal( 'uuid' ) ) : null
						)
					)
				);
			}

			return false;
		}

		if ( !$request->wasPosted() ) {
			return false;
		}

		$action = $request->getVal( 'sanction-action' );

		// showResult, code, errorCode, uuid, targetName
		$query = [];
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
		if ( !RequestContext::getMain()->getCsrfTokenSet()->getToken( 'sanctions' )->match(
			$request->getVal( 'token' ) ) ) {
			list( $query['showResult'], $query['errorCode'] ) = [ true, 0 ];
			// '토큰이 일치하지 않습니다.'
		} else { switch ( $action ) {
			case 'write':
				// 제재안 올리기
				$user = $this->getUser();
				$targetName = $request->getVal( 'target' );
				$forInsultingName = $request->getBool( 'forInsultingName' );
				$content = $request->getVal( 'content' ) ?:
					$this->msg( 'sanctions-topic-no-description' )->text();

				if ( !$targetName ) {
					list( $query['showResult'], $query['errorCode'] ) = [ true, 100 ];
					// '사용자명이 입력되지 않았습니다.'
					break;
				}

				$target = $this->userFactory->newFromName( $targetName );

				if ( !$target || !$target->isRegistered() ) {
					list( $query['showResult'], $query['errorCode'], $query['targetName'] )
						= [ true, 101, $targetName ];
					// '"'.$targetName.'"라는 이름의 사용자가 존재하지 않습니다.'
					break;
				}

				// 만일 동일 사용자명에 대한 부적절한 사용자명 변경 건의안이 이미 있다면 중복 작성을 막습니다.
				if ( $forInsultingName ) {
					$existingSanction = $this->sanctionStore->findByTarget( $target, true, false );
					if ( $existingSanction && count( $existingSanction ) > 0 ) {
						$existingSanction = $existingSanction[0];
						list(
							$query['showResult'],
							$query['errorCode'],
							$query['targetName'],
							$query['uuid']
						) = [
							true,
							102,
							$targetName,
							$existingSanction->getWorkflowId()->getAlphaDecimal()
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

				list( $query['showResult'], $query['code'], $query['uuid'] )
					= [ true, 0, $sanction->getWorkflowId()->getAlphaDecimal() ];
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

				$sanctionId = (int)$request->getVal( 'sanctionId' );
				$sanction = $this->sanctionStore->newFromId( $sanctionId );

				if ( !$sanction || !$sanction->toggleEmergency( $user ) ) {
					list( $query['showResult'], $query['errorCode'], $query['uuid'] )
					= [ true, 3, $sanction->getWorkflowId()->getAlphaDecimal() ];
					// '절차 변경에 실패하였습니다.'
					break;
				}
				if ( $sanction->isEmergency() ) {
					list( $query['showResult'], $query['code'], $query['uuid'] )
					= [ true, 1, $sanction->getWorkflowId()->getAlphaDecimal() ];
					// '절차를 긴급으로 바꾸었습니다.'
				} else {
					list( $query['showResult'], $query['code'], $query['uuid'] )
					= [ true, 2, $sanction->getWorkflowId()->getAlphaDecimal() ];
					// '절차를 일반으로 바꾸었습니다.'
				}
				break;
		}
		}

		$output->redirect( $this->getPageTitle()->getLocalURL( $query ) );

		return true;
	}

	/**
	 * 결과에 따른 제재안 집행
	 */
	protected function executeExpiredSanctions() {
		$sanctions = $this->sanctionStore->findNotHandledExpired();
		foreach ( $sanctions as $sanction ) {
			$sanction->execute();
		}
	}

	/**
	 * @param int $errorCode
	 * @param UUID|null $uuid
	 * @param string $targetName
	 * @return string Error Message
	 */
	protected function makeErrorMessage( $errorCode, $uuid, $targetName ) {
		$link = $uuid ?
			$this->linkRenderer->makeLink( $this->sanctionStore->newFromWorkflowId( $uuid )->getWorkflow() ) :
			'';
		switch ( $errorCode ) {
		case 0:
			return $this->msg( "sanctions-submit-error-invalid-token" )->text();
		case 1:
			return $this->msg( "sanctions-submit-error-no-permission" )->text();
		case 2:
			return $this->msg( "sanctions-submit-error-failed-to-add-topic" )->text();
		case 3:
			return $this->msg( "sanctions-submit-error-failed-to-toggle-process" )->text();
		case 4:
			return $this->msg( "sanctions-submit-error-failed-to-execute" )->text();
		case 100:
			return $this->msg( "sanctions-submit-error-no-username" )->text();
		case 101:
			return $this->msg( "sanctions-submit-error-invaild-username", $targetName )->text();
		case 102:
			return $this->msg(
					"sanctions-submit-error-insulting-report-already-exist", [ $targetName, $link ]
				)->text();
		default:
			return $this->msg( "sanctions-submit-error-other", (string)$errorCode )->text();
		}
	}

	/**
	 * @param int $code
	 * @param UUID|null $uuid
	 * @return string Message
	 */
	protected function makeMessage( $code, $uuid ) {
		$link = $uuid ?
			$this->linkRenderer->makeLink( $this->sanctionStore->newFromWorkflowId( $uuid )->getWorkflow() ) :
			'';
		switch ( $code ) {
		case 0:
			return $this->msg( "sanctions-submit-massage-added-topic", $link )->text();
		case 1:
			return $this->msg( "sanctions-submit-massage-switched-emergency", $link )->text();
		case 2:
			return $this->msg( "sanctions-submit-massage-switched-normal", $link )->text();
		case 3:
			return $this->msg( "sanctions-submit-massage-executed-sanction", $link )->text();
		default:
			return $this->msg( "sanctions-submit-massage-other", (string)$code )->text();
		}
	}

	protected function makeDiffLink() {
		$newRevisionId = $this->mNewRevisionId;

		if ( $newRevisionId == null ) {
			return '';
		}

		$newRevision = $this->revLookup->getRevisionById( $newRevisionId );
		$oldRevisionId = $this->mOldRevisionId;

		$rt = '';
		if ( $oldRevisionId != null ) {
			$rt = $this->msg( 'sanctions-topic-diff', [
				(string)$oldRevisionId,
				(string)$newRevisionId,
				(string)$newRevision->getPageAsLinkTarget()
			] )->inContentLanguage()->text();
		} else {
			$rt = $this->msg( 'sanctions-topic-revision', [
				(string)$newRevisionId,
				(string)$newRevision->getPageAsLinkTarget()
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
