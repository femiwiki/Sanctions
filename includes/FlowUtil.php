<?php

namespace MediaWiki\Extension\Sanctions;

use Flow\Container;
use Flow\Model\PostRevision;
use Flow\Model\UUID;
use Flow\WorkflowLoaderFactory;
use RequestContext;
use User;

class FlowUtil {

	/**
	 * @param UUID|string $uuid
	 * @return PostRevision
	 */
	public static function findPostRevisionFromUUID( $uuid ) {
		if ( $uuid instanceof UUID ) {
			$uuid = $uuid->getAlphadecimal();
		} elseif ( is_string( $uuid ) ) {
			$uuid = strtolower( $uuid );
		}

		$storage = Container::get( 'storage' );
		$found = $storage->find(
			'PostRevision',
			[ 'rev_type_id' => $uuid ],
			[ 'sort' => 'rev_id', 'order' => 'DESC', 'limit' => 1 ]
		);

		$post = reset( $found );
		return $post;
	}

	/**
	 * @param PostRevision $post
	 * @param User $user
	 * @param string $content
	 */
	public static function replyTo( PostRevision $post, User $user, string $content ) {
		$action = 'reply';
		$params = [
			'topic' => [
				'action' => 'flow',
				'submodule' => 'reply',
				'replyTo' => $post->getPostId(),
				'content' => $content,
				'format' => 'wikitext'
			]
		];

		/** @var WorkflowLoaderFactory $factory */
		$factory = Container::get( 'factory.loader.workflow' );

		$loader = $factory->createWorkflowLoader(
			$post->getCollection()->getTitle(),
			$post->getCollection()->getWorkflowId()
		);

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
				return;
			}
		}

		$loader->commit( $blocksToCommit );
	}
}
