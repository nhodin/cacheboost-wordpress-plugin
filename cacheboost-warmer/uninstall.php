<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$cbwarmer_options = [
    'cbwarmer_options',
    'cbwarmer_logs',
    'cbwarmer_notice_dismissed',
    'cbwarmer_last_flush',
];

$cbwarmer_transients = [
    'cbwarmer_widget_data',
];

foreach ($cbwarmer_options as $cbwarmer_option) {
    delete_option($cbwarmer_option);
}

foreach ($cbwarmer_transients as $cbwarmer_transient) {
    delete_transient($cbwarmer_transient);
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids']) as $cbwarmer_site_id) {
        switch_to_blog($cbwarmer_site_id);
        foreach ($cbwarmer_options as $cbwarmer_option) {
            delete_option($cbwarmer_option);
        }
        foreach ($cbwarmer_transients as $cbwarmer_transient) {
            delete_transient($cbwarmer_transient);
        }
        restore_current_blog();
    }
}
