<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration\Hooks;

use MediaWiki\Extension\Sanctions\Hooks\Main;
use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\Extension\Sanctions\VoteStore;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Sanctions\Hooks\Main
 */
class MainTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers \MediaWiki\Extension\Sanctions\Hooks\Main::__construct
	 */
	public function testConstruct() {
		$sanctionStore = new SanctionStore( MediaWikiServices::getInstance()->getDBLoadBalancer() );
		$voteStore = new VoteStore( MediaWikiServices::getInstance()->getDBLoadBalancer() );
		$actual = new Main(
			$sanctionStore,
			$voteStore,
			MediaWikiServices::getInstance()->getUserFactory()
		);
		$this->assertInstanceOf( Main::class, $actual );
	}
}
