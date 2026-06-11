<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$cacheboost_options = [
    'cacheboost_options',
    'cacheboost_logs',
    'cacheboost_notice_dismissed',
    'cacheboost_last_flush',
];

$cacheboost_transients = [
    'cacheboost_widget_data',
];

foreach ($cacheboost_options as $cacheboost_option) {
    delete_option($cacheboost_option);
}

foreach ($cacheboost_transients as $cacheboost_transient) {
    delete_transient($cacheboost_transient);
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids']) as $cacheboost_site_id) {
        switch_to_blog($cacheboost_site_id);
        foreach ($cacheboost_options as $cacheboost_option) {
            delete_option($cacheboost_option);
        }
        foreach ($cacheboost_transients as $cacheboost_transient) {
            delete_transient($cacheboost_transient);
        }
        restore_current_blog();
    }
}
