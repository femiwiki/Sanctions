<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use Flow\Model\UUID;
use MediaWiki\Extension\Sanctions\Sanction;
use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use User;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\Sanctions\SanctionStore
 * @group Database
 */
class SanctionStoreTest extends MediaWikiIntegrationTestCase {

	protected function getSanctionStore(): SanctionStore {
		$store = new SanctionStore( MediaWikiServices::getInstance()->getDBLoadBalancer() );
		$this->assertInstanceOf( SanctionStore::class, $store );
		return $store;
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\SanctionStore::getDBLoadBalancer
	 */
	public function testGetDBLoadBalancer() {
		$sanctionStore = $this->getSanctionStore();

		'@phan-var SanctionsStore $sanctionStore';
		$sanctionStore = TestingAccessWrapper::newFromObject( $sanctionStore );
		$actual = $sanctionStore->getDBLoadBalancer();
		$this->assertInstanceOf( ILoadBalancer::class, $actual );
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\SanctionStore::__construct
	 * @covers \MediaWiki\Extension\Sanctions\SanctionStore::newFromId
	 */
	public function testNewFromId() {
		$store = $this->getSanctionStore();
		$find = $store->newFromId( 111 );
		$this->assertNull( $find, 'should be null if the given id is not found' );

		$author = new User();
		$author->setId( 10 );
		$author->setName( 'SanctionStore-find-test-author' );
		$target = new User();
		$target->setId( 11 );
		$target->setName( 'SanctionStore-find-test-target' );
		$uuid = UUID::create();
		$expiry = wfGetDB( DB_REPLICA )->timestamp();

		$sanction = new Sanction();
		$sanction->setAuthor( $author );
		$sanction->setTarget( $target );
		$sanction->setWorkflowId( $uuid );
		$sanction->setExpiry( $expiry );
		$id = $sanction->insert();
		$this->assertIsInt( $id );

		$store = $this->getSanctionStore();
		$find = $store->newFromId( $id );
		$this->assertInstanceOf( Sanction::class, $find );
		$this->assertEquals( $author, $sanction->getAuthor() );
		$this->assertEquals( $target, $sanction->getTarget() );
		$this->assertEquals( $uuid, $sanction->getWorkflowId() );
		$this->assertEquals( $expiry, $sanction->getExpiry() );
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\SanctionStore::findByTarget
	 */
	public function testFindByTarget() {
		$store = $this->getSanctionStore();
		$find = $store->newFromId( 111 );
		$this->assertNull( $find, 'should be null if the given id is not found' );

		$author = new User();
		$author->setId( 10 );
		$author->setName( 'SanctionStore-find-test-author' );
		$target = new User();
		$target->setId( 11 );
		$target->setName( 'SanctionStore-find-test-target' );
		$uuid = UUID::create();
		$expiry = wfGetDB( DB_REPLICA )->timestamp( wfTimestamp( TS_MW ) + 100 );

		$sanction = new Sanction();
		$sanction->setAuthor( $author );
		$sanction->setTarget( $target );
		$sanction->setTargetOriginalName( 'SanctionStore-find-test-target' );
		$sanction->setWorkflowId( $uuid );
		$sanction->setExpiry( $expiry );
		$id = $sanction->insert();
		$this->assertIsInt( $id );

		$store = $this->getSanctionStore();
		$find = $store->findByTarget( $target, true, false );

		$this->assertCount( 1, $find );
		$find = $find[0];
		$this->assertInstanceOf( Sanction::class, $find );
		$this->assertEquals( $id, $sanction->getId() );
		$this->assertEquals( $author, $sanction->getAuthor() );
		$this->assertEquals( $target, $sanction->getTarget() );
		$this->assertTrue( $sanction->isForInsultingName() );
		$this->assertEquals( $uuid, $sanction->getWorkflowId() );
		$this->assertEquals( $expiry, $sanction->getExpiry() );
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\SanctionStore::newFromWorkflowId
	 */
	public function testNewFromWorkflowId() {
		$store = $this->getSanctionStore();
		$find = $store->newFromId( 111 );
		$this->assertNull( $find, 'should be null if the given id is not found' );

		$author = new User();
		$author->setId( 10 );
		$author->setName( 'SanctionStore-find-test-author' );
		$target = new User();
		$target->setId( 11 );
		$target->setName( 'SanctionStore-find-test-target' );
		$uuid = UUID::create();
		$expiry = wfGetDB( DB_REPLICA )->timestamp( wfTimestamp( TS_MW ) + 100 );

		$sanction = new Sanction();
		$sanction->setAuthor( $author );
		$sanction->setTarget( $target );
		$sanction->setTargetOriginalName( 'SanctionStore-find-test-target' );
		$sanction->setWorkflowId( $uuid );
		$sanction->setExpiry( $expiry );
		$id = $sanction->insert();
		$this->assertIsInt( $id );

		$store = $this->getSanctionStore();
		$find = $store->newFromWorkflowId( $uuid );
		$this->assertInstanceOf( Sanction::class, $find );
		$this->assertEquals( $id, $sanction->getId() );
		$this->assertEquals( $author, $sanction->getAuthor() );
		$this->assertEquals( $target, $sanction->getTarget() );
		$this->assertEquals( $expiry, $sanction->getExpiry() );
	}
}
