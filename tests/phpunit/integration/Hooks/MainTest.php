<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration\Hooks;

use MediaWiki\Extension\Sanctions\Hooks\Main;
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
		$voteStore = new VoteStore( MediaWikiServices::getInstance()->getDBLoadBalancer() );
		$actual = new Main(
			MediaWikiServices::getInstance()->getUserFactory(),
			$voteStore
		);
		$this->assertInstanceOf( Main::class, $actual );
	}
}
