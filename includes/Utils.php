<?php

namespace MediaWiki\Extension\Sanctions;

use Message;
use MWTimestamp;
use User;

class Utils {
	/**
	 * @param User $user
	 * @param string[]|bool &$reasons An array of reasons why can't participate.
	 * @param bool $contentLang
	 * @return bool
	 */
	public static function hasVoteRight( User $user, &$reasons = false, $contentLang = false ) {
		global $wgActorTableSchemaMigrationStage;

		// If the user is not logged in
		if ( $user->isAnon() ) {
			if ( $reasons !== false ) {
				self::addReason( wfMessage( 'sanctions-reason-not-logged-in' ), $reasons, $contentLang );
			}
			return false;
		}

		$reg = $user->getRegistration();
		if ( !$reg ) {
			if ( $reasons !== false ) {
				self::addReason( wfMessage( 'sanctions-reason-failed-to-load-registration' ), $reasons,
					$contentLang );
			} else {
				return false;
			}
		}

		// If the user has not allowed to edit
		if ( !$user->isAllowed( 'edit' ) ) {
			if ( $reasons !== false ) {
				self::addReason( wfMessage( 'sanctions-reason-no-edit-permission' ), $reasons, $contentLang );
			} else {
				return false;
			}
		}

		$verificationPeriod = (float)wfMessage( 'sanctions-voting-right-verification-period' )
			->inContentLanguage()->text();
		$verificationEdits = (int)wfMessage( 'sanctions-voting-right-verification-edits' )
			->inContentLanguage()->text();

		$twentyDaysAgo = time() - ( 60 * 60 * 24 * $verificationPeriod );
		$twentyDaysAgo = wfTimestamp( TS_MW, $twentyDaysAgo );

		// If account has not been created more than 20 days
		if ( $twentyDaysAgo < $reg ) {
			if ( $reasons !== false ) {
				self::addReason( wfMessage( 'sanctions-reason-unsatisfying-verification-period', [
					(string)$verificationPeriod,
					MWTimestamp::getLocalInstance( (string)$reg )->getTimestamp( TS_ISO_8601 )
				] ), $reasons, $contentLang );
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
			if ( $reasons !== false ) {
				self::addReason( wfMessage( 'sanctions-reason-unsatisfying-verification-edits', [
					(string)$verificationPeriod,
					(string)$count,
					(string)$verificationEdits
				] ), $reasons, $contentLang );
			} else {
				return false;
			}
		}

		if ( $user->isBlocked() ) {
			if ( $reasons !== false ) {
				self::addReason( wfMessage( 'sanctions-reason-blocked' ), $reasons, $contentLang );
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
				if ( $reasons !== false ) {
						self::addReason( wfMessage( 'sanctions-reason-recently-blocked', [
							MWTimestamp::getLocalInstance( $blockExpiry )->getTimestamp( TS_ISO_8601 ),
							(string)$verificationPeriod,
						] ), $reasons, $contentLang );
				} else {
					return false;
				}
			}
		}

		if ( $reasons !== false ) {
			return count( $reasons ) == 0;
		}
		return true;
	}

	/**
	 * @param Message $msg
	 * @param array &$reasons
	 * @param bool $contentLang
	 */
	private static function addReason( Message $msg, &$reasons, $contentLang ) {
		if ( $contentLang ) {
			$msg = $msg->inContentLanguage();
		}
		$reasons[] = $msg->text();
	}
}
