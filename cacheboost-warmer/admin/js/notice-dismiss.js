(function ($) {
    $(document).on('click', '#cacheboost-setup-notice .notice-dismiss', function () {
        $.post(ajaxurl, {
            action: 'cacheboost_dismiss_notice',
            nonce: CacheBoostNotice.nonce
        });
    });
})(jQuery);
