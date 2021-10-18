<?php

namespace MediaWiki\Extension\Sanctions\Hooks;

use Config;
use Flow\Exception\InvalidInputException;
use Flow\Model\UUID;
use MediaWiki\Extension\Sanctions\Sanction;
use OutputPage;
use RequestContext;
use SanctionsCreateTemplates;
use SpecialPage;
use Title;

class Main implements
	\MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook,
	\MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook
	{
	/**
	 * Create tables in the database
	 *
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__;

		if ( $updater->getDB()->getType() == 'mysql' ) {
			$updater->addExtensionUpdate(
				[ 'addTable', 'sanctions',
				"$dir/../../sql/sanctions.tables.sql", true ]
			);
		}
		// @todo else

		require_once "$dir/../../maintenance/SanctionsCreateTemplates.php";
		$updater->addPostDatabaseUpdateMaintenance( SanctionsCreateTemplates::class );

		return true;
	}

	/**
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function onFlowAddModules( OutputPage $out ) {
		$title = $out->getTitle();
		if ( $title == null ) {
			return true;
		}

		$specialSanctionTitle = SpecialPage::getTitleFor( 'Sanctions' );
		$discussionPageName = wfMessage( 'sanctions-discussion-page-name' )
			->inContentLanguage()->text();

		// The Flow board for sanctions.
		if ( $title->equals( Title::newFromText( $discussionPageName ) ) ) {
			// Flow does not support redirection, so implement it.
			// See https://phabricator.wikimedia.org/T102300
			$request = RequestContext::getMain()->getRequest();
			$redirect = $request->getVal( 'redirect' );
			if ( !$redirect || $redirect !== 'no' ) {
				$out->redirect( $specialSanctionTitle->getLocalURL() );
			}

			$out->addModules( 'ext.sanctions.flow-board' );

			return true;
		}

		// Each Flow topic.
		$uuid = null;
		try {
			$uuid = UUID::create( strtolower( $title->getText() ) );
		} catch ( InvalidInputException $e ) {
			return true;
		}

		// Do nothing if the UUID is invalid.
		if ( !$uuid ) {
			return true;
		}

		// Do nothing if the topic is not about sanction.
		$sanction = Sanction::newFromUUID( $uuid );
		if ( $sanction === false ) {
			return true;
		}

		$out->addModules( 'ext.sanctions.flow-topic' );

		if ( !$sanction->isHandled() ) {
			$sanction->checkNewVotes();
		}
		// else @todo mark as expired

		return true;
	}

	/**
	 * export static key and id to JavaScript
	 * @inheritDoc
	 */
	public function onResourceLoaderGetConfigVars( array &$vars, $skin, Config $config ): void {
		$vars['wgSanctionsAgreeTemplate'] = wfMessage( 'sanctions-agree-template-title' )
			->inContentLanguage()->text();
		$vars['wgSanctionsDisagreeTemplate'] = wfMessage( 'sanctions-disagree-template-title' )
			->inContentLanguage()->text();
		$vars['wgSanctionsInsultingNameTopicTitle'] = wfMessage( 'sanctions-type-insulting-name' )
			->inContentLanguage()->text();
		$vars['wgSanctionsMaxBlockPeriod'] = (int)wfMessage( 'sanctions-max-block-period' )
			->inContentLanguage()->text();
	}
}
