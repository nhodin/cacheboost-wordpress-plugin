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
            Logger::log('buffer', 'Flush sent: flush_all');
            ApiClient::send(['event' => 'flush_all']);
        } elseif (!empty(self::$urls)) {
            $count   = count(self::$urls);
            $preview = array_slice(self::$urls, 0, 3);
            $suffix  = $count > 3 ? sprintf(' (+%d more)', $count - 3) : '';
            Logger::log('buffer', sprintf('Flush sent: flush_by_url — %d URL(s): %s%s', $count, implode(', ', $preview), $suffix));
            ApiClient::send(['event' => 'flush_by_url', 'urls' => self::$urls]);
        }
    }
}
