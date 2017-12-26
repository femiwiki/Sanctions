<?php

class SanctionsUtils {

	public static function hasVoteRight (User $user) {
		//로그인을 하지 않은 경우 불가
		if($user->isAnon()) return false;

		$reg = $user->getRegistration();
		if(!$reg) return false;

		//현재 편집 권한이 없을 경우 불가
		if( !$user->isAllowed('edit') ) return false;

		$verificationPeriod = (int)wfMessage( 'sanctions-voting-right-verification-period' )->text();
		$verificationEdits = (int)wfMessage( 'sanctions-voting-right-verification-edits' )->text();
		
		$twentyDaysAgo = time()-(60*60*24*$verificationPeriod);
		$twentyDaysAgo = wfTimestamp(TS_MW, $twentyDaysAgo);
		
	 	// 계정 생성 후 20일 이상 경과되지 않았을 경우 불가
		if ($twentyDaysAgo < $reg) return false;

		$db = wfGetDB( DB_MASTER );

		// 최근 20일 이내에 3회 이상의 기여 이력이 있음 (현재도 활동하고 있음)
		$count = $db->selectRowCount(
			'revision',
			'*',
			[
				'rev_user' => $user->getId(),
				'rev_timestamp > '.$twentyDaysAgo
			]
		);
		if( $count < $verificationEdits ) return false;

		// 현재 제재되어 있는 경우 불가
		if($user->isBlocked()) return false;
		
		// 최근 20일 이내에 제재 이력이 없음 (최근 부정적 활동이 없었음)
		$count = $db->selectRowCount(
			'ipblocks',
			'*',
			[
				'ipb_user' => $user->getId(),
				'ipb_expiry > '.$twentyDaysAgo
			]
		);
		if( $count > 0 ) return false;

		return true;
	}
}
