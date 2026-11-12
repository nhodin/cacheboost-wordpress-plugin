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
        // Jetpack Boost writes "// Boost Cache Plugin - v0.0.3" (Page_Cache::ADVANCED_CACHE_SIGNATURE).
        'Boost Cache Plugin'            => 'jetpack-boost',
        // SpeedyCache copies main/advanced-cache.php, whose header comment is "SPEEDYCACHE".
        'SPEEDYCACHE'                   => 'speedycache',
        // Batcache's advanced-cache.php is the plugin's own file.
        'BATCACHE!!!'                   => 'batcache',
        // Surge's uninstall.php recognizes its drop-in by this line.
        'namespace Surge;'              => 'surge',
        // Not verified: LiteSpeed Cache stopped writing advanced-cache.php with WordPress 5.3 and the marker
        // is gone from its source. Old files left behind still name it, so they are not reported as unknown.
        'LSCACHE_ADV_CACHE'             => 'litespeed-cache',
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
            new JetpackBoostAdapter(),
            new SpeedyCacheAdapter(),
            new DetectionOnlyAdapter('batcache', ['batcache']),
            new DetectionOnlyAdapter('surge', ['surge']),
            new DetectionOnlyAdapter('endurance-page-cache', ['endurance-page-cache']),
            new DetectionOnlyAdapter('runcloud-hub', ['runcloud-hub']),
        ];
    }

    /** @return string[] Options whose update changes the snapshot. */
    public static function watched_options(): array {
        $options = [
            'automatic_platform_optimization', 'icl_sitepress_settings', 'polylang',
            'woocommerce_default_customer_address', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id',
            'woocommerce_myaccount_page_id', '_wcml_settings', 'trp_settings', 'GTranslate',
        ];
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
            'woocommerce_settings' => $this->woocommerce_settings($installed),
            'woocommerce_pages'    => $this->woocommerce_pages($installed),
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
     * Active CDN plugins. Only the fields below are sent: every one of these plugins keeps an API key, a token
     * or a zone / distribution identifier in its own options, and none of them is read.
     * `purges`: the plugin purges the CDN by itself when content changes, null when it cannot tell.
     *
     * - Cloudflare 4.14.5: its options hold the setting as ['value' => 'on'|'off'|bool, …]
     *   (src/API/Plugin.php, src/WordPress/Hooks.php); it purges on content changes (src/WordPress/Hooks.php).
     * - Fastly 1.2.29 (purgely.php) and C3 Cloudfront Cache Controller 7.3.2 purge on content changes.
     * - bunny.net 3.1.1: no content hook (only src/Admin/Controller/CdnCachePurge.php, a button), so false.
     * - Sucuri 2.8.1: SucuriScanFirewall::shouldAutoClearCache() is the constant SUCURI_CLEAR_CACHE_ON_PUBLISH or
     *   the plugin setting auto_clear_cache, kept in the plugin's own data file, not in wp_options.
     * - CDN Enabler 2.0.8 only rewrites URLs.
     */
    private function cdn_plugins(array $installed): array {
        $table = [
            ['cloudflare', ['cloudflare'], 'cloudflare', true],
            ['fastly', ['fastly'], 'fastly', true],
            // The wordpress.org folder is c3-cloudfront-clear-cache; the name of the plugin gives the slug.
            ['c3-cloudfront-cache-controller', ['c3-cloudfront-clear-cache', 'c3-cloudfront-cache-controller'], 'cloudfront', true],
            ['bunnycdn', ['bunnycdn'], 'bunny', false],
            ['sucuri-scanner', ['sucuri-scanner'], 'sucuri', 'sucuri'],
            ['cdn-enabler', ['cdn-enabler'], 'keycdn', null],
        ];

        $plugins = [];
        foreach ($table as [$slug, $folders, $cdn, $purges]) {
            $plugin = $this->find($installed, $folders);
            if ($plugin === null || !$plugin['active']) continue;

            $entry = [
                'slug'    => $slug,
                'version' => $plugin['version'],
                'cdn'     => $cdn,
                // Sucuri's setting has to be asked to the plugin.
                'purges'  => $purges === 'sucuri' ? $this->sucuri_purges() : $purges,
            ];
            if ($slug === 'cloudflare') {
                $apo = get_option('automatic_platform_optimization', null);
                $apo = is_array($apo) ? ($apo['value'] ?? false) : false;
                $entry['apo'] = $apo !== false && $apo !== 'off';
            }
            $plugins[] = $entry;
        }
        return $plugins;
    }

    private function sucuri_purges(): ?bool {
        if (defined('SUCURI_CLEAR_CACHE_ON_PUBLISH')) return true;
        // The setting lives in the plugin's data file: ask the plugin, whose option API reads it.
        if (!class_exists('SucuriScanOption')) return null;
        return \SucuriScanOption::isEnabled(':auto_clear_cache');
    }

    /**
     * WooCommerce 9.x: woocommerce_default_customer_address is '' | 'base' | 'geolocation' | 'geolocation_ajax'
     * (src/Enums/DefaultCustomerAddress.php). "Geolocate (with page caching support)" redirects every visitor
     * to ?v=<hash> (includes/class-wc-cache-helper.php), which a page cache then stores once per hash.
     */
    private function woocommerce_settings(array $installed): ?array {
        if ($this->active_version($installed, ['woocommerce']) === null) return null;

        $address = get_option('woocommerce_default_customer_address', 'base');
        return [
            'default_customer_address' => in_array($address, ['', 'base', 'geolocation', 'geolocation_ajax'], true) ? $address : null,
            'currency_plugin'          => $this->currency_plugin($installed),
        ];
    }

    /**
     * Currency switchers change prices per visitor, which a page cache has to vary on.
     * WooCommerce Multilingual (WPML) only counts when its multi-currency mode is on:
     * _wcml_settings['enable_multi_currency'] is 2 (WCML_MULTI_CURRENCIES_INDEPENDENT, inc/constants.php).
     * Folders of the Aelia and YITH plugins are not verified against their source.
     */
    private function currency_plugin(array $installed): ?string {
        $plugins = [
            'woocommerce-multilingual'            => ['woocommerce-multilingual'],
            'woo-multi-currency'                  => ['woo-multi-currency'],
            'woocommerce-currency-switcher'       => ['woocommerce-currency-switcher'],
            'woocommerce-aelia-currencyswitcher'  => ['woocommerce-aelia-currencyswitcher'],
            'yith-woocommerce-multi-currency'     => ['yith-woocommerce-multi-currency-switcher', 'yith-woocommerce-multi-currency'],
        ];
        foreach ($plugins as $slug => $folders) {
            if ($this->active_version($installed, $folders) === null) continue;
            if ($slug === 'woocommerce-multilingual') {
                $wcml = get_option('_wcml_settings', []);
                if (!is_array($wcml) || (int) ($wcml['enable_multi_currency'] ?? 0) !== 2) continue;
            }
            return $slug;
        }
        return null;
    }

    /**
     * Paths of the cart, checkout and account pages, which a page cache must never store.
     * They are translated by the site (/carrito/), so the permalink is what counts, not the slug of the default.
     * Path only: no host, no query.
     */
    private function woocommerce_pages(array $installed): ?array {
        if ($this->active_version($installed, ['woocommerce']) === null) return null;

        $pages = [];
        foreach (['cart', 'checkout', 'myaccount'] as $page) {
            $id        = (int) get_option("woocommerce_{$page}_page_id", 0);
            $permalink = $id > 0 ? get_permalink($id) : false;
            $path      = is_string($permalink) ? wp_parse_url($permalink, PHP_URL_PATH) : null;
            $pages[$page] = is_string($path) && $path !== '' ? $path : null;
        }
        return $pages;
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

        // TranslatePress 3.3.6 builds URLs as /<language>/ only; subdomains need a paid add-on (class-url-converter.php).
        if ($this->active_version($installed, ['translatepress-multilingual']) !== null) {
            return ['plugin' => 'translatepress', 'lang_in' => 'directory'];
        }

        // Weglot 5.4 puts the language code (or its custom slug) first in the path.
        if ($this->active_version($installed, ['weglot']) !== null) {
            return ['plugin' => 'weglot', 'lang_in' => 'directory'];
        }

        // GTranslate 5.0.1: the paid plans change the URL (option GTranslate: pro_version sub-directory,
        // enterprise_version sub-domain); the free widget translates in the browser, so no language in the URL.
        if ($this->active_version($installed, ['gtranslate']) !== null) {
            $settings = get_option('GTranslate', []);
            $settings = is_array($settings) ? $settings : [];
            $lang_in  = !empty($settings['enterprise_version']) ? 'subdomain' : (!empty($settings['pro_version']) ? 'directory' : null);
            return ['plugin' => 'gtranslate', 'lang_in' => $lang_in];
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
