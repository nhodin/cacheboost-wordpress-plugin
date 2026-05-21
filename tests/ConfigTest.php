<?php

declare(strict_types=1);

namespace CacheBoost\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CacheBoost\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
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

    private function makeConfig(array $options): Config
    {
        Functions\expect('get_option')
            ->once()
            ->with('cacheboost_options', [])
            ->andReturn($options);

        return new Config();
    }

    // ── defaults ─────────────────────────────────────────────────────────────

    public function test_all_defaults_when_options_empty(): void
    {
        $config = $this->makeConfig([]);

        self::assertFalse($config->is_enabled());
        self::assertSame('', $config->get_api_key());
        self::assertTrue($config->is_smart_enabled());
        self::assertTrue($config->is_full_enabled());
        self::assertFalse($config->is_stock_warming_enabled());
        self::assertSame('https://api.cacheboost.io', $config->get_api_endpoint());
    }

    // ── is_enabled ───────────────────────────────────────────────────────────

    public function test_is_enabled_true_when_set(): void
    {
        self::assertTrue($this->makeConfig(['enabled' => true])->is_enabled());
    }

    public function test_is_enabled_false_when_falsy(): void
    {
        self::assertFalse($this->makeConfig(['enabled' => ''])->is_enabled());
    }

    // ── get_api_key ───────────────────────────────────────────────────────────

    public function test_get_api_key_returns_configured_value(): void
    {
        self::assertSame('cb_live_abc123', $this->makeConfig(['api_key' => 'cb_live_abc123'])->get_api_key());
    }

    // ── is_smart_enabled ─────────────────────────────────────────────────────

    public function test_smart_enabled_by_default(): void
    {
        self::assertTrue($this->makeConfig([])->is_smart_enabled());
    }

    public function test_smart_disabled_when_set_false(): void
    {
        self::assertFalse($this->makeConfig(['smart_enabled' => false])->is_smart_enabled());
    }

    // ── is_full_enabled ──────────────────────────────────────────────────────

    public function test_full_enabled_by_default(): void
    {
        self::assertTrue($this->makeConfig([])->is_full_enabled());
    }

    public function test_full_disabled_when_set_false(): void
    {
        self::assertFalse($this->makeConfig(['full_enabled' => false])->is_full_enabled());
    }

    // ── is_stock_warming_enabled ──────────────────────────────────────────────

    public function test_stock_warming_enabled_when_set(): void
    {
        self::assertTrue($this->makeConfig(['stock_warming' => true])->is_stock_warming_enabled());
    }

    public function test_stock_warming_disabled_by_default(): void
    {
        self::assertFalse($this->makeConfig([])->is_stock_warming_enabled());
    }

    // ── get_api_endpoint ──────────────────────────────────────────────────────

    public function test_get_api_endpoint_returns_configured_value(): void
    {
        self::assertSame(
            'https://custom.endpoint.io',
            $this->makeConfig(['api_endpoint' => 'https://custom.endpoint.io'])->get_api_endpoint()
        );
    }
}
