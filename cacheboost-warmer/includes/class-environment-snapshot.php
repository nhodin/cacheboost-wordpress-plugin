<?php
namespace CacheBoostWarmer;

if (!defined('ABSPATH')) exit;

/**
 * Builds the environment snapshot sent to POST /v1/sites/{id}/environment: versions, cache plugins and
 * their normalized settings, drop-ins, object cache, hosting, multilingual setup and CacheBoost settings.
 * CacheBoost cannot query the site, so its cache diagnostics rely on the last snapshot received.
 *
 * Only values listed here are collected: never an option as a whole, never a credential.
 */
class EnvironmentSnapshot {

    /** advanced-cache.php marker => slug of the plugin that wrote it (checked against each plugin's drop-in). */
    private const DROPIN_MARKERS = [
        'WP_ROCKET_ADVANCED_CACHE'      => 'wp-rocket',
        'W3 Total Cache'                => 'w3-total-cache',
        'WP SUPER CACHE'                => 'wp-super-cache',
        'Cache Enabler'                 => 'cache-enabler',
        'BREEZE_ADVANCED_CACHE'         => 'breeze',
        'WPHB_ADVANCED_CACHE'           => 'hummingbird-performance',
        'WPO_ADVANCED_CACHE'            => 'wp-optimize',
        'SWCFPC_ADVANCED_CACHE'         => 'wp-cloudflare-page-cache',
        '/plugins/sg-cachepress/'       => 'sg-cachepress',
        'WebSharks\\CometCache'         => 'comet-cache',
        'NITROPACK_ADVANCED_CACHE'      => 'nitropack',
        'POWERED_CACHE_PAGE_CACHING'    => 'powered-cache',
        // Not verified: FlyingPress is not public; its drop-in loads files from the flying-press folder.
        'flying-press'                  => 'flyingpress',
    ];

    private const DROPIN_READ_BYTES = 8192;

    public function __construct(private Config $config) {}

    /** @return CachePluginAdapter[] */
    public static function adapters(): array {
        return [
            new WpRocketAdapter(),
            new W3TotalCacheAdapter(),
            new LiteSpeedCacheAdapter(),
            new WpSuperCacheAdapter(),
            new FlyingPressAdapter(),
            new WpFastestCacheAdapter(),
            new CacheEnablerAdapter(),
            new BreezeAdapter(),
            new SiteGroundOptimizerAdapter(),
            new HummingbirdAdapter(),
            new WpOptimizeAdapter(),
            new SuperPageCacheAdapter(),
            new NginxHelperAdapter(),
            new ProxyCachePurgeAdapter(),
            new DetectionOnlyAdapter('comet-cache', ['comet-cache', 'comet-cache-pro']),
            new DetectionOnlyAdapter('swift-performance', ['swift-performance-lite', 'swift-performance']),
            new DetectionOnlyAdapter('nitropack', ['nitropack']),
            new DetectionOnlyAdapter('powered-cache', ['powered-cache']),
            new DetectionOnlyAdapter('autoptimize', ['autoptimize']),
            new DetectionOnlyAdapter('redis-cache', ['redis-cache']),
            new DetectionOnlyAdapter('object-cache-pro', ['object-cache-pro']),
            new DetectionOnlyAdapter('docket-cache', ['docket-cache']),
        ];
    }

    /** @return string[] Options whose update changes the snapshot. */
    public static function watched_options(): array {
        $options = ['automatic_platform_optimization', 'icl_sitepress_settings', 'polylang'];
        foreach (self::adapters() as $adapter) {
            $options = array_merge($options, $adapter->watched_options());
        }
        return array_values(array_unique($options));
    }

