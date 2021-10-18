<?php

namespace MediaWiki\Extension\Sanctions\Hooks;

use Config;
use Flow\Exception\InvalidInputException;
use Flow\Model\UUID;
use MediaWiki\Extension\Sanctions\FlowUtil;
use MediaWiki\Extension\Sanctions\Sanction;
use MediaWiki\Extension\Sanctions\Utils;
use MediaWiki\Extension\Sanctions\Vote;
use MediaWiki\Extension\Sanctions\VoteStore;
use MediaWiki\User\UserFactory;
use OutputPage;
use RequestContext;
use SanctionsCreateTemplates;
use SpecialPage;
use Title;
use User;

class Main implements
	\MediaWiki\Hook\RecentChange_saveHook,
	\MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook,
	\MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook
{

	/** @var UserFactory */
	private $userFactory;

	/** @var VoteStore */
	private $voteStore;

	/**
	 * @param UserFactory $userFactory
	 * @param VoteStore $voteStore
	 */
	public function __construct( UserFactory $userFactory, VoteStore $voteStore ) {
		$this->userFactory = $userFactory;
		$this->voteStore = $voteStore;
	}

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

	/**
	 * We use this hook as a alternative of PageSaveComplete for Flow. (T283459)
	 * @inheritDoc
	 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	 */
	public function onRecentChange_save( $recentChange ) {
		if ( $recentChange->getAttribute( 'rc_type' ) != RC_FLOW ) {
			return;
		}

		$params = $recentChange->parseParams();
		if ( !isset( $params['flow-workflow-change'] ) ) {
			return;
		}
		$params = $params['flow-workflow-change'];
		$user = $this->userFactory->newFromUserIdentity( $recentChange->getPerformerIdentity() );
		switch ( $params['action' ] ) {
			case 'reply':
				$this->handleReply( $params, $user );
				break;
		}
	}

	/**
	 * @param array $change
	 * @param User $user
	 */
	public function handleReply( array $change, User $user ) {
		$sanction = Sanction::newFromUUID( $change['workflow'] );
		if ( $sanction->isHandled() ) {
			return;
		}

		$post = FlowUtil::findPostRevisionFromUUID( $change['revision'] );

		$period = Vote::extractPeriodFromReply( $post->getContentRaw() );
		if ( $period === null ) {
			return;
		}

		// Reply as the bot.
		if ( $sanction->getAuthor()->equals( $user ) && $period > 0 ) {
			$content = wfMessage( 'sanctions-topic-auto-reply-no-count' )->inContentLanguage()->text() .
				PHP_EOL . '* ' .
				wfMessage( 'sanctions-topic-auto-reply-unable-self-agree' )->inContentLanguage()->text();
			FlowUtil::replyTo(
				$post,
				Utils::getBot(),
				$content
			);
			return;
		}
		\MediaWiki\Logger\LoggerFactory::getInstance( 'femiwiki-log' )->warning( 'not author' );
		if ( !Utils::hasVoteRight( $user, $reasons, true ) ) {
			$content = wfMessage( 'sanctions-topic-auto-reply-no-count' )->inContentLanguage()->text() .
				PHP_EOL . '* ' . implode( PHP_EOL . '* ', $reasons ?? [] );
			FlowUtil::replyTo(
				$post,
				Utils::getBot(),
				$content
			);
			return;
		}

		\MediaWiki\Logger\LoggerFactory::getInstance( 'femiwiki-log' )->warning( 'has right' );
		$lastVote = $this->voteStore->getVoteBySanction( $sanction, $user );
		\MediaWiki\Logger\LoggerFactory::getInstance( 'femiwiki-log' )->warning( print_r( $lastVote, true ) );
		$timestamp = $post->getPostId()->getTimestamp();
		if ( $lastVote ) {
			$lastVote->updateByPostRevision( $post, $timestamp );
		} else {
			$vote = new Vote();
			// TODO call $vote->loadFromPost( $post ) instead.
			$vote->setSanction( $sanction );
			$vote->setUser( $user );
			$vote->setPeriod( Vote::extractPeriodFromReply( $post->getContentRaw() ) );
			$vote->insert( $timestamp );
		}
		$sanction->onVotesChanged();
	}
}
