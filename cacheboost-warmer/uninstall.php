<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$options = [
    'cacheboost_options',
    'cacheboost_logs',
    'cacheboost_notice_dismissed',
];

$transients = [
    'cacheboost_test_connection',
];

foreach ($options as $option) {
    delete_option($option);
}

foreach ($transients as $transient) {
    delete_transient($transient);
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids']) as $site_id) {
        switch_to_blog($site_id);
        foreach ($options as $option) {
            delete_option($option);
        }
        foreach ($transients as $transient) {
            delete_transient($transient);
        }
        restore_current_blog();
    }
}
