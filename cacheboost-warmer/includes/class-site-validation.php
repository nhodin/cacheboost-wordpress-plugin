<?php
namespace CacheBoostWarmer;

if (!defined('ABSPATH')) exit;

/**
 * Validates domain ownership from the plugin instead of asking the user to upload
 * a {token}.html file or edit their theme.
 *
 * The proof is still served by the domain itself: CacheBoost fetches /{token}.html,
 * which this plugin answers only while a validation call is in flight. Holding an
 * API key alone must never be enough to validate a domain.
 */
class SiteValidation {

    const OPTION = 'cbwarmer_validation_token';

    /**
     * Blocking — for admin AJAX only.
     *
     * @return array{success: bool, message?: string}
     */
    public static function validate(string $api_key, int $site_id): array {
        $token = ApiClient::get_validation_token($api_key, $site_id);
        if ($token === null) {
            return ['success' => false, 'message' => __('Could not retrieve the validation token.', 'cacheboost-warmer')];
        }

        update_option(self::OPTION, $token);
        try {
            return ApiClient::validate_site($api_key, $site_id);
        } finally {
            // The file is only needed while CacheBoost checks it; don't leave it public.
            delete_option(self::OPTION);
        }
    }

    /** Answers GET /{token}.html while a validation is in progress. Hooked early on init. */
    public static function maybe_serve_token_file(): void {
        $uri   = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $token = self::token_for_path((string) wp_parse_url($uri, PHP_URL_PATH));
        if ($token === null) return;

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo esc_html($token);
        exit;
    }

    /** Returns the token to serve for this request path, or null if the request is not a pending validation. */
    public static function token_for_path(string $path): ?string {
        // Cheap URL check first so regular requests never hit the options table.
        if (!preg_match('#^/([A-Za-z0-9]+)\.html$#', $path, $m)) return null;

        $token = get_option(self::OPTION);
        if (!is_string($token) || $token === '' || !hash_equals($token, $m[1])) return null;

        return $token;
    }
}
