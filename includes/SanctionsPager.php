<?php

namespace MediaWiki\Extension\Sanctions;

use Html;
use IContextSource;
use IndexPager;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\User\UserFactory;
use MWTimestamp;
use RequestContext;
use stdClass;
use TemplateParser;
use Title;
use User;

class SanctionsPager extends IndexPager {
	/** @var bool */
	protected $userHasVoteRight = null;

	/** @var string */
	private $targetName;

	/** @var UserFactory */
	private $userFactory;

	/** @var SanctionStore */
	private $sanctionStore;

	/** @var LinkRenderer */
	private $linkRenderer;

	/** @var TemplateParser */
	private $templateParser;

	/**
	 * @param IContextSource $context
	 * @param SanctionStore $sanctionStore
	 * @param UserFactory $userFactory
	 * @param LinkRenderer $linkRenderer
	 * @param string|null $targetName
	 */
	public function __construct(
		IContextSource $context,
		SanctionStore $sanctionStore,
		UserFactory $userFactory,
		LinkRenderer $linkRenderer,
		string $targetName = null
	) {
		parent::__construct( $context );
		$this->targetName = $targetName;
		$this->sanctionStore = $sanctionStore;
		$this->userFactory = $userFactory;
		$this->linkRenderer = $linkRenderer;

		$this->templateParser = new TemplateParser( __DIR__ . '/templates' );
	}

	/**
	 * @return string
	 */
	public function getIndexField() {
		return 'st_handled';
	}

	/**
	 * @return string[]|array[]
	 */
	public function getExtraSortFields() {
		if ( $this->getUserHasVoteRight() ) {
			return [ 'not_expired', 'my_sanction', 'voted_from', 'st_expiry' ];
		}
		return [ 'st_expiry' ];
	}

	/**
	 * @return string
	 */
	public function getNavigationBar() {
		return '';
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$query = [
			'tables' => [
				'sanctions'
			],
			'fields' => [
				'st_id',
				'st_author',
				'st_expiry',
				'st_handled',
				'my_sanction' => 'st_author = ' . $this->getUser()->getId(),
				'not_expired' => 'st_expiry > ' . wfTimestamp( TS_MW ),
			]
		];

		if ( $this->targetName ) {
			$query['conds'][] = 'st_target = ' . $this->userFactory->newFromName( $this->targetName )->getId();
		} else {
			$query['conds']['st_handled'] = 0;
		}

		if ( $this->getUserHasVoteRight() ) {
			$query['tables'][] = 'sanctions_vote';
			$query['fields']['voted_from'] = 'stv_id';
			$query['join_conds']['sanctions_vote'] = [
				'LEFT JOIN',
				[
					'stv_topic = st_topic',
					'stv_user' => $this->getUser()->getId(),
				]
			];
		} else {
			// If the user does not have permission to participate in the sanctions procedure, they will
			// not see expired sanctions.
			$query['conds'][] = 'st_expiry > ' . wfTimestamp( TS_MW );
		}

		return $query;
	}

	/** @inheritDoc */
	protected function getStartBody() {
		return Html::openElement(
			'div',
			[ 'class' => 'sanctions' ]
		);
	}

	/** @inheritDoc */
	protected function getEndBody() {
		return Html::closeElement( 'div' );
	}

