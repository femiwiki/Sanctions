<?php

use MediaWiki\Extension\Sanctions\Hooks\SanctionsHookRunner;
use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\Extension\Sanctions\VoteStore;
use MediaWiki\MediaWikiServices;

// @codeCoverageIgnoreStart

return [
	'SanctionsHookRunner' => static function ( MediaWikiServices $services ): SanctionsHookRunner {
		return new SanctionsHookRunner( $services->getHookContainer() );
	},
	'VoteStore' => static function ( MediaWikiServices $services ): VoteStore {
		return new VoteStore( $services->getDBLoadBalancer() );
	},
	'SanctionStore' => static function ( MediaWikiServices $services ): SanctionStore {
		return new SanctionStore( $services->getDBLoadBalancer() );
	},
];

// @codeCoverageIgnoreEnd
