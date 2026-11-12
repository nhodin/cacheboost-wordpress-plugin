<?php
namespace CacheBoostWarmer;

if (!defined('ABSPATH')) exit;

/**
 * Translates one cache plugin's own settings into the normalized keys of the environment snapshot
 * (POST /v1/sites/{id}/environment), so the CacheBoost diagnostics never read a plugin-specific option.
 *
 * Privacy rule: an adapter reads only the keys it lists, never serializes a whole option, and the
 * output goes through normalize(), which drops anything that is not a known key of the expected type.
 * Several plugins keep Cloudflare credentials or licence keys next to their cache settings.
 *
 * Option and constant names were checked against each plugin's source; see the class comments.
 */
abstract class CachePluginAdapter {

    /** Normalized setting => type. All keys are always sent, null when unknown. */
    public const SETTING_KEYS = [
        'page_cache'             => 'bool',
        'lifespan_hours'         => 'int',
        'mobile_cache'           => 'bool',
        'separate_mobile_cache'  => 'bool',
        'logged_in_cache'        => 'bool',
        'preload'                => 'bool',
        'ignored_query_params'   => 'list',
        'cdn_integration'        => 'string',
        'cdn_purge'              => 'bool',
        'rejected_cookies_count' => 'int',
        'rejected_uris_count'    => 'int',
    ];

    private const MAX_LIST = 200;

    /** Slug sent to CacheBoost (the wordpress.org slug, "flyingpress" for FlyingPress). */
    abstract public function slug(): string;

    /** 1: full settings, 2: main settings, 3: detection and version only. */
    abstract public function tier(): int;

    /** @return string[] Plugin folder names, premium editions included. */
    abstract public function folders(): array;

    /** @return string[] Options whose update changes the snapshot. */
    public function watched_options(): array {
        return [];
    }

    /** Raw normalized values. Only called while the plugin is active, so its settings are meaningful. */
    protected function read(): array {
        return [];
    }

    final public function settings(): array {
        return self::normalize($this->read());
    }

    /** @return array<string, mixed> Every key of SETTING_KEYS, typed, null when unknown. */
    public static function normalize(array $raw): array {
        $out = [];
        foreach (self::SETTING_KEYS as $key => $type) {
            $value = $raw[$key] ?? null;
            if ($value === null) {
                $out[$key] = null;
                continue;
            }
            switch ($type) {
                case 'bool':
                    $out[$key] = (bool) $value;
                    break;
                case 'int':
                    $out[$key] = is_numeric($value) ? max(0, (int) $value) : null;
                    break;
                case 'list':
                    // Query parameter names only: anything else (a value that is not a name) is dropped.
                    $names = array_filter(
                        self::entries($value),
                        fn ($name) => (bool) preg_match('/^[A-Za-z0-9_.*\[\]-]{1,64}$/', $name)
                    );
                    $out[$key] = array_slice(array_values(array_unique($names)), 0, self::MAX_LIST);
                    break;
                case 'string':
                    $value     = strtolower(trim((string) $value));
                    $out[$key] = preg_match('/^[a-z0-9.-]{1,32}$/', $value) ? $value : null;
                    break;
            }
        }
        return $out;
    }

    /** The listed keys of an option, nothing else. */
    protected static function pick(mixed $option, array $keys): array {
        if (is_object($option)) {
            $option = get_object_vars($option);
        }
        if (!is_array($option)) {
            return [];
        }
        return array_intersect_key($option, array_flip($keys));
    }

    /** Seconds to whole hours, rounded up so a short lifespan never reads as 0 (= never expires). */
    protected static function hours(mixed $seconds): ?int {
        if (!is_numeric($seconds)) return null;
        $seconds = (int) $seconds;
        return $seconds <= 0 ? 0 : (int) ceil($seconds / 3600);
    }

    /** List settings are stored as arrays or as one entry per line, depending on the plugin. */
    protected static function entries(mixed $value): array {
        if (is_string($value)) {
            $value = preg_split('/[\r\n]+/', $value);
        }
        if (!is_array($value)) return [];
        return array_values(array_filter(array_map(
            fn ($entry) => is_scalar($entry) ? trim((string) $entry) : '',
            $value
        ), fn ($entry) => $entry !== ''));
    }

    protected static function count_entries(mixed $value): ?int {
        return $value === null ? null : count(self::entries($value));
    }

