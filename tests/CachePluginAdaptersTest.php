<?php

declare(strict_types=1);

namespace CacheBoostWarmer\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use CacheBoostWarmer\BreezeAdapter;
use CacheBoostWarmer\CacheEnablerAdapter;
use CacheBoostWarmer\CachePluginAdapter;
use CacheBoostWarmer\FlyingPressAdapter;
use CacheBoostWarmer\HummingbirdAdapter;
use CacheBoostWarmer\JetpackBoostAdapter;
use CacheBoostWarmer\LiteSpeedCacheAdapter;
use CacheBoostWarmer\NginxHelperAdapter;
use CacheBoostWarmer\ProxyCachePurgeAdapter;
use CacheBoostWarmer\SpeedyCacheAdapter;
use CacheBoostWarmer\SuperPageCacheAdapter;
use CacheBoostWarmer\W3TotalCacheAdapter;
use CacheBoostWarmer\WpFastestCacheAdapter;
use CacheBoostWarmer\WpOptimizeAdapter;
use CacheBoostWarmer\WpRocketAdapter;
use CacheBoostWarmer\WpSuperCacheAdapter;
use PHPUnit\Framework\TestCase;

/** Stands in for W3TC\Config, read through w3tc_config(). */
final class FakeW3tcConfig
{
    public function __construct(private array $values, private array $extensions = []) {}
    public function get_boolean(string $key): bool { return (bool) ($this->values[$key] ?? false); }
    public function get_integer(string $key): int { return (int) ($this->values[$key] ?? 0); }
    public function get_string(string $key): string { return (string) ($this->values[$key] ?? ''); }
    public function get_array(string $key): array { return (array) ($this->values[$key] ?? []); }
    public function is_extension_active(string $extension): bool { return in_array($extension, $this->extensions, true); }
}

class CachePluginAdaptersTest extends TestCase
{
    use WordPressStubs;

    private const SECRETS = ['SECRET-API-KEY', 'owner@example.com', 'SECRET-TOKEN', 'SECRET-LICENCE', 'SECRET-ZONE'];

