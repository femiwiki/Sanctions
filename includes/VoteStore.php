<?php

namespace MediaWiki\Extension\Sanctions;

use User;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

class VoteStore {

	/** @var ILoadBalancer */
	private $loadBalancer;

	/**
	 * @param ILoadBalancer $loadBalancer
	 */
	public function __construct( ILoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * @param Sanction $sanction
	 * @param User $user
	 * @return Vote|null
	 */
	public function getVoteBySanction( Sanction $sanction, User $user ) {
		$db = $this->getDBConnectionRef( DB_REPLICA );
		$row = $db->selectRow(
			'sanctions_vote',
			[
				'stv_user',
				'stv_topic',
				'stv_period',
			],
			[
				'stv_topic' => $sanction->getWorkflowId()->getBinary(),
				'stv_user' => $user->getId(),
			]
		);
		if ( !$row ) {
			return null;
		}
		return Vote::newFromRow( $row );
	}

	/**
	 * @param Sanction $sanction
	 * @param IDatabase|null $dbw
	 * @return bool
	 */
	public function deleteOn( Sanction $sanction, $dbw = null ) {
		$dbw = $dbw ?: $this->getDBConnectionRef( DB_PRIMARY );

		$dbw->delete(
			'sanctions_vote',
			[ 'stv_topic' => $sanction->getWorkflowId()->getBinary() ],
			__METHOD__
		);

		if ( $dbw->affectedRows() == 0 ) {
			return false;
		}
		return true;
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