    /** The site's own settings, else the network-wide ones some plugins keep in a site option on multisite. */
    protected static function blog_or_network_option(string $name): mixed {
        $value = get_option($name, []);
        if (empty($value) && is_multisite()) {
            $value = get_site_option($name, []);
        }
        return $value;
    }
}

/** Plugins only detected, with their version: their settings are not read. */
final class DetectionOnlyAdapter extends CachePluginAdapter {
    public function __construct(private string $slug, private array $folders) {}

    public function slug(): string { return $this->slug; }
    public function tier(): int { return 3; }
    public function folders(): array { return $this->folders; }
}

/** WP Rocket 4.4.1: option wp_rocket_settings (WP_ROCKET_SLUG), inc/Engine/Admin/Settings/Settings.php. */
final class WpRocketAdapter extends CachePluginAdapter {
    public function slug(): string { return 'wp-rocket'; }
    public function tier(): int { return 1; }
    public function folders(): array { return ['wp-rocket']; }
    public function watched_options(): array { return ['wp_rocket_settings']; }

    protected function read(): array {
        // Never the whole option: it also holds cloudflare_api_key, cloudflare_email and the Sucuri API key.
        $o = self::pick(get_option('wp_rocket_settings', []), [
            'cache_mobile', 'do_caching_mobile_files', 'cache_logged_user', 'purge_cron_interval',
            'purge_cron_unit', 'manual_preload', 'cache_reject_cookies', 'cache_reject_uri',
            'do_cloudflare', 'sucury_waf_cache_sync',
        ]);

        $cloudflare = !empty($o['do_cloudflare']);
        $sucuri     = !empty($o['sucury_waf_cache_sync']);

        return [
            // WP Rocket has no page cache switch: the cache is on while the plugin is active.
            'page_cache'             => true,
            'lifespan_hours'         => $this->lifespan($o),
            'mobile_cache'           => $o['cache_mobile'] ?? null,
            'separate_mobile_cache'  => isset($o['cache_mobile']) ? !empty($o['cache_mobile']) && !empty($o['do_caching_mobile_files']) : null,
            'logged_in_cache'        => $o['cache_logged_user'] ?? null,
            'preload'                => $o['manual_preload'] ?? null,
            // Parameter names as array keys, from WP Rocket's dynamic lists (utm_*, gclid, fbclid…).
            'ignored_query_params'   => function_exists('rocket_get_ignored_parameters') ? array_keys((array) rocket_get_ignored_parameters()) : null,
            // The Cloudflare add-on and the Sucuri sync purge on rocket_after_clean_domain.
            'cdn_integration'        => $cloudflare ? 'cloudflare' : ($sucuri ? 'sucuri' : null),
            'cdn_purge'              => $cloudflare || $sucuri,
            'rejected_cookies_count' => self::count_entries($o['cache_reject_cookies'] ?? null),
            'rejected_uris_count'    => self::count_entries($o['cache_reject_uri'] ?? null),
        ];
    }

    /** purge_cron_interval (0 = never) in purge_cron_unit: HOUR_IN_SECONDS, DAY_IN_SECONDS, or MINUTE_IN_SECONDS before 3.x. */
    private function lifespan(array $o): ?int {
        if (!isset($o['purge_cron_interval']) || !is_numeric($o['purge_cron_interval'])) return null;
        $seconds_per_unit = ['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400];
        $unit = $seconds_per_unit[$o['purge_cron_unit'] ?? 'HOUR_IN_SECONDS'] ?? 3600;
        return self::hours((int) $o['purge_cron_interval'] * $unit);
    }
}

/**
 * W3 Total Cache 2.11.0: settings live in a file (wp-content/w3tc-config), read through w3tc_config()
 * (W3TC\Config::get_*, keys from ConfigKeys.php). Saving them fires w3tc_config_save.
 */
final class W3TotalCacheAdapter extends CachePluginAdapter {
    private const FSD_ENGINES = ['bunnycdn' => 'bunny', 'cloudfront' => 'cloudfront', 'transparentcdn' => 'transparentcdn'];

    public function slug(): string { return 'w3-total-cache'; }
    public function tier(): int { return 1; }
    public function folders(): array { return ['w3-total-cache']; }

