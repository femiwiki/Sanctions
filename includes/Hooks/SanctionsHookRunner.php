<?php

namespace MediaWiki\Extension\Sanctions\Hooks;

use MediaWiki\HookContainer\HookContainer;

class SanctionsHookRunner implements
	\MediaWiki\Extension\Renameuser\Hook\RenameUserAbortHook
{
	/**
	 * @var HookContainer
	 */
	private $container;

	/**
	 * @param HookContainer $container
	 */
	public function __construct( HookContainer $container ) {
		$this->container = $container;
	}

	/**
	 * @param int $uid The user ID
	 * @param string $old The old username
	 * @param string $new The new username
	 *
	 * @return bool|void
	 */
	public function onRenameUserAbort( int $uid, string $old, string $new ) {
		return $this->container->run(
			'RenameUserAbort',
			[ $uid, $old, $new ]
		);
	}
}
