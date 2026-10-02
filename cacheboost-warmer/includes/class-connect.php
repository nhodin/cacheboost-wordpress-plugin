<?php
namespace CacheBoostWarmer;

if (!defined('ABSPATH')) exit;

/**
 * "Connect to CacheBoost" flow: replaces copy-pasting an API key.
 *
 * 1. authorize_url() sends the admin to app.cache-boost.com/connect/wordpress (sign up or log in, then consent).
 * 2. CacheBoost redirects back to the settings page with a one-time code.
 * 3. handle_callback() exchanges the code for an API key restricted to this site (PKCE S256:
 *    the code is useless without the verifier kept here, so a leaked return URL leaks nothing).
 * 4. ensure_full_warming_boost() creates the sitemap and Boost that Full warming needs.
 */
class Connect {

    public const APP_URL          = 'https://app.cache-boost.com';
    public const TRANSIENT_PREFIX = 'cbwarmer_connect_';
    private const TTL             = 900; // 15 min to sign up and come back

    public static function authorize_url(): string {
        $verifier = self::base64url(random_bytes(48));
        $state    = self::base64url(random_bytes(24));

        set_transient(self::TRANSIENT_PREFIX . $state, [
            'verifier' => $verifier,
            'user_id'  => get_current_user_id(),
        ], self::TTL);

        return self::APP_URL . '/connect/wordpress?' . http_build_query([
            'site_url'              => home_url(),
            'redirect_uri'          => admin_url('admin.php?page=cbwarmer'),
            'state'                 => $state,
            'code_challenge'        => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @return array{success: bool, reason?: string, api_key?: string, site_id?: int}
     */
    public static function handle_callback(string $state, string $code, string $error): array {
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $state)) {
            return ['success' => false, 'reason' => 'invalid_state'];
        }

        $transient = self::TRANSIENT_PREFIX . $state;
        $pending   = get_transient($transient);
        // The flow must come back to the admin who started it, once.
        if (!is_array($pending) || (int) ($pending['user_id'] ?? 0) !== get_current_user_id()) {
            return ['success' => false, 'reason' => 'invalid_state'];
        }
        delete_transient($transient);

        if ($error !== '') {
            return ['success' => false, 'reason' => 'denied'];
        }

        $response = wp_remote_post(rtrim((new Config())->get_api_endpoint(), '/') . '/v1/connect/token', [
            'timeout' => 15,
            'body'    => ['code' => $code, 'code_verifier' => $pending['verifier']],
        ]);

        if (is_wp_error($response)) {
            Logger::log('api', 'POST /v1/connect/token failed: ' . $response->get_error_message(), 'error');
            return ['success' => false, 'reason' => 'exchange_failed'];
        }

        $status = wp_remote_retrieve_response_code($response);
        $data   = json_decode(wp_remote_retrieve_body($response), true);
        if ($status !== 200 || !is_array($data) || !ApiClient::is_valid_api_key((string) ($data['api_key'] ?? ''))) {
            Logger::log('api', sprintf('POST /v1/connect/token failed: HTTP %d', $status), 'error');
            return ['success' => false, 'reason' => 'exchange_failed'];
        }

        Logger::log('api', sprintf('Connected to CacheBoost — site #%d', (int) ($data['site_id'] ?? 0)));

        return ['success' => true, 'api_key' => $data['api_key'], 'site_id' => (int) ($data['site_id'] ?? 0)];
    }

    /**
     * Full warming needs a Boost built from the site's sitemap. Reuses the first existing Boost
     * of the site, otherwise registers the sitemap and creates one. Returns the Boost ID, 0 on failure.
     *
     * @param string[] $regions Regions to warm from, in order of preference.
     */
    public static function ensure_full_warming_boost(string $api_key, int $site_id, array $regions): int {
        $existing = ApiClient::fetch_boosts_for_site($api_key, $site_id);
        if ($existing['success'] && !empty($existing['boosts'])) {
            return (int) $existing['boosts'][0]['id'];
        }

        $region = $regions[0] ?? '';
        if ($region === '') {
            return 0;
        }

        $base    = rtrim((new Config())->get_api_endpoint(), '/');
        $headers = ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'];

        $sitemap_url = self::sitemap_url();
        $sitemap_id  = 0;
        $r = wp_remote_post("{$base}/v1/sites/{$site_id}/sitemaps", [
            'timeout' => 10,
            'headers' => $headers,
            'body'    => wp_json_encode(['url' => $sitemap_url]),
        ]);
        if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 201) {
            $sitemap_id = (int) (json_decode(wp_remote_retrieve_body($r), true)['id'] ?? 0);
        } elseif (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 409) {
            // Already registered (e.g. a previous connection): look it up.
            $list = wp_remote_get("{$base}/v1/sites/{$site_id}/sitemaps", ['timeout' => 10, 'headers' => $headers]);
            foreach ((array) json_decode(is_wp_error($list) ? '[]' : wp_remote_retrieve_body($list), true) as $sm) {
                if (($sm['url'] ?? '') === $sitemap_url) {
                    $sitemap_id = (int) $sm['id'];
                }
            }
        }
        if ($sitemap_id === 0) {
            Logger::log('api', 'Could not register sitemap ' . $sitemap_url, 'error');
            return 0;
        }

        $r = wp_remote_post("{$base}/v1/boosts", [
            'timeout' => 10,
            'headers' => $headers,
            'body'    => wp_json_encode([
                'name'        => 'WordPress — full site',
                'site_id'     => $site_id,
                'source_type' => 'sitemap',
                'region'      => [$region],
                'sitemap_ids' => [$sitemap_id],
            ]),
        ]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 201) {
            Logger::log('api', 'Could not create the Full warming Boost', 'error');
            return 0;
        }

        $boost_id = (int) (json_decode(wp_remote_retrieve_body($r), true)['id'] ?? 0);
        Logger::log('api', sprintf('Full warming Boost #%d created from %s', $boost_id, $sitemap_url));
        return $boost_id;
    }

    /** Yoast SEO and Rank Math replace the core sitemap with /sitemap_index.xml. */
    public static function sitemap_url(): string {
        if (defined('WPSEO_VERSION') || class_exists('RankMath')) {
            return home_url('/sitemap_index.xml');
        }
        $core = function_exists('get_sitemap_url') ? get_sitemap_url('index') : false;
        return $core ?: home_url('/wp-sitemap.xml');
    }

    /** PKCE S256: base64url(SHA-256(verifier)) without padding (RFC 7636). */
    public static function challenge(string $verifier): string {
        return self::base64url(hash('sha256', $verifier, true));
    }

    private static function base64url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
