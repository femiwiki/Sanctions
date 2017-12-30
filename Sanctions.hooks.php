<?php

use Flow\Model\UUID;
use Flow\Exception\InvalidInputException;

class SanctionsHooks {
	/**
	 * 데이터베이스 테이블을 만듭니다.
	 * @param $updater DatabaseUpdater
	 * @throws MWException
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater = null ) {
		$dir = dirname( __FILE__ );

		if ( $updater->getDB()->getType() == 'mysql' ) {
			$updater->addExtensionUpdate( array( 'addTable', 'sanctions',
				"$dir/sanctions.tables.sql", true ) );
		}
		return true;
	}

	/**
	 * 제재안 게시물(topic)을 방문하였을 때 HTML을 검사하여 sanctions_vote 테이블에 반영합니다.
	 * 이것 말고 onEditFilter이나 onArticleSaveComplete이나 onRevisionInsertComplete를 쓰고 싶었지만 flow 게시글을 작성할 때는 작동하지 않아 불가했습니다.
	 * @todo 좀 더 제대로 된 방법 사용하기.
	 * 
	 * @param $out - The OutputPage object.
	 * @param &$skin - Skin object that will be used to generate the page.
	 * @return bool true in all cases
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		// 주제 이름공간이 아니면 검사하지 않습니다.
		$title = $out->getTitle();

		if ( $title->getFullText() == '페미위키토론:제재안에 대한 의결' )
			$out->addModuleStyles( 'ext.flow-default-board' );

		if ( $title->getNamespace() != NS_TOPIC ) return true;

		// UUID가 적절하지 않은 경우에 검사하지 않습니다.
		$uuid = null;
		try {
			$uuid = UUID::create( strtolower( $title->getText() ) );
		} catch ( InvalidInputException $e ) {
			return true;
		}

		$sanction = Sanction::newFromUUID( $uuid );
		if ( $sanction === false )
			return true;

		$subTitle = '<' . Linker::link( Title::newFromText( '특수:제재안목록' ), '전체 제재안 목록보기' );
		$out->setSubTitle( $subTitle );

		if ( !$sanction->isVotable() )
			return true;

		$db = wfGetDB( DB_MASTER );
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
				[ 'flow_tree_node' => [ 'INNER JOIN', 'workflow_id = tree_ancestor_id' ] ],
				[ 'flow_tree_revision' => [ 'INNER JOIN', 'tree_descendant_id = tree_rev_descendant_id' ] ],
				[ 'flow_revision' => [ 'INNER JOIN', 'tree_rev_id = rev_id' ] ],
			]
		);

		// 유효표(제재 절차 잠여 요건을 만족하는 사람의 표)와 무효표를 따지지 않고 우선 세어서 배열에 담습니다.
		$votes = [];
		foreach($res as $row) {
			$timestamp = UUID::create( $row->rev_id )->getTimestamp();
			$userId = $row->rev_user_id;
			$content = $row->rev_content;

			// post에 의견이 담겨있는지 검사합니다.
			// 각 의견의 구분은 위키의 틀 안에 적어둔 태그를 사용합니다.
			$period = 0;
			if ( preg_match( '/<span class="sanction-vote-agree-period">(\d+)<\/span>/', $content, $period ) ) {
				$period = $period[1];
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
			if( isset( $votes[$userId] ) && $votes[$userId]['timestamp'] > $timestamp )
				continue;

			//배열에 저장합니다.
			$votes[$userId] = [
				'timestamp' => $timestamp,
				'period' => $period
			];
		}

		// 무효표를 버립니다
		foreach ( $votes as $userId => $vote ) {
			if( !SanctionsUtils::hasVoteRight( User::newFromId( $userId ) ) )
				unset( $votes[$userId] );
		}

		// 유효표가 하나도 없을 경우 아무것도 하지 않습니다.
		if ( !count( $votes ) ) return true;

		// 표를 데이터베이스에 반영합니다
		$voteData = [];
		foreach ( $votes as $userId => $vote )
			$voteData[] = [
				'user' => $userId,
				'period' => $vote['period']
			];
		$sanction->countVotes( $sanction, $voteData );

		return true;
	}

	public static function onUserToolLinksEdit( $userId, $userText, &$items ) {
		$items[] = Linker::link( Title::newFromText( '특수:제재안목록/'.$userText ),'제재안' );
		return true;
	}

	public static function onContributionsToolLinks( $id, $title, &$tools ) {
		global $wgUser;

		if ( $wgUser == null || !SanctionsUtils::hasVoteRight( $wgUser ) )
			return true;

		$targetName = User::newFromId($id)->getName();
		$tools[] = Linker::link( Title::newFromText( '특수:제재안목록/'.$targetName ),'제재안' );

		return true;
	}

	/**
	 * $newRev: Revision object of the "new" revision
	 * &$links: Array of HTML links
	 * $oldRev: Revision object of the "old" revision (may be null)
	 */
	public static function onDiffRevisionTools( Revision $newRev, &$links, $oldRev ) {
		global $wgUser;

		if ( $wgUser == null || !SanctionsUtils::hasVoteRight( $wgUser ) )
			return true;

		$ids = '';
		if ( $oldRev != null )
			$ids .= $oldRev->getId().'/';
		$ids .= $newRev->getId();

		$titleText = Title::newFromText( '특수:제재안목록/'.$newRev->getUserText().'/'.$ids );
		$links[] = Linker::link( $titleText , '이 편집을 근거로 제재 건의' );

		return true;
	}

	/**
	 * $rev: Revision object
	 * &$links: Array of HTML links
	 */
	public static function onHistoryRevisionTools( $rev, &$links ) {
		global $wgUser;

		if ( $wgUser == null || !SanctionsUtils::hasVoteRight( $wgUser ) )
			return true;

		$titleText = Title::newFromText( '특수:제재안목록/'.$rev->getUserText().'/'.$rev->getId() );
		$links[] = Linker::link( $titleText , '이 편집을 근거로 제재 건의' );

		return true;
	}

	public static function onRevisionInsertComplete( &$revision, $data, $flags ){ 
		EchoEvent::create( array(
			'type' => 'welcome',
			'agent' => User::newFromName('Admin'),
			'extra' => array(
				'notifyAgent' => true
			)
		) );
	}


	/**
	 * @todo [[특:제재안목록]]이 아닌 다른 곳에서 새 주제들 쓸 수 없게 하기
	*/
}
