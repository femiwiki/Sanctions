<?php

use Flow\Model\UUID;

class SanctionsHooks {

	/**
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
		$title = $out->getTitle();

		//주제 이름공간이 아니면 검사하지 않습니다.
		if($title->getNamespace() != NS_TOPIC) return true;

		$uuid = UUID::create(strtolower($title->getText()))->getBinary();

		//제재안이 아닌 topic이나 의결기간이 종료된 topic이라면 검사하지 않습니다.
		$dbw = wfGetDB( DB_MASTER );
		$rowc = $dbw->selectRowCount(
			'sanctions',
			[],
			[
				'st_topic' => $uuid,
				'st_expiry > '.wfTimestamp()
			]
		);
		if ($rowc == 0) return;

		$html = $out->getHTML();

		//포스트 머리를 기준으로 HTML을 자릅니다.
		$posts = preg_split('/class\s*=\s*"[^-]*flow-post[^-"]*"/', $html);

		//0번 원소는 포스트가 시작하기 전이므로 제합니다.
		unset($posts[0]);

		$votes = array();
		foreach($posts as $post) {
			//post에 의견이 담겨있는지 검사합니다.
			//각 의견의 구분은 위키의 틀 안에 적어둔 태그를 사용합니다.
			// @todo 좀 더 괜찮은 방법으로 찾기.
			if(preg_match('/class="vote-agree-days">(\d+)/', $post, $days))
				$days = $days[1];
			//찬성만 하고 날짜를 적지 않았다면 1일로 처리합니다.
			else if(preg_match('/class="vote-agree">/', $post, $days))
				$days = 1;
			
			if($days != false)
				$days = (int)$days;
			else if(strpos($post, 'vote-disagree') !== false)
				$days = 0;
			else{
				continue;
			}

			//사용자 이름을 찾습니다.
			// @todo 좀 더 괜찮은 방법으로 찾기.
			if(!preg_match('/new mw-userlink">\s*<bdi>(.+)<\/bdi>/', $post, $name))
				continue;
			$name = $name[1];

			// 작성 시간을 찾습니다.
			// @todo 좀 더 괜찮은 방법으로 찾기.
			if(!preg_match('/datetime="(\d+)"\s*class="flow-timestamp/', $post, $timestamp))
				continue;
			$timestamp = (int)$timestamp[1];

			// 해당 사용자가 남긴 가장 마지막 의견이 아니라면 무시합니다.
			if(isset($votes[$name]) && $votes[$name]['timestamp'] > $timestamp)
				continue;

			$votes[$name] = array();
			$votes[$name]['timestamp'] = $timestamp;
			$votes[$name]['days'] = $days;
		}

		foreach($votes as $name => $vote) {
			$user = User::newFromName($name);
			$userId = $user->getId();

			// @todo user가 투표 조건을 만족하는지 확인합니다.
			if(!SanctionsUtils::hasVoteRight($user))
				continue;

			//DB에 의견을 추가하거나 이미 있다면 갱신합니다.
			$count = $dbw->selectRowCount(
				'sanctions_vote',
				[],
				[
					'stv_topic' => $uuid,
					'stv_user'=> $userId
				]
			);
			if($count == 0)
				$dbw->insert(
					'sanctions_vote',
					[
						'stv_topic' => $uuid,
						'stv_period' => $vote['days'],
						'stv_user' => $userId
					]
				);
			else {
				$dbw->update(
					'sanctions_vote',
					[
						'stv_period' => $vote['days']
					],
					[
						'stv_user' => $userId,
						'stv_topic' => $uuid
					]
				);
			}
		}

		/**
		 * @todo 3인 이상이 반대했을 시 즉시 부결하기
		*/

		return true;
	}

	public static function onUserToolLinksEdit( $userId, $userText, &$items ) {
		$items[] = Linker::link(Title::newFromText('특수:제재안목록/'.$userText),'제재안');
		return true;
	}

	public static function onContributionsToolLinks( $id, $title, &$tools ) {
		$user = User::newFromId($id);
		if ( $id && SanctionsUtils::hasVoteRight($user) )
			$tools[] = Linker::link(Title::newFromText('특수:제재안목록/'.$user->getName()),'제재안');

		return true;
	}

	/**
	 * @todo [[특:제재안목록]]이 아닌 다른 곳에서 새 주지들 쓸 수 없게 하기
	*/
}
