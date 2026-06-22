<?php
namespace CacheBoostWarmer;

if (!defined('ABSPATH')) exit;

class HooksWooCommerce {
    public function __construct(private EventBuffer $buffer) {}

    public function register(): void {
        add_action('woocommerce_update_product', [$this, 'on_product_update']);
        add_action('woocommerce_new_product',    [$this, 'on_product_update']);

        add_action('woocommerce_update_product_variation', [$this, 'on_variation_update']);
        add_action('woocommerce_save_product_variation',   [$this, 'on_variation_update']);

        // Off by default — high-volume stores can generate many calls per order
        if ((new Config())->is_stock_warming_enabled()) {
            add_action('woocommerce_product_set_stock',        [$this, 'on_stock_change']);
            add_action('woocommerce_product_set_stock_status', [$this, 'on_stock_change']);
        }
    }

    public function on_product_update(int $product_id): void {
        $urls = array_filter([get_permalink($product_id)]);

        $terms = get_the_terms($product_id, 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $link = get_term_link($term);
                if (!is_wp_error($link)) $urls[] = $link;
            }
        }

        $this->buffer->push_urls($urls);
    }

    public function on_variation_update(int $variation_id): void {
        $variation = wc_get_product($variation_id);
        if (!$variation) return;
        $parent_url = get_permalink($variation->get_parent_id());
        if ($parent_url) $this->buffer->push_urls([$parent_url]);
    }

    public function on_stock_change(object $product): void {
        $url = get_permalink($product->get_id());
        if ($url) $this->buffer->push_urls([$url]);
    }
}
