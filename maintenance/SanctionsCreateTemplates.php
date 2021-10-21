<?php

use MediaWiki\Extension\Sanctions\Utils;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * The templates will be created with a default content, but can be customized.
 * If the templates already exists, they will be left untouched.
 *
 * @ingroup Maintenance
 */

class SanctionsCreateTemplates extends LoggedUpdateMaintenance {
	/**
	 * Returns an array of templates to be created (= pages in NS_TEMPLATE)
	 *
	 * The key in the array is an i18n message so the template titles can be
	 * internationalized and/or edited per wiki.
	 * The value is a callback function that will only receive $title and is
	 * expected to return the page content in wikitext.
	 *
	 * @return array [title i18n key => content callback]
	 */
	protected function getTemplates() {
		return [
			'sanctions-agree-template-title' => static function ( Title $title ) {
				return "{{#if:{{{1|}}}|'''" .
					wfMessage(
						'sanctions-agree-with-day-template-body',
						"<span class=\"sanction-vote-agree-period\">{{{1|}}}</span>"
					)->inContentLanguage()->plain() .
					"'''|<span class=\"sanction-vote-agree\"></span>'''" .
					wfMessage( 'sanctions-agree-template-body' )->inContentLanguage()->plain() .
					"'''}}";
			},
			'sanctions-disagree-template-title' => static function ( Title $title ) {
				return "<span class=\"sanction-vote-disagree\"></span>'''" .
				wfMessage( 'sanctions-disagree-template-body' )->inContentLanguage()->plain() .
				"'''";
			}
		];
	}

	public function __construct() {
		parent::__construct();

		$this->mDescription = "Creates templates required by Sanctions";

		$this->requireExtension( 'Sanctions' );
	}

	/**
	 * @return string
	 */
	protected function getUpdateKey() {
		$templates = $this->getTemplates();
		$keys = array_keys( $templates );
		sort( $keys );

		// make the updatekey unique for the i18n keys of the pages to be created
		// so we can easily skip this update if there are no changes
		return __CLASS__ . ':' . md5( implode( ',', $keys ) );
	}

	protected function doDBUpdates() {
		$status = Status::newGood();

		$templates = $this->getTemplates();
		foreach ( $templates as $key => $callback ) {
			$title = Title::newFromText( wfMessage( $key )->inContentLanguage()->plain(), NS_TEMPLATE );
			$content = new WikitextContent( $callback( $title ) );

			$status->merge( $this->create( $title, $content ) );
		}

		return $status->isOK();
	}

	/**
	 * Creates a page with the given content (unless it already exists)
	 *
	 * @param Title $title
	 * @param WikitextContent $content
	 * @return Status
	 * @throws MWException
	 */
	protected function create( Title $title, WikitextContent $content ) {
		$article = new Article( $title );
		$page = $article->getPage();

		if ( $page->getRevisionRecord() !== null ) {
			// template already exists, don't overwrite it
			return Status::newGood();
		}

		return $page->doUserEditContent(
			$content,
			Utils::getBot(),
			'/* Automatically created by Sanctions */',
			EDIT_FORCE_BOT | EDIT_SUPPRESS_RC
		);
	}
}

$maintClass = SanctionsCreateTemplates::class;
require_once RUN_MAINTENANCE_IF_MAIN;