    protected function read(): array {
        $c = function_exists('w3tc_config') ? w3tc_config() : null;
        if (!is_object($c) || !method_exists($c, 'get_boolean')) return [];

        $page_cache = $c->get_boolean('pgcache.enabled');

        // A user agent group with its own theme or redirect gets its own cached pages.
        $separate_mobile = false;
        if ($c->get_boolean('mobile.enabled')) {
            foreach ((array) $c->get_array('mobile.rgroups') as $group) {
                $separate_mobile = $separate_mobile || !empty($group['enabled']);
            }
        }

        // The Cloudflare extension purges Cloudflare on every W3TC flush; Full Site Delivery purges its CDN.
        $cdn = null;
        if ($c->is_extension_active('cloudflare')) {
            $cdn = 'cloudflare';
        } elseif ($c->get_boolean('cdnfsd.enabled')) {
            $engine = $c->get_string('cdnfsd.engine');
            $cdn    = self::FSD_ENGINES[$engine] ?? $engine;
        }

        return [
            'page_cache'             => $page_cache,
            'lifespan_hours'         => self::hours($c->get_integer('pgcache.lifetime')),
            // W3TC serves the same page cache to every device unless a user agent group splits it.
            'mobile_cache'           => $page_cache,
            'separate_mobile_cache'  => $separate_mobile,
            'logged_in_cache'        => !$c->get_boolean('pgcache.reject.logged'),
            'preload'                => $c->get_boolean('pgcache.prime.enabled'),
            'ignored_query_params'   => $c->get_array('pgcache.accept.qs'),
            'cdn_integration'        => $cdn,
            'cdn_purge'              => $cdn !== null,
            'rejected_cookies_count' => self::count_entries($c->get_array('pgcache.reject.cookie')),
            'rejected_uris_count'    => self::count_entries($c->get_array('pgcache.reject.uri')),
        ];
    }
}

/**
 * LiteSpeed Cache 7.9.3: each setting is its own option, litespeed.conf.<id> (src/base.cls.php, Root::name()).
 * Values are read through the litespeed_conf filter (src/api.cls.php), which applies constant overrides.
 */
final class LiteSpeedCacheAdapter extends CachePluginAdapter {
    private const IDS = [
        'cache', 'cache-ttl_pub', 'cache-mobile', 'cache-priv', 'cache-exc_cookies', 'cache-exc',
        'cache-drop_qs', 'crawler', 'cdn-cloudflare', 'cdn-cloudflare_clear', 'cdn-quic',
    ];

    public function slug(): string { return 'litespeed-cache'; }
    public function tier(): int { return 1; }
    public function folders(): array { return ['litespeed-cache']; }

    public function watched_options(): array {
        return array_map(fn ($id) => 'litespeed.conf.' . $id, self::IDS);
    }

    protected function read(): array {
        $conf = [];
        foreach (self::IDS as $id) {
            $value = apply_filters('litespeed_conf', $id);
            // Without LiteSpeed's callback the filter hands the id back.
            $conf[$id] = $value === $id ? null : $value;
        }

        $cloudflare = !empty($conf['cdn-cloudflare']);
        $quic       = !$cloudflare && !empty($conf['cdn-quic']);

        return [
            'page_cache'             => $conf['cache'],
            'lifespan_hours'         => self::hours($conf['cache-ttl_pub']),
            // Mobile visitors get cached pages either way; cache-mobile gives them their own copy.
            'mobile_cache'           => $conf['cache'] === null ? null : true,
            'separate_mobile_cache'  => $conf['cache-mobile'],
            // Private (per-user) cache for logged-in users.
            'logged_in_cache'        => $conf['cache-priv'],
            'preload'                => $conf['crawler'],
            'ignored_query_params'   => $conf['cache-drop_qs'],
            'cdn_integration'        => $cloudflare ? 'cloudflare' : ($quic ? 'quic.cloud' : null),
            // Purge All clears Cloudflare only with cdn-cloudflare_clear (src/purge.cls.php);
            // QUIC.cloud follows LiteSpeed's purge headers.
            'cdn_purge'              => ($cloudflare && !empty($conf['cdn-cloudflare_clear'])) || $quic,
            'rejected_cookies_count' => self::count_entries($conf['cache-exc_cookies']),
            'rejected_uris_count'    => self::count_entries($conf['cache-exc']),
        ];
    }
}

/**
 * WP Super Cache 3.1.5: settings are globals loaded from wp-content/wp-cache-config.php
 * (wp-cache-config-sample.php, inc/admin-ui.php, inc/settings-forms.php). Not options: no update hook.
 */
final class WpSuperCacheAdapter extends CachePluginAdapter {
    public function slug(): string { return 'wp-super-cache'; }
    public function tier(): int { return 1; }
    public function folders(): array { return ['wp-super-cache']; }

