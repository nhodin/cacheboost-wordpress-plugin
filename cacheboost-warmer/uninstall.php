<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$cbwarmer_options = [
    'cbwarmer_options',
    'cbwarmer_logs',
    'cbwarmer_notice_dismissed',
    'cbwarmer_last_flush',
    'cbwarmer_validation_token',
    'cbwarmer_site_validated',
    'cbwarmer_environment_state',
];

// Network deactivation runs the deactivation hook on one site only: each site's events are cleared here.
$cbwarmer_cron_hooks = [
    'cbwarmer_send_environment',
    'cbwarmer_environment_daily',
];

$cbwarmer_transients = [
    'cbwarmer_widget_data',
    'cbwarmer_flyingpress_full_debounce',
];

foreach ($cbwarmer_options as $cbwarmer_option) {
    delete_option($cbwarmer_option);
}

foreach ($cbwarmer_transients as $cbwarmer_transient) {
    delete_transient($cbwarmer_transient);
}

foreach ($cbwarmer_cron_hooks as $cbwarmer_cron_hook) {
    wp_clear_scheduled_hook($cbwarmer_cron_hook);
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
        foreach ($cbwarmer_cron_hooks as $cbwarmer_cron_hook) {
            wp_clear_scheduled_hook($cbwarmer_cron_hook);
        }
        restore_current_blog();
    }
}
