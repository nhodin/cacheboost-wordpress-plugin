<?php

declare(strict_types=1);

namespace CacheBoostWarmer\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CacheBoostWarmer\ApiClient;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
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

    private function stubCanNotify(bool $enabled = true, string $key = 'cb_live_abc123'): void
    {
        Functions\stubs([
            'get_option'     => ['enabled' => $enabled, 'api_key' => $key, 'api_endpoint' => 'https://api.cacheboost.io'],
            'wp_json_encode' => fn (mixed $data) => json_encode($data),
            'update_option'  => true,
        ]);
    }

    // ── is_valid_api_key ──────────────────────────────────────────────────────

    public function test_valid_key_accepted(): void
    {
        self::assertTrue(ApiClient::is_valid_api_key('cb_live_abc123'));
        self::assertTrue(ApiClient::is_valid_api_key('cb_live_ABC123XYZ'));
    }

    public function test_key_without_prefix_rejected(): void
    {
        self::assertFalse(ApiClient::is_valid_api_key('abc123'));
    }

    public function test_empty_key_rejected(): void
    {
        self::assertFalse(ApiClient::is_valid_api_key(''));
    }

    public function test_key_with_special_chars_rejected(): void
    {
        self::assertFalse(ApiClient::is_valid_api_key('cb_live_abc!@#'));
    }

    public function test_wrong_prefix_rejected(): void
    {
        self::assertFalse(ApiClient::is_valid_api_key('cb_test_abc123'));
    }

    // ── can_notify ────────────────────────────────────────────────────────────

    public function test_can_notify_false_when_disabled(): void
    {
        Functions\expect('get_option')->once()->andReturn(['enabled' => false, 'api_key' => 'cb_live_abc123']);

        self::assertFalse(ApiClient::can_notify());
    }

    public function test_can_notify_false_with_invalid_key(): void
    {
        Functions\expect('get_option')->once()->andReturn(['enabled' => true, 'api_key' => 'invalid_key']);

        self::assertFalse(ApiClient::can_notify());
    }

    public function test_can_notify_true_when_enabled_with_valid_key(): void
    {
        Functions\expect('get_option')->once()->andReturn(['enabled' => true, 'api_key' => 'cb_live_abc123']);

        self::assertTrue(ApiClient::can_notify());
    }

    // ── trigger_warm ─────────────────────────────────────────────────────────

    public function test_trigger_warm_does_nothing_when_disabled(): void
    {
        Functions\expect('get_option')->once()->andReturn(['enabled' => false, 'api_key' => 'cb_live_abc123']);
        Functions\expect('wp_remote_post')->never();

        ApiClient::trigger_warm(42, ['https://example.com/page/']);

        $this->addToAssertionCount(1);
    }

    public function test_trigger_warm_does_nothing_when_site_id_zero(): void
    {
        $this->stubCanNotify();
        Functions\expect('wp_remote_post')->never();

        ApiClient::trigger_warm(0, ['https://example.com/page/']);

        $this->addToAssertionCount(1);
    }

    public function test_trigger_warm_posts_to_correct_endpoint(): void
    {
        $this->stubCanNotify();

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function (string $url, array $args): array {
                self::assertSame('https://api.cacheboost.io/v1/sites/42/warm', $url);
                self::assertFalse($args['blocking']);
                return [];
            });

        ApiClient::trigger_warm(42, ['https://example.com/page/']);
    }

    public function test_trigger_warm_adds_bearer_authorization_header(): void
    {
        $this->stubCanNotify();

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function (string $url, array $args): array {
                self::assertSame('Bearer cb_live_abc123', $args['headers']['Authorization']);
                return [];
            });

        ApiClient::trigger_warm(42, ['https://example.com/page/']);
    }

    public function test_trigger_warm_payload_contains_urls_without_event(): void
    {
        $this->stubCanNotify();

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function (string $url, array $args): array {
                $body = json_decode($args['body'], true);
                self::assertSame(['https://example.com/page/'], $body['urls']);
                self::assertArrayNotHasKey('event', $body);
                self::assertArrayNotHasKey('site_url', $body);
                return [];
            });

        ApiClient::trigger_warm(42, ['https://example.com/page/']);
    }

    // ── trigger_boost_run ─────────────────────────────────────────────────────

    public function test_trigger_boost_run_does_nothing_when_boost_id_zero(): void
    {
        Functions\expect('get_option')->once()->andReturn(['enabled' => true, 'api_key' => 'cb_live_abc123']);
        Functions\expect('wp_remote_post')->never();

        ApiClient::trigger_boost_run(0);

        $this->addToAssertionCount(1);
    }

    public function test_trigger_boost_run_posts_to_correct_endpoint(): void
    {
        $this->stubCanNotify();

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function (string $url, array $args): array {
                self::assertSame('https://api.cacheboost.io/v1/boosts/5/run', $url);
                self::assertFalse($args['blocking']);
                return [];
            });

        ApiClient::trigger_boost_run(5);
    }

    // ── ping ──────────────────────────────────────────────────────────────────

    public function test_ping_returns_success_on_200(): void
    {
        // ping() calls /v1/me then /v1/sites — two HTTP calls, both succeed
        Functions\stubs([
            'get_option'                       => [],
            'update_option'                    => true,
            'wp_remote_get'                    => [],
            'is_wp_error'                      => false,
            'wp_remote_retrieve_response_code' => 200,
            'wp_remote_retrieve_body'          => '{"scopes":["sites:read"],"regions":["us"]}',
        ]);

        $result = ApiClient::ping('cb_live_abc123', 'https://api.cacheboost.io');

        self::assertTrue($result['success']);
    }

    /** @dataProvider nonSuccessStatusProvider */
    public function test_ping_returns_error_on_non_200(int $status): void
    {
        // Early return after /v1/me fails — only one HTTP call
        Functions\stubs(['get_option' => [], 'update_option' => true]);
        Functions\expect('wp_remote_get')->once()->andReturn([]);
        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn($status);

        $result = ApiClient::ping('cb_live_abc123', 'https://api.cacheboost.io');

        self::assertFalse($result['success']);
        self::assertStringContainsString((string) $status, $result['message']);
    }

    public static function nonSuccessStatusProvider(): array
    {
        return [
            'unauthorized' => [401],
            'forbidden'    => [403],
            'server error' => [500],
        ];
    }

    public function test_ping_returns_error_on_wp_error(): void
    {
        $wpError = \Mockery::mock('WP_Error');
        $wpError->shouldReceive('get_error_message')->andReturn('cURL error: could not connect');

        Functions\stubs(['get_option' => [], 'update_option' => true]);
        Functions\expect('wp_remote_get')->once()->andReturn($wpError);
        Functions\expect('is_wp_error')->once()->andReturn(true);

        $result = ApiClient::ping('cb_live_abc123', 'https://api.cacheboost.io');

        self::assertFalse($result['success']);
        self::assertStringContainsString('cURL error', $result['message']);
    }

    public function test_ping_strips_trailing_slash_from_endpoint(): void
    {
        // Verify trailing slash on endpoint doesn't produce double-slash in URL
        $callCount = 0;
        Functions\stubs([
            'get_option'                       => [],
            'update_option'                    => true,
            'is_wp_error'                      => false,
            'wp_remote_retrieve_response_code' => 200,
            'wp_remote_retrieve_body'          => '{"scopes":[],"regions":[]}',
        ]);
        Functions\expect('wp_remote_get')
            ->times(2)
            ->andReturnUsing(function (string $url) use (&$callCount): array {
                if ($callCount++ === 0) {
                    self::assertSame('https://api.cacheboost.io/v1/me', $url);
                }
                return [];
            });

        ApiClient::ping('cb_live_abc123', 'https://api.cacheboost.io/');
    }
}