    /** @var string[] WP Super Cache globals set by a test */
    private array $globals = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubWordPress();
    }

    protected function tearDown(): void
    {
        foreach ($this->globals as $name) {
            unset($GLOBALS[$name]);
        }
        @unlink(WP_CONTENT_DIR . '/wphb-cache/wphb-cache.php');
        Monkey\tearDown();
        parent::tearDown();
    }

    private function assertNoSecret(array $settings): void
    {
        $json = (string) json_encode($settings);
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame(array_keys(CachePluginAdapter::SETTING_KEYS), array_keys($settings));
    }

    // ── normalize ─────────────────────────────────────────────────────────────

    public function test_normalize_returns_every_key_typed_and_drops_unknown_ones(): void
    {
        $settings = CachePluginAdapter::normalize([
            'page_cache'           => 1,
            'lifespan_hours'       => '-3',
            'ignored_query_params' => "utm_source\ngclid\nnot a param name\nutm_source",
            'cdn_integration'      => 'Cloudflare',
            'api_key'              => 'SECRET-API-KEY',
        ]);

        self::assertTrue($settings['page_cache']);
        self::assertSame(0, $settings['lifespan_hours']);
        self::assertSame(['utm_source', 'gclid'], $settings['ignored_query_params']);
        self::assertSame('cloudflare', $settings['cdn_integration']);
        self::assertNull($settings['mobile_cache']);
        self::assertNoSecret($settings);
    }

    public function test_normalize_rejects_a_cdn_name_that_is_not_a_slug(): void
    {
        self::assertNull(CachePluginAdapter::normalize(['cdn_integration' => 'https://evil.example/?k=1'])['cdn_integration']);
    }

    // ── Tier 1 ────────────────────────────────────────────────────────────────

    public function test_wp_rocket(): void
    {
        $this->options['wp_rocket_settings'] = [
            'cache_mobile'            => 1,
            'do_caching_mobile_files' => 0,
            'cache_logged_user'       => 0,
            'purge_cron_interval'     => 10,
            'purge_cron_unit'         => 'HOUR_IN_SECONDS',
            'manual_preload'          => 1,
            'cache_reject_cookies'    => ['woocommerce_items_in_cart', 'my_cookie'],
            'cache_reject_uri'        => ['/checkout/(.*)'],
            'do_cloudflare'           => 1,
            'cloudflare_api_key'      => 'SECRET-API-KEY',
            'cloudflare_email'        => 'owner@example.com',
            'cloudflare_zone_id'      => 'SECRET-ZONE',
            'sucury_waf_api_key'      => 'SECRET-TOKEN',
        ];
        Functions\when('rocket_get_ignored_parameters')->justReturn(['utm_source' => 1, 'gclid' => 1, 'fbclid' => 1]);

        $settings = (new WpRocketAdapter())->settings();

        self::assertSame([
            'page_cache'             => true,
            'lifespan_hours'         => 10,
            'mobile_cache'           => true,
            'separate_mobile_cache'  => false,
            'logged_in_cache'        => false,
            'preload'                => true,
            'ignored_query_params'   => ['utm_source', 'gclid', 'fbclid'],
            'cdn_integration'        => 'cloudflare',
            'cdn_purge'              => true,
            'rejected_cookies_count' => 2,
            'rejected_uris_count'    => 1,
        ], $settings);
        $this->assertNoSecret($settings);
    }

    public function test_wp_rocket_lifespan_in_days_and_never(): void
    {
        Functions\when('rocket_get_ignored_parameters')->justReturn([]);
        $this->options['wp_rocket_settings'] = ['purge_cron_interval' => 2, 'purge_cron_unit' => 'DAY_IN_SECONDS'];
        self::assertSame(48, (new WpRocketAdapter())->settings()['lifespan_hours']);

        $this->options['wp_rocket_settings'] = ['purge_cron_interval' => 0, 'purge_cron_unit' => 'HOUR_IN_SECONDS'];
        self::assertSame(0, (new WpRocketAdapter())->settings()['lifespan_hours']);
    }

    public function test_w3_total_cache(): void
    {
        Functions\when('w3tc_config')->justReturn(new FakeW3tcConfig([
            'pgcache.enabled'       => true,
            'pgcache.lifetime'      => 5400,
            'mobile.enabled'        => true,
            'mobile.rgroups'        => ['high' => ['enabled' => true], 'low' => ['enabled' => false]],
            'pgcache.reject.logged' => true,
            'pgcache.prime.enabled' => false,
            'pgcache.accept.qs'     => ['utm_source', 'gclid'],
            'pgcache.reject.cookie' => ['wptouch_switch_toggle'],
            'pgcache.reject.uri'    => ['wp-.*\.php', 'index\.php'],
            'cloudflare.key'        => 'SECRET-API-KEY',
        ], ['cloudflare']));

        $settings = (new W3TotalCacheAdapter())->settings();

        self::assertSame([
            'page_cache'             => true,
            'lifespan_hours'         => 2,
            'mobile_cache'           => true,
            'separate_mobile_cache'  => true,
            'logged_in_cache'        => false,
            'preload'                => false,
            'ignored_query_params'   => ['utm_source', 'gclid'],
            'cdn_integration'        => 'cloudflare',
            'cdn_purge'              => true,
            'rejected_cookies_count' => 1,
            'rejected_uris_count'    => 2,
        ], $settings);
        $this->assertNoSecret($settings);
    }

    public function test_w3_total_cache_full_site_delivery(): void
    {
        Functions\when('w3tc_config')->justReturn(new FakeW3tcConfig(['cdnfsd.enabled' => true, 'cdnfsd.engine' => 'bunnycdn']));

        $settings = (new W3TotalCacheAdapter())->settings();

        self::assertSame('bunny', $settings['cdn_integration']);
        self::assertTrue($settings['cdn_purge']);
    }

    public function test_litespeed_cache(): void
    {
        $conf = [
            'cache'                => true,
            'cache-ttl_pub'        => 604800,
            'cache-mobile'         => false,
            'cache-priv'           => true,
            'cache-exc_cookies'    => ['my_cookie'],
            'cache-exc'            => ['/cart/', '/checkout/'],
            'cache-drop_qs'        => ['fbclid', 'gclid', 'utm*', '_ga'],
            'crawler'              => false,
            'cdn-cloudflare'       => true,
            'cdn-cloudflare_clear' => true,
            'cdn-quic'             => false,
        ];
        Filters\expectApplied('litespeed_conf')->andReturnUsing(fn (string $id) => $conf[$id]);

        $settings = (new LiteSpeedCacheAdapter())->settings();

        self::assertSame([
            'page_cache'             => true,
            'lifespan_hours'         => 168,
            'mobile_cache'           => true,
            'separate_mobile_cache'  => false,
            'logged_in_cache'        => true,
            'preload'                => false,
            'ignored_query_params'   => ['fbclid', 'gclid', 'utm*', '_ga'],
            'cdn_integration'        => 'cloudflare',
            'cdn_purge'              => true,
            'rejected_cookies_count' => 1,
            'rejected_uris_count'    => 2,
        ], $settings);
    }

    public function test_litespeed_cloudflare_without_purge_on_purge_all(): void
    {
        Filters\expectApplied('litespeed_conf')->andReturnUsing(
            fn (string $id) => ['cache' => true, 'cdn-cloudflare' => true, 'cdn-cloudflare_clear' => false][$id] ?? false
        );

        $settings = (new LiteSpeedCacheAdapter())->settings();

        self::assertSame('cloudflare', $settings['cdn_integration']);
        self::assertFalse($settings['cdn_purge']);
    }

    public function test_litespeed_settings_are_unknown_until_its_filter_answers(): void
    {
        // Brain Monkey's apply_filters() hands the value back, like WordPress without LiteSpeed's callback.
        $settings = (new LiteSpeedCacheAdapter())->settings();

        self::assertNull($settings['page_cache']);
        self::assertNull($settings['lifespan_hours']);
    }

    public function test_wp_super_cache(): void
    {
        $globals = [
            'cache_enabled'                   => true,
            'cache_max_time'                  => 1800,
            'wp_cache_mobile_enabled'         => 1,
            'wp_cache_not_logged_in'          => 2,
            'wp_cache_preload_interval'       => 600,
            'wpsc_ignore_tracking_parameters' => 1,
            'wpsc_tracking_parameters'        => ['fbclid', 'gclid', 'utm_source'],
            'wpsc_rejected_cookies'           => ['wordpress_logged_in'],
            'cache_rejected_uri'              => ['wp-.*\.php', 'index\.php'],
        ];
        foreach ($globals as $name => $value) {
            $GLOBALS[$name] = $value;
            $this->globals[] = $name;
        }

        self::assertSame([
            'page_cache'             => true,
            'lifespan_hours'         => 1,
            'mobile_cache'           => true,
            'separate_mobile_cache'  => true,
            'logged_in_cache'        => false,
            'preload'                => true,
            'ignored_query_params'   => ['fbclid', 'gclid', 'utm_source'],
            'cdn_integration'        => null,
            'cdn_purge'              => false,
            'rejected_cookies_count' => 1,
            'rejected_uris_count'    => 2,
        ], (new WpSuperCacheAdapter())->settings());
    }

    public function test_wp_super_cache_tracking_parameters_count_only_when_ignored(): void
    {
        $GLOBALS['wpsc_ignore_tracking_parameters'] = 0;
        $GLOBALS['wpsc_tracking_parameters']        = ['fbclid'];
        $this->globals = ['wpsc_ignore_tracking_parameters', 'wpsc_tracking_parameters'];

        self::assertSame([], (new WpSuperCacheAdapter())->settings()['ignored_query_params']);
    }

    public function test_flyingpress(): void
    {
        $this->options['FLYING_PRESS_CONFIG'] = [
            'license_key'          => 'SECRET-LICENCE',
            'cache_lifespan'       => '10h',
            'cache_mobile'         => false,
            'cache_logged_in'      => false,
            'cache_preload'        => true,
            'cache_ignore_queries' => ['utm_source', 'fbclid'],
            'cache_bypass_cookies' => ['wp_woocommerce_session_'],
            'cache_bypass_urls'    => ['/cart/', '/checkout/', '/my-account/'],
            'cdn'                  => true,
            'cdn_type'             => 'flying_cdn',
        ];

        $settings = (new FlyingPressAdapter())->settings();

        self::assertSame([
            'page_cache'             => true,
            'lifespan_hours'         => 10,
            'mobile_cache'           => true,
            'separate_mobile_cache'  => false,
            'logged_in_cache'        => false,
            'preload'                => true,
            'ignored_query_params'   => ['utm_source', 'fbclid'],
            'cdn_integration'        => 'flyingcdn',
            'cdn_purge'              => null,
            'rejected_cookies_count' => 1,
            'rejected_uris_count'    => 3,
        ], $settings);
        $this->assertNoSecret($settings);
    }

    public function test_flyingpress_never_expiring_cache(): void
    {
        $this->options['FLYING_PRESS_CONFIG'] = ['cache_lifespan' => 'never'];

        self::assertSame(0, (new FlyingPressAdapter())->settings()['lifespan_hours']);
    }

    // ── Tier 2 ────────────────────────────────────────────────────────────────

    public function test_wp_fastest_cache_reads_cloudflare_status_but_not_its_credentials(): void
    {
        $this->options['WpFastestCache'] = json_encode([
            'wpFastestCacheStatus'       => 'on',
            'wpFastestCacheMobile'       => 'on',
            'wpFastestCacheMobileTheme'  => 'on',
            'wpFastestCacheLoggedInUser' => 'on',
        ]);
        // WPFC keeps the Cloudflare email in cdnurl and the API key in originurl.
        $this->options['WpFastestCacheCDN'] = json_encode([
            ['id' => 'cloudflare', 'cdnurl' => 'owner@example.com', 'originurl' => 'SECRET-API-KEY'],
        ]);
        Functions\when('wp_get_schedules')->justReturn(['everysixhours' => ['interval' => 21600]]);
        Functions\when('_get_cron_array')->justReturn([
            time() + 60 => ['wp_fastest_cache_0' => ['k' => ['schedule' => 'everysixhours', 'args' => ['{"prefix":"all","content":"all"}']]]],
        ]);

        $settings = (new WpFastestCacheAdapter())->settings();

        self::assertTrue($settings['page_cache']);
        self::assertSame(6, $settings['lifespan_hours']);
        self::assertTrue($settings['mobile_cache']);
        self::assertTrue($settings['separate_mobile_cache']);
        self::assertFalse($settings['logged_in_cache']);
        self::assertSame('cloudflare', $settings['cdn_integration']);
        self::assertTrue($settings['cdn_purge']);
        $this->assertNoSecret($settings);
    }

    public function test_cache_enabler(): void
    {
        $this->options['cache_enabler'] = ['cache_expires' => 1, 'cache_expiry_time' => 12, 'mobile_cache' => 1];

        $settings = (new CacheEnablerAdapter())->settings();

        self::assertTrue($settings['page_cache']);
        self::assertSame(12, $settings['lifespan_hours']);
        self::assertTrue($settings['separate_mobile_cache']);
        self::assertFalse($settings['cdn_purge']);
    }

    public function test_breeze(): void
    {
        $this->options['breeze_basic_settings'] = [
            'breeze-active'          => '1',
            'breeze-b-ttl'           => 1440,
            'breeze-mobile-cache'    => '1',
            'breeze-mobile-separate' => '0',
            'breeze-disable-admin'   => ['administrator' => 0, 'editor' => 1],
        ];

        $settings = (new BreezeAdapter())->settings();

        self::assertTrue($settings['page_cache']);
        self::assertSame(24, $settings['lifespan_hours']);
        self::assertTrue($settings['mobile_cache']);
        self::assertFalse($settings['separate_mobile_cache']);
        self::assertTrue($settings['logged_in_cache']);
        self::assertFalse($settings['cdn_purge']);
    }

    public function test_hummingbird_reads_cloudflare_switches_only(): void
    {
        $this->options['wphb_settings'] = [
            'page_cache' => ['enabled' => true, 'preload' => true],
            'cloudflare' => ['enabled' => true, 'connected' => true, 'email' => 'owner@example.com', 'api_key' => 'SECRET-API-KEY'],
        ];
        file_put_contents(WP_CONTENT_DIR . '/wphb-cache/wphb-cache.php', json_encode([
            'clear_interval' => ['enabled' => true, 'interval' => 48],
            'settings'       => ['mobile' => 1, 'logged_in' => 0],
        ]));

        $settings = (new HummingbirdAdapter())->settings();

        self::assertTrue($settings['page_cache']);
        self::assertSame(48, $settings['lifespan_hours']);
        self::assertTrue($settings['mobile_cache']);
        self::assertFalse($settings['logged_in_cache']);
        self::assertSame('cloudflare', $settings['cdn_integration']);
        $this->assertNoSecret($settings);
    }

    public function test_wp_optimize(): void
    {
        $this->options['wpo_cache_config'] = [
            'enable_page_caching'          => true,
            'page_cache_length'            => 86400,
            'enable_mobile_caching'        => false,
            'enable_user_caching'          => false,
            'enable_schedule_preload'      => true,
            'cache_ignore_query_variables' => ['utm_source'],
        ];

        $settings = (new WpOptimizeAdapter())->settings();

        self::assertTrue($settings['page_cache']);
        self::assertSame(24, $settings['lifespan_hours']);
        self::assertTrue($settings['mobile_cache']);
        self::assertFalse($settings['separate_mobile_cache']);
        self::assertTrue($settings['preload']);
        self::assertSame(['utm_source'], $settings['ignored_query_params']);
    }

    public function test_super_page_cache_never_reads_cloudflare_credentials(): void
    {
        $this->options['swcfpc_config'] = [
            'cf_cache_enabled'      => 1,
            'cf_fallback_cache'     => 1,
            'cf_fallback_cache_ttl' => 0,
            'cf_email'              => 'owner@example.com',
            'cf_apikey'             => 'SECRET-API-KEY',
            'cf_apitoken'           => 'SECRET-TOKEN',
            'cf_zoneid'             => 'SECRET-ZONE',
        ];

        $settings = (new SuperPageCacheAdapter())->settings();

        self::assertTrue($settings['page_cache']);
        self::assertSame(0, $settings['lifespan_hours']);
        self::assertSame('cloudflare', $settings['cdn_integration']);
        self::assertTrue($settings['cdn_purge']);
        $this->assertNoSecret($settings);
    }

    public function test_jetpack_boost_page_cache_module_on(): void
    {
        $this->options['jetpack_boost_status_page-cache'] = '1';

        $settings = (new JetpackBoostAdapter())->settings();

        self::assertTrue($settings['page_cache']);
        self::assertFalse($settings['cdn_purge']);
    }

    public function test_jetpack_boost_page_cache_module_off_or_missing(): void
    {
        self::assertFalse((new JetpackBoostAdapter())->settings()['page_cache']);

        $this->options['jetpack_boost_status_page-cache'] = '';
        self::assertFalse((new JetpackBoostAdapter())->settings()['page_cache']);
    }

    public function test_speedycache(): void
    {
        $this->options['speedycache_options'] = [
            'status' => true, 'logged_in_user' => false, 'mobile' => true, 'mobile_theme' => true, 'preload' => true,
            'purge_interval' => 2, 'purge_interval_unit' => 'days',
            'varniship' => 'SECRET-TOKEN', 'license' => 'SECRET-LICENCE',
        ];

        $settings = (new SpeedyCacheAdapter())->settings();

        self::assertTrue($settings['page_cache']);
        self::assertSame(48, $settings['lifespan_hours']);
        self::assertTrue($settings['mobile_cache']);
        self::assertTrue($settings['separate_mobile_cache']);
        self::assertFalse($settings['logged_in_cache']);
        self::assertTrue($settings['preload']);
        $this->assertNoSecret($settings);
    }

    public function test_speedycache_mobile_without_mobile_theme_is_not_cached(): void
    {
        $this->options['speedycache_options'] = ['status' => true, 'mobile' => true, 'mobile_theme' => false];

        $settings = (new SpeedyCacheAdapter())->settings();

        self::assertFalse($settings['mobile_cache']);
        self::assertFalse($settings['separate_mobile_cache']);
    }

    /** @dataProvider speedycacheLifespans */
    public function test_speedycache_lifespan(array $options, ?int $hours): void
    {
        $this->options['speedycache_options'] = ['status' => true] + $options;

        self::assertSame($hours, (new SpeedyCacheAdapter())->settings()['lifespan_hours']);
    }

    public static function speedycacheLifespans(): array
    {
        return [
            'never'      => [[], 0],
            'hours'      => [['purge_interval' => 6, 'purge_interval_unit' => 'hours'], 6],
            'exact time' => [['purge_interval' => 1, 'purge_interval_unit' => 'days', 'purge_enable_exact_time' => true, 'purge_exact_time' => '03:00'], 24],
            'unit unknown' => [['purge_interval' => 1, 'purge_interval_unit' => 'weeks'], null],
        ];
    }

    public function test_nginx_helper(): void
    {
        $this->options['rt_wp_nginx_helper_options'] = ['enable_purge' => 1, 'preload_cache' => 0, 'redis_password' => 'SECRET-TOKEN'];

        $settings = (new NginxHelperAdapter())->settings();

        self::assertTrue($settings['page_cache']);
        self::assertFalse($settings['preload']);
        self::assertFalse($settings['cdn_purge']);
        $this->assertNoSecret($settings);
    }

    public function test_proxy_cache_purge_development_mode_bypasses_the_cache(): void
    {
        $this->options['vhp_varnish_devmode'] = ['active' => true, 'expire' => time() + 3600];

        $settings = (new ProxyCachePurgeAdapter())->settings();

        self::assertFalse($settings['page_cache']);
        self::assertSame('varnish', $settings['cdn_integration']);
        self::assertTrue($settings['cdn_purge']);
    }
}
