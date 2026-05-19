<?php

// Brain Monkey requires WP functions to be undefined at bootstrap time so it can
// define them as stubs in setUp(). Do NOT define any WP function here.
define('ABSPATH', '/');

require_once __DIR__ . '/../vendor/autoload.php';

// Load only the class files — not the main plugin file, which calls add_action()
// at the top level and would require Brain Monkey to already be set up.
$includes = __DIR__ . '/../cacheboost-warmer/includes';
require_once $includes . '/class-config.php';
require_once $includes . '/class-api-client.php';
require_once $includes . '/class-event-buffer.php';
require_once $includes . '/class-hooks-native.php';
require_once $includes . '/class-hooks-cache-plugins.php';
require_once $includes . '/class-hooks-woocommerce.php';
