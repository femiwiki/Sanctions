<?php

class SanctionsUtils {
	/**
	 * @param User $user
	 * @param array &$reason An array of reasons why can't participate.
	 * @return bool
	 */
	public static function hasVoteRight( User $user, &$reason = false ) {
		global $wgActorTableSchemaMigrationStage;

		// If the user is not logged in
		if ( $user->isAnon() ) {
			if ( $reason !== false ) {
				$reason[] = wfMessage( 'sanctions-reason-not-logged-in' )->text();
			}
			return false;
		}

		$reg = $user->getRegistration();
		if ( !$reg ) {
			if ( $reason !== false ) {
				$reason[] = wfMessage( 'sanctions-reason-failed-to-load-registration' )->text();
			} else {
				return false;
			}
		}

		// If the user has not allowed to edit
		if ( !$user->isAllowed( 'edit' ) ) {
			if ( $reason !== false ) {
				$reason[] = wfMessage( 'sanctions-reason-no-edit-permission' )->text();
			} else {
				return false;
			}
		}

		$verificationPeriod = (float)wfMessage( 'sanctions-voting-right-verification-period' )->text();
		$verificationEdits = (int)wfMessage( 'sanctions-voting-right-verification-edits' )->text();

		$twentyDaysAgo = time() - ( 60 * 60 * 24 * $verificationPeriod );
		$twentyDaysAgo = wfTimestamp( TS_MW, $twentyDaysAgo );

		// If account has not been created more than 20 days
		if ( $twentyDaysAgo < $reg ) {
			if ( $reason !== false ) {
				$reason[] = wfMessage( 'sanctions-reason-unsatisfying-verification-period', [
					$verificationPeriod,
					MWTimestamp::getLocalInstance( $reg )->getTimestamp( TS_ISO_8601 )
				] )->text();
			} else {
				return false;
			}
		}

		$db = wfGetDB( DB_MASTER );

		// There have been more than three contribution histories within the last 20 days (currently
		// active)
		$count = 0;
		if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_READ_OLD ) {
			$count = $db->selectRowCount(
				'revision',
				'*',
				[
					'rev_user' => $user->getId(),
					'rev_timestamp > ' . $twentyDaysAgo
				]
			);
		} else {
			$count = $db->selectRowCount(
				[
					'revision',
					'revision_actor_temp',
					'actor',
					'user',
				],
				'user_id',
				[
					'user_id' => $user->getId(),
					'rev_timestamp > ' . $twentyDaysAgo
				],
				__METHOD__,
				[],
				[
					'revision_actor_temp' => [ 'LEFT JOIN', [ 'rev_id = revactor_rev' ] ],
					'actor' => [ 'LEFT JOIN', [ 'revactor_actor = actor_id ' ] ],
					'user' => [ 'LEFT JOIN', [ 'actor_user = user_id ' ] ],
				]
			);
		}
		if ( $count < $verificationEdits ) {
			if ( $reason !== false ) {
				$reason[] = wfMessage( 'sanctions-reason-unsatisfying-verification-edits', [
					$verificationPeriod,
					$count,
					$verificationEdits
				] )->text();
			} else {
				return false;
			}
		}

		if ( $user->isBlocked() ) {
			if ( $reason !== false ) {
				$reason[] = wfMessage( 'sanctions-reason-blocked' )->text();
			} else {
				return false;
			}
		} else {
			// No blocking history within the last 20 days (no recent negative activity)
			$blockExpiry = $db->selectField(
				'ipblocks',
				'MAX(ipb_expiry)',
				[
				'ipb_user' => $user->getId()
				],
				__METHOD__,
				[ 'GROUP BY' => 'ipb_id' ]
			);
			if ( $blockExpiry > $twentyDaysAgo ) {
				if ( $reason !== false ) {
					$reason[] = wfMessage( 'sanctions-reason-recently-blocked', [
						MWTimestamp::getLocalInstance( $blockExpiry )->getTimestamp( TS_ISO_8601 ),
						$verificationPeriod,
					] )->text();
				} else {
					return false;
				}
			}
		}

		if ( $reason !== false ) { return count( $reason ) == 0;
		}
			return true;
	}
}
