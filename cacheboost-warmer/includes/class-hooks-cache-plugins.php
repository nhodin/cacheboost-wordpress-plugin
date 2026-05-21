<?php
namespace CacheBoost;

if (!defined('ABSPATH')) exit;

class HooksCachePlugins {
    public function __construct(private EventBuffer $buffer, private Config $config) {}

    public function register(): void {
        if (function_exists('rocket_clean_domain'))  $this->register_wprocket();
        if (function_exists('w3tc_pgcache_flush'))   $this->register_w3tc();
        if (class_exists('LiteSpeed_Cache_API'))     $this->register_litespeed();
        if (function_exists('wp_cache_clear_cache')) $this->register_wpsupercache();
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

    private function register_litespeed(): void {
        if ($this->config->is_full_enabled()) {
            add_action('litespeed_purge_all', fn () => $this->buffer->push_full());
        }
        if ($this->config->is_smart_enabled()) {
            add_action('litespeed_purge_post', function ($post_id) {
                $url = get_permalink($post_id);
                if ($url) $this->buffer->push_urls([$url]);
            });
        }
    }

    private function register_wpsupercache(): void {
        if ($this->config->is_full_enabled()) {
            add_action('wp_cache_cleared', fn () => $this->buffer->push_full());
        }
    }
}
