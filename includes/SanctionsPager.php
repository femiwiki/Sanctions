<?php

namespace MediaWiki\Extension\Sanctions;

use Html;
use IContextSource;
use IndexPager;
use Linker;
use MWTimestamp;
use stdClass;
use TemplateParser;
use Title;
use User;

class SanctionsPager extends IndexPager {
	/** @var bool */
	protected $userHasVoteRight = null;

	/** @var string */
	private $targetName;

	/** @var TemplateParser */
	private $templateParser;

	/**
	 * @param IContextSource $context
	 * @param string|null $targetName
	 */
	public function __construct( IContextSource $context, ?string $targetName ) {
		parent::__construct( $context );
		$this->targetName = $targetName;
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
		Sanction::checkAllSanctionNewVotes();
		$subquery = $this->mDb->buildSelectSubquery(
			'sanctions_vote',
			[ 'stv_id', 'stv_topic' ],
			[ 'stv_user' => $this->getUser()->getId() ]
		);
		$query = [
			'tables' => [
				'sanctions'
			],
			'fields' => [
				'st_id',
				'my_sanction' => 'st_author = ' . $this->getUser()->getId(),
				'st_expiry',
				'not_expired' => 'st_expiry > ' . wfTimestamp( TS_MW ),
				'st_handled'
			]
		];

		if ( $this->targetName ) {
			$query['conds'][] = 'st_target = ' . User::newFromName( $this->targetName )->getId();
		} else {
			$query['conds']['st_handled'] = 0;
		}

		if ( $this->getUserHasVoteRight() ) {
			// If 'AS' is not written explicitly, it will not work as expected.
			$query['tables']['sub'] = $subquery . ' AS';
			$query['fields']['voted_from'] = 'stv_id';
			$query['join_conds'] = [ 'sub' => [ 'LEFT JOIN', 'st_topic = sub.stv_topic' ] ];
		} else {
			// If the user does not have permission to participate in the sanctions procedure, they will
			// not see expired sanctions.
			$query['conds'][] = 'st_expiry > ' . wfTimestamp( TS_MW );
		}

		return $query;
	}

	/**
	 * @param array|stdClass $row
	 * @return string
	 */
	public function formatRow( $row ) {
		$sanction = Sanction::newFromId( $row->st_id );
		$isMySanction = $sanction->getAuthor()->equals( $this->getUser() );
		$expired = $sanction->isExpired();
		$handled = $sanction->isHandled();
		$targetName = $sanction->getTarget()->getName();
		$isForInsultingName = $sanction->isForInsultingName();

		$data = [
			'is-expired' => $expired,
			'is-handled' => $handled,
			'can-vote' => $this->getUserHasVoteRight(),
		];
		$class = [ 'sanction' ];

		$isVoted = $row->voted_from != null;
		if ( $isMySanction ) {
			$class[] = 'my-sanction';
			$data['vote-status'] = wfMessage( 'sanctions-row-label-my-sanction' )->text();
			if ( $this->getUserHasVoteRight() && $isVoted ) {
				$class[] = 'voted';
			}
		} else {
			$data['vote-status'] = $isVoted ?
				wfMessage( 'sanctions-row-label-voted' )->text() :
				wfMessage( 'sanctions-row-label-not-voted' )->text();
		}

		if ( $expired ) {
			$class[] = 'expired';
		}

		if ( $handled ) {
			$class[] = 'handled';
		} else {
			$data['process'] = wfMessage(
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

				$timeLeftText = wfMessage( 'sanctions-row-label-expiry', $timeLeftText )->text();
				$data['time-left'] = $timeLeftText;

				if ( $this->getUser()->isAllowed( 'block' ) ) {
					$data['data-toggle-process'] = [
						'action' => $this->getContext()->getTitle()->getFullURL(),
						'label' => wfMessage( 'sanctions-row-button-toggle-emergency' )->text(),
						'token' => $this->getUser()->getEditToken( 'sanctions' ),
						'sanction-id' => (string)$row->st_id,
					];
				}
			} else {
				$data['pending'] = wfMessage( 'sanctions-row-label-pending' )->text();
				$data['pass-status'] = wfMessage(
					// sanctions-row-label-passed
					// sanctions-row-label-rejected
					'sanctions-row-label-' . ( $sanction->isPassed() ? 'passed' : 'rejected' )
				)->text();

				if ( $this->getUserHasVoteRight() ) {
					$data['data-execute'] = [
						'action' => $this->getContext()->getTitle()->getFullURL(),
						'label' => wfMessage( 'sanctions-row-button-execute' )->text(),
						'token' => $this->getUser()->getEditToken( 'sanctions' ),
						'sanction-id' => (string)$row->st_id,
					];
				}
			}
		}

		if ( $sanction->isEmergency() ) {
			$class[] = 'emergency';
		}

		if ( $isForInsultingName ) {
			$originalName = $sanction->getTargetOriginalName();
			$targetNameForDisplay = self::maskStringPartially( $originalName );
			$class[] = 'insulting-name';
		} else {
			$targetNameForDisplay = $targetName;
			$class[] = 'block';

		}

		// @todo Use better way?
		$userLinkTitle = Title::newFromText(
			strtok( $this->getTitle(), '/' )
			. '/' . $targetName
		);

		$data['title'] = wfMessage( 'sanctions-topic-title', [
			Linker::link(
				$userLinkTitle,
				$targetNameForDisplay,
				[ 'class' => 'sanction-target' ]
			),
			Linker::link(
				$sanction->getTopic(),
				wfMessage( 'sanctions-type-' . ( $isForInsultingName ? 'insulting-name' : 'block' ) )
					->text(),
				[ 'class' => 'sanction-type' ]
			)
		] )->text();

		$data['class'] = implode( ' ', $class );

		return $this->templateParser->processTemplate( 'Sanction', $data );
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
		$text = wfMessage( 'sanctions-empty' )->text();

		if ( $this->targetName == null ) {
			$text = wfMessage( 'sanctions-empty-now' )->text();
		} else {
			$text = wfMessage( 'sanctions-empty-about-now', $this->targetName )->text();
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
