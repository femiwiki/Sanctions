<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use Flow\Model\UUID;
use MediaWiki\Extension\Sanctions\SanctionsPager;
use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use MessageCache;
use RequestContext;
use User;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\Sanctions\SanctionsPager
 */
class SanctionsPagerTest extends MediaWikiIntegrationTestCase {

	private function getSanctionsPager( User $viewer = null, string $targetName = null ) {
		$request = new RequestContext();
		if ( $viewer ) {
			$request->setUser( $viewer );
		}
		$sanctionStore = new SanctionStore( MediaWikiServices::getInstance()->getDBLoadBalancer() );
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		return new SanctionsPager( $request, $sanctionStore, $userFactory, $linkRenderer, $targetName );
	}

	/**
	 * TODO Mock edit counts without touching system message.
	 * @return User
	 */
	private function getVotableUser() {
		// Make MessageCache to return sanctions-voting-right-verification-edits as 0
		$mock = $this->createMock( MessageCache::class );
		$mock->method( 'get' )
			->will( $this->returnValue( '0' ) );
		$mock->method( 'transform' )
			->will( $this->returnArgument( 0 ) );
		$this->setService( 'MessageCache', $mock );

		$user = $this->createMock( User::class );

		$user->expects( $this->any() )
			->method( 'isAnon' )
			->will( $this->returnValue( false ) );
		$user->expects( $this->any() )
			->method( 'getRegistration' )
			->will( $this->returnValue( wfTimestamp( TS_MW, 1 ) ) );
		$user->expects( $this->any() )
			->method( 'isAllowed' )
			->will( $this->returnValue( true ) );
		$user->expects( $this->any() )
			->method( 'isBlocked' )
			->will( $this->returnValue( false ) );

		return $user;
	}

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
		$you->setId( 10 );
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

	/**
	 * @covers \MediaWiki\Extension\Sanctions\SanctionsPager::getExtraSortFields
	 * @covers \MediaWiki\Extension\Sanctions\SanctionsPager::getIndexField
	 * @covers \MediaWiki\Extension\Sanctions\SanctionsPager::getQueryInfo
	 */
	public function testSortOrderNotLoggedIn() {
		$pager = $this->getSanctionsPager( new User() );

		'@phan-var SanctionsPager $pager';
		$pager = TestingAccessWrapper::newFromObject( $pager );
		$queryInfo = $pager->buildQueryInfo( '', 1, \IndexPager::QUERY_DESCENDING );

		$this->assertSame( [ 'st_handled DESC', 'st_expiry DESC' ], $queryInfo[4]['ORDER BY'] );
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\SanctionsPager::getExtraSortFields
	 * @covers \MediaWiki\Extension\Sanctions\SanctionsPager::getIndexField
	 * @covers \MediaWiki\Extension\Sanctions\SanctionsPager::getQueryInfo
	 */
	public function testSortOrderRegistered() {
		$pager = $this->getSanctionsPager( $this->getVotableUser() );

		'@phan-var SanctionsPager $pager';
		$pager = TestingAccessWrapper::newFromObject( $pager );
		$queryInfo = $pager->buildQueryInfo( '', 1, \IndexPager::QUERY_DESCENDING );

		$expected = [
			'st_handled DESC',
			'not_expired DESC',
			'my_sanction DESC',
			'voted_from DESC',
			'st_expiry DESC',
		];
		$this->assertSame( $expected, $queryInfo[4]['ORDER BY'] );
	}
}
