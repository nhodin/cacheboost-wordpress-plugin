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
            'plugin_version', 'wp_version', 'php_version', 'woocommerce', 'multilingual', 'wp_cache_constant',
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

        self::assertSame([['slug' => 'cloudflare', 'version' => '4.12.8', 'apo' => true]], $payload['cdn_plugins']);
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

    public function test_watched_options_cover_level_one_plugins(): void
    {
        $options = EnvironmentSnapshot::watched_options();

        foreach (['wp_rocket_settings', 'litespeed.conf.cache', 'FLYING_PRESS_CONFIG', 'automatic_platform_optimization'] as $option) {
            self::assertContains($option, $options);
        }
    }
}
