<?php
namespace CacheBoostWarmer;

if (!defined('ABSPATH')) exit;

/**
 * Keeps CacheBoost's copy of the environment snapshot up to date.
 *
 * Every trigger (connection, settings saved, plugin activated, deactivated or updated, a known cache
 * plugin's settings saved) only schedules one WP-Cron event, so:
 * - all triggers of a request, and of the requests before the event runs, end in a single send;
 * - the snapshot is built in a fresh request, where a plugin just activated or updated is fully loaded;
 * - the blocking API call runs in the cron request, never while a visitor waits for a page.
 * A daily event catches what has no hook (settings kept in files, timeout rules).
 *
 * The snapshot is sent only when its hash changed. With sharing turned off, a DELETE is sent once.
 */
class EnvironmentReporter {
    public const SEND_HOOK    = 'cbwarmer_send_environment';
    public const DAILY_HOOK   = 'cbwarmer_environment_daily';
    // ['hash' => …, 'sent_at' => …] after a send, ['deleted_at' => …] once the DELETE went through.
    public const STATE_OPTION = 'cbwarmer_environment_state';

    private static bool $scheduled = false;

    public function register(): void {
        add_action(self::SEND_HOOK, [self::class, 'send']);
        add_action(self::DAILY_HOOK, [self::class, 'send']);
        add_action('admin_init', [self::class, 'ensure_daily_event']);

        // Connection, settings saved, Test Connection (they all write cbwarmer_options).
        add_action('add_option_cbwarmer_options', [self::class, 'schedule']);
        add_action('update_option_cbwarmer_options', [self::class, 'schedule']);

        add_action('activated_plugin', [self::class, 'schedule']);
        add_action('deactivated_plugin', [self::class, 'schedule']);
        add_action('upgrader_process_complete', [self::class, 'schedule']);

        foreach (EnvironmentSnapshot::watched_options() as $option) {
            add_action("add_option_{$option}", [self::class, 'schedule']);
            add_action("update_option_{$option}", [self::class, 'schedule']);
        }
        // Settings stored outside options: W3TC writes a file, WP Super Cache rewrites wp-cache-config.php.
        add_action('w3tc_config_save', [self::class, 'schedule']);
        add_action('load-settings_page_wpsupercache', [self::class, 'schedule']);
    }

    /** Hooked to actions whose arguments are not needed. */
    public static function schedule(): void {
        if (self::$scheduled) return;
        self::$scheduled = true;

        if (!ApiClient::is_valid_api_key((new Config())->get_api_key())) return;
        if (!wp_next_scheduled(self::SEND_HOOK)) {
            wp_schedule_single_event(time(), self::SEND_HOOK);
        }
    }

    /** Its first run right away also sends the first snapshot after an update from 1.2. */
    public static function ensure_daily_event(): void {
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event(time(), 'daily', self::DAILY_HOOK);
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::SEND_HOOK);
        wp_clear_scheduled_hook(self::DAILY_HOOK);
    }

    /**
     * @param bool $force Send even if unchanged ("Send now" button); CacheBoost then answers stored: false.
     * @return array{success: bool, code: string, message?: string}
     *   code: sent | unchanged | deleted | disabled | not_connected | plugin_disabled | error
     */
    public static function send(bool $force = false): array {
        $config  = new Config();
        $site_id = (int) $config->get_site_id();
        if (!ApiClient::is_valid_api_key($config->get_api_key()) || $site_id <= 0) {
            return ['success' => false, 'code' => 'not_connected'];
        }

        $state = get_option(self::STATE_OPTION, []);
        $state = is_array($state) ? $state : [];

        if (!$config->is_environment_sharing_enabled()) {
            if (!empty($state['deleted_at'])) {
                return ['success' => true, 'code' => 'disabled'];
            }
            $result = ApiClient::delete_environment($site_id);
            if (!$result['success']) {
                return ['success' => false, 'code' => 'error', 'message' => $result['message'] ?? ''];
            }
            update_option(self::STATE_OPTION, ['deleted_at' => time()], false);
            return ['success' => true, 'code' => 'deleted'];
        }

        // Like warming requests: nothing is sent while the plugin is switched off.
        if (!$config->is_enabled()) {
            return ['success' => false, 'code' => 'plugin_disabled'];
        }

        $payload = (new EnvironmentSnapshot($config))->collect();
        // The site is part of the hash: connecting to another site sends its first snapshot.
        $hash = hash('sha256', $site_id . '|' . wp_json_encode($payload));
        if (!$force && ($state['hash'] ?? '') === $hash) {
            return ['success' => true, 'code' => 'unchanged'];
        }

        $result = ApiClient::send_environment($site_id, $payload);
        if (!$result['success']) {
            // The hash is not stored, so the next trigger or the daily event tries again.
            return ['success' => false, 'code' => 'error', 'message' => $result['message'] ?? ''];
        }
        update_option(self::STATE_OPTION, ['hash' => $hash, 'sent_at' => time()], false);
        return ['success' => true, 'code' => $result['stored'] ? 'sent' : 'unchanged'];
    }

    /** Time of the last snapshot sent, for the settings page. */
    public static function last_sent_at(): ?int {
        $state = get_option(self::STATE_OPTION, []);
        return is_array($state) && !empty($state['sent_at']) ? (int) $state['sent_at'] : null;
    }
}
