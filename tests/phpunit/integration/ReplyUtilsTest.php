<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use MediaWiki\Extension\Sanctions\ReplyUtils;
use MediaWikiIntegrationTestCase;

/** @covers \MediaWiki\Extension\Sanctions\ReplyUtils */
class ReplyUtilsTest extends MediaWikiIntegrationTestCase {

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
		];
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\ReplyUtils::checkNewVote
	 * @dataProvider provideReplies
	 */
	public function testCheckNewVote( $expected, $content, $flowContentType = 'html' ) {
		$this->setMwGlobals( 'wgFlowContentFormat', $flowContentType );
		$actual = ReplyUtils::checkNewVote( $content, $newContent );
		$this->assertSame( $expected, $actual );
	}
}