    public function collect(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = (array) get_plugins();

        return [
            'plugin_version'       => defined('CBWARMER_VERSION') ? CBWARMER_VERSION : null,
            'wp_version'           => (string) get_bloginfo('version'),
            'php_version'          => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'woocommerce'          => $this->active_version($installed, ['woocommerce']),
            'multilingual'         => $this->multilingual($installed),
            'wp_cache_constant'    => defined('WP_CACHE') && WP_CACHE,
            'advanced_cache_owner' => $this->advanced_cache_owner(),
            'object_cache'         => $this->object_cache(),
            'hosting'              => $this->hosting(),
            'cache_plugins'        => $this->cache_plugins($installed),
            'cdn_plugins'          => $this->cdn_plugins($installed),
            'cacheboost'           => [
                // What actually runs: Full warming also needs a Boost to start.
                'full_warming'        => $this->config->is_enabled() && $this->config->is_full_enabled() && $this->config->get_boost_id() > 0,
                'smart_warming'       => $this->config->is_enabled() && $this->config->is_smart_enabled(),
                'flyingpress_warming' => $this->config->is_enabled() && $this->config->is_flyingpress_warming_enabled(),
            ],
        ];
    }

    /** @return array{basename: string, version: ?string, active: bool}|null First installed plugin found in one of these folders. */
    private function find(array $installed, array $folders): ?array {
        foreach ($installed as $basename => $headers) {
            if (in_array(dirname((string) $basename), $folders, true)) {
                $version = $headers['Version'] ?? '';
                return [
                    'basename' => (string) $basename,
                    'version'  => is_string($version) && $version !== '' ? $version : null,
                    'active'   => is_plugin_active((string) $basename),
                ];
            }
        }
        return null;
    }

    private function active_version(array $installed, array $folders): ?string {
        $plugin = $this->find($installed, $folders);
        return $plugin && $plugin['active'] ? $plugin['version'] : null;
    }

    /** Installed cache plugins, active or not: an inactive one may have left its drop-in behind. */
    private function cache_plugins(array $installed): array {
        $plugins = [];
        foreach (self::adapters() as $adapter) {
            $plugin = $this->find($installed, $adapter->folders());
            if ($plugin === null) continue;
            $plugins[] = [
                'slug'     => $adapter->slug(),
                'version'  => $plugin['version'],
                'active'   => $plugin['active'],
                'tier'     => $adapter->tier(),
                // An inactive plugin's options may be stale: they say nothing about the site.
                'settings' => $plugin['active'] ? $adapter->settings() : CachePluginAdapter::normalize([]),
            ];
        }
        return $plugins;
    }

    /**
     * Cloudflare 4.14.5: its options hold the setting as ['value' => 'on'|'off'|bool, …]
     * (src/API/Plugin.php, src/WordPress/Hooks.php). The API key and email are separate options, never read.
     */
    private function cdn_plugins(array $installed): array {
        $cloudflare = $this->find($installed, ['cloudflare']);
        if ($cloudflare === null || !$cloudflare['active']) return [];

        $apo = get_option('automatic_platform_optimization', null);
        $apo = is_array($apo) ? ($apo['value'] ?? false) : false;

        return [[
            'slug'    => 'cloudflare',
            'version' => $cloudflare['version'],
            'apo'     => $apo !== false && $apo !== 'off',
        ]];
    }

    /**
     * WPML (not public): icl_sitepress_settings['language_negotiation_type'], 1 directory, 2 domain, 3 parameter.
     * Polylang (polylang/polylang): polylang['force_lang'], 0 language set from the content (not in the URL,
     * reported as null), 1 directory (a parameter without pretty permalinks), 2 subdomain, 3 domain.
     */
    private function multilingual(array $installed): ?array {
        if ($this->active_version($installed, ['sitepress-multilingual-cms']) !== null) {
            $settings = get_option('icl_sitepress_settings', []);
            $type     = is_array($settings) ? (int) ($settings['language_negotiation_type'] ?? 0) : 0;
            return ['plugin' => 'wpml', 'lang_in' => [1 => 'directory', 2 => 'domain', 3 => 'parameter'][$type] ?? null];
        }

        if ($this->active_version($installed, ['polylang', 'polylang-pro']) !== null) {
            $settings = get_option('polylang', []);
            $force    = is_array($settings) ? (int) ($settings['force_lang'] ?? 0) : 0;
            $lang_in  = [1 => 'directory', 2 => 'subdomain', 3 => 'domain'][$force] ?? null;
            if ($lang_in === 'directory' && !get_option('permalink_structure')) {
                $lang_in = 'parameter';
            }
            return ['plugin' => 'polylang', 'lang_in' => $lang_in];
        }

        return null;
    }

