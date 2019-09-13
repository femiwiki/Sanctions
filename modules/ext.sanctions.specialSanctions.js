( function ( mw, $ ) {
    // Prevent from clicking multiple times on the submit button.
    $('#sanctionsForm').submit(
        function () {
            $(this).find('#submit-button').prop('disabled', true);
            return true;
        }
    );
} )(mediaWiki, jQuery);
