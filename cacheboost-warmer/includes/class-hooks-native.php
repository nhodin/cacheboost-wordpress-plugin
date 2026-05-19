<?php
namespace CacheBoost;

if (!defined('ABSPATH')) exit;

class HooksNative {
    public function __construct(private EventBuffer $buffer) {}

    public function register(): void {
        add_action('save_post',    [$this, 'on_save_post'], 10, 1);
        add_action('deleted_post', [$this, 'on_save_post'], 10, 1);
        add_action('edit_term',    [$this, 'on_edit_term'], 10, 3);

        foreach (['switch_theme', 'wp_update_nav_menu', 'customize_save_after'] as $hook) {
            add_action($hook, fn () => $this->buffer->push_full());
        }

        add_action('upgrader_process_complete', fn () => $this->buffer->push_full());
    }

    public function on_save_post(int $post_id): void {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;

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

    public function on_edit_term(int $term_id, int $tt_id, string $taxonomy): void {
        $link = get_term_link($term_id, $taxonomy);
        if (!is_wp_error($link)) {
            $this->buffer->push_urls([$link]);
        }
    }
}
