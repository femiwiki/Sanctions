<?php

class SanctionsPager extends IndexPager {
	/**
	 * @var bool
	 */
	protected $userHasVoteRight = null;

	/**
	 * @var string
	 */
	private $targetName;

	/**
	 * @param IContextSource $context
	 * @param string $targetName
	 */
	public function __construct( $context, $targetName ) {
		parent::__construct( $context );
		$this->targetName = $targetName;
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

		if ( $this->getUserHasVoteRight() ) {
			$isVoted = $row->voted_from != null;
		}

		$author = $sanction->getAuthor();
		$isMySanction = $author->equals( $this->getUser() );

		$expiry = $sanction->getExpiry();
		$expired = $sanction->isExpired();

		$process = wfMessage(
			'sanctions-row-label-' . ( $sanction->isEmergency() ? 'emergency' : 'normal' )
		)->text();
		$passStatus = wfMessage(
			'sanctions-row-label-' . ( $sanction->isPassed() ? 'passed' : 'rejected' )
		)->text();

		$handled = $sanction->isHandled();

		if ( !$expired && !$handled ) {
			$timeLeftText = $this->getLanguage()->formatTimePeriod(
				MWTimestamp::getInstance( $expiry )->getTimestamp()
				- MWTimestamp::getInstance()->getTimestamp(),
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

		$userLinkTitle = Title::newFromText(
			strtok( $this->getTitle(), '/' )
			. '/' . $target->getName()
		); // @todo Use better way?

		$rowTitle = wfMessage( 'sanctions-topic-title', [
			linker::link(
				$userLinkTitle,
				$targetNameForDiplay,
				[ 'class' => 'sanction-target' ]
			),
			linker::link(
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
		if ( $this->getUserHasVoteRight() && !$isMySanction ) {
			$class .= $isVoted ? ' voted' : ' not-voted';
		}

		$out = Html::openElement(
			'div',
			[ 'class' => $class ]
		);
		if ( $expired && !$handled ) {
			$out .= Html::rawelement(
				'div',
				[ 'class' => 'sanction-expired' ],
				wfMessage( 'sanctions-row-label-pending' )->text()
			);
			$out .= Html::rawelement(
				'div',
				[ 'class' => 'sanction-pass-status' ],
				$passStatus
			);
		}
		if ( $this->getUserHasVoteRight() || $isMySanction ) {
			$out .= Html::rawelement(
				'div',
				[ 'class' => 'sanction-vote-status' ],
				$isMySanction ?
					wfMessage( 'sanctions-row-label-my-sanction' )->text() :
					( $isVoted ?
						wfMessage( 'sanctions-row-label-voted' )->text() :
						wfMessage( 'sanctions-row-label-not-voted' )->text()
					)
			);
		}
		if ( !$expired && !$handled ) {
			$out .= Html::rawelement(
				'div',
				[ 'class' => 'sanction-timeLeft' ],
				$timeLeftText
			);
		}
		if ( $expired && $this->getUserHasVoteRight() && !$handled ) {
			$out .= $this->executeButton( $sanction->getId() );
		}
		if ( !$handled ) {
			$out .= Html::rawelement(
				'div',
				[ 'class' => 'sanction-process' ],
				$process
			);
		}
		if ( !$expired && !$handled && $this->getUser()->isAllowed( 'block' ) ) {
			$out .= $this->processToggleButton( $sanction->getId() );
		}

		$out .= Html::rawelement(
			'div',
			[ 'class' => 'sanction-title' ],
			$rowTitle
		);

		return $out . Html::closeElement( 'div' );
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
	 * @param int $sanctionId
	 * @return string
	 */
	protected function processToggleButton( $sanctionId ) {
		$out = '';

		$out .= Xml::tags(
			'form',
			[
			'method' => 'post',
			'action' => $this->getContext()->getTitle()->getFullURL(),
			'class' => 'sanction-process-toggle'
			],
			Html::submitButton(
				wfMessage( 'sanctions-row-button-toggle-emergency' )->text(),
				[ 'class' => 'sanction-process-toggle-button' ],
				[ 'mw-ui-progressive' ]
			) .
			Html::hidden(
				'token',
				$this->getUser()->getEditToken( 'sanctions' )
			) .
			Html::hidden(
				'sanctionId',
				(string)$sanctionId
			) .
			Html::hidden(
				'sanction-action',
				'toggle-emergency'
			)
		);

		return $out;
	}

	/**
	 * @param int $sanctionId
	 * @return string
	 */
	protected function executeButton( $sanctionId ) {
		$out = '';

		$out .= Xml::tags(
			'form',
			[
			'method' => 'post',
			'action' => $this->getContext()->getTitle()->getFullURL()
			],
			Html::submitButton(
				wfMessage( 'sanctions-row-button-execute' )->text(),
				[ 'class' => 'sanction-exectute-button' ],
				[ 'mw-ui-progressive' ]
			) .
			Html::hidden(
				'token',
				$this->getUser()->getEditToken( 'sanctions' )
			) .
			Html::hidden(
				'sanctionId',
				(string)$sanctionId
			) .
			Html::hidden(
				'sanction-action',
				'execute'
			)
		);

		return $out;
	}

	/**
	 * @return bool
	 */
	protected function getUserHasVoteRight() {
		if ( $this->userHasVoteRight === null ) {
			$this->userHasVoteRight = SanctionsUtils::hasVoteRight( $this->getUser() );
		}
		return $this->userHasVoteRight;
	}
}
