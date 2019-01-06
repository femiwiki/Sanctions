( function ( mw, $ ) {
    // 제재안 올리기 버튼을 여러번 클릭하지 못하게 하기
    $('#sanctionsForm').submit(
        function () {
            $(this).find('#submit-button').prop('disabled', true);
            return true;
        }
    );
} )(mediaWiki, jQuery);