    /** Slug of the plugin that wrote wp-content/advanced-cache.php, "unknown" if unrecognized, null without one. */
    private function advanced_cache_owner(): ?string {
        $head = $this->read_head(WP_CONTENT_DIR . '/advanced-cache.php');
        if ($head === null) return null;
        foreach (self::DROPIN_MARKERS as $marker => $slug) {
            if (str_contains($head, $marker)) return $slug;
        }
        return 'unknown';
    }

    /** @return array{enabled: bool, backend: ?string} */
    private function object_cache(): array {
        if (!wp_using_ext_object_cache()) {
            return ['enabled' => false, 'backend' => null];
        }

        $head    = (string) $this->read_head(WP_CONTENT_DIR . '/object-cache.php');
        $backend = 'other';
        if (str_contains($head, 'LiteSpeed Cache - Object Cache')) {
            // LiteSpeed's drop-in serves both: object-kind on means Redis (src/object-cache.cls.php).
            $kind    = apply_filters('litespeed_conf', 'object-kind');
            $backend = !empty($kind) && $kind !== 'object-kind' ? 'redis' : 'memcached';
        } elseif (str_contains($head, 'Docket Cache')) {
            $backend = 'other';
        } elseif (stripos($head, 'redis') !== false || str_contains($head, 'Object Cache Pro')) {
            $backend = 'redis';
        } elseif (stripos($head, 'memcache') !== false) {
            $backend = 'memcached';
        }

        return ['enabled' => true, 'backend' => $backend];
    }

    /**
     * Hosting detection by constants, mu-plugin classes and server variables, as in WP Rocket's
     * HostResolver (inc/ThirdParty/Hostings/HostResolver.php, WP Rocket 4.4.1), plus Kinsta's mu-plugin
     * constant for requests without its server variable (WP-CLI cron). Constants holding keys
     * (O2SWITCH_VARNISH_PURGE_KEY) are tested with defined(), never read.
     */
    private function hosting(): ?string {
        $server = fn (string $key) => isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;

        $brand = strtolower((string) $server('GROUPONE_BRAND_NAME'));
        if ($brand === 'one.com') return 'onecom';
        if ($brand === 'proisp.no') return 'proisp';
        if (str_contains((string) $server('DOCUMENT_ROOT'), '.cloudwaysapps.com/') || $server('cw_allowed_ip') !== null) return 'cloudways';
        if (defined('IS_PRESSABLE') && IS_PRESSABLE) return 'pressable';
        if (getenv('SPINUPWP_CACHE_PATH')) return 'spinupwp';
        if (class_exists('WpeCommon') && function_exists('wpe_param')) return 'wpengine';
        if (defined('O2SWITCH_VARNISH_PURGE_KEY')) return 'o2switch';
        if (defined('WPCOMSH_VERSION')) return 'wordpresscom';
        if ($server('HTTP_WPXCLOUD') !== null) return 'wpxcloud';
        if (class_exists('\WPaas\Plugin')) return 'godaddy';
        if ($server('KINSTA_CACHE_ZONE') !== null || defined('KINSTAMU_VERSION')) return 'kinsta';
        if (defined('WP_NINUKIS_WP_NAME') || class_exists('NinukisCaching')) return 'pressidium';
        // SiteGround Speed Optimizer only works on SiteGround servers.
        if (is_plugin_active('sg-cachepress/sg-cachepress.php')) return 'siteground';
        return null;
    }

    /** The beginning of a drop-in: enough for its header and markers, never the whole file. */
    private function read_head(string $file): ?string {
        if (!is_file($file) || !is_readable($file)) return null;
        $head = file_get_contents($file, false, null, 0, self::DROPIN_READ_BYTES);
        return $head === false ? null : $head;
    }
}
