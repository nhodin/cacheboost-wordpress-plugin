<?php
namespace CacheBoost;

if (!defined('ABSPATH')) exit;

class EventBuffer {
    private static bool   $registered = false;
    private static bool   $flushed    = false;
    private static string $mode       = 'smart';
    private static array  $urls       = [];

    public function push_full(): void {
        self::$mode = 'full';
        Logger::log('buffer', 'Full flush requested');
        $this->maybe_register_shutdown();
    }

    public function push_urls(array $urls): void {
        if (self::$mode === 'full') return;
        $added = [];
        foreach ($urls as $url) {
            if ($url && !in_array($url, self::$urls, true)) {
                self::$urls[] = $url;
                $added[]      = $url;
            }
        }
        if (!empty($added)) {
            $preview = array_slice($added, 0, 3);
            $suffix  = count($added) > 3 ? sprintf(' (+%d more)', count($added) - 3) : '';
            Logger::log('buffer', sprintf('%d URL(s) added to buffer: %s%s', count($added), implode(', ', $preview), $suffix));
        }
        $this->maybe_register_shutdown();
    }

    private function maybe_register_shutdown(): void {
        if (self::$registered) return;
        self::$registered = true;
        add_action('shutdown', [$this, 'flush'], 9999);
    }

    public function flush(): void {
        if (self::$flushed) return;
        self::$flushed = true;

        if (self::$mode === 'full') {
            $config   = new Config();
            if (!$config->is_full_enabled()) {
                Logger::log('buffer', 'Full flush skipped — full warming is disabled');
                return;
            }
            $boost_id = $config->get_boost_id();
            if ($boost_id <= 0) {
                Logger::log('buffer', 'Full flush skipped — no Boost ID configured. Select a Boost in settings.', 'error');
                return;
            }
            update_option('cacheboost_last_flush', ['ts' => time(), 'mode' => 'full', 'urls' => []]);
            Logger::log('buffer', sprintf('Flush sent: full — boost run #%d', $boost_id));
            ApiClient::trigger_boost_run($boost_id);
            return;
        }

        if (!empty(self::$urls)) {
            $count   = count(self::$urls);
            $preview = array_slice(self::$urls, 0, 3);
            $suffix  = $count > 3 ? sprintf(' (+%d more)', $count - 3) : '';
            $config = new Config();
            if (!$config->is_smart_enabled()) {
                Logger::log('buffer', 'Smart flush skipped — smart warming is disabled');
                return;
            }
            $site_id = $config->get_site_id();
            if ($site_id === null) {
                Logger::log('buffer', 'Smart flush skipped — site_id not resolved. Test the connection in settings.', 'error');
                return;
            }
            update_option('cacheboost_last_flush', ['ts' => time(), 'mode' => 'smart', 'urls' => self::$urls]);
            Logger::log('buffer', sprintf('Flush sent: smart — %d URL(s): %s%s', $count, implode(', ', $preview), $suffix));
            ApiClient::trigger_warm($site_id, self::$urls);
        }
    }
}
