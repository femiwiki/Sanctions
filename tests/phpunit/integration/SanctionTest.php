<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use MediaWiki\Extension\Sanctions\Sanction;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Sanctions\Sanction
 */
class SanctionTest extends MediaWikiIntegrationTestCase {
	/**
	 * @covers \MediaWiki\Extension\Sanctions\Sanction::newFromRow
	 */
	public function testNewFromRow() {
		$sanction = Sanction::newFromRow( (object)[
			'st_author' => 10,
			'st_target' => 11,
		] );
		$this->assertInstanceOf( Sanction::class, $sanction );
		$this->assertSame( 10, $sanction->getAuthor()->getId() );
		$this->assertSame( 11, $sanction->getTarget()->getId() );
	}
}
