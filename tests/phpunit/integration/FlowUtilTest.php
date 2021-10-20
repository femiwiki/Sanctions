<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use Flow\Data\ManagerGroup;
use Flow\WorkflowLoaderFactory;
use MediaWiki\Extension\Sanctions\FlowUtil;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Sanctions\FlowUtil
 */
class FlowUtilTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers \MediaWiki\Extension\Sanctions\FlowUtil::getStorage()
	 */
	public function testGetStorage() {
		$storage = FlowUtil::getStorage();

		$this->assertInstanceOf( ManagerGroup::class, $storage );
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\FlowUtil::getWorkflowLoaderFactory()
	 */
	public function testGetWorkflowLoaderFactory() {
		$storage = FlowUtil::getWorkflowLoaderFactory();

		$this->assertInstanceOf( WorkflowLoaderFactory::class, $storage );
	}
}
