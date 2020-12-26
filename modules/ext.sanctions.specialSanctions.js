(function ($) {
  'use strict';

  // Prevent from clicking multiple times on the submit button.
  $(document).on('submit', '#sanctionsForm', function () {
    $(this).find('.submit-button').prop('disabled', true);
    return true;
  });
})(jQuery);
