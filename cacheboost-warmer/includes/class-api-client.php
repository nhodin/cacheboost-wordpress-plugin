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

    public static function trigger_warm(int $site_id, array $urls): void {
        if (!self::can_notify() || $site_id <= 0 || empty($urls)) return;

        $config     = new Config();
        $api_key    = $config->get_api_key();
        $regions    = $config->get_regions();
        $region_str = !empty($regions) ? implode(', ', $regions) : 'all';

        $body = ['urls' => array_values(array_unique($urls))];
        if (!empty($regions)) {
            $body['region'] = $regions;
        }

        Logger::log('api', sprintf('POST /v1/sites/%d/warm — %d URL(s), regions: %s', $site_id, count($urls), $region_str));

        wp_remote_post(rtrim($config->get_api_endpoint(), '/') . "/v1/sites/{$site_id}/warm", [
            'blocking' => false,
            'headers'  => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
    }

    public static function trigger_boost_run(int $boost_id): void {
        if (!self::can_notify() || $boost_id <= 0) return;

        $config  = new Config();
        $api_key = $config->get_api_key();

        Logger::log('api', sprintf('POST /v1/boosts/%d/run — full site warm', $boost_id));

        wp_remote_post(rtrim($config->get_api_endpoint(), '/') . "/v1/boosts/{$boost_id}/run", [
            'blocking' => false,
            'headers'  => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => '{}',
        ]);
    }

    /**
     * Fetches scheduled boosts for a given site. Blocking — for admin AJAX only.
     *
     * @return array{success: bool, boosts: list<array{id:int,name:string}>, message?: string}
     */
    public static function fetch_boosts_for_site(string $api_key, int $site_id): array {
        $r = wp_remote_get(rtrim((new Config())->get_api_endpoint(), '/') . '/v1/boosts', [
            'timeout' => 10,
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
        ]);

        if (is_wp_error($r)) {
            return ['success' => false, 'boosts' => [], 'message' => $r->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($r);
        if ($code !== 200) {
            return ['success' => false, 'boosts' => [], 'message' => sprintf('HTTP %d', $code)];
        }

        $all = json_decode(wp_remote_retrieve_body($r), true);
        if (!is_array($all)) {
            return ['success' => true, 'boosts' => []];
        }

        $boosts = [];
        foreach ($all as $boost) {
            if (isset($boost['site_id']) && (int) $boost['site_id'] === $site_id) {
                $boosts[] = ['id' => (int) $boost['id'], 'name' => (string) $boost['name']];
            }
        }

        return ['success' => true, 'boosts' => $boosts];
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
     * Fetches the detail of a single run including live cache stats.
     *
     * @return array{success: bool, run?: array<string,mixed>, message?: string}
     */
    public static function get_run(int $run_id): array {
        $config  = new Config();
        $api_key = $config->get_api_key();

        if (!self::is_valid_api_key($api_key)) {
            return ['success' => false, 'message' => __('No valid API key configured.', 'cacheboost-warmer')];
        }

        $base    = rtrim($config->get_api_endpoint(), '/');
        $headers = ['Authorization' => 'Bearer ' . $api_key, 'Accept' => 'application/json'];

        $resp = wp_remote_get($base . "/v1/runs/{$run_id}", ['timeout' => 10, 'headers' => $headers]);
        if (is_wp_error($resp)) {
            return ['success' => false, 'message' => $resp->get_error_message()];
        }
        if (wp_remote_retrieve_response_code($resp) !== 200) {
            return ['success' => false, 'message' => sprintf('HTTP %d', wp_remote_retrieve_response_code($resp))];
        }

        $run = json_decode(wp_remote_retrieve_body($resp), true);
        return ['success' => true, 'run' => $run];
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
