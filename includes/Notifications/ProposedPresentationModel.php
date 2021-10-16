<?php

namespace MediaWiki\Extension\Sanctions\Notifications;

use EchoEventPresentationModel;
use Message;

class ProposedPresentationModel extends EchoEventPresentationModel {

	public function canRender() {
		return (bool)$this->event->getTitle();
	}

	/**
	 * @return string
	 */
	public function getIconType() {
		return 'placeholder';
	}

	/**
	 * @return array
	 */
	public function getPrimaryLink() {
		$link = $this->getPageLink( $this->event->getTitle(), '', true );
		return $link;
	}

	/**
	 * @return Message
	 */
	public function getHeaderMessage(): Message {
		$event = $this->event;
		if ( $event->getExtraParam( 'is-for-insulting-name' ) ) {
			$msg = $this->getMessageWithAgent( 'notification-header-sanctions-proposed-against-insulting-name' );
		} else {
			$msg = $this->getMessageWithAgent( 'notification-header-sanctions-proposed' );
		}
		return $msg;
	}
}