    protected function read(): array {
        $g = fn (string $name) => $GLOBALS[$name] ?? null;

        $page_cache = $g('cache_enabled');
        $ignore_tracking = !empty($g('wpsc_ignore_tracking_parameters'));

        return [
            'page_cache'             => $page_cache,
            'lifespan_hours'         => self::hours($g('cache_max_time')),
            'mobile_cache'           => $page_cache === null ? null : (bool) $page_cache,
            // "Mobile device support" caches mobile visitors separately.
            'separate_mobile_cache'  => $g('wp_cache_mobile_enabled'),
            // 0: "Enable caching for all visitors", 1: not for visitors with a cookie, 2: not for logged-in visitors.
            'logged_in_cache'        => $g('wp_cache_not_logged_in') === null ? null : (int) $g('wp_cache_not_logged_in') === 0,
            // Refresh preloaded cache files every N minutes, 0 to disable.
            'preload'                => $g('wp_cache_preload_interval') === null ? null : (int) $g('wp_cache_preload_interval') > 0,
            'ignored_query_params'   => $ignore_tracking ? $g('wpsc_tracking_parameters') : [],
            // Its CDN option only rewrites static file URLs: nothing purges a CDN.
            'cdn_integration'        => null,
            'cdn_purge'              => false,
            'rejected_cookies_count' => self::count_entries($g('wpsc_rejected_cookies')),
            'rejected_uris_count'    => self::count_entries($g('cache_rejected_uri')),
        ];
    }
}

/**
 * FlyingPress: not public. Option FLYING_PRESS_CONFIG (FlyingPress\Config::$config, persisted by
 * Config::update_config()) is confirmed by plugins integrating with it. The cache_* key names below
 * follow the FlyingPress documentation and are NOT verified against its code: an unknown key reads as null.
 */
final class FlyingPressAdapter extends CachePluginAdapter {
    public function slug(): string { return 'flyingpress'; }
    public function tier(): int { return 1; }
    public function folders(): array { return ['flying-press']; }
    public function watched_options(): array { return ['FLYING_PRESS_CONFIG']; }

    protected function read(): array {
        // The option also holds the licence key: whitelist only.
        $o = self::pick(get_option('FLYING_PRESS_CONFIG', []), [
            'cache', 'cache_lifespan', 'cache_mobile', 'cache_logged_in', 'cache_preload',
            'cache_ignore_queries', 'cache_bypass_cookies', 'cache_bypass_urls', 'cdn', 'cdn_type',
        ]);

        $cdn = !empty($o['cdn']) ? (($o['cdn_type'] ?? '') === 'flying_cdn' ? 'flyingcdn' : 'custom') : null;

        return [
            // The page cache has no switch in recent versions; older ones stored it under "cache".
            'page_cache'             => array_key_exists('cache', $o) ? (bool) $o['cache'] : true,
            'lifespan_hours'         => $this->lifespan($o['cache_lifespan'] ?? null),
            'mobile_cache'           => true,
            'separate_mobile_cache'  => $o['cache_mobile'] ?? null,
            'logged_in_cache'        => $o['cache_logged_in'] ?? null,
            'preload'                => $o['cache_preload'] ?? null,
            'ignored_query_params'   => $o['cache_ignore_queries'] ?? null,
            'cdn_integration'        => $cdn,
            // Whether a FlyingPress purge also purges Cloudflare's HTML cache is unknown: not reported.
            'cdn_purge'              => null,
            'rejected_cookies_count' => self::count_entries($o['cache_bypass_cookies'] ?? null),
            'rejected_uris_count'    => self::count_entries($o['cache_bypass_urls'] ?? null),
        ];
    }

