<?php

// Brain Monkey requires WP functions to be undefined at bootstrap time so it can
// define them as stubs in setUp(). Do NOT define any WP function here.
define('ABSPATH', '/');
// Drop-in fixtures (advanced-cache.php, object-cache.php…) are written here by the tests.
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/cbwarmer-tests-content-' . getmypid());
mkdir(WP_CONTENT_DIR . '/wphb-cache', 0777, true);
register_shutdown_function(function () {
    foreach (['advanced-cache.php', 'object-cache.php', 'wphb-cache/wphb-cache.php'] as $file) {
        @unlink(WP_CONTENT_DIR . '/' . $file);
    }
    @rmdir(WP_CONTENT_DIR . '/wphb-cache');
    @rmdir(WP_CONTENT_DIR);
});

require_once __DIR__ . '/../vendor/autoload.php';

// Load only the class files — not the main plugin file, which calls add_action()
// at the top level and would require Brain Monkey to already be set up.
$includes = __DIR__ . '/../cacheboost-warmer/includes';
require_once $includes . '/class-config.php';
require_once $includes . '/class-logger.php';
require_once $includes . '/class-api-client.php';
require_once $includes . '/class-event-buffer.php';
require_once $includes . '/class-hooks-native.php';
require_once $includes . '/class-hooks-cache-plugins.php';
require_once $includes . '/class-hooks-woocommerce.php';
require_once $includes . '/class-site-validation.php';
require_once $includes . '/class-connect.php';
require_once $includes . '/class-account-status.php';
require_once $includes . '/class-cache-plugin-adapters.php';
require_once $includes . '/class-environment-snapshot.php';
require_once $includes . '/class-environment-reporter.php';

require_once __DIR__ . '/Support/WordPressStubs.php';
