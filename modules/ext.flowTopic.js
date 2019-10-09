( ( function ( $ ) {
	'use strict';

	// Show help message below the flow topic
	var message = mw.message( 'sanctions-voting-help-message' ).text();
	// eslint-disable-next-line no-jquery/no-global-selector
	$( '.flow-board' ).append( message );
} )( jQuery ) );
