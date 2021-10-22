<?php

namespace MediaWiki\Extension\Sanctions;

use User;
use Flow\Model\UUID;
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

	/**
	 * @param int $id
	 * @return Sanction|null
	 */
	public function newFromId( $id ) {
		$db = $this->getDBConnectionRef( DB_REPLICA );

		$row = $db->selectRow(
			'sanctions',
			'*',
			[ 'st_id' => $id ]
		);
		if ( !$row ) {
			return null;
		}
		return Sanction::newFromRow( $row );
	}

	/**
	 * Find out if there is an inappropriate username change suggestion for the user.
	 *
	 * @param User $user
	 * @return Sanction|null
	 */
	public function findExistingSanctionForInsultingNameOf( $user ) {
		$db = $this->getDBConnectionRef( DB_REPLICA );
		$targetId = $user->getId();

		$row = $db->selectRow(
			'sanctions',
			'*',
			[
				'st_target' => $targetId,
				"st_original_name <> ''",
				'st_expiry > ' . wfTimestamp( TS_MW )
			]
		);
		if ( !$row ) {
			return null;
		}
		return Sanction::newFromRow( $row );
	}

	/**
	 * @param UUID|string $uuid
	 * @return Sanction|null
	 */
	public function newFromWorkflowId( $uuid ) {
		if ( $uuid instanceof UUID ) {
			$uuid = $uuid->getBinary();
		} elseif ( is_string( $uuid ) ) {
			$uuid = UUID::create( strtolower( $uuid ) )->getBinary();
		}

		$rt = new Sanction();
		if ( $rt->loadFrom( 'st_topic', $uuid ) ) {
			return $rt;
		}
		return null;
	}

}
