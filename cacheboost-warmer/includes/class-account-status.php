<?php
namespace CacheBoostWarmer;

if (!defined('ABSPATH')) exit;

/**
 * Email verification state of the CacheBoost account behind the API key.
 *
 * Accounts created with "Connect to CacheBoost" must verify their email within 7 days;
 * after that the API refuses the key (403, code "email_unverified"). Warm requests are
 * fire-and-forget, so without this check the admin would never learn why warming stopped.
 */
class AccountStatus {

    public const TRANSIENT = 'cbwarmer_account_status';
    private const TTL_OK      = 43200; // 12 h: nothing to report
    private const TTL_PENDING = 3600;  // 1 h: the notice disappears soon after verification
    private const TTL_UNKNOWN = 900;   // 15 min: API unreachable, retry without slowing every admin page

    /**
     * @return array{state: 'ok'|'pending'|'blocked'|'unknown', deadline?: string}
     */
    public static function get(string $api_key): array {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        $status = self::fetch($api_key);
        $ttl    = match ($status['state']) {
            'ok'      => self::TTL_OK,
            'unknown' => self::TTL_UNKNOWN,
            default   => self::TTL_PENDING,
        };
        set_transient(self::TRANSIENT, $status, $ttl);
        return $status;
    }

    /**
     * @return array{state: 'ok'|'pending'|'blocked'|'unknown', deadline?: string}
     */
    public static function fetch(string $api_key): array {
        $response = wp_remote_get(rtrim((new Config())->get_api_endpoint(), '/') . '/v1/me', [
            'timeout' => 5,
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
        ]);
        if (is_wp_error($response)) {
            return ['state' => 'unknown'];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 403 && ($body['code'] ?? '') === 'email_unverified') {
            return ['state' => 'blocked'];
        }
        if ($code !== 200 || !is_array($body)) {
            return ['state' => 'unknown'];
        }
        // Older API versions do not send these fields: treat as verified.
        if (($body['email_verified'] ?? true) === false && !empty($body['email_verify_deadline'])) {
            return ['state' => 'pending', 'deadline' => (string) $body['email_verify_deadline']];
        }
        return ['state' => 'ok'];
    }

    public static function clear(): void {
        delete_transient(self::TRANSIENT);
    }
}
