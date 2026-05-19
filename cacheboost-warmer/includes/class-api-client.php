<?php
namespace CacheBoost;

if (!defined('ABSPATH')) exit;

class ApiClient {

    public static function is_valid_api_key(string $key): bool {
        return (bool) preg_match('/^cb_live_[a-zA-Z0-9]+$/', $key);
    }

    public static function can_notify(): bool {
        $config = new Config();
        return $config->is_enabled() && self::is_valid_api_key($config->get_api_key());
    }

    public static function send(array $payload): void {
        if (!self::can_notify()) return;

        $config  = new Config();
        $api_key = $config->get_api_key();

        wp_remote_post($config->get_api_endpoint() . '/v1/warm', [
            'blocking' => false,
            'headers'  => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode(array_merge($payload, [
                'site_url'  => get_home_url(),
                'timestamp' => time(),
            ])),
        ]);
    }

    /**
     * Blocking connectivity check used by the admin test button.
     *
     * @return array{success: bool, message?: string}
     */
    public static function ping(string $api_key, string $endpoint): array {
        $response = wp_remote_get(rtrim($endpoint, '/') . '/v1/ping', [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            return ['success' => true];
        }

        return ['success' => false, 'message' => sprintf('HTTP %d', $code)];
    }
}
