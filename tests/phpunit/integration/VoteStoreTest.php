<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use Flow\Model\UUID;
use MediaWiki\Extension\Sanctions\Sanction;
use MediaWiki\Extension\Sanctions\Vote;
use MediaWiki\Extension\Sanctions\VoteStore;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use User;

/**
 * @covers \MediaWiki\Extension\Sanctions\VoteStore
 * @group Database
 */
class VoteStoreTest extends MediaWikiIntegrationTestCase {

	protected static function getVoteStore(): VoteStore {
		return new VoteStore( MediaWikiServices::getInstance()->getDBLoadBalancer() );
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\VoteStore::__construct
	 */
	public function testConstruct() {
		$actual = $this->getVoteStore();
		$this->assertInstanceOf( VoteStore::class, $actual );
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\VoteStore::getVoteBySanction
	 * @covers \MediaWiki\Extension\Sanctions\VoteStore::deleteOn
	 * @covers \MediaWiki\Extension\Sanctions\Vote::insert
	 */
	public function testGetVoteBySanction() {
		$uuid = UUID::create();
		$user = new User();
		$user->setId( 1 );
		$sanction = new Sanction();
		$sanction->setWorkflowId( $uuid );

		$vote = Vote::newFromRow( (object)[
			'stv_user' => $user->getId(),
			'stv_period' => 10,
		] );
		$vote->setSanction( $sanction );
		$vote->insert( $uuid->getTimestamp() );

		$store = new VoteStore( MediaWikiServices::getInstance()->getDBLoadBalancer() );
		$find = $store->getVoteBySanction( $sanction, $user );
		$this->assertSame( $vote->getPeriod(), $find->getPeriod() );

		$store->deleteOn( $sanction );
		$find = $store->getVoteBySanction( $sanction, $user );
		$this->assertNull( $find );
	}
}
