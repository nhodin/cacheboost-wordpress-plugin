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
        $this->maybe_register_shutdown();
    }

    public function push_urls(array $urls): void {
        if (self::$mode === 'full') return;
        foreach ($urls as $url) {
            if ($url && !in_array($url, self::$urls, true)) {
                self::$urls[] = $url;
            }
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
            ApiClient::send(['event' => 'flush_all']);
        } elseif (!empty(self::$urls)) {
            ApiClient::send(['event' => 'flush_by_url', 'urls' => self::$urls]);
        }
    }
}
