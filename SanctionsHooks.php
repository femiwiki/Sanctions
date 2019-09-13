<?php

use Flow\Model\UUID;
use Flow\Exception\InvalidInputException;

class SanctionsHooks {
	/**
	 * Create tables in the database
	 *
	 * @param DatabaseUpdater|null $updater
	 * @throws MWException
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater = null ) {
		$dir = __DIR__;

		if ( $updater->getDB()->getType() == 'mysql' ) {
			$updater->addExtensionUpdate(
				[ 'addTable', 'sanctions',
				"$dir/sanctions.tables.sql", true ]
			);
		} // @todo else
		return true;
	}

	/**
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function onFlowAddModules( OutputPage $out ) {
		$title = $out->getTitle();
		$specialSanctionTitle = SpecialPage::getTitleFor( 'Sanctions' ); // Special:Sanctions
		$discussionPageName = wfMessage( 'sanctions-discussion-page-name' )->text(); // ProjectTalk:foobar

		if ( $title == null ) {
			return true;
		}

		// The Flow board for sanctions
		if ( $title->getFullText() == $discussionPageName ) {
			// Flow does not support redirection, so implement it.
			// See https://phabricator.wikimedia.org/T102300
			$request = RequestContext::getMain()->getRequest();
			$redirect = $request->getVal( 'redirect' );
			if ( !$redirect || $redirect == 'no ' ) {
				$out->redirect( $specialSanctionTitle->getLocalURL( $query ) );
			}

			$out->addModules( 'ext.sanctions.flow-board' );

			return true;
		}

		// Each Flow topic
		$uuid = null;
		try {
			$uuid = UUID::create( strtolower( $title->getText() ) );
		} catch ( InvalidInputException $e ) {
			return true;
		}

		// Do nothing when UUID is invalid
		if ( !$uuid ) {
			return true;
		}

		// Do nothing when the topic is not about sanction
		$sanction = Sanction::newFromUUID( $uuid );
		if ( $sanction === false ) {
			return true;
		}

		$out->addModules( 'ext.sanctions.flow-topic' );

		if ( !$sanction->isExpired() ) {
			$sanction->checkNewVotes();
		}
		// else @todo mark as expired

		return true;
	}

	// (talk|contribs)
	public static function onUserToolLinksEdit( $userId, $userText, &$items ) {
		global $wgUser;
		if ( $wgUser == null || !SanctionsUtils::hasVoteRight( $wgUser ) ) {
			return true;
		}

		$items[] = Linker::link(
			SpecialPage::getTitleFor( 'Sanctions', $userText ),
			wfMessage( 'sanctions-link-on-user-tool' )->text()
		);
		return true;
	}

	/**
	 * (edit) (undo) (thank)
	 * @param Revesion $newRev Revision object of the "new" revision
	 * @param array &$links Array of HTML links
	 * @param Revision $oldRev Revision object of the "old" revision (may be null)
	 * @return bool
	 */
	public static function onDiffRevisionTools( Revision $newRev, &$links, $oldRev ) {
		global $wgUser;
		if ( $wgUser == null || !SanctionsUtils::hasVoteRight( $wgUser ) ) {
			return true;
		}

		$ids = '';
		if ( $oldRev != null ) {
			$ids .= $oldRev->getId() . '/';
		}
		$ids .= $newRev->getId();

		$titleText = $newRev->getUserText() . '/' . $ids;
		$links[] = Linker::link(
			SpecialPage::getTitleFor( 'Sanctions', $titleText ),
			wfMessage( 'sanctions-link-on-diff' )->text()
	);

		return true;
	}

	/**
	 * @param Revision $rev Revision object
	 * @param array &$links Array of HTML links
	 * @return bool
	 */
	public static function onHistoryRevisionTools( $rev, &$links ) {
		global $wgUser;

		if ( $wgUser == null || !SanctionsUtils::hasVoteRight( $wgUser ) ) {
			return true;
		}

		$titleText = $rev->getUserText() . '/' . $rev->getId();
		$links[] = Linker::link(
			SpecialPage::getTitleFor( 'Sanctions', $titleText ),
			wfMessage( 'sanctions-link-on-history' )->text()
		);

		return true;
	}

	/**
	 * @param BaseTemplate $baseTemplate The BaseTemplate base skin template.
	 * @param array &$toolbox An array of toolbox items.
	 */
	public static function onBaseTemplateToolbox( BaseTemplate $baseTemplate, array &$toolbox ) {
		$user = $baseTemplate->getSkin()->getRelevantUser();
		if ( $user ) {
			$rootUser = $user->getName();

			$toolbox = wfArrayInsertAfter(
				$toolbox,
				[ 'sanctions' => [
					'text' => wfMessage( 'sanctions-link-on-user-page' )->text(),
					'href' => Skin::makeSpecialUrlSubpage( 'Sanctions', $rootUser ),
					'id' => 't-sanctions'
				] ],
				isset( $toolbox['blockip'] ) ? 'blockip' : 'log'
			);
		}
	}

	/**
	 * @param int $id - User identifier
	 * @param Title $title - User page title
	 * @param array &$tools - Array of tool links
	 * @param SpecialPage $sp - The SpecialPage object
	 */
	public static function onContributionsToolLinks( $id, $title, &$tools, $sp ) {
		$tools['sanctions'] = $sp->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'Sanctions', User::newFromId( $id ) ),
				wfMessage( 'sanctions-link-on-user-contributes' )->text()
			);
	}
}
