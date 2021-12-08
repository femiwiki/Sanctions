<?php

namespace MediaWiki\Extension\Sanctions\Hooks;

use MediaWiki\Extension\Sanctions\Sanction;
use MediaWiki\Extension\Sanctions\SanctionStore;
use Message;
use MWTimestamp;
use WANObjectCache;
use Wikimedia\Rdbms\Database;

class Block implements \MediaWiki\Block\Hook\GetUserBlockHook {

	/** @var SanctionStore */
	private $sanctionStore;

	/** @var WANObjectCache */
	private $wanCache;

	/**
	 * @param SanctionStore $sanctionStore
	 * @param WANObjectCache $wanCache
	 */
	public function __construct(
		SanctionStore $sanctionStore,
		WANObjectCache $wanCache
	) {
		$this->sanctionStore = $sanctionStore;
		$this->wanCache = $wanCache;
	}

	/** @inheritDoc */
	public function onGetUserBlock( $user, $ip, &$block ) {
		// TODO: The current version of Sanctions is not designed for anonymous targets.
		// It should be improved in the future.
		if ( !$user->isRegistered() ) {
			return;
		}
		$store = $this->sanctionStore;
		$callback = static function ( $old, &$ttl, array &$setOpts ) use ( $user, $store ) {
			$setOpts += Database::getCacheSetOptions( $store->getDBConnectionRef( DB_REPLICA ) );
			$unhandledSanctions = $store->findByTarget( $user, null, null, false );
			if ( $unhandledSanctions ) {
				$shouldBeExecuted = [];
				$earliestExpiry = $unhandledSanctions[0]->getExpiry();
				foreach ( $unhandledSanctions as $sanction ) {
					if ( !$sanction->isExpired() ) {
						$earliestExpiry = min( $sanction->getExpiry(), $earliestExpiry );
					} elseif ( !$sanction->isHandled() ) {
						$shouldBeExecuted[] = $sanction;
					}
				}
				if ( $shouldBeExecuted ) {
					// Execution makes a change of the result of query.
					// https://github.com/femiwiki/Sanctions/issues/223
					$ttl = WANObjectCache::TTL_UNCACHEABLE;
					return $shouldBeExecuted;
				}

				// Convert to a relative time.
				$ttl = (int)MWTimestamp::getInstance( $earliestExpiry )->getTimestamp() -
					(int)MWTimestamp::getInstance()->getTimestamp();
			}
			return [];
		};

		/** @var Sanction[] $sanctionsToExecute */
		$sanctionsToExecute = $this->wanCache->getWithSetCallback(
			$this->wanCache->makeKey(
				'sanctions-block-check',
				// The name of the target can be changed while the sanction is open.
				$user->getId()
			),
			self::getDefaultTtl(),
			$callback
		);

		foreach ( $sanctionsToExecute as $sanction ) {
			$sanction->execute( false, $block );
		}
	}

	/**
	 * @return int
	 */
	protected static function getDefaultTtl(): int {
		$ttl = ( new Message( 'sanctions-voting-period' ) )->text();
		$ttl = (float)$ttl;
		$ttl *= WANObjectCache::TTL_DAY;

		return (int)$ttl;
	}
}
