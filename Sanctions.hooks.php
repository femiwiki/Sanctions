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
	 * 이것 말고 onEditFilter이나 onArticleSaveComplete를 쓰고 싶었지만 workflow에 포스트를 추가할 때는 작동하지 않아 불가했습니다.
	 * @todo 좀 더 제대로 된 방법 사용하기.
	 * 
	 * @param $out - The OutputPage object.
	 * @param &$skin - Skin object that will be used to generate the page.
	 * @return bool true in all cases
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		// 주제 이름공간이 아니면 검사하지 않습니다.
		$title = $out->getTitle();
		if ( $title->getNamespace() != NS_TOPIC ) return true;

		// UUID가 적절하지 않은 경우에 검사하지 않습니다.
		try {
			$uuid = UUID::create( strtolower( $title->getText() ) )->getBinary();
		} catch ( InvalidInputException $e ) {
			return true;
		}

		$sanction = Sanction::newFromUUID( $uuid );
		// 제재안을 가져오는데 실패하거나 제재안에 의견을 내는 것이 불가할 경우 검사하지 않습니다.
		if ( $sanction === false || !$sanction->isVotable() )
			return true;

		// html을 가져와 각 포스트를 대강 구합니다.
		// @todo 좀 더 괜찮은 방법으로 찾기.
		$html = $out->getHTML();

		// 포스트 머리에 있는 문자열을 기준으로 HTML을 대강 자릅니다.
		$posts = preg_split( '/class\s*=\s*"[^-]*flow-post[^-"]*"/', $html );
		// 0번 원소는 포스트가 시작하기 전이므로 제합니다.
		unset( $posts[0] );
		
		// 유효표(제재 절차 잠여 요건을 만족하는 사람의 표)와 무효표를 따지지 않고 우선 세어 배열에 담습니다.
		$votes = array();
		foreach ( $posts as $post ) {
			// post에 의견이 담겨있는지 검사합니다.
			// 각 의견의 구분은 위키의 틀 안에 적어둔 태그를 사용합니다.
			// @todo 좀 더 괜찮은 방법으로 찾기.
			if ( preg_match( '/class="vote-agree-days">(\d+)/', $post, $period ) )
				$period = $period[1];
			// 찬성만 하고 날짜를 적지 않았다면 1일로 처리합니다.
			else if ( preg_match( '/class="vote-agree">/', $post) )
				$period = 1;
			
			// 찬성하지 않았다면 반대하였는지 확인합니다.
			if ( !$period && strpos( $post, 'vote-disagree' ) !== false )
				$period = 0;
			else {
				// 찬성도 반대도 하지 않았다면 아무것도 없는 의견이므로 세지 않습니다.
				continue;
			}

			// 의견을 남긴 사용자 이름을 찾습니다.
			// @todo 좀 더 괜찮은 방법으로 찾기.
			if( !preg_match( '/new mw-userlink">\s*<bdi>(.+)<\/bdi>/', $post, $name ) )
				continue;
			$name = $name[1];

			// 작성 시간을 찾습니다.
			// @todo 좀 더 괜찮은 방법으로 찾기.
			if( !preg_match( '/datetime="(\d+)"\s*class="flow-timestamp/', $post, $timestamp ) )
				continue;
			$timestamp = $timestamp[1];

			// 이 의견이 해당 사용자가 남긴 가장 마지막 의견이 아니라면 무시합니다.
			if( isset( $votes[$name] ) && $votes[$name]['timestamp'] > $timestamp )
				continue;

			//배열에 저장합니다.
			$votes[$name] = [
				'timestamp' => $timestamp,
				'period' => $period
			];
		}

		// 무효표를 버립니다
		foreach ( $votes as $name => $vote ) {
			if( !SanctionsUtils::hasVoteRight( $name ) )
				unset( $votes[$name] );
		}

		// 유효표가 하나도 없을 경우 아무것도 하지 않습니다.
		if ( !count( $votes ) ) return true;

		// 표를 데이터베이스에 반영합니다
		$voteData = [];
		foreach ( $votes as $name => $vote )
			$voteData[] = [
				'stv_user' => User::newFromName( $name )->getId(),
				'stv_period' => $vote['period']
			];
		$sanction->countVotes( $voteData );

		return true;
	}

	public static function onUserToolLinksEdit( $userId, $userText, &$items ) {
		$items[] = Linker::link( Title::newFromText( '특수:제재안목록/'.$userText ),'제재안' );
		return true;
	}

	public static function onContributionsToolLinks( $id, $title, &$tools ) {
		$user = User::newFromId( $id );
		if ( $id && SanctionsUtils::hasVoteRight( $user ) )
			$tools[] = Linker::link( Title::newFromText( '특수:제재안목록/'.$user->getName() ),'제재안' );

		return true;
	}

	/**
	 * @todo [[특:제재안목록]]이 아닌 다른 곳에서 새 주제들 쓸 수 없게 하기
	*/
}
