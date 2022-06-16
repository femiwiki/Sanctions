<?php

namespace MediaWiki\Extension\Sanctions\Hooks;

use MediaWiki\Extension\Sanctions\Utils;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use RequestContext;
use SpecialPage;
use Title;

class ToolLinks implements
	\MediaWiki\Diff\Hook\DiffToolsHook,
	\MediaWiki\Hook\ContributionsToolLinksHook,
	\MediaWiki\Hook\HistoryToolsHook,
	\MediaWiki\Hook\SidebarBeforeOutputHook,
	\MediaWiki\Hook\UserToolLinksEditHook
	{
		/** @var UserFactory */
		private $userFactory;

		/** @var LinkRenderer */
		private $linkRenderer;

		/**
		 * @param UserFactory $userFactory
		 * @param LinkRenderer $linkRenderer
		 */
		public function __construct( UserFactory $userFactory, LinkRenderer $linkRenderer ) {
			$this->userFactory = $userFactory;
			$this->linkRenderer = $linkRenderer;
		}

	/**
	 * (talk|contribs)
	 * @param int $userId User ID of the current user
	 * @param string $userText Username of the current user
	 * @param string[] &$items Array of user tool links as HTML fragments
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onUserToolLinksEdit( $userId, $userText, &$items ) {
		$viewer = RequestContext::getMain()->getUser();
		if ( $viewer == null || !Utils::hasVoteRight( $viewer ) ) {
			return true;
		}

		$items[] = $this->linkRenderer->makeLink(
			SpecialPage::getTitleFor( 'Sanctions', $userText ),
			wfMessage( 'sanctions-link-on-user-tool' )->text()
		);
		return true;
	}

	/**
	 * @param RevisionRecord $newRevRecord New revision
	 * @param string[] &$links Array of HTML links
	 * @param RevisionRecord|null $oldRevRecord Old revision (may be null)
	 * @param UserIdentity $userIdentity Current user
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onDiffTools( $newRevRecord, &$links, $oldRevRecord, $userIdentity ) {
		if ( !Utils::hasVoteRight( $userIdentity ) ) {
			return true;
		}

		$ids = '';
		if ( $oldRevRecord != null ) {
			$ids .= $oldRevRecord->getId() . '/';
		}
		$ids .= $newRevRecord->getId();

		$titleText = $newRevRecord->getUser()->getName() . '/' . $ids;
		$links[] = $this->linkRenderer->makeLink(
			SpecialPage::getTitleFor( 'Sanctions', $titleText ),
			wfMessage( 'sanctions-link-on-diff' )->text()
		);

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onHistoryTools( $revRecord, &$links, $prevRevRecord, $userIdentity ) {
		if ( !Utils::hasVoteRight( $userIdentity ) ) {
			return true;
		}

		$user = $revRecord->getUser();
		if ( !$user ) {
			return true;
		}
		$titleText = $revRecord->getUser()->getName() . '/' . $revRecord->getId();
		$links[] = $this->linkRenderer->makeLink(
			SpecialPage::getTitleFor( 'Sanctions', $titleText ),
			wfMessage( 'sanctions-link-on-history' )->text()
		);

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		$user = $skin->getRelevantUser();

		if ( !$user ) {
			return;
		}

		$rootUser = $user->getName();

		$sanctionsLink = [
			'sanctions' => [
				'text' => $skin->msg( 'sanctions-link-on-user-page' )->text(),
				'href' => $skin::makeSpecialUrlSubpage( 'Sanctions', $rootUser ),
				'id' => 't-sanctions'
			]
		];

		if ( !isset( $sidebar['TOOLBOX'] ) || !$sidebar['TOOLBOX'] ) {
			$sidebar['TOOLBOX'] = $sanctionsLink;
		} else {
			$toolbox = $sidebar['TOOLBOX'];

			$sidebar['TOOLBOX'] = wfArrayInsertAfter(
				$toolbox,
				$sanctionsLink,
				isset( $toolbox['blockip'] ) ? 'blockip' : 'log'
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onContributionsToolLinks( $id, Title $title, array &$tools, SpecialPage $specialPage ) {
		$tools['sanctions'] = $specialPage->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'Sanctions', $this->userFactory->newFromId( $id )->getName() ),
				wfMessage( 'sanctions-link-on-user-contributes' )->text()
			);
	}
}
