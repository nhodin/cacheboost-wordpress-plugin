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

require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-config.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-api-client.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-event-buffer.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-hooks-native.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-hooks-cache-plugins.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-hooks-woocommerce.php';
require_once CACHEBOOST_PLUGIN_DIR . 'admin/settings-page.php';

add_action('plugins_loaded', function () {
    load_plugin_textdomain(
        'cacheboost-warmer',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});

add_action('init', function () {
    $buffer = new CacheBoost\EventBuffer();
    (new CacheBoost\HooksNative($buffer))->register();
    (new CacheBoost\HooksCachePlugins($buffer))->register();
    if (class_exists('WooCommerce')) {
        (new CacheBoost\HooksWooCommerce($buffer))->register();
    }
});
