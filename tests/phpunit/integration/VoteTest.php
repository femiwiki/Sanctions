<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use Flow\Model\UUID;
use InvalidArgumentException;
use MediaWiki\Extension\Sanctions\Vote;
use MediaWikiIntegrationTestCase;

/** @covers \MediaWiki\Extension\Sanctions\Vote */
class VoteTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers \MediaWiki\Extension\Sanctions\Vote::newFromRow
	 * @covers \MediaWiki\Extension\Sanctions\Vote::loadFromRow
	 */
	public function testNewFromRow() {
		$this->expectException( InvalidArgumentException::class );
		Vote::newFromRow( null );
		$actual = Vote::newFromRow( (object)[
			'stv_user' => 0,
			'stv_topic' => UUID::create(),
			'stv_period' => 1,
		] );
		$this->assertInstanceOf( Vote::class, $actual );
		$this->assertNull( $actual->getSanction() );
	}

	public static function provideReplies() {
		return [
			'Agreement with days should be caught' => [
				10,
				'<span class="sanction-vote-agree-period">10</span>',
				'html',
			],
			'Agreement with days in wikitext should be caught' => [
				10,
				'{{Support|10}}',
				'wikitext',
			],
			'Disagreement in wikitext should be caught' => [
				0,
				'{{Oppose}}',
				'wikitext',
			],
			'Agreement without days in wikitext should be caught' => [
				1,
				'{{Support}}',
				'wikitext',
			],
			'Plain reply should be handled' => [
				null,
				'lorem ipsum',
				'html',
			],
		];
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\Vote::extractPeriodFromReply
	 * @dataProvider provideReplies
	 */
	public function testCheckNewVote( $expected, $content, $flowContentType = 'html' ) {
		$this->setMwGlobals( 'wgFlowContentFormat', $flowContentType );
		$actual = Vote::extractPeriodFromReply( $content );
		$this->assertSame( $expected, $actual );
	}
}
