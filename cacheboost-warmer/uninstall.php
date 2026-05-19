<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('cacheboost_options');
delete_transient('cacheboost_test_connection');

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids']) as $site_id) {
        switch_to_blog($site_id);
        delete_option('cacheboost_options');
        delete_transient('cacheboost_test_connection');
        restore_current_blog();
    }
}
