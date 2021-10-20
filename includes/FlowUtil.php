<?php

namespace MediaWiki\Extension\Sanctions;

use Flow\Collection\PostSummaryCollection;
use Flow\Container;
use Flow\Data\ManagerGroup;
use Flow\Model\PostRevision;
use Flow\Model\PostSummary;
use Flow\Model\UUID;
use Flow\WorkflowLoaderFactory;
use RequestContext;
use Title;
use User;

class FlowUtil {

	/** @return ManagerGroup|null */
	public static function getStorage() {
		$storage = Container::get( 'storage' );
		if ( $storage instanceof ManagerGroup ) {
			return $storage;
		}
		return null;
	}

	/** @return WorkflowLoaderFactory|null */
	public static function getWorkflowLoaderFactory() {
		$storage = Container::get( 'factory.loader.workflow' );
		if ( $storage instanceof WorkflowLoaderFactory ) {
			return $storage;
		}
		return null;
	}

	/**
	 * @param UUID $uuid
	 * @return PostRevision|null
	 */
	public static function findPostRevisionFromUUID( UUID $uuid ) {
		$storage = self::getStorage();
		if ( !$storage ) {
			return null;
		}
		$found = $storage->find(
			'PostRevision',
			[ 'rev_type_id' => $uuid ],
			[ 'sort' => 'rev_id', 'order' => 'DESC', 'limit' => 1 ]
		);

		$post = reset( $found );
		return $post;
	}

	/**
	 * @param UUID $workflow the UUID of the workflow to find.
	 * @return PostSummary|null
	 */
	public static function findSummaryOfTopic( $workflow ) {
		$summaryCollection = PostSummaryCollection::newFromId( $workflow );
		try {
			$rev = $summaryCollection->getLastRevision();
			if ( $rev instanceof PostSummary ) {
				return $rev;
			}
		} catch ( \Exception $e ) {
			// no summary - that's ok!
		}
		return null;
	}

	/**
	 * @param PostRevision $post
	 * @param User $user
	 * @param string $content wikitext.
	 * @return bool
	 */
	public static function replyTo( PostRevision $post, User $user, string $content ) {
		return self::action(
			'reply',
			$post->getCollection()->getTitle(),
			$post->getCollection()->getWorkflowId(),
			$user,
			[
				'topic' => [
					'submodule' => 'reply',
					'replyTo' => $post->getPostId(),
					'content' => $content,
					'format' => 'wikitext'
				]
			]
		);
	}

	/**
	 * @param Title $title
	 * @param UUID $workflow the UUID of the workflow to find.
	 * @param User $user
	 * @param string $content wikitext.
	 * @return bool
	 */
	public static function updateSummary( Title $title, UUID $workflow, User $user, string $content ) {
		$summary = self::findSummaryOfTopic( $workflow );
		return self::action(
			'edit-topic-summary',
			$title,
			$workflow,
			$user,
			[
				'topicsummary' => [
					'page' => $title->getFullText(),
					'submodule' => 'edit-topic-summary',
					'prev_revision' => $summary ? $summary->getRevisionId()->getAlphadecimal() : null,
					'summary' => $content,
					'format' => 'wikitext'
				]
			]
		);
	}

	/**
	 * @param string $action
	 * @param Title $title
	 * @param UUID $workflow
	 * @param User $user
	 * @param array $params
	 * @return bool
	 */
	protected static function action( string $action, Title $title, UUID $workflow, User $user, array $params ) {
		$params += [
			'action' => 'flow',
		];
		$factory = self::getWorkflowLoaderFactory();
		if ( !$factory ) {
			return false;
		}

		$loader = $factory->createWorkflowLoader( $title, $workflow );

		$blocks = $loader->getBlocks();

		$request = RequestContext::getMain();
		$request->setUser( $user );
		$blocksToCommit = $loader->handleSubmit(
			$request,
			$action,
			$params
		);

		$errors = [];
		foreach ( $blocks as $block ) {
			if ( $block->hasErrors() ) {
				$errorKeys = $block->getErrors();
				foreach ( $errorKeys as $errorKey ) {
					$errors[] = $block->getErrorMessage( $errorKey );
				}
				Utils::getLogger()->warning( 'Errors: ' . implode( '. ', $errors ) );
				return false;
			}
		}

		$loader->commit( $blocksToCommit );
		return true;
	}
}
