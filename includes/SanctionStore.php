<?php

namespace MediaWiki\Extension\Sanctions;

use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\ILoadBalancer;

class SanctionStore {

	/** @var ILoadBalancer */
	private $loadBalancer;

	/**
	 * @param ILoadBalancer $loadBalancer
	 */
	public function __construct( ILoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * @return ILoadBalancer
	 */
	private function getDBLoadBalancer() {
		return $this->loadBalancer;
	}

	/**
	 * @param int $mode DB_PRIMARY or DB_REPLICA
	 *
	 * @param array $groups
	 * @return DBConnRef
	 */
	private function getDBConnectionRef( $mode, $groups = [] ) {
		$lb = $this->getDBLoadBalancer();
		return $lb->getConnectionRef( $mode, $groups );
	}

}
