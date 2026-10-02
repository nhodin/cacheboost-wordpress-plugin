<?php

declare(strict_types=1);

namespace CacheBoostWarmer\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CacheBoostWarmer\AccountStatus;
use PHPUnit\Framework\TestCase;

/**
 * Warm requests are fire-and-forget: when CacheBoost refuses the key because the account
 * email was never verified, nothing in WordPress fails visibly. AccountStatus is the only
 * way the admin learns why warming stopped — and that it is about to.
 */
class AccountStatusTest extends TestCase
{
    /** @var array<string, array{mixed, int}> In-memory transients: value, TTL. */
    private array $transients = [];

    private int $apiCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->transients = [];
        $this->apiCalls   = 0;

        Functions\stubs([
            'get_option'       => fn (string $name, mixed $default = false) => $default,
            'is_wp_error'      => fn ($v) => $v === 'wp_error',
            'get_transient'    => fn (string $key) => $this->transients[$key][0] ?? false,
            'set_transient'    => function (string $key, mixed $value, int $ttl): bool { $this->transients[$key] = [$value, $ttl]; return true; },
            'delete_transient' => function (string $key): bool { unset($this->transients[$key]); return true; },
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stubMe(int|string $code, array $body = []): void
    {
        Functions\when('wp_remote_get')->alias(function (string $url) use ($code, $body) {
            $this->apiCalls++;
            self::assertSame('https://api.cache-boost.com/v1/me', $url);
            return $code === 'wp_error' ? 'wp_error' : ['code' => $code, 'body' => json_encode($body)];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn (array $r) => $r['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn (array $r) => $r['body']);
    }

    public function test_refused_key_for_unverified_email_is_reported_as_blocked(): void
    {
        $this->stubMe(403, ['error' => 'Email address not verified.', 'code' => 'email_unverified']);

        self::assertSame(['state' => 'blocked'], AccountStatus::fetch('cb_live_x'));
    }

    public function test_other_403_is_not_mistaken_for_an_email_problem(): void
    {
        // A missing scope must not tell the admin to check their inbox.
        $this->stubMe(403, ['error' => "Scope 'sites:read' required."]);

        self::assertSame(['state' => 'unknown'], AccountStatus::fetch('cb_live_x'));
    }

    public function test_grace_period_exposes_the_deadline(): void
    {
        $this->stubMe(200, ['user_id' => 1, 'email_verified' => false, 'email_verify_deadline' => '2026-10-09 11:00:00']);

        self::assertSame(['state' => 'pending', 'deadline' => '2026-10-09 11:00:00'], AccountStatus::fetch('cb_live_x'));
    }

    public function test_older_api_without_the_fields_means_nothing_to_report(): void
    {
        // The plugin may run against an API deployed before these fields existed.
        $this->stubMe(200, ['user_id' => 1, 'scopes' => []]);

        self::assertSame(['state' => 'ok'], AccountStatus::fetch('cb_live_x'));
    }

    public function test_state_is_cached_so_admin_pages_do_not_call_the_api_each_time(): void
    {
        $this->stubMe(200, ['email_verified' => true]);

        AccountStatus::get('cb_live_x');
        AccountStatus::get('cb_live_x');

        self::assertSame(1, $this->apiCalls);
    }

    public function test_pending_state_is_rechecked_sooner_than_a_healthy_one(): void
    {
        // Once the user clicks the link, the warning must not linger for half a day.
        $this->stubMe(200, ['email_verified' => false, 'email_verify_deadline' => '2026-10-09 11:00:00']);
        AccountStatus::get('cb_live_x');
        $pendingTtl = $this->transients[AccountStatus::TRANSIENT][1];

        AccountStatus::clear();
        $this->stubMe(200, ['email_verified' => true]);
        AccountStatus::get('cb_live_x');

        self::assertLessThan($this->transients[AccountStatus::TRANSIENT][1], $pendingTtl);
    }

    public function test_unreachable_api_is_unknown(): void
    {
        $this->stubMe('wp_error');

        self::assertSame(['state' => 'unknown'], AccountStatus::fetch('cb_live_x'));
    }
}