    /** "never", a number of hours, or "<n>h" / "<n>d". */
    private function lifespan(mixed $value): ?int {
        if ($value === null) return null;
        if ($value === 'never' || $value === 0 || $value === '0') return 0;
        if (is_numeric($value)) return (int) $value;
        if (is_string($value) && preg_match('/^(\d+)\s*([hd])$/i', $value, $m)) {
            return (int) $m[1] * (strtolower($m[2]) === 'd' ? 24 : 1);
        }
        return null;
    }
}

/**
 * WP Fastest Cache 1.5.5: option WpFastestCache (JSON, inc/admin.php), CDNs in WpFastestCacheCDN,
 * timeout rules as wp_fastest_cache[_N] cron events with {"prefix":"all","content":"all"} (inc/admin.php).
 */
final class WpFastestCacheAdapter extends CachePluginAdapter {
    public function slug(): string { return 'wp-fastest-cache'; }
    public function tier(): int { return 2; }
    public function folders(): array { return ['wp-fastest-cache']; }
    public function watched_options(): array { return ['WpFastestCache', 'WpFastestCacheCDN']; }

    protected function read(): array {
        $o = self::pick(json_decode((string) get_option('WpFastestCache', ''), true), [
            'wpFastestCacheStatus', 'wpFastestCacheMobile', 'wpFastestCacheMobileTheme', 'wpFastestCacheLoggedInUser',
        ]);

        $mobile_off   = isset($o['wpFastestCacheMobile']);       // "Don't show the cached version for desktop to mobile devices"
        $mobile_theme = isset($o['wpFastestCacheMobileTheme']);  // Premium: separate cache for mobile

        return [
            'page_cache'            => isset($o['wpFastestCacheStatus']),
            'lifespan_hours'        => $this->lifespan(),
            'mobile_cache'          => !$mobile_off || $mobile_theme,
            'separate_mobile_cache' => $mobile_off && $mobile_theme,
            'logged_in_cache'       => !isset($o['wpFastestCacheLoggedInUser']),
            'cdn_integration'       => $this->cloudflare() ? 'cloudflare' : null,
            // A WPFC cache clear purges Cloudflare (CdnWPFC::cloudflare_clear_cache).
            'cdn_purge'             => $this->cloudflare(),
        ];
    }

    /** Only the id and status of each CDN entry: for Cloudflare, cdnurl and originurl hold the email and API key. */
    private function cloudflare(): bool {
        foreach ((array) json_decode((string) get_option('WpFastestCacheCDN', ''), true) as $cdn) {
            $cdn = self::pick($cdn, ['id', 'status']);
            if (($cdn['id'] ?? '') === 'cloudflare' && ($cdn['status'] ?? '') !== 'pause') return true;
        }
        return false;
    }

    /** Shortest "clear all" timeout rule, 0 without one. */
    private function lifespan(): int {
        $schedules = wp_get_schedules();
        $shortest  = 0;
        foreach ((array) _get_cron_array() as $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                if (!preg_match('/^wp_fastest_cache(_\d+)?$/', (string) $hook)) continue;
                foreach ((array) $events as $event) {
                    $args = json_decode((string) ($event['args'][0] ?? ''), true);
                    if (($args['prefix'] ?? '') !== 'all') continue;
                    $interval = (int) ($schedules[$event['schedule'] ?? '']['interval'] ?? 0);
                    if ($interval > 0 && ($shortest === 0 || $interval < $shortest)) $shortest = $interval;
                }
            }
        }
        return (int) self::hours($shortest);
    }
}

/** Cache Enabler 1.8.16: option cache_enabler (inc/cache_enabler.class.php, get_default_settings()). */
final class CacheEnablerAdapter extends CachePluginAdapter {
    public function slug(): string { return 'cache-enabler'; }
    public function tier(): int { return 2; }
    public function folders(): array { return ['cache-enabler']; }
    public function watched_options(): array { return ['cache_enabler']; }

    protected function read(): array {
        $o = self::pick(get_option('cache_enabler', []), ['cache_expires', 'cache_expiry_time', 'mobile_cache']);

        return [
            'page_cache'            => true,
            // cache_expiry_time is in hours; cached pages never expire unless cache_expires is set.
            'lifespan_hours'        => empty($o['cache_expires']) ? 0 : (int) ($o['cache_expiry_time'] ?? 0),
            'mobile_cache'          => true,
            'separate_mobile_cache' => $o['mobile_cache'] ?? null,
            // Logged-in users always bypass the cache.
            'logged_in_cache'       => false,
            'cdn_integration'       => null,
            'cdn_purge'             => false,
        ];
    }
}

