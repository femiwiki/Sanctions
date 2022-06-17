<?php

namespace MediaWiki\Extension\Sanctions;

use ManualLogEntry;
use MediaWiki\Block\AbstractBlock;
use MediaWiki\Block\CompositeBlock;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Extension\Renameuser\RenameuserSQL;
use MediaWiki\Extension\Sanctions\Hooks\SanctionsHookRunner;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use Message;
use MWTimestamp;
use Psr\Log\LoggerInterface;
use Title;
use TitleValue;
use User;
use Wikimedia\IPUtils;

class Utils {
	/**
	 * @param UserIdentity $user
	 * @param string[]|bool &$reasons An array of reasons why can't participate.
	 * @param bool $contentLang
	 * @return bool
	 */
	public static function hasVoteRight( UserIdentity $user, &$reasons = false, $contentLang = false ) {
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$user = $userFactory->newFromUserIdentity( $user );

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

		$db = wfGetDB( DB_REPLICA );

		// There have been more than three contribution histories within the last 20 days (currently
		// active)
		$count = $db->selectRowCount(
			[
				'revision',
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
				'actor' => [ 'LEFT JOIN', [ 'rev_actor = actor_id ' ] ],
				'user' => [ 'LEFT JOIN', [ 'actor_user = user_id ' ] ],
			]
		);
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

		if ( $user->getBlock() !== null ) {
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

		if ( is_array( $reasons ) ) {
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

	/**
	 * Rename the given User.
	 * This function includes some code that originally are in SpecialRenameuser.php
	 *
	 * @param string $oldName
	 * @param string $newName
	 * @param User $target
	 * @param User $renamer
	 * @param string $reason
	 * @return bool
	 */
	public static function doRename( $oldName, $newName, $target, $renamer, $reason ) {
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();

		$bot = self::getBot();
		$targetId = $target->idForName();
		$oldUser = $userFactory->newFromName( $oldName );
		$newUser = $userFactory->newFromName( $newName );
		$oldUserPageTitle = Title::makeTitle( NS_USER, $oldName );
		$newUserPageTitle = Title::makeTitle( NS_USER, $newName );

		if ( $targetId === 0 || $newUser->idForName() !== 0 ) {
			return false;
		}

		// Give other affected extensions a chance to validate or abort
		$hookRunner = new SanctionsHookRunner(
			MediaWikiServices::getInstance()->getHookContainer()
		);
		if ( $hookRunner->onRenameUserAbort( $targetId, $oldName, $newName ) ) {
			return false;
		}

		// Do the heavy lifting...
		$rename = new RenameuserSQL(
			$oldName,
			$newName,
			$targetId,
			$renamer,
			[ 'reason' => $reason ]
		);
		if ( !$rename->rename() ) {
			return false;
		}

		// If this user is renaming his/herself, make sure that Title::moveTo()
		// doesn't make a bunch of null move edits under the old name!
		if ( $renamer->getId() === $targetId ) {
			$renamer->setName( $newName );
		}

		// Move any user pages
		if ( $bot->isAllowed( 'move' ) ) {
			$dbr = wfGetDB( DB_REPLICA );
			$pages = $dbr->select(
				'page',
				[ 'page_namespace', 'page_title' ],
				[
					'page_namespace' => [ NS_USER, NS_USER_TALK ],
					$dbr->makeList(
						[
							'page_title ' . $dbr->buildLike( $oldUserPageTitle->getDBkey() . '/', $dbr->anyString() ),
							'page_title = ' . $dbr->addQuotes( $oldUserPageTitle->getDBkey() ),
						], LIST_OR
					),
				],
				__METHOD__
			);
			$movePageFactory = MediaWikiServices::getInstance()->getMovePageFactory();
			foreach ( $pages as $row ) {
				$oldPage = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
				$newPage = Title::makeTitleSafe(
					$row->page_namespace,
					preg_replace( '!^[^/]+!', $newUserPageTitle->getDBkey(), $row->page_title )
				);

				$movePage = $movePageFactory->newMovePage( $oldPage, $newPage );

				if ( !$movePage->isValidMove() ) {
					return false;
				} else {
					$success = $movePage->move(
						$bot,
						wfMessage(
							'renameuser-move-log',
							$oldUserPageTitle->getText(),
							$newUserPageTitle->getText()
						)->inContentLanguage()->text(),
						true
					);

					if ( !$success->isGood() ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * @param User $target
	 * @param string $expiry
	 * @param string $reason
	 * @param bool $preventEditOwnUserTalk
	 * @param User|null $user
	 *
	 * @return bool
	 */
	public static function doBlock(
			$target,
			$expiry,
			$reason,
			$preventEditOwnUserTalk = true,
			$user = null
		) {
		$bot = self::getBot();
		$blockStore = MediaWikiServices::getInstance()->getDatabaseBlockStore();

		$block = new DatabaseBlock();
		$block->setTarget( $target );
		$block->setBlocker( $bot );
		$block->setReason( $reason );
		$block->isHardblock( true );
		$block->isAutoblocking( boolval( wfMessage( 'sanctions-autoblock' )->text() ) );
		$block->isCreateAccountBlocked( true );
		$block->isUsertalkEditAllowed( $preventEditOwnUserTalk );
		$block->setExpiry( $expiry );

		$success = $blockStore->insertBlock( $block );

		if ( !$success ) {
			return false;
		}

		$logParams = [];
		$time = MWTimestamp::getInstance( $expiry );
		// Even if done as below, it comes out in local time.
		$logParams['5::duration'] = $time->getTimestamp( TS_ISO_8601 );
		$flags = [ 'nocreate' ];
		if ( !$block->isAutoblocking() && !IPUtils::isIPAddress( $target ) ) {
			// Conditionally added same as SpecialBlock
			$flags[] = 'noautoblock';
		}
		$logParams['6::flags'] = implode( ',', $flags );

		$logEntry = new ManualLogEntry( 'block', 'block' );
		$logEntry->setTarget( Title::makeTitle( NS_USER, $target ) );
		$logEntry->setComment( $reason );
		$logEntry->setPerformer( $user == null ? $bot : $user );
		$logEntry->setParameters( $logParams );
		$blockIds = array_merge( [ $success['id'] ], $success['autoIds'] );
		$logEntry->setRelations( [ 'ipb_id' => $blockIds ] );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId );

		return true;
	}

	/**
	 * @param User $target
	 * @param bool $withLog
	 * @param string|null $reason
	 * @param User|null $user
	 * @param AbstractBlock|null $block
	 * @return bool
	 */
	public static function unblock( $target, $withLog = false, $reason = null, $user = null, $block = null ) {
		$blockStore = MediaWikiServices::getInstance()->getDatabaseBlockStore();

		if ( $block != null ) {
			if ( $block instanceof CompositeBlock ) {
				foreach ( $block->getOriginalBlocks() as $originalBlock ) {
					if ( $originalBlock instanceof DatabaseBlock ) {
						'@phan-var DatabaseBlock $originalBlock';
						return $blockStore->deleteBlock( $originalBlock );
					}
				}
			} elseif ( $block instanceof DatabaseBlock ) {
				'@phan-var DatabaseBlock $block';
				return $blockStore->deleteBlock( $block );
			}
		}

		$page = TitleValue::tryNew( NS_USER, $block->getTargetName() );

		if ( $withLog ) {
			$bot = self::getBot();

			$logEntry = new ManualLogEntry( 'block', 'unblock' );
			if ( $page !== null ) {
				$logEntry->setTarget( $page );
			}
			$logEntry->setComment( $reason );
			$logEntry->setPerformer( $user == null ? $bot : $user );
			$logId = $logEntry->insert();
			$logEntry->publish( $logId );
		}
	}

	/**
	 * @return LoggerInterface
	 */
	public static function getLogger(): LoggerInterface {
		static $logger = null;
		if ( !$logger ) {
			$logger = LoggerFactory::getInstance( 'Sanctions' );
		}
		return $logger;
	}

	/**
	 * @return User
	 */
	public static function getBot() {
		static $bot;
		if ( !$bot ) {
			$botName = wfMessage( 'sanctions-bot-name' )->inContentLanguage()->text();
			$bot = User::newSystemUser( $botName, [ 'steal' => true ] );

			$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
			$groups = $userGroupManager->getUserGroups( $bot );
			foreach ( [ 'bot', 'flow-bot' ] as $group ) {
				if ( !in_array( $group, $groups ) ) {
					$userGroupManager->addUserToGroup( $bot, $group );
				}
			}
		}
		return $bot;
	}
}
