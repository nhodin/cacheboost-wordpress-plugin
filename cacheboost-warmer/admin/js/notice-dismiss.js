(function ($) {
    $(document).on('click', '#cbwarmer-setup-notice .notice-dismiss', function () {
        $.post(ajaxurl, {
            action: 'cbwarmer_dismiss_notice',
            nonce: CacheBoostWarmerNotice.nonce
        });
    });
})(jQuery);
