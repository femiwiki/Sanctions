<?php

namespace MediaWiki\Extension\Sanctions\Hooks;

use User;

class Notification implements
	\MediaWiki\User\Hook\EmailConfirmedHook
	{

	/**
	 * allow edit even when $wgEmailAuthentication is set to true
	 *
	 * @param User $user User being checked
	 * @param bool &$confirmed Whether or not the email address is confirmed
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onEmailConfirmed( $user, &$confirmed ) {
		if ( !self::isSanctionBot( $user ) ) {
			return true;
		}

		$confirmed = true;
		return false;
	}

	/**
	 * Defining the events for this extension
	 *
	 * @param array &$notifs
	 * @param array &$categories
	 * @param array &$icons
	 */
	public static function onBeforeCreateEchoEvent( &$notifs, &$categories, &$icons ) {
		$categories['sanctions-against-me'] = [
			'priority' => 1,
			'no-dismiss' => [ 'web' ],
			'tooltip' => 'sanctions-pref-tooltip-sanctions-against-me',
		];

		$notifs['sanctions-proposed'] = [
			'category' => 'sanctions-against-me',
			'group' => 'negative',
			'section' => 'alert',
			'presentation-model' => \MediaWiki\Extension\Sanctions\Notifications\ProposedPresentationModel::class,
			'user-locators' => [ [ 'EchoUserLocator::locateFromEventExtra', [ 'target-id' ] ] ],
		];
	}

	/**
	 * @param User $user
	 * @return bool
	 */
	private static function isSanctionBot( User $user ) {
		return $user->getName() === wfMessage( 'sanctions-bot-name' )->inContentLanguage()->text();
	}
}
