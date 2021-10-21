<?php

namespace MediaWiki\Extension\Sanctions;

use Flow\Collection\PostSummaryCollection;
use Flow\Container;
use Flow\Data\ManagerGroup;
use Flow\Import\Converter;
use Flow\Import\EnableFlow\EnableFlowWikitextConversionStrategy;
use Flow\Import\SourceStore\NullImportSourceStore;
use Flow\Model\PostRevision;
use Flow\Model\PostSummary;
use Flow\Model\UUID;
use Flow\WorkflowLoaderFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\NullLogger;
use RequestContext;
use Title;
use User;

class FlowUtil {

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
	 * @param Title $title
	 * @param User $user
	 * @param string $topic
	 * @param string $content wikitext.
	 * @return UUID|null
	 */
	public static function createTopic( Title $title, User $user, string $topic, string $content ) {
		$factory = self::getWorkflowLoaderFactory();
		if ( !$factory ) {
			return null;
		}
		if ( $title->getContentModel() != CONTENT_MODEL_FLOW_BOARD ) {
			if ( !self::convertToFlow( $title ) ) {
				return null;
			}
		}

		$metadata = self::action(
			'new-topic',
			[
				'topiclist' => [
					'submodule' => 'new-topic',
					'page' => $title->getFullText(),
					'topic' => $topic,
					'content' => $content
				]
			],
			$user,
			$title
		);
		if ( isset( $metadata['topiclist']['topic-id'] ) ) {
			return UUID::create( $metadata['topiclist']['topic-id'] );
		}
		return null;
	}

	/**
	 * Change the content of the given title to flow if it is not.
	 * @param Title $title
	 * @return bool
	 */
	public static function convertToFlow( $title ) {
		$logger = new NullLogger;
		$user = Utils::getBot();
		if ( $title->exists( Title::READ_LATEST ) ) {
			$converter = new Converter(
				wfGetDB( DB_PRIMARY ),
				Container::get( 'importer' ),
				$logger,
				$user,
				new EnableFlowWikitextConversionStrategy(
					MediaWikiServices::getInstance()->getParser(),
					new NullImportSourceStore(),
					$logger,
					$user
				)
			);

			try {
				$converter->convert( $title );
			} catch ( \Exception $e ) {
				return false;
			}
		} else {
			$loaderFactory = self::getWorkflowLoaderFactory();
			if ( !$loaderFactory ) {
				return false;
			}
			$occupationController = Container::get( 'occupation_controller' );

			$creationStatus = $occupationController->safeAllowCreation( $title, $user, false );
			if ( !$creationStatus->isGood() ) {
				return false;
			}

			$loader = $loaderFactory->createWorkflowLoader( $title );
			$blocks = $loader->getBlocks();

			$action = 'edit-header';
			$params = [
				'header' => [
					'content' => '',
					'format' => 'wikitext',
				],
			];

			$blocksToCommit = $loader->handleSubmit(
				clone RequestContext::getMain(),
				$action,
				$params
			);

			foreach ( $blocks as $block ) {
				if ( $block->hasErrors() ) {
					$errors = $block->getErrors();

					foreach ( $errors as $errorKey ) {
						Utils::getLogger()->warning( $block->getErrorMessage( $errorKey ) );
					}
					return false;
				}
			}

			$loader->commit( $blocksToCommit );
		}
		return true;
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
			[
				'topicsummary' => [
					'submodule' => 'edit-topic-summary',
					'page' => $title->getFullText(),
					'prev_revision' => $summary ? $summary->getRevisionId()->getAlphadecimal() : null,
					'summary' => $content,
					'format' => 'wikitext'
				]
			],
			$user,
			$title,
			$workflow
		) !== null;
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
			[
				'topic' => [
					'submodule' => 'reply',
					'replyTo' => $post->getPostId(),
					'content' => $content,
					'format' => 'wikitext'
				]
			],
			$user,
			$post->getCollection()->getTitle(),
			$post->getCollection()->getWorkflowId()
		) !== null;
	}

	/**
	 * @param string $action
	 * @param array $params
	 * @param User $user
	 * @param Title $title
	 * @param UUID|null $workflow
	 * @return array|null
	 */
	protected static function action( string $action, array $params, User $user, Title $title, UUID $workflow = null ) {
		$factory = self::getWorkflowLoaderFactory();
		if ( !$factory ) {
			return null;
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
				return null;
			}
		}

		$commitMetadata = $loader->commit( $blocksToCommit );
		return $commitMetadata ?: null;
	}

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
}
