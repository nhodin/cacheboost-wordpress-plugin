<?php
namespace CacheBoostWarmer;

if (!defined('ABSPATH')) exit;

class HooksCachePlugins {
    // Set when a FlyingPress full purge requests a full warm; purges arriving before it expires are absorbed.
    public const FLYINGPRESS_DEBOUNCE = 'cbwarmer_flyingpress_full_debounce';
    public const FLYINGPRESS_DEBOUNCE_SECONDS = 60;

    public function __construct(private EventBuffer $buffer, private Config $config) {}

    public function register(): void {
        if (function_exists('rocket_clean_domain'))  $this->register_wprocket();
        if (function_exists('w3tc_pgcache_flush'))   $this->register_w3tc();
        // LiteSpeed Cache 3.0+ dropped the LiteSpeed_Cache_API class; LSCWP_V is defined by every version since.
        if (defined('LSCWP_V'))                      $this->register_litespeed();
        if (function_exists('wp_cache_clear_cache')) $this->register_wpsupercache();
        if (class_exists('FlyingPress\Purge') && $this->config->is_flyingpress_warming_enabled()) {
            $this->register_flyingpress();
        }
    }

    /**
     * Hook names from https://docs.flyingpress.com/en/articles/11405950-hooking-into-purge-events-in-flyingpress
     * (FlyingPress\Purge class: https://docs.flyingpress.com/en/articles/11406092-programmatically-purge-and-preload-cache).
     */
    private function register_flyingpress(): void {
        if ($this->config->is_full_enabled()) {
            add_action('flying_press_purge_pages:after', [$this, 'on_flyingpress_full_purge']);
            add_action('flying_press_purge_everything:after', [$this, 'on_flyingpress_full_purge']);
        }
        if ($this->config->is_smart_enabled()) {
            // A purge_urls() call may also fire purge_url per URL; EventBuffer drops duplicates.
            add_action('flying_press_purge_urls:after', fn ($urls) => $this->on_flyingpress_url_purge((array) $urls));
            add_action('flying_press_purge_url:after', fn ($url) => $this->on_flyingpress_url_purge([$url]));
        }
    }

    public function on_flyingpress_full_purge(): void {
        // The API refuses a second run of the same boost while one is pending or running (HTTP 409),
        // so successive purges never warm in parallel; this keeps a burst of purges to one boost request.
        if (get_transient(self::FLYINGPRESS_DEBOUNCE)) {
            Logger::log('buffer', 'FlyingPress full purge — full warm already requested less than a minute ago');
            return;
        }
        set_transient(self::FLYINGPRESS_DEBOUNCE, 1, self::FLYINGPRESS_DEBOUNCE_SECONDS);
        $this->buffer->push_full();
    }

    public function on_flyingpress_url_purge(array $urls): void {
        // A full warm was just requested and covers these URLs; an inline warm alongside it would skew HIT/MISS stats.
        if (get_transient(self::FLYINGPRESS_DEBOUNCE)) return;
        $this->buffer->push_urls(array_filter($urls, 'is_string'));
    }

    private function register_wprocket(): void {
        if ($this->config->is_full_enabled()) {
            add_action('after_rocket_clean_domain', fn () => $this->buffer->push_full());
        }
        if ($this->config->is_smart_enabled()) {
            add_action('after_rocket_clean_post', function ($post) {
                $url = get_permalink($post->ID);
                if ($url) $this->buffer->push_urls([$url]);
            });
        }
    }

    private function register_w3tc(): void {
        if ($this->config->is_full_enabled()) {
            add_action('w3tc_flush_all', fn () => $this->buffer->push_full());
        }
        if ($this->config->is_smart_enabled()) {
            add_action('w3tc_flush_post', function ($post_id) {
                $url = get_permalink($post_id);
                if ($url) $this->buffer->push_urls([$url]);
            });
        }
    }

    /**
     * Listens to the events LiteSpeed fires after a purge (src/purge.cls.php), not to litespeed_purge_all /
     * litespeed_purge_post: those are commands other plugins fire to request a purge, so a purge from the
     * LiteSpeed admin or on publish never reaches them.
     */
    private function register_litespeed(): void {
        if ($this->config->is_full_enabled()) {
            // Fired by Purge LSCache and by Purge All, which then also fires litespeed_purged_all: hooking both would warm twice.
            add_action('litespeed_purged_all_lscache', [$this, 'on_litespeed_full_purge']);
        }
        if ($this->config->is_smart_enabled()) {
            // Fired for each purged post, including on publish (transition_post_status).
            add_action('litespeed_purged_post', [$this, 'on_litespeed_post_purge']);
        }
    }

    public function on_litespeed_full_purge(): void {
        $this->buffer->push_full();
    }

    public function on_litespeed_post_purge($post_id): void {
        $url = get_permalink((int) $post_id);
        if ($url) $this->buffer->push_urls([$url]);
    }

    private function register_wpsupercache(): void {
        if ($this->config->is_full_enabled()) {
            add_action('wp_cache_cleared', fn () => $this->buffer->push_full());
        }
    }
}
