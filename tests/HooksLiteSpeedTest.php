<?php

declare(strict_types=1);

namespace CacheBoostWarmer\Tests;

use Brain\Monkey;
use CacheBoostWarmer\Config;
use CacheBoostWarmer\EventBuffer;
use CacheBoostWarmer\HooksCachePlugins;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * LiteSpeed Cache 3.0+ has no LiteSpeed_Cache_API class (it lives under the LiteSpeed\ namespace),
 * and litespeed_purge_all / litespeed_purge_post are commands other plugins fire to request a purge:
 * a purge from the LiteSpeed admin or on publish never fires them. The events fired after a purge are
 * litespeed_purged_all_lscache (Purge All and Purge LSCache) and litespeed_purged_post.
 */
class HooksLiteSpeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function config(bool $full = true, bool $smart = true): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('is_full_enabled')->willReturn($full);
        $config->method('is_smart_enabled')->willReturn($smart);
        return $config;
    }

    public function test_nothing_is_hooked_when_litespeed_is_not_active(): void
    {
        (new HooksCachePlugins($this->createMock(EventBuffer::class), $this->config()))->register();

        self::assertFalse(has_action('litespeed_purged_all_lscache'));
        self::assertFalse(has_action('litespeed_purged_post'));
    }

    #[RunInSeparateProcess]
    public function test_purge_events_are_hooked_when_litespeed_is_active(): void
    {
        define('LSCWP_V', '7.9.3');
        $hooks = new HooksCachePlugins($this->createMock(EventBuffer::class), $this->config());
        $hooks->register();

        self::assertNotFalse(has_action('litespeed_purged_all_lscache', [$hooks, 'on_litespeed_full_purge']));
        self::assertNotFalse(has_action('litespeed_purged_post', [$hooks, 'on_litespeed_post_purge']));
        // Purge All fires litespeed_purged_all_lscache then litespeed_purged_all: hooking both would warm twice.
        self::assertFalse(has_action('litespeed_purged_all'));
        // Commands, not events: listening to them would only catch purges requested by third parties.
        self::assertFalse(has_action('litespeed_purge_all'));
        self::assertFalse(has_action('litespeed_purge_post'));
    }

    #[RunInSeparateProcess]
    public function test_disabled_triggers_are_not_hooked(): void
    {
        define('LSCWP_V', '7.9.3');
        (new HooksCachePlugins($this->createMock(EventBuffer::class), $this->config(false, false)))->register();

        self::assertFalse(has_action('litespeed_purged_all_lscache'));
        self::assertFalse(has_action('litespeed_purged_post'));
    }

    public function test_full_purge_requests_a_full_warm(): void
    {
        $buffer = $this->createMock(EventBuffer::class);
        $buffer->expects(self::once())->method('push_full');

        (new HooksCachePlugins($buffer, $this->config()))->on_litespeed_full_purge();
    }

    public function test_post_purge_warms_the_post_permalink(): void
    {
        \Brain\Monkey\Functions\expect('get_permalink')->once()->with(42)->andReturn('https://example.com/carta/');
        $buffer = $this->createMock(EventBuffer::class);
        $buffer->expects(self::once())->method('push_urls')->with(['https://example.com/carta/']);

        (new HooksCachePlugins($buffer, $this->config()))->on_litespeed_post_purge(42);
    }

    public function test_post_purge_without_permalink_warms_nothing(): void
    {
        \Brain\Monkey\Functions\when('get_permalink')->justReturn(false);
        $buffer = $this->createMock(EventBuffer::class);
        $buffer->expects(self::never())->method('push_urls');

        (new HooksCachePlugins($buffer, $this->config()))->on_litespeed_post_purge(42);
    }
}
