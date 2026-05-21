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
        $regions = $config->get_regions();

        $body = array_merge($payload, [
            'site_url'  => get_home_url(),
            'timestamp' => time(),
        ]);

        if (!empty($regions)) {
            $body['region'] = $regions;
        }

        $site_id = $config->get_site_id();
        if ($site_id === null) {
            Logger::log('api', 'send() aborted — site_id not set. Test the connection or save settings first.', 'error');
            return;
        }

        $event      = $payload['event'] ?? 'unknown';
        $region_str = !empty($regions) ? implode(', ', $regions) : 'all';
        if (isset($payload['urls'])) {
            Logger::log('api', sprintf('POST /v1/sites/%d/warm — %s (%d URLs), regions: %s', $site_id, $event, count($payload['urls']), $region_str));
        } else {
            Logger::log('api', sprintf('POST /v1/sites/%d/warm — %s, regions: %s', $site_id, $event, $region_str));
        }

        wp_remote_post($config->get_api_endpoint() . "/v1/sites/{$site_id}/warm", [
            'blocking' => false,
            'headers'  => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
    }

    /**
     * Fetches the last 25 runs (inline warm-runs + scheduled boost runs) for the current site.
     *
     * @return array{success: bool, runs?: array<array<string,mixed>>, message?: string}
     */
    public static function get_runs(): array {
        $config  = new Config();
        $api_key = $config->get_api_key();

        if (!self::is_valid_api_key($api_key)) {
            return ['success' => false, 'message' => __('No valid API key configured.', 'cacheboost-warmer')];
        }

        $site_id = $config->get_site_id();
        if ($site_id === null) {
            return ['success' => false, 'message' => __('Site not configured. Please test the connection in settings.', 'cacheboost-warmer')];
        }

        $base    = rtrim($config->get_api_endpoint(), '/');
        $headers = ['Authorization' => 'Bearer ' . $api_key, 'Accept' => 'application/json'];

        $resp = wp_remote_get($base . "/v1/runs?site_id={$site_id}&limit=25", ['timeout' => 10, 'headers' => $headers]);
        if (is_wp_error($resp)) {
            return ['success' => false, 'message' => $resp->get_error_message()];
        }
        if (wp_remote_retrieve_response_code($resp) !== 200) {
            return ['success' => false, 'message' => sprintf('HTTP %d', wp_remote_retrieve_response_code($resp))];
        }

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $runs = $body['data'] ?? [];

        foreach ($runs as &$r) {
            $r['_type'] = ($r['source_type'] ?? '') === 'inline' ? 'inline' : 'full';
        }
        unset($r);

        return ['success' => true, 'runs' => $runs];
    }

    /**
     * Blocking connectivity check used by the admin test button.
     *
     * Calls GET /v1/me (scopes) then GET /v1/sites (accessible sites with validation status).
     *
     * @return array{success: bool, scopes?: string[], sites?: array<array<string,mixed>>, message?: string}
     */
    public static function ping(string $api_key, string $endpoint): array {
        $base    = rtrim($endpoint, '/');
        $headers = ['Authorization' => 'Bearer ' . $api_key];

        $me = wp_remote_get($base . '/v1/me', ['timeout' => 10, 'headers' => $headers]);

        if (is_wp_error($me)) {
            Logger::log('api', 'GET /v1/me failed: ' . $me->get_error_message(), 'error');
            return ['success' => false, 'message' => $me->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($me);
        if ($code !== 200) {
            Logger::log('api', sprintf('GET /v1/me failed: HTTP %d', $code), 'error');
            return ['success' => false, 'message' => sprintf('HTTP %d', $code)];
        }

        $me_body    = json_decode(wp_remote_retrieve_body($me), true);
        $scopes_str = implode(', ', $me_body['scopes'] ?? []);
        $regions_str = implode(', ', $me_body['regions'] ?? []);
        Logger::log('api', sprintf('GET /v1/me OK — scopes: %s | regions: %s', $scopes_str, $regions_str ?: 'none'));

        $sites_resp = wp_remote_get($base . '/v1/sites', ['timeout' => 10, 'headers' => $headers]);
        $sites = [];
        if (!is_wp_error($sites_resp) && wp_remote_retrieve_response_code($sites_resp) === 200) {
            $sites = json_decode(wp_remote_retrieve_body($sites_resp), true) ?? [];
            Logger::log('api', sprintf('GET /v1/sites OK — %d site(s)', count($sites)));
        } elseif (is_wp_error($sites_resp)) {
            Logger::log('api', 'GET /v1/sites failed: ' . $sites_resp->get_error_message(), 'error');
        }

        return [
            'success' => true,
            'scopes'  => $me_body['scopes'] ?? [],
            'regions' => $me_body['regions'] ?? [],
            'sites'   => $sites,
        ];
    }
}