/**
 * Breeze 3.9.3: option breeze_basic_settings (inc/breeze-configuration.php, Breeze_Options_Reader).
 * On Cloudways, Breeze_CloudFlare_Helper purges Cloudflare Enterprise when its constants are defined.
 */
final class BreezeAdapter extends CachePluginAdapter {
    public function slug(): string { return 'breeze'; }
    public function tier(): int { return 2; }
    public function folders(): array { return ['breeze']; }
    public function watched_options(): array { return ['breeze_basic_settings']; }

    protected function read(): array {
        $o = self::pick(self::blog_or_network_option('breeze_basic_settings'), [
            'breeze-active', 'breeze-b-ttl', 'breeze-mobile-cache', 'breeze-mobile-separate', 'breeze-disable-admin',
        ]);

        $cloudflare = class_exists('Breeze_CloudFlare_Helper') && method_exists('Breeze_CloudFlare_Helper', 'is_cloudflare_enabled')
            && \Breeze_CloudFlare_Helper::is_cloudflare_enabled();

        return [
            'page_cache'            => isset($o['breeze-active']) ? (string) $o['breeze-active'] === '1' : null,
            // Minutes.
            'lifespan_hours'        => isset($o['breeze-b-ttl']) ? self::hours((int) $o['breeze-b-ttl'] * 60) : null,
            'mobile_cache'          => isset($o['breeze-mobile-cache']) ? (string) $o['breeze-mobile-cache'] === '1' : null,
            'separate_mobile_cache' => isset($o['breeze-mobile-separate']) ? (string) $o['breeze-mobile-separate'] === '1' : null,
            // Roles for which the cache stays on while logged in.
            'logged_in_cache'       => isset($o['breeze-disable-admin']) ? in_array(1, array_map('intval', (array) $o['breeze-disable-admin']), true) : null,
            'cdn_integration'       => $cloudflare ? 'cloudflare' : null,
            'cdn_purge'             => $cloudflare,
        ];
    }
}

/** SiteGround Speed Optimizer 7.8.6: siteground_optimizer_* options (core/Options, helpers/helpers.php). */
final class SiteGroundOptimizerAdapter extends CachePluginAdapter {
    private const OPTIONS = [
        'siteground_optimizer_enable_cache', 'siteground_optimizer_file_caching',
        'siteground_optimizer_user_agent_header', 'siteground_optimizer_logged_in_cache',
    ];

    public function slug(): string { return 'sg-cachepress'; }
    public function tier(): int { return 2; }
    public function folders(): array { return ['sg-cachepress']; }
    public function watched_options(): array { return self::OPTIONS; }

    protected function read(): array {
        $on = fn (string $name) => (int) get_option($name, 0) === 1;

        return [
            // Dynamic (server) caching or file-based caching.
            'page_cache'            => $on('siteground_optimizer_enable_cache') || $on('siteground_optimizer_file_caching'),
            'mobile_cache'          => true,
            // "Browser-specific caching" keeps a separate copy per device type.
            'separate_mobile_cache' => $on('siteground_optimizer_user_agent_header'),
            'logged_in_cache'       => $on('siteground_optimizer_logged_in_cache'),
        ];
    }
}

/**
 * Hummingbird 4.0.0: option wphb_settings (core/class-settings.php) for the module switches and the
 * Cloudflare integration, wp-content/wphb-cache/wphb-cache.php (JSON) for the page cache settings
 * (core/modules/class-page-cache.php).
 */
final class HummingbirdAdapter extends CachePluginAdapter {
    public function slug(): string { return 'hummingbird-performance'; }
    public function tier(): int { return 2; }
    public function folders(): array { return ['hummingbird-performance', 'wp-hummingbird']; }
    public function watched_options(): array { return ['wphb_settings']; }

