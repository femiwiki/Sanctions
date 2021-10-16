<?php

namespace MediaWiki\Extension\Sanctions;

use Wikimedia\Rdbms\DBConnRef;

class ReplyUtils {

	/**
	 * @param string $content
	 * @param string &$newContent
	 * @return int|null
	 */
	public static function checkNewVote( $content, &$newContent ) {
		global $wgFlowContentFormat;

		// Filter out posts does not includes vote. We use tags to identify a vote.
		$countedText = $wgFlowContentFormat == 'html'
			? '"sanction-vote-counted"'
			: '<!--sanction-vote-counted-->';
		if ( strpos( $content, $countedText ) ) {
			return null;
		}

		if ( $wgFlowContentFormat === 'html' ) {
			$agreementWithDayRegex = '/<span class="sanction-vote-agree-period">(\d+)<\/span>/';
			$agreementRegex = '"sanction-vote-agree"';
			$disagreementRegex = '"sanction-vote-disagree"';
		} else {
			$agreementTemplateTitle = wfMessage( 'sanctions-agree-template-title' )->inContentLanguage()->text();
			$agreementTemplateTitle = preg_quote( $agreementTemplateTitle );
			$agreementWithDayRegex = "/\{\{${agreementTemplateTitle}\|(\d+)\}\}/";
			$agreementRegex = '{{' . $agreementTemplateTitle . '}}';
			$disagreementRegex = wfMessage( 'sanctions-disagree-template-title' )->inContentLanguage()->text();
			$disagreementRegex = '{{' . $disagreementRegex . '}}';
		}

		if ( strpos( $content, $disagreementRegex ) ) {
			return 0;
		}
		if ( preg_match( $agreementWithDayRegex, $content, $matches ) != 0 && count( $matches ) > 0 ) {
			return (int)$matches[1];
		}
		// If the affirmative opinion is without explicit length, it would be considered as a day.
		if ( strpos( $content, $agreementRegex ) ) {
			return 1;
		}
		return null;
	}

	/**
	 * Append "is counted" mark
	 * @param DBConnRef $db
	 * @param string $content
	 * @param int $revId
	 */
	public static function appendCheckedMark( $db, $content, $revId ) {
		global $wgFlowContentFormat;

		if ( $wgFlowContentFormat === 'html' ) {
			$newContent = $content . Html::rawelement(
				'span',
				[ 'class' => 'sanction-vote-counted' ]
			);
		} else {
			$newContent = $content . '<!--sanction-vote-counted-->';
		}
		$db->update(
			'flow_revision',
			[
				'rev_content' => $newContent
			],
			[
				'rev_id' => $revId
			]
		);
	}
}
