<?php
namespace CacheBoost;

if (!defined('ABSPATH')) exit;

class HooksNative {
    public function __construct(private EventBuffer $buffer, private Config $config) {}

    public function register(): void {
        if ($this->config->is_smart_enabled()) {
            add_action('save_post',    [$this, 'on_save_post'], 10, 1);
            add_action('deleted_post', [$this, 'on_deleted_post'], 10, 2);
            add_action('edit_term',    [$this, 'on_edit_term'], 10, 3);
        }

        if ($this->config->is_full_enabled()) {
            foreach (['switch_theme', 'wp_update_nav_menu', 'customize_save_after'] as $hook) {
                add_action($hook, fn () => $this->buffer->push_full());
            }
            add_action('upgrader_process_complete', fn () => $this->buffer->push_full());
        }
    }

    public function on_save_post(int $post_id): void {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        if (get_post_status($post_id) !== 'publish') return;

        $urls = array_filter([get_permalink($post_id), get_home_url()]);

        foreach (get_object_taxonomies(get_post_type($post_id)) as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $urls[] = get_term_link($term);
                }
            }
        }

        $this->buffer->push_urls(array_filter($urls, 'is_string'));
    }

    public function on_deleted_post(int $post_id, ?object $post = null): void {
        // The post row is gone — its permalink is dead. Re-warm the home page,
        // whose listings referenced the deleted content.
        if (!$post || $post->post_status !== 'publish' || !is_post_type_viewable($post->post_type)) return;
        $home = get_home_url();
        if ($home) $this->buffer->push_urls([$home]);
    }

    public function on_edit_term(int $term_id, int $tt_id, string $taxonomy): void {
        $link = get_term_link($term_id, $taxonomy);
        if (!is_wp_error($link)) {
            $this->buffer->push_urls([$link]);
        }
    }
}