    protected function read(): array {
        $settings   = self::blog_or_network_option('wphb_settings');
        $page_cache = self::pick($settings['page_cache'] ?? [], ['enabled', 'preload']);
        // email, api_key and zone sit in the same group.
        $cloudflare = self::pick($settings['cloudflare'] ?? [], ['enabled', 'connected']);
        $cf_on      = !empty($cloudflare['enabled']) && !empty($cloudflare['connected']);

        $file   = WP_CONTENT_DIR . '/wphb-cache/wphb-cache.php';
        $config = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $flags  = self::pick($config['settings'] ?? [], ['mobile', 'logged_in']);
        $clear  = self::pick($config['clear_interval'] ?? [], ['enabled', 'interval']);

        return [
            'page_cache'      => $page_cache['enabled'] ?? null,
            // clear_interval.interval is in hours.
            'lifespan_hours'  => is_array($config) ? (!empty($clear['enabled']) ? (int) ($clear['interval'] ?? 0) : 0) : null,
            'mobile_cache'    => $flags['mobile'] ?? null,
            'logged_in_cache' => $flags['logged_in'] ?? null,
            'preload'         => $page_cache['preload'] ?? null,
            'cdn_integration' => $cf_on ? 'cloudflare' : null,
            'cdn_purge'       => $cf_on,
        ];
    }
}

/** WP-Optimize 4.7.0: option wpo_cache_config, a network option on multisite (cache/class-wpo-cache-config.php). */
final class WpOptimizeAdapter extends CachePluginAdapter {
    public function slug(): string { return 'wp-optimize'; }
    public function tier(): int { return 2; }
    public function folders(): array { return ['wp-optimize', 'wp-optimize-premium']; }
    public function watched_options(): array { return ['wpo_cache_config']; }

    protected function read(): array {
        $o = self::pick(self::blog_or_network_option('wpo_cache_config'), [
            'enable_page_caching', 'page_cache_length', 'enable_mobile_caching', 'enable_user_caching',
            'enable_sitemap_preload', 'enable_schedule_preload', 'cache_ignore_query_variables',
            'cache_exception_cookies', 'cache_exception_urls',
        ]);

        return [
            'page_cache'             => $o['enable_page_caching'] ?? null,
            'lifespan_hours'         => self::hours($o['page_cache_length'] ?? null),
            // Without "mobile caching", mobile visitors share the desktop files.
            'mobile_cache'           => true,
            'separate_mobile_cache'  => $o['enable_mobile_caching'] ?? null,
            'logged_in_cache'        => $o['enable_user_caching'] ?? null,
            'preload'                => isset($o['enable_page_caching']) ? !empty($o['enable_sitemap_preload']) || !empty($o['enable_schedule_preload']) : null,
            'ignored_query_params'   => $o['cache_ignore_query_variables'] ?? null,
            'cdn_integration'        => null,
            'cdn_purge'              => false,
            'rejected_cookies_count' => self::count_entries($o['cache_exception_cookies'] ?? null),
            'rejected_uris_count'    => self::count_entries($o['cache_exception_urls'] ?? null),
        ];
    }
}

/**
 * Super Page Cache 5.3.10: option swcfpc_config (src/Services/Settings_Store.php, keys in src/Constants.php).
 * cf_cache_enabled is the Cloudflare page cache, cf_fallback_cache the disk cache at the origin.
 */
final class SuperPageCacheAdapter extends CachePluginAdapter {
    public function slug(): string { return 'wp-cloudflare-page-cache'; }
    public function tier(): int { return 2; }
    public function folders(): array { return ['wp-cloudflare-page-cache']; }
    public function watched_options(): array { return ['swcfpc_config']; }

    protected function read(): array {
        // The same option holds cf_email, cf_apikey, cf_apitoken and the zone id: whitelist only.
        $o = self::pick(get_option('swcfpc_config', []), ['cf_cache_enabled', 'cf_fallback_cache', 'cf_fallback_cache_ttl']);

        $cloudflare = !empty($o['cf_cache_enabled']);
        $disk       = $cloudflare && !empty($o['cf_fallback_cache']);

        return [
            'page_cache'      => $disk,
            // Seconds, 0 = never.
            'lifespan_hours'  => $disk ? self::hours($o['cf_fallback_cache_ttl'] ?? null) : null,
            'cdn_integration' => $cloudflare ? 'cloudflare' : null,
            'cdn_purge'       => $cloudflare,
        ];
    }
}

/** Nginx Helper 3.0.0: site option rt_wp_nginx_helper_options (admin/class-nginx-helper-admin.php). */
final class NginxHelperAdapter extends CachePluginAdapter {
    public function slug(): string { return 'nginx-helper'; }
    public function tier(): int { return 2; }
    public function folders(): array { return ['nginx-helper']; }
    public function watched_options(): array { return ['rt_wp_nginx_helper_options']; }

