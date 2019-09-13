( function ( mw, $ ) {
    // Show help message below the flow topic
    var message = mw.message("sanctions-voting-help-message").text();
    $(' .flow-board').append(message);
} )(mediaWiki, jQuery);
