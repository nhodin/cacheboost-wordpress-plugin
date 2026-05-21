<?php
/**
 * Plugin Name: CacheBoost Warmer
 * Plugin URI:  https://cacheboost.io
 * Description: Notifies CacheBoost API after cache purge events to trigger targeted or full cache warming.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      CacheBoost
 * Author URI:  https://cacheboost.io
 * License:     MIT
 * Text Domain: cacheboost-warmer
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('CACHEBOOST_VERSION', '1.0.0');
define('CACHEBOOST_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-logger.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-config.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-api-client.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-event-buffer.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-hooks-native.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-hooks-cache-plugins.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-hooks-woocommerce.php';
require_once CACHEBOOST_PLUGIN_DIR . 'admin/settings-page.php';
require_once CACHEBOOST_PLUGIN_DIR . 'admin/history-page.php';

add_action('plugins_loaded', function () {
    load_plugin_textdomain(
        'cacheboost-warmer',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});

register_activation_hook(__FILE__, function () {
    if (!get_option('cacheboost_notice_dismissed')) {
        update_option('cacheboost_notice_dismissed', '0');
    }
});

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (get_option('cacheboost_notice_dismissed') === '1') return;
    $options = get_option('cacheboost_options', []);
    if (!empty($options['api_key'])) return;

    $settings_url = admin_url('options-general.php?page=cacheboost');
    $message = sprintf(
        /* translators: %s: link to CacheBoost settings page */
        __('CacheBoost Warmer is installed. <a href="%s">Configure your API key</a> to start warming your cache.', 'cacheboost-warmer'),
        esc_url($settings_url)
    );
    printf(
        '<div class="notice notice-info is-dismissible" id="cacheboost-setup-notice"><p>%s</p></div>',
        $message
    );
});

add_action('wp_ajax_cacheboost_dismiss_notice', function () {
    check_ajax_referer('cacheboost_dismiss_notice', 'nonce');
    if (current_user_can('manage_options')) {
        update_option('cacheboost_notice_dismissed', '1');
    }
    wp_die();
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function (array $links): array {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('options-general.php?page=cacheboost')),
        __('Settings')
    );
    array_unshift($links, $settings_link);
    return $links;
});

add_action('admin_footer', function () {
    if (!current_user_can('manage_options')) return;
    if (get_option('cacheboost_notice_dismissed') === '1') return;
    $options = get_option('cacheboost_options', []);
    if (!empty($options['api_key'])) return;
    ?>
    <script>
    (function($){
        $(document).on('click', '#cacheboost-setup-notice .notice-dismiss', function(){
            $.post(ajaxurl, {
                action: 'cacheboost_dismiss_notice',
                nonce: '<?php echo wp_create_nonce('cacheboost_dismiss_notice'); ?>'
            });
        });
    })(jQuery);
    </script>
    <?php
});

add_action('init', function () {
    $config = new CacheBoost\Config();
    $buffer = new CacheBoost\EventBuffer();
    (new CacheBoost\HooksNative($buffer, $config))->register();
    (new CacheBoost\HooksCachePlugins($buffer, $config))->register();
    if (class_exists('WooCommerce')) {
        (new CacheBoost\HooksWooCommerce($buffer))->register();
    }
});
