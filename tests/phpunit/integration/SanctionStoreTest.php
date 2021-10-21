<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;

/** @covers \MediaWiki\Extension\Sanctions\SanctionStore */
class SanctionStoreTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers \MediaWiki\Extension\Sanctions\SanctionStore::__construct
	 */
	public function testConstruct() {
		$actual = new SanctionStore( MediaWikiServices::getInstance()->getDBLoadBalancer() );
		$this->assertInstanceOf( SanctionStore::class, $actual );
	}
}
