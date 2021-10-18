<?php

namespace MediaWiki\Extension\Sanctions\Hooks;

use EchoEvent;
use MediaWiki\MediaWikiServices;
use RequestContext;
use User;

class Notification implements
	\MediaWiki\Hook\AbortEmailNotificationHook,
	\MediaWiki\User\Hook\EmailConfirmedHook
	{

	/**
	 * Abort notifications regarding occupied pages coming from the RecentChange class.
	 * Flow has its own notifications through Echo.
	 *
	 * Also don't notify for actions made by Sanction bot.
	 *
	 * Copied from
	 * https://github.com/wikimedia/mediawiki-extensions-Flow/blob/de0b9ad/Hooks.php#L963-L996
	 *
	 * @inheritDoc
	 */
	public function onAbortEmailNotification( $editor, $title, $rc ) {
		if ( $title->getContentModel() === CONTENT_MODEL_FLOW_BOARD ) {
			// Since we are aborting the notification we need to manually update the watchlist
			$config = RequestContext::getMain()->getConfig();
			if ( $config->get( 'EnotifWatchlist' ) || $config->get( 'ShowUpdatedMarker' ) ) {
				MediaWikiServices::getInstance()->getWatchedItemStore()->updateNotificationTimestamp(
					$editor,
					$title,
					wfTimestampNow()
				);
			}
			return false;
		}

		if ( self::isSanctionBot( $editor ) ) {
			return false;
		}

		return true;
	}

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
	 * Suppress all Echo notifications generated by Sanction bot
	 *
	 * Copied from
	 * https://github.com/wikimedia/mediawiki-extensions-Flow/blob/de0b9ad/Hooks.php#L1018-L1034
	 *
	 * @param EchoEvent $event
	 * @return bool
	 */
	public static function onBeforeEchoEventInsert( EchoEvent $event ) {
		$agent = $event->getAgent();

		if ( $agent === null ) {
			return true;
		}

		if ( self::isSanctionBot( $agent ) ) {
			return false;
		}

		return true;
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