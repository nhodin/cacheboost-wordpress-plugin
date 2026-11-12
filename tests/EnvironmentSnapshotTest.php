<?php

declare(strict_types=1);

namespace CacheBoostWarmer\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CacheBoostWarmer\CachePluginAdapter;
use CacheBoostWarmer\Config;
use CacheBoostWarmer\EnvironmentSnapshot;
use PHPUnit\Framework\TestCase;

class EnvironmentSnapshotTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubWordPress();
        $this->connect();
        Functions\when('rocket_get_ignored_parameters')->justReturn(['utm_source' => 1]);
    }

    protected function tearDown(): void
    {
        @unlink(WP_CONTENT_DIR . '/advanced-cache.php');
        @unlink(WP_CONTENT_DIR . '/object-cache.php');
        unset($_SERVER['KINSTA_CACHE_ZONE']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function collect(): array
    {
        return (new EnvironmentSnapshot(new Config()))->collect();
    }

    public function test_payload_has_the_contract_keys_in_order(): void
    {
        self::assertSame([
            'plugin_version', 'wp_version', 'php_version', 'woocommerce', 'woocommerce_settings', 'woocommerce_pages', 'multilingual', 'wp_cache_constant',
            'advanced_cache_owner', 'object_cache', 'hosting', 'cache_plugins', 'cdn_plugins', 'cacheboost',
        ], array_keys($this->collect()));
    }

    public function test_root_values_on_a_bare_site(): void
    {
        $payload = $this->collect();

        self::assertSame('6.8.3', $payload['wp_version']);
        self::assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $payload['php_version']);
        self::assertNull($payload['woocommerce']);
        self::assertNull($payload['multilingual']);
        self::assertFalse($payload['wp_cache_constant']);
        self::assertNull($payload['advanced_cache_owner']);
        self::assertSame(['enabled' => false, 'backend' => null], $payload['object_cache']);
        self::assertNull($payload['hosting']);
        self::assertSame([], $payload['cache_plugins']);
        self::assertSame([], $payload['cdn_plugins']);
        self::assertSame(['full_warming' => true, 'smart_warming' => true, 'flyingpress_warming' => true], $payload['cacheboost']);
    }

    public function test_full_warming_is_off_without_a_boost(): void
    {
        $this->connect(['boost_id' => 0]);

        self::assertFalse($this->collect()['cacheboost']['full_warming']);
    }

    public function test_cache_plugins_with_tier_version_and_settings(): void
    {
        $this->install('wp-rocket/wp-rocket.php', '3.17.2');
        $this->install('redis-cache/redis-cache.php', '2.5.4');
        $this->options['wp_rocket_settings'] = ['cache_mobile' => 1, 'purge_cron_interval' => 10, 'purge_cron_unit' => 'HOUR_IN_SECONDS'];

        $plugins = $this->collect()['cache_plugins'];

        self::assertCount(2, $plugins);
        self::assertSame(['slug' => 'wp-rocket', 'version' => '3.17.2', 'active' => true, 'tier' => 1], array_slice($plugins[0], 0, 4));
        self::assertSame(10, $plugins[0]['settings']['lifespan_hours']);
        // Tier 3: detection and version only.
        self::assertSame(['slug' => 'redis-cache', 'version' => '2.5.4', 'active' => true, 'tier' => 3], array_slice($plugins[1], 0, 4));
        self::assertSame(CachePluginAdapter::normalize([]), $plugins[1]['settings']);
    }

    public function test_inactive_plugin_is_listed_without_reading_its_settings(): void
    {
        $this->install('flying-press/flying-press.php', '5.0.2', false);
        $this->options['FLYING_PRESS_CONFIG'] = ['cache_lifespan' => 'never', 'license_key' => 'SECRET-LICENCE'];

        $plugin = $this->collect()['cache_plugins'][0];

        self::assertSame('flyingpress', $plugin['slug']);
        self::assertFalse($plugin['active']);
        self::assertNull($plugin['settings']['lifespan_hours']);
    }

    public function test_premium_edition_folder_is_detected(): void
    {
        $this->install('swift-performance/performance.php', '2.3.7');

        self::assertSame('swift-performance', $this->collect()['cache_plugins'][0]['slug']);
    }

    public function test_cloudflare_plugin_reports_apo_and_no_credential(): void
    {
        $this->install('cloudflare/cloudflare.php', '4.12.8');
        $this->options['automatic_platform_optimization'] = ['id' => 'automatic_platform_optimization', 'value' => true];
        $this->options['cloudflare_api_key']              = 'SECRET-API-KEY';
        $this->options['cloudflare_api_email']            = 'owner@example.com';

        $payload = $this->collect();

        self::assertSame([['slug' => 'cloudflare', 'version' => '4.12.8', 'cdn' => 'cloudflare', 'purges' => true, 'apo' => true]], $payload['cdn_plugins']);
        self::assertStringNotContainsString('SECRET-API-KEY', (string) json_encode($payload));
        self::assertStringNotContainsString('owner@example.com', (string) json_encode($payload));
    }

    public function test_cloudflare_apo_off(): void
    {
        $this->install('cloudflare/cloudflare.php', '4.12.8');
        $this->options['automatic_platform_optimization'] = ['value' => 'off'];

        self::assertFalse($this->collect()['cdn_plugins'][0]['apo']);
    }

    public function test_woocommerce_version_when_active(): void
    {
        $this->install('woocommerce/woocommerce.php', '9.4.1');

        self::assertSame('9.4.1', $this->collect()['woocommerce']);
    }

    /** @dataProvider wpmlModes */
    public function test_wpml_language_mode(int $type, ?string $lang_in): void
    {
        $this->install('sitepress-multilingual-cms/sitepress.php', '4.7.0');
        $this->options['icl_sitepress_settings'] = ['language_negotiation_type' => $type, 'site_key' => 'SECRET-LICENCE'];

        $payload = $this->collect();

        self::assertSame(['plugin' => 'wpml', 'lang_in' => $lang_in], $payload['multilingual']);
        self::assertStringNotContainsString('SECRET-LICENCE', (string) json_encode($payload));
    }

    public static function wpmlModes(): array
    {
        return ['directory' => [1, 'directory'], 'domain' => [2, 'domain'], 'parameter' => [3, 'parameter']];
    }

    /** @dataProvider polylangModes */
    public function test_polylang_language_mode(int $force_lang, string $permalinks, ?string $lang_in): void
    {
        $this->install('polylang/polylang.php', '3.6.0');
        $this->options['polylang']            = ['force_lang' => $force_lang];
        $this->options['permalink_structure'] = $permalinks;

        self::assertSame(['plugin' => 'polylang', 'lang_in' => $lang_in], $this->collect()['multilingual']);
    }

    public static function polylangModes(): array
    {
        return [
            'from content'          => [0, '/%postname%/', null],
            'directory'             => [1, '/%postname%/', 'directory'],
            'plain permalinks'      => [1, '', 'parameter'],
            'subdomain'             => [2, '/%postname%/', 'subdomain'],
            'domain'                => [3, '/%postname%/', 'domain'],
        ];
    }

    public function test_advanced_cache_owner_from_drop_in_marker(): void
    {
        file_put_contents(WP_CONTENT_DIR . '/advanced-cache.php', "<?php\ndefined( 'ABSPATH' ) || exit;\ndefine( 'WP_ROCKET_ADVANCED_CACHE', true );\n");

        self::assertSame('wp-rocket', $this->collect()['advanced_cache_owner']);
    }

    public function test_unrecognized_drop_in_is_unknown(): void
    {
        file_put_contents(WP_CONTENT_DIR . '/advanced-cache.php', "<?php\n// custom\n");

        self::assertSame('unknown', $this->collect()['advanced_cache_owner']);
    }

    public function test_object_cache_backend_from_drop_in(): void
    {
        Functions\when('wp_using_ext_object_cache')->justReturn(true);
        file_put_contents(WP_CONTENT_DIR . '/object-cache.php', "<?php\n/**\n * Plugin Name: Redis Object Cache Drop-In\n */\n");

        self::assertSame(['enabled' => true, 'backend' => 'redis'], $this->collect()['object_cache']);
    }

    public function test_hosting_from_server_variable(): void
    {
        $_SERVER['KINSTA_CACHE_ZONE'] = 'site-zone';

        self::assertSame('kinsta', $this->collect()['hosting']);
    }

    private function setUpWooCommerce(): void
    {
        $this->install('woocommerce/woocommerce.php', '9.4.1');
    }

    public function test_woocommerce_blocks_are_null_without_woocommerce(): void
    {
        // Settings left behind by a deactivated WooCommerce say nothing about the site.
        $this->install('woocommerce/woocommerce.php', '9.4.1', false);
        $this->options['woocommerce_default_customer_address'] = 'geolocation_ajax';

        $payload = $this->collect();

        self::assertNull($payload['woocommerce_settings']);
        self::assertNull($payload['woocommerce_pages']);
    }

    /** @dataProvider customerAddresses */
    public function test_woocommerce_default_customer_address(mixed $stored, ?string $expected): void
    {
        $this->setUpWooCommerce();
        $this->options['woocommerce_default_customer_address'] = $stored;

        self::assertSame($expected, $this->collect()['woocommerce_settings']['default_customer_address']);
    }

    public static function customerAddresses(): array
    {
        return [
            'no location'      => ['', ''],
            'shop base'        => ['base', 'base'],
            'geolocation'      => ['geolocation', 'geolocation'],
            'geolocation ajax' => ['geolocation_ajax', 'geolocation_ajax'],
            'unexpected value' => ['elsewhere', null],
        ];
    }

    public function test_woocommerce_default_customer_address_is_base_when_never_saved(): void
    {
        $this->setUpWooCommerce();

        self::assertSame(
            ['default_customer_address' => 'base', 'currency_plugin' => null],
            $this->collect()['woocommerce_settings']
        );
    }

    public function test_woocommerce_currency_plugin(): void
    {
        $this->setUpWooCommerce();
        $this->install('woo-multi-currency/woo-multi-currency.php', '2.2.3');

        self::assertSame('woo-multi-currency', $this->collect()['woocommerce_settings']['currency_plugin']);
    }

    public function test_inactive_currency_plugin_is_ignored(): void
    {
        $this->setUpWooCommerce();
        $this->install('woocommerce-currency-switcher/index.php', '1.4.3.1', false);

        self::assertNull($this->collect()['woocommerce_settings']['currency_plugin']);
    }

    /** @dataProvider wcmlModes */
    public function test_wcml_counts_only_with_multi_currency_on(int $mode, ?string $expected): void
    {
        $this->setUpWooCommerce();
        $this->install('woocommerce-multilingual/wpml-woocommerce.php', '5.4.0');
        $this->options['_wcml_settings'] = ['enable_multi_currency' => $mode, 'currency_options' => ['EUR' => ['rate' => 'SECRET-LICENCE']]];

        $payload = $this->collect();

        self::assertSame($expected, $payload['woocommerce_settings']['currency_plugin']);
        self::assertStringNotContainsString('SECRET-LICENCE', (string) json_encode($payload));
    }

    public static function wcmlModes(): array
    {
        return ['disabled' => [0, null], 'independent currencies' => [2, 'woocommerce-multilingual']];
    }

    public function test_woocommerce_pages_are_translated_paths_only(): void
    {
        $this->setUpWooCommerce();
        $this->options['woocommerce_cart_page_id']     = '11';
        $this->options['woocommerce_checkout_page_id'] = '12';
        $this->options['woocommerce_myaccount_page_id'] = '13';
        $permalinks = [
            11 => 'https://shop.example.com/carrito/',
            12 => 'https://shop.example.com/finalizar-compra/?utm=x',
            13 => 'https://shop.example.com/mi-cuenta/',
        ];
        Functions\when('get_permalink')->alias(fn (int $id) => $permalinks[$id] ?? false);
        Functions\when('wp_parse_url')->alias(fn (string $url, int $component = -1) => parse_url($url, $component));

        self::assertSame(
            ['cart' => '/carrito/', 'checkout' => '/finalizar-compra/', 'myaccount' => '/mi-cuenta/'],
            $this->collect()['woocommerce_pages']
        );
    }

    public function test_woocommerce_page_not_defined_is_null(): void
    {
        $this->setUpWooCommerce();
        $this->options['woocommerce_cart_page_id']     = '11';
        $this->options['woocommerce_checkout_page_id'] = '0';
        // No account page option at all; the cart page was deleted.
        Functions\when('get_permalink')->justReturn(false);
        Functions\when('wp_parse_url')->alias(fn (string $url, int $component = -1) => parse_url($url, $component));

        self::assertSame(['cart' => null, 'checkout' => null, 'myaccount' => null], $this->collect()['woocommerce_pages']);
    }

    /** @dataProvider cdnPlugins */
    public function test_cdn_plugins(string $basename, string $slug, string $cdn, ?bool $purges): void
    {
        $this->install($basename, '1.0.0');

        self::assertSame(
            [['slug' => $slug, 'version' => '1.0.0', 'cdn' => $cdn, 'purges' => $purges]],
            $this->collect()['cdn_plugins']
        );
    }

    public static function cdnPlugins(): array
    {
        return [
            'fastly'     => ['fastly/purgely.php', 'fastly', 'fastly', true],
            'cloudfront' => ['c3-cloudfront-clear-cache/c3-cloudfront-clear-cache.php', 'c3-cloudfront-cache-controller', 'cloudfront', true],
            'bunny'      => ['bunnycdn/bunnycdn.php', 'bunnycdn', 'bunny', false],
            'keycdn'     => ['cdn-enabler/cdn-enabler.php', 'cdn-enabler', 'keycdn', null],
        ];
    }

    public function test_inactive_cdn_plugin_is_not_listed(): void
    {
        $this->install('fastly/purgely.php', '1.2.29', false);

        self::assertSame([], $this->collect()['cdn_plugins']);
    }

    public function test_cdn_plugins_never_leak_credentials(): void
    {
        foreach (['fastly/purgely.php', 'c3-cloudfront-clear-cache/c3-cloudfront-clear-cache.php', 'bunnycdn/bunnycdn.php', 'cdn-enabler/cdn-enabler.php', 'cloudflare/cloudflare.php'] as $basename) {
            $this->install($basename, '1.0.0');
        }
        $this->options['fastly-settings-general'] = ['fastly_api_key' => 'SECRET-API-KEY', 'fastly_service_id' => 'SECRET-ZONE'];
        $this->options['c3_settings']             = ['distribution_id' => 'SECRET-ZONE', 'access_key' => 'SECRET-TOKEN'];
        $this->options['bunnycdn']                = ['api_key' => 'SECRET-API-KEY', 'pull_zone_id' => 'SECRET-ZONE'];
        $this->options['cdn_enabler']             = ['url' => 'https://SECRET-LICENCE.kxcdn.com'];
        $this->options['cloudflare_api_key']      = 'SECRET-API-KEY';

        $json = (string) json_encode($this->collect());

        foreach (['SECRET-API-KEY', 'SECRET-ZONE', 'SECRET-TOKEN', 'SECRET-LICENCE'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    public function test_sucuri_purge_is_unknown_without_its_option_api(): void
    {
        $this->install('sucuri-scanner/sucuri.php', '2.8.1');

        self::assertSame(
            [['slug' => 'sucuri-scanner', 'version' => '2.8.1', 'cdn' => 'sucuri', 'purges' => null]],
            $this->collect()['cdn_plugins']
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_sucuri_purge_follows_its_firewall_setting(): void
    {
        require_once __DIR__ . '/Support/SucuriScanOption.php';
        $this->install('sucuri-scanner/sucuri.php', '2.8.1');

        \SucuriScanOption::$enabled = [];
        self::assertFalse($this->collect()['cdn_plugins'][0]['purges']);

        \SucuriScanOption::$enabled = [':auto_clear_cache'];
        self::assertTrue($this->collect()['cdn_plugins'][0]['purges']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_sucuri_purge_with_the_constant(): void
    {
        define('SUCURI_CLEAR_CACHE_ON_PUBLISH', true);
        $this->install('sucuri-scanner/sucuri.php', '2.8.1');

        self::assertTrue($this->collect()['cdn_plugins'][0]['purges']);
    }

    public function test_new_cache_plugins_and_their_tiers(): void
    {
        $this->install('jetpack-boost/jetpack-boost.php', '4.5.0');
        $this->install('speedycache/speedycache.php', '1.4.3');
        $this->install('batcache/batcache.php', '1.5');
        $this->install('surge/surge.php', '1.2.1');
        $this->install('endurance-page-cache/endurance-page-cache.php', '2.3.0');
        $this->install('runcloud-hub/runcloud-hub.php', '1.1.0');

        $tiers = array_column($this->collect()['cache_plugins'], 'tier', 'slug');

        self::assertSame([
            'jetpack-boost' => 2, 'speedycache' => 2, 'batcache' => 3, 'surge' => 3,
            'endurance-page-cache' => 3, 'runcloud-hub' => 3,
        ], $tiers);
    }

    /** @dataProvider dropIns */
    public function test_advanced_cache_owner_of_new_plugins(string $content, string $slug): void
    {
        file_put_contents(WP_CONTENT_DIR . '/advanced-cache.php', $content);

        self::assertSame($slug, $this->collect()['advanced_cache_owner']);
    }

    public static function dropIns(): array
    {
        return [
            'jetpack boost' => ["<?php\n// Boost Cache Plugin - v0.0.3\nrequire_once('/x');\n", 'jetpack-boost'],
            'speedycache'   => ["<?php\n/*\n* SPEEDYCACHE\n* https://speedycache.com/\n*/\n", 'speedycache'],
            'batcache'      => ["<?php\n// nananananananananananananananana BATCACHE!!!\n", 'batcache'],
            'surge'         => ["<?php\n/**\n * Surge advanced-cache.php dropin\n */\n\nnamespace Surge;\n", 'surge'],
            // Left behind by LiteSpeed Cache before WordPress 5.3.
            'litespeed'     => ["<?php\ndefine( 'LSCACHE_ADV_CACHE', true );\n", 'litespeed-cache'],
        ];
    }

    /** @dataProvider newMultilingualPlugins */
    public function test_new_multilingual_plugins(string $basename, string $plugin, ?string $lang_in, array $options = []): void
    {
        $this->install($basename, '1.0.0');
        $this->options = $options + $this->options;

        self::assertSame(['plugin' => $plugin, 'lang_in' => $lang_in], $this->collect()['multilingual']);
    }

    public static function newMultilingualPlugins(): array
    {
        return [
            'translatepress'       => ['translatepress-multilingual/index.php', 'translatepress', 'directory'],
            'weglot'               => ['weglot/weglot.php', 'weglot', 'directory'],
            'gtranslate free'      => ['gtranslate/gtranslate.php', 'gtranslate', null, ['GTranslate' => ['pro_version' => '', 'enterprise_version' => '']]],
            'gtranslate never set' => ['gtranslate/gtranslate.php', 'gtranslate', null],
            'gtranslate pro'       => ['gtranslate/gtranslate.php', 'gtranslate', 'directory', ['GTranslate' => ['pro_version' => 1, 'enterprise_version' => '']]],
            'gtranslate business'  => ['gtranslate/gtranslate.php', 'gtranslate', 'subdomain', ['GTranslate' => ['pro_version' => '', 'enterprise_version' => 1]]],
        ];
    }

    public function test_watched_options_cover_the_new_blocks(): void
    {
        $options = EnvironmentSnapshot::watched_options();

        foreach ([
            'woocommerce_default_customer_address', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id',
            'woocommerce_myaccount_page_id', '_wcml_settings', 'GTranslate', 'speedycache_options', 'jetpack_boost_status_page-cache',
        ] as $option) {
            self::assertContains($option, $options);
        }
    }

    public function test_watched_options_cover_level_one_plugins(): void
    {
        $options = EnvironmentSnapshot::watched_options();

        foreach (['wp_rocket_settings', 'litespeed.conf.cache', 'FLYING_PRESS_CONFIG', 'automatic_platform_optimization'] as $option) {
            self::assertContains($option, $options);
        }
    }
}
