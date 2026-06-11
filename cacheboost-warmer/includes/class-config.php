<?php
namespace CacheBoost;

if (!defined('ABSPATH')) exit;

class Config {
    private array $options;

    public function __construct() {
        $this->options = get_option('cacheboost_options', []);
    }

    public function is_enabled(): bool {
        return (bool) ($this->options['enabled'] ?? true);
    }

    public function get_api_key(): string {
        return $this->options['api_key'] ?? '';
    }

    public function is_smart_enabled(): bool {
        return $this->options['smart_enabled'] ?? true;
    }

    public function is_full_enabled(): bool {
        return $this->options['full_enabled'] ?? true;
    }

    public function is_stock_warming_enabled(): bool {
        return !empty($this->options['stock_warming']);
    }

    public function is_dashboard_widget_enabled(): bool {
        return $this->options['dashboard_widget'] ?? true;
    }

    public function get_api_endpoint(): string {
        return $this->options['api_endpoint'] ?? 'https://api.cache-boost.com';
    }

    public function get_regions(): array {
        return $this->options['regions'] ?? [];
    }

    public function get_site_id(): ?int {
        $id = $this->options['site_id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    public function get_boost_id(): int {
        return (int) ($this->options['boost_id'] ?? 0);
    }
}
