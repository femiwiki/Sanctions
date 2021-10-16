<?php

namespace Flow\Tests;

use MediaWiki\Extension\Sanctions\Hooks\Sanctions;
use MediaWiki\Tests\HookContainer\HookRunnerTestBase;

/**
 * @covers \MediaWiki\Extension\Sanctions\Hooks\Sanctions
 */
class SanctionsHookRunnerTest extends HookRunnerTestBase {

	public function provideHookRunners() {
		yield Sanctions::class => [ Sanctions::class ];
	}
}
