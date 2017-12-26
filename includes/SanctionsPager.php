<?php

use Flow\Model\UUID;

class SanctionsPager extends IndexPager {
	protected $UserHasVoteRight = null;

	function __construct( $context, $targetName) {
		parent::__construct( $context );
		$this->targetName = $targetName;
	}

	function getIndexField () {
		if($this->getUserHasVoteRight())
			return 'not_expired';
		return 'st_expiry';
	}

	function getExtraSortFields () {
		if($this->getUserHasVoteRight())
			return ['voted_from', 'st_expiry'];
		return null;
	}

	function getNavigationBar () {
		return '';
	}

	function getQueryInfo () {
		$subquery = $this->mDb->selectSQLText(
				 		'sanctions_vote',
				 		['stv_id','stv_topic'],
				 		[ 'stv_user' => $this->getUser()->getId() ]
				 );
		$query = [
			'tables' => [
				 'sanctions'
			 ],
			'fields' => [
				'st_id',
				'st_target',
				'st_topic',
				'st_expiry',
				'not_expired' => 'st_expiry > '.wfTimestamp(TS_MW),
				'st_emergency',
				'st_original_name'
			],
			'conds' => [ 'st_handled = 0' ]
		];

		if($this->targetName)
			$query['conds'][] = 'st_target = '.User::newFromName($this->targetName)->getId();

		if($this->getUserHasVoteRight()) {
			$query['tables']['sub'] = '('.$subquery.') AS'; //AS를 따로 써야 작동하길래 이렇게 썼는데 당최 이게 맞는지??
			$query['fields']['voted_from'] = 'stv_id';
			//처리된 제재안은 보지 않습니다.
			$query['conds']['st_handled'] = 0;
			$query['join_conds'] = ['sub' => ['LEFT JOIN', 'st_topic = sub.stv_topic']];
		} else {
			//제재 절차 참가 권한이 없을 때는 만료된 제재안은 보지 않습니다.		
			$query['conds'][] = 'st_expiry > '.wfTimestamp(TS_MW);
		}
		
		return $query;
	}

	function getEmptyBody () {
		return Html::rawelement(
                            'div',
                            ['class' => 'sanction-empty'],
                            $this->targetName.'님에 대한 제재안이 없습니다.'
                        );
	}

	function formatRow( $row ) {
		//foreach($row as $key => $value) echo $key.'-'.$value.'<br/>';
		//echo '<div style="clear:both;">------------------------------------------------</div>';
		$expired = !$row->not_expired;
		if($this->getUserHasVoteRight())
			$isVoted = $row->voted_from != null;

		$process = $row->st_emergency?'긴급':'일반';

		if( !$expired ) {
			$diff = MWTimestamp::getInstance($row->st_expiry)->diff(MWTimestamp::getInstance());
			if( $diff->d )
				$timeLeftText = $diff->d.'일 '.$diff->h.'시간 남음';
			elseif ( $diff->h )
				$timeLeftText = $diff->h.'시간 남음';
			else
				$timeLeftText = $diff->i.'분 남음';
		}

		$target = User::newFromId($row->st_target);
		$hasInsultingName = isset($row->st_original_name) && $row->st_original_name != null;
		$targetName = $target->getName();
		$targetOriginalName = $hasInsultingName?$row->st_original_name:$targetName;

		$topicTitle = UUID::create( $row->st_topic )->getAlphadecimal();
		$title = linker::link(Title::newFromText(strtok( $this->getTitle(), '/').'/'.$target->getName()), $targetOriginalName,['class'=>'sanction-target']).' 님에 대한 ';
		
		$title .= linker::link(Title::newFromText('주제:'.$topicTitle), ($hasInsultingName?'부적절한 사용자명 변경 건의':'편집 차단 건의'),['class'=>'sanction-type']);

		$class = 'sanction';
		$class .= ($hasInsultingName?' insulting-name':' block')
			.($row->st_emergency?' emergency':'')
			.($expired?' expired':'');
		if($this->getUserHasVoteRight())
			$class .= ($isVoted?' voted':' not-voted');

		$out = Html::openElement(
                        'div',
                        array('class' => $class)
                    );

		if( $expired )
			$out .= Html::rawelement(
                'div',
                ['class' => 'sanction-expired'],
                '기간 종료'
            );
		if($this->getUserHasVoteRight())
			$out .= Html::rawelement(
                'div',
                ['class' => 'sanction-vote-status'],
                $isVoted?'참여함':'참여 전'
            );
		if( !$expired )
			$out .= Html::rawelement(
                'div',
                ['class' => 'sanction-timeLeft'],
                $timeLeftText
            );
		if( $expired && $this->getUser()->isAllowed('block') )
			$out .= $this->executeButton($row->st_id);
		$out .= Html::rawelement(
            'div',
            ['class' => 'sanction-process'],
            $process
        );
		if( !$expired && $this->getUser()->isAllowed('block') )
			$out .= $this->processToggleButton($row->st_id);

		$out .= Html::rawelement(
                        'div',
                        ['class' => 'sanction-title'],
                        $title
                    );

		return $out.Html::closeElement('div');
	}

	protected function processToggleButton($sanctionId) {
		$out = '';

		$out .= Xml::tags(
			'form',
			[
				'method' => 'post',
				'action' => $this->getContext()->getTitle(),
				'class'=>'sanction-process-toggle'
			],
			Html::submitButton(
				'전환',
				['class'=>'sanction-process-toggle-button'], [ 'mw-ui-progressive' ]
			) .
			Html::hidden(
				'token',
				$this->getUser()->getEditToken( array( 'sanctions' ) )
			) .
			Html::hidden(
				'sanctionId',
				$sanctionId
			).
			Html::hidden(
				'result',
				'toggle-emergency'
			)
		);

		return $out;
	}

	protected function executeButton($sanctionId) {
		$out = '';

		$out .= Xml::tags(
			'form',
			[
				'method' => 'post',
				'action' => $this->getContext()->getTitle(),
				'class'=>'sanction-exectute-form'
			],
			Html::submitButton(
				'집행',
				['class'=>'sanction-exectute-button'], [ 'mw-ui-progressive' ]
			) .
			Html::hidden(
				'token',
				$this->getUser()->getEditToken( array( 'sanctions' ) )
			) .
			Html::hidden(
				'sanctionId',
				$sanctionId
			).
			Html::hidden(
				'result',
				'execute'
			)
		); 

		return $out;
	}

	protected function getUserHasVoteRight() {
		if( $this->UserHasVoteRight === null )
			$this->UserHasVoteRight = SanctionsUtils::hasVoteRight($this->getUser());
		return $this->UserHasVoteRight;
	}
}