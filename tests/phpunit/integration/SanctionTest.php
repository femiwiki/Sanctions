<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use MediaWiki\Extension\Sanctions\Sanction;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Sanctions\Sanction::getBot
 */
class SanctionTest extends MediaWikiIntegrationTestCase {
	public function testGetBot() {
		$bot = Sanction::getBot();
		$this->assertTrue( $bot->isSystemUser() );
	}
}
