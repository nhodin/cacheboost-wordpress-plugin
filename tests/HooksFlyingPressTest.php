<?php

declare(strict_types=1);

namespace CacheBoostWarmer\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CacheBoostWarmer\Config;
use CacheBoostWarmer\EventBuffer;
use CacheBoostWarmer\HooksCachePlugins;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * After a FlyingPress purge the CDN edges are empty, so the first visitor gets a MISS unless we warm.
 * But a burst of purges must yield one boost: concurrent boosts compete and skew the HIT/MISS stats.
 */
class HooksFlyingPressTest extends TestCase
{
    /** @var array<string, mixed> Fake transient store shared across simulated requests. */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->transients = [];
        Functions\when('get_transient')->alias(fn (string $k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function (string $k, $v) { $this->transients[$k] = $v; return true; });
        Functions\stubs(['get_option' => [], 'update_option' => true]); // Logger
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function hooks(EventBuffer $buffer): HooksCachePlugins
    {
        return new HooksCachePlugins($buffer, $this->createMock(Config::class));
    }

    public function test_burst_of_full_purges_requests_a_single_boost(): void
    {
        $buffer = $this->createMock(EventBuffer::class);
        $buffer->expects(self::once())->method('push_full');

        // Each call stands for a separate request (separate hook instance), as when an admin purges twice.
        $this->hooks($buffer)->on_flyingpress_full_purge();
        $this->hooks($buffer)->on_flyingpress_full_purge();
        $this->hooks($buffer)->on_flyingpress_full_purge();
    }

    public function test_full_purge_after_debounce_window_requests_a_new_boost(): void
    {
        $buffer = $this->createMock(EventBuffer::class);
        $buffer->expects(self::exactly(2))->method('push_full');

        $this->hooks($buffer)->on_flyingpress_full_purge();
        $this->transients = []; // window expired
        $this->hooks($buffer)->on_flyingpress_full_purge();
    }

    public function test_url_purge_warms_only_that_url(): void
    {
        $buffer = $this->createMock(EventBuffer::class);
        $buffer->expects(self::never())->method('push_full');
        $buffer->expects(self::once())->method('push_urls')->with(['https://example.com/carta/']);

        $this->hooks($buffer)->on_flyingpress_url_purge(['https://example.com/carta/']);
    }

    public function test_url_purge_right_after_full_purge_does_not_start_a_parallel_inline_warm(): void
    {
        $buffer = $this->createMock(EventBuffer::class);
        $buffer->expects(self::once())->method('push_full');
        $buffer->expects(self::never())->method('push_urls');

        $this->hooks($buffer)->on_flyingpress_full_purge();
        $this->hooks($buffer)->on_flyingpress_url_purge(['https://example.com/carta/']);
    }

    public function test_nothing_is_hooked_when_flyingpress_is_not_active(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('is_flyingpress_warming_enabled')->willReturn(true);
        $config->method('is_full_enabled')->willReturn(true);
        $config->method('is_smart_enabled')->willReturn(true);

        (new HooksCachePlugins($this->createMock(EventBuffer::class), $config))->register();

        self::assertFalse(has_action('flying_press_purge_everything:after'));
        self::assertFalse(has_action('flying_press_purge_url:after'));
    }

    #[RunInSeparateProcess]
    public function test_purge_hooks_are_registered_when_flyingpress_is_active(): void
    {
        eval('namespace FlyingPress; class Purge {}');
        $config = $this->createMock(Config::class);
        $config->method('is_flyingpress_warming_enabled')->willReturn(true);
        $config->method('is_full_enabled')->willReturn(true);
        $config->method('is_smart_enabled')->willReturn(true);

        $hooks = new HooksCachePlugins($this->createMock(EventBuffer::class), $config);
        $hooks->register();

        self::assertNotFalse(has_action('flying_press_purge_pages:after', [$hooks, 'on_flyingpress_full_purge']));
        self::assertNotFalse(has_action('flying_press_purge_everything:after', [$hooks, 'on_flyingpress_full_purge']));
        self::assertNotFalse(has_action('flying_press_purge_urls:after'));
        self::assertNotFalse(has_action('flying_press_purge_url:after'));
    }

    #[RunInSeparateProcess]
    public function test_option_off_disables_flyingpress_warming(): void
    {
        eval('namespace FlyingPress; class Purge {}');
        $config = $this->createMock(Config::class);
        $config->method('is_flyingpress_warming_enabled')->willReturn(false);
        $config->method('is_full_enabled')->willReturn(true);
        $config->method('is_smart_enabled')->willReturn(true);

        (new HooksCachePlugins($this->createMock(EventBuffer::class), $config))->register();

        self::assertFalse(has_action('flying_press_purge_everything:after'));
        self::assertFalse(has_action('flying_press_purge_url:after'));
    }
}
