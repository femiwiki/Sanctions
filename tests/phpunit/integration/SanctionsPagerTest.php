<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use Flow\Model\UUID;
use MediaWiki\Extension\Sanctions\SanctionsPager;
use MediaWikiIntegrationTestCase;
use User;

/**
 * @covers \MediaWiki\Extension\Sanctions\SanctionsPager
 */
class SanctionsPagerTest extends MediaWikiIntegrationTestCase {
	public static function provideRow() {
		$future = wfTimestamp( TS_MW, time() + 60 );
		$past = wfTimestamp( TS_MW, time() - 60 );
		return [
			'An unexpired sanction should be shown' => [
				[
					'sanction',
					'block',
				],
				[
					'st_author' => 'Other',
					'st_expiry' => $future,
				]
			],
			'An expired sanction should be shown' => [
				[
					'sanction',
					'expired',
					'block',
				],
				[
					'st_author' => 'Other',
					'st_expiry' => $past,
				]
			],
		];
	}

	/**
	 * Test must be integration test for UUID::create().
	 * @covers \MediaWiki\Extension\Sanctions\SanctionsPager::getClasses
	 * @dataProvider provideRow
	 */
	public function testGetClasses( $expected, $row ) {
		$you = new User();
		$you->setName( 'You' );
		$row += [
			'st_id' => 0,
			'st_author' => '',
			'st_topic' => UUID::create(),
			'st_target' => '',
			'st_original_name' => '',
			'st_expiry' => 'test' . wfTimestamp( TS_MW, time() + 60 ),
			'st_handled' => false,
			'st_emergency' => false,
		];
		$actual = SanctionsPager::GetClasses( (object)$row, $you );

		sort( $expected );
		sort( $actual );
		$this->assertEquals( $expected, $actual );
	}
}
