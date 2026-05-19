<?php

declare(strict_types=1);

namespace CacheBoost\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CacheBoost\ApiClient;
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

    // Stub the WP functions that Config + ApiClient::send() call, so tests that
    // exercise send() don't have to repeat the boilerplate every time.
    private function stubCanNotify(bool $enabled = true, string $key = 'cb_live_abc123'): void
    {
        Functions\stubs([
            'get_option'     => ['enabled' => $enabled, 'api_key' => $key, 'api_endpoint' => 'https://api.cacheboost.io'],
            'get_home_url'   => 'https://example.com',
            'wp_json_encode' => fn (mixed $data) => json_encode($data),
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

    // ── send ──────────────────────────────────────────────────────────────────

    public function test_send_does_nothing_when_disabled(): void
    {
        Functions\expect('get_option')->once()->andReturn(['enabled' => false, 'api_key' => 'cb_live_abc123']);
        Functions\expect('wp_remote_post')->never();

        ApiClient::send(['event' => 'flush_all']);

        $this->addToAssertionCount(1); // assertion is the Mockery ->never() expectation above
    }

    public function test_send_posts_to_v1_warm_endpoint(): void
    {
        $this->stubCanNotify();

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function (string $url, array $args): array {
                self::assertSame('https://api.cacheboost.io/v1/warm', $url);
                self::assertFalse($args['blocking']);
                return [];
            });

        ApiClient::send(['event' => 'flush_all']);
    }

    public function test_send_adds_bearer_authorization_header(): void
    {
        $this->stubCanNotify();

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function (string $url, array $args): array {
                self::assertSame('Bearer cb_live_abc123', $args['headers']['Authorization']);
                return [];
            });

        ApiClient::send(['event' => 'flush_all']);
    }

    public function test_send_payload_contains_event_site_url_and_timestamp(): void
    {
        $this->stubCanNotify();

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function (string $url, array $args): array {
                $body = json_decode($args['body'], true);
                self::assertSame('flush_by_url', $body['event']);
                self::assertSame('https://example.com', $body['site_url']);
                self::assertArrayHasKey('timestamp', $body);
                self::assertSame(['https://example.com/page/'], $body['urls']);
                return [];
            });

        ApiClient::send(['event' => 'flush_by_url', 'urls' => ['https://example.com/page/']]);
    }

    // ── ping ──────────────────────────────────────────────────────────────────

    public function test_ping_returns_success_on_200(): void
    {
        Functions\expect('wp_remote_get')->once()->andReturn([]);
        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn(200);

        $result = ApiClient::ping('cb_live_abc123', 'https://api.cacheboost.io');

        self::assertTrue($result['success']);
    }

    /** @dataProvider nonSuccessStatusProvider */
    public function test_ping_returns_error_on_non_200(int $status): void
    {
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

        Functions\expect('wp_remote_get')->once()->andReturn($wpError);
        Functions\expect('is_wp_error')->once()->andReturn(true);

        $result = ApiClient::ping('cb_live_abc123', 'https://api.cacheboost.io');

        self::assertFalse($result['success']);
        self::assertStringContainsString('cURL error', $result['message']);
    }

    public function test_ping_strips_trailing_slash_from_endpoint(): void
    {
        Functions\expect('wp_remote_get')
            ->once()
            ->andReturnUsing(function (string $url): array {
                self::assertSame('https://api.cacheboost.io/v1/ping', $url);
                return [];
            });
        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn(200);

        ApiClient::ping('cb_live_abc123', 'https://api.cacheboost.io/');
    }
}
