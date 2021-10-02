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
		// foreach($row as $key => $value) echo $key.'-'.$value.'<br/>';
		// echo '<div style="clear:both;">------------------------------------------------</div>';
		$sanction = Sanction::newFromId( $row->st_id );
		$author = $sanction->getAuthor();
		$isMySanction = $author->equals( $this->getUser() );

		if ( $this->getUserHasVoteRight() || $isMySanction ) {
			$isVoted = $row->voted_from != null;
		}

		$expiry = $sanction->getExpiry();
		$expired = $sanction->isExpired();

		// sanctions-row-label-emergency
		// sanctions-row-label-normal
		$process = wfMessage(
			'sanctions-row-label-' . ( $sanction->isEmergency() ? 'emergency' : 'normal' )
		)->text();
		// sanctions-row-label-passed
		// sanctions-row-label-rejected
		$passStatus = wfMessage(
			'sanctions-row-label-' . ( $sanction->isPassed() ? 'passed' : 'rejected' )
		)->text();

		$handled = $sanction->isHandled();

		if ( !$expired && !$handled ) {
			$timeLeftText = $this->getLanguage()->formatTimePeriod(
				(int)MWTimestamp::getInstance( $expiry )->getTimestamp()
				- (int)MWTimestamp::getInstance()->getTimestamp(),
				[
					'noabbrevs' => true,
					'avoid' => 'avoidseconds'
				]
			);

			$timeLeftText = wfMessage( 'sanctions-row-label-expiry', $timeLeftText )->text();
		}

		$target = $sanction->getTarget();

		$isForInsultingName = $sanction->isForInsultingName();
		$targetName = $target->getName();

		if ( $isForInsultingName ) {
			$originalName = $sanction->getTargetOriginalName();
			$length = mb_strlen( $originalName, 'utf-8' );
			$targetNameForDiplay =
				mb_substr( $originalName, 0, 1, 'utf-8' )
				. str_repeat( '*', $length - 2 );

			if ( $length > 1 ) {
				$targetNameForDiplay .= iconv_substr( $originalName, $length - 1, $length, 'utf-8' );
			}
		} else {
			$targetNameForDiplay = $targetName;
		}

		$topicTitle = $sanction->getTopic();

		// @todo Use better way?
		$userLinkTitle = Title::newFromText(
			strtok( $this->getTitle(), '/' )
			. '/' . $target->getName()
		);

		$rowTitle = wfMessage( 'sanctions-topic-title', [
			Linker::link(
				$userLinkTitle,
				$targetNameForDiplay,
				[ 'class' => 'sanction-target' ]
			),
			Linker::link(
				$topicTitle,
				wfMessage( 'sanctions-type-' . ( $isForInsultingName ? 'insulting-name' : 'block' ) )
					->text(),
				[ 'class' => 'sanction-type' ]
			)
		] )->text();

		$class = 'sanction';
		$class .= ( $isMySanction ? ' my-sanction' : '' )
		. ( $isForInsultingName ? ' insulting-name' : ' block' )
		. ( $sanction->isEmergency() ? ' emergency' : '' )
		. ( $expired ? ' expired' : '' )
		. ( $handled ? ' handled' : '' );
		if ( isset( $isVoted ) ) {
			$class .= $isVoted ? ' voted' : ' not-voted';
		}
		$sanctionId = $sanction->getId();
		$data = [
			'class' => $class,
			'is-expired' => $expired,
			'is-handled' => $handled,
			'can-vote' => $this->getUserHasVoteRight(),
			'vote-status' => $isMySanction ?
				wfMessage( 'sanctions-row-label-my-sanction' )->text() :
				( $isVoted ?
					wfMessage( 'sanctions-row-label-voted' )->text() :
					wfMessage( 'sanctions-row-label-not-voted' )->text()
				),
			'time-left' => $timeLeftText,
			'title' => $rowTitle,
		];

		if ( $expired && !$handled ) {
			$data['pending'] = wfMessage( 'sanctions-row-label-pending' )->text();
			$data['pass-status'] = $passStatus;
		}

		if ( $expired && $this->getUserHasVoteRight() && !$handled ) {
			$data['data-execute'] = [
				'action' => $this->getContext()->getTitle()->getFullURL(),
				'label' => wfMessage( 'sanctions-row-button-execute' )->text(),
				'token' => $this->getUser()->getEditToken( 'sanctions' ),
				'sanction-id' => (string)$sanctionId,
			];
		}
		if ( !$handled ) {
			$data['process'] = $process;
		}
		if ( !$expired && !$handled && $this->getUser()->isAllowed( 'block' ) ) {
			$data['data-toggle-process'] = [
				'action' => $this->getContext()->getTitle()->getFullURL(),
				'label' => wfMessage( 'sanctions-row-button-toggle-emergency' )->text(),
				'token' => $this->getUser()->getEditToken( 'sanctions' ),
				'sanction-id' => (string)$sanctionId,
			];
		}

		return $this->templateParser->processTemplate( 'Sanction', $data );
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