	/**
	 * @param array|stdClass $row
	 * @return string
	 */
	public function formatRow( $row ) {
		$sanction = $this->sanctionStore->newFromId( $row->st_id );
		$isMySanction = $sanction->getAuthor()->equals( $this->getUser() );
		$expired = $sanction->isExpired();
		$handled = $sanction->isHandled();
		$targetName = $sanction->getTarget()->getName();
		$isForInsultingName = $sanction->isForInsultingName();

		$data = [
			'class' => implode( ' ', $this->getClasses( $row, $this->getUser() ) ),
			'id' => 'sanction-' . $sanction->getWorkflowId()->getAlphadecimal(),
			'is-expired' => $expired,
			'is-handled' => $handled,
			'can-vote' => $this->getUserHasVoteRight(),
		];

		$isVoted = isset( $row->voted_from );
		if ( $isMySanction ) {
			$data['vote-status'] = $this->msg( 'sanctions-row-label-my-sanction' )->text();
		} else {
			$data['vote-status'] = $isVoted ?
				$this->msg( 'sanctions-row-label-voted' )->text() :
				$this->msg( 'sanctions-row-label-not-voted' )->text();
		}

		if ( !$handled ) {
			$data['process'] = $this->msg(
				// sanctions-row-label-emergency
				// sanctions-row-label-normal
				'sanctions-row-label-' . ( $sanction->isEmergency() ? 'emergency' : 'normal' )
			)->text();
			if ( !$expired ) {
				$expiry = $sanction->getExpiry();
				$timeLeftText = $this->getLanguage()->formatTimePeriod(
					(int)MWTimestamp::getInstance( $expiry )->getTimestamp()
					- (int)MWTimestamp::getInstance()->getTimestamp(),
					[
						'noabbrevs' => true,
						'avoid' => 'avoidseconds'
					]
				);

				$timeLeftText = $this->msg( 'sanctions-row-label-expiry', $timeLeftText )->text();
				$data['time-left'] = $timeLeftText;

				if ( $this->getUser()->isAllowed( 'block' ) ) {
					$data['data-toggle-process'] = [
						'action' => $this->getContext()->getTitle()->getFullURL(),
						'label' => $this->msg( 'sanctions-row-button-toggle-emergency' )->text(),
						'token' => RequestContext::getMain()->getCsrfTokenSet()->getToken( 'sanctions' )->toString(),
						'sanction-id' => (string)$row->st_id,
					];
				}
			} else {
				$data['pending'] = $this->msg( 'sanctions-row-label-pending' )->text();
				$data['pass-status'] = $this->msg(
					// sanctions-row-label-passed
					// sanctions-row-label-rejected
					'sanctions-row-label-' . ( $sanction->isPassed() ? 'passed' : 'rejected' )
				)->text();
			}
		}

		if ( $isForInsultingName ) {
			$originalName = $sanction->getTargetOriginalName();
			$targetNameForDisplay = self::maskStringPartially( $originalName );
		} else {
			$targetNameForDisplay = $targetName;

		}

		// @todo Use better way?
		$userLinkTitle = Title::newFromText(
			strtok( $this->getTitle(), '/' )
			. '/' . $targetName
		);

		$data['title'] = $this->msg( 'sanctions-topic-title', [
			$this->linkRenderer->makeLink(
				$userLinkTitle,
				$targetNameForDisplay,
				[ 'class' => 'sanction-target' ]
			),
			$this->linkRenderer->makeLink(
				$sanction->getWorkflow(),
				$this->msg( 'sanctions-type-' . ( $isForInsultingName ? 'insulting-name' : 'block' ) )
					->text(),
				[ 'class' => 'sanction-type' ]
			)
		] )->text();

		return $this->templateParser->processTemplate( 'Sanction', $data );
	}

	/**
	 * @param array|stdClass $row
	 * @param User $visitor
	 * @return array
	 */
	public static function getClasses( $row, User $visitor ) {
		$sanction = Sanction::newFromRow( $row );
		$class = [ 'sanction' ];
		if ( $sanction->getAuthor()->equals( $visitor ) ) {
			$class[] = 'my-sanction';
		} elseif ( Utils::hasVoteRight( $visitor ) ) {
			$class[] = isset( $row->voted_from ) ? 'voted' : 'not-voted';
		}

		if ( $sanction->isExpired() ) {
			$class[] = 'expired';
		}
		if ( $sanction->isHandled() ) {
			$class[] = 'handled';
		}
		if ( $sanction->isEmergency() ) {
			$class[] = 'emergency';
		}
		$class[] = $sanction->isForInsultingName()
			? 'insulting-name'
			: 'block';

		return $class;
	}

	/**
	 * @param string $str
	 * @return string
	 */
	public static function maskStringPartially( string $str ) {
		$length = mb_strlen( $str, 'utf-8' );
		$masked = mb_substr( $str, 0, 1, 'utf-8' ) . str_repeat( '*', $length - 2 );

		if ( $length > 1 ) {
			$masked .= iconv_substr( $str, $length - 1, $length, 'utf-8' );
		}
		return $masked;
	}

	/**
	 * @return string
	 */
	public function getEmptyBody() {
		$text = $this->msg( 'sanctions-empty' )->text();

		if ( $this->targetName == null ) {
			$text = $this->msg( 'sanctions-empty-now' )->text();
		} else {
			$text = $this->msg( 'sanctions-empty-about-now', $this->targetName )->text();
		}
		return Html::rawelement(
			'div',
			[ 'class' => 'sanction-empty' ],
			$text
		);
	}

	/**
	 * @return bool
	 */
	protected function getUserHasVoteRight() {
		if ( $this->userHasVoteRight === null ) {
			$this->userHasVoteRight = Utils::hasVoteRight( $this->getUser() );
		}
		return $this->userHasVoteRight;
	}
}
