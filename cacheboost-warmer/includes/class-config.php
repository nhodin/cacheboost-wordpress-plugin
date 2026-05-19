<?php
namespace CacheBoost;

if (!defined('ABSPATH')) exit;

class Config {
    private array $options;

    public function __construct() {
        $this->options = get_option('cacheboost_options', []);
    }

    public function is_enabled(): bool {
        return !empty($this->options['enabled']);
    }

    public function get_api_key(): string {
        return $this->options['api_key'] ?? '';
    }

    public function get_mode(): string {
        return $this->options['mode'] ?? 'smart';
    }

    public function is_stock_warming_enabled(): bool {
        return !empty($this->options['stock_warming']);
    }

    public function get_api_endpoint(): string {
        return $this->options['api_endpoint'] ?? 'https://api.cacheboost.io';
    }
}