    protected function read(): array {
        // redis_password lives in the same option.
        $o = self::pick(get_site_option('rt_wp_nginx_helper_options', []), ['enable_purge', 'preload_cache']);

        return [
            // The Nginx (FastCGI or Redis) page cache it purges sits at the origin, not on a CDN.
            'page_cache'      => $o['enable_purge'] ?? null,
            'preload'         => $o['preload_cache'] ?? null,
            'cdn_integration' => null,
            'cdn_purge'       => false,
        ];
    }
}

/** Proxy Cache Purge 5.2.0: purges Varnish on every change; site option vhp_varnish_devmode (varnish-http-purge.php). */
final class ProxyCachePurgeAdapter extends CachePluginAdapter {
    public function slug(): string { return 'varnish-http-purge'; }
    public function tier(): int { return 2; }
    public function folders(): array { return ['varnish-http-purge']; }
    public function watched_options(): array { return ['vhp_varnish_devmode']; }

    protected function read(): array {
        $devmode = self::pick(get_site_option('vhp_varnish_devmode', []), ['active', 'expire']);
        $bypass  = !empty($devmode['active']) && (int) ($devmode['expire'] ?? 0) > time();

        return [
            // Development mode makes Varnish bypass the cache until it expires.
            'page_cache'      => !$bypass,
            'cdn_integration' => 'varnish',
            'cdn_purge'       => true,
        ];
    }
}

/**
 * Jetpack Boost 4.x: the page cache module is the option jetpack_boost_status_page-cache
 * (Status::get_option_name(), app/lib/class-status.php; module slug page_cache). Its other settings live
 * in a file (wp-content/boost-cache/config.php), not in options. The lifespan is the constant
 * JETPACK_BOOST_CACHE_DURATION (one hour by default), left unread.
 */
final class JetpackBoostAdapter extends CachePluginAdapter {
    public function slug(): string { return 'jetpack-boost'; }
    public function tier(): int { return 2; }
    public function folders(): array { return ['jetpack-boost']; }
    public function watched_options(): array { return ['jetpack_boost_status_page-cache']; }

    protected function read(): array {
        return [
            'page_cache'      => (bool) get_option('jetpack_boost_status_page-cache', false),
            'cdn_integration' => null,
            'cdn_purge'       => false,
        ];
    }
}

/**
 * SpeedyCache 1.4.3: option speedycache_options (speedycache.php), keys status, logged_in_user, mobile,
 * mobile_theme, preload, purge_interval, purge_interval_unit (main/util.php cache_lifespan()).
 * Other options of the plugin (speedycache_cdn, speedycache_img…) are not read.
 */
final class SpeedyCacheAdapter extends CachePluginAdapter {
    public function slug(): string { return 'speedycache'; }
    public function tier(): int { return 2; }
    public function folders(): array { return ['speedycache']; }
    public function watched_options(): array { return ['speedycache_options']; }

    protected function read(): array {
        $o = self::pick(get_option('speedycache_options', []), [
            'status', 'logged_in_user', 'mobile', 'mobile_theme', 'preload',
            'purge_interval', 'purge_interval_unit', 'purge_enable_exact_time', 'purge_exact_time',
        ]);

        // Never expires without an interval; a daily exact time is a 24 hour lifespan; hours or days otherwise.
        $interval = (int) ($o['purge_interval'] ?? 0);
        if ($interval <= 0) {
            $lifespan = 0;
        } elseif (!empty($o['purge_enable_exact_time']) && !empty($o['purge_exact_time'])) {
            $lifespan = 24;
        } elseif (($o['purge_interval_unit'] ?? '') === 'hours') {
            $lifespan = $interval;
        } elseif (($o['purge_interval_unit'] ?? '') === 'days') {
            $lifespan = $interval * 24;
        } else {
            $lifespan = null;
        }

        // With "mobile" on and no mobile theme option, mobile visitors are not cached at all (advanced-cache.php).
        $mobile = !empty($o['mobile']);
        $theme  = !empty($o['mobile_theme']);

        return [
            'page_cache'            => !empty($o['status']),
            'lifespan_hours'        => $lifespan,
            'mobile_cache'          => !$mobile || $theme,
            'separate_mobile_cache' => $mobile && $theme,
            'logged_in_cache'       => !empty($o['logged_in_user']),
            'preload'               => !empty($o['preload']),
            'cdn_integration'       => null,
            'cdn_purge'             => false,
        ];
    }
}
