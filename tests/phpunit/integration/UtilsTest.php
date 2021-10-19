<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use MediaWiki\Extension\Sanctions\Utils;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use User;

/**
 * @covers \MediaWiki\Extension\Sanctions\Utils
 */
class UtilsTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers \MediaWiki\Extension\Sanctions\Utils::getBot()
	 */
	public function testGetBot() {
		$bot = Utils::getBot();
		$this->assertInstanceOf( User::class, $bot );
		$this->assertTrue( $bot->isSystemUser() );
		$this->assertTrue( $bot->isBot() );
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\Utils::getLogger()
	 */
	public function testGetLogger() {
		$logger = Utils::getLogger();
		$this->assertInstanceOf( LoggerInterface::class, $logger );
	}
}
