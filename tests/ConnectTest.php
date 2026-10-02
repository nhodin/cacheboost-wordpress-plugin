<?php

declare(strict_types=1);

namespace CacheBoostWarmer\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CacheBoostWarmer\Connect;
use PHPUnit\Framework\TestCase;

/**
 * "Connect to CacheBoost" replaces copy-pasting an API key. The return URL carries a
 * one-time code that can leak (logs, Referer, history), so the plugin must only accept
 * a return it started itself, for the admin who started it, and only it can redeem the code.
 */
class ConnectTest extends TestCase
{
    private const ADMIN_ID = 1;

    /** @var array<string, mixed> In-memory transients. */
    private array $transients = [];

    /** @var list<array{string, array}> wp_remote_post calls. */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->transients = [];
        $this->posts      = [];

        Functions\stubs([
            'get_option'             => fn (string $name, mixed $default = false) => $name === 'cbwarmer_options' ? [] : $default,
            'update_option'          => true,
            'get_current_user_id'    => self::ADMIN_ID,
            'home_url'               => fn (string $path = '') => 'https://boutique.fr' . $path,
            'admin_url'              => fn (string $path = '') => 'https://boutique.fr/wp-admin/' . $path,
            'set_transient'          => function (string $key, mixed $value): bool { $this->transients[$key] = $value; return true; },
            'get_transient'          => fn (string $key) => $this->transients[$key] ?? false,
            'delete_transient'       => function (string $key): bool { unset($this->transients[$key]); return true; },
            'is_wp_error'            => false,
            'wp_json_encode'         => fn ($data) => json_encode($data),
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Starts a flow and returns its state, as CacheBoost would echo it back. */
    private function startFlow(): string
    {
        parse_str((string) parse_url(Connect::authorize_url(), PHP_URL_QUERY), $query);
        return $query['state'];
    }

    /** @param array<string, array{int, mixed}> $byUrlSuffix */
    private function stubPosts(array $byUrlSuffix): void
    {
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use ($byUrlSuffix): array {
            $this->posts[] = [$url, $args];
            foreach ($byUrlSuffix as $suffix => [$code, $body]) {
                if (str_ends_with($url, $suffix)) return ['code' => $code, 'body' => json_encode($body)];
            }
            self::fail("Unexpected POST $url");
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn (array $r) => $r['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn (array $r) => $r['body']);
    }

    // ── PKCE / authorize URL ──────────────────────────────────────────────────

    public function test_challenge_matches_rfc7636_and_the_api(): void
    {
        // The API checks the same vector: any encoding drift would break every connection.
        self::assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            Connect::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')
        );
    }

    public function test_authorize_url_sends_this_site_and_a_challenge_of_the_kept_verifier(): void
    {
        parse_str((string) parse_url(Connect::authorize_url(), PHP_URL_QUERY), $q);

        self::assertSame('https://boutique.fr', $q['site_url']);
        self::assertSame('https://boutique.fr/wp-admin/admin.php?page=cbwarmer', $q['redirect_uri']);
        self::assertSame('S256', $q['code_challenge_method']);

        // Only the challenge leaves WordPress; the verifier stays in the transient.
        $pending = $this->transients[Connect::TRANSIENT_PREFIX . $q['state']];
        self::assertSame(Connect::challenge($pending['verifier']), $q['code_challenge']);
        self::assertStringNotContainsString($pending['verifier'], Connect::authorize_url());
    }

    // ── Callback ──────────────────────────────────────────────────────────────

    public function test_success_redeems_the_code_with_the_kept_verifier(): void
    {
        $state   = $this->startFlow();
        $verifier = $this->transients[Connect::TRANSIENT_PREFIX . $state]['verifier'];
        $this->stubPosts(['/v1/connect/token' => [200, ['api_key' => 'cb_live_' . str_repeat('a', 32), 'site_id' => 7]]]);

        $result = Connect::handle_callback($state, 'the-code', '');

        self::assertTrue($result['success']);
        self::assertSame(7, $result['site_id']);
        self::assertSame(['code' => 'the-code', 'code_verifier' => $verifier], $this->posts[0][1]['body']);
        // One-time: the same return URL cannot be replayed.
        self::assertSame([], $this->transients);
    }

    public function test_unknown_state_is_rejected_without_calling_the_api(): void
    {
        // A crafted link must not make this site redeem someone else's code.
        $this->stubPosts([]);

        $result = Connect::handle_callback('forged-state-forged-state', 'code', '');

        self::assertSame(['success' => false, 'reason' => 'invalid_state'], $result);
        self::assertSame([], $this->posts);
    }

    public function test_return_opened_by_another_admin_is_rejected(): void
    {
        $state = $this->startFlow();
        Functions\when('get_current_user_id')->justReturn(2);
        $this->stubPosts([]);

        $result = Connect::handle_callback($state, 'code', '');

        self::assertSame('invalid_state', $result['reason']);
        self::assertSame([], $this->posts);
    }

    public function test_cancelled_consent_stores_nothing(): void
    {
        $state = $this->startFlow();
        $this->stubPosts([]);

        $result = Connect::handle_callback($state, '', 'access_denied');

        self::assertSame('denied', $result['reason']);
        self::assertSame([], $this->posts);
    }

    public function test_rejected_exchange_is_reported(): void
    {
        $state = $this->startFlow();
        $this->stubPosts(['/v1/connect/token' => [400, ['error' => 'Invalid or expired code.']]]);

        self::assertSame('exchange_failed', Connect::handle_callback($state, 'expired', '')['reason']);
    }

    // ── Full warming Boost ────────────────────────────────────────────────────

    public function test_reconnecting_reuses_the_existing_boost(): void
    {
        // Reconnect must not pile up duplicate Boosts (each would warm the whole site).
        Functions\when('wp_remote_get')->justReturn(['code' => 200, 'body' => json_encode([['id' => 5, 'site_id' => 7, 'name' => 'Mine']])]);
        $this->stubPosts([]);

        self::assertSame(5, Connect::ensure_full_warming_boost('cb_live_x', 7, ['us']));
        self::assertSame([], $this->posts);
    }

    public function test_new_site_gets_its_sitemap_and_a_boost_built_from_it(): void
    {
        // Without this Boost, Full warming silently does nothing after a global purge.
        Functions\when('wp_remote_get')->justReturn(['code' => 200, 'body' => '[]']);
        Functions\when('get_sitemap_url')->justReturn('https://boutique.fr/wp-sitemap.xml');
        $this->stubPosts([
            '/v1/sites/7/sitemaps' => [201, ['id' => 12]],
            '/v1/boosts'           => [201, ['id' => 9]],
        ]);

        self::assertSame(9, Connect::ensure_full_warming_boost('cb_live_x', 7, ['fr', 'us']));

        self::assertSame(['url' => 'https://boutique.fr/wp-sitemap.xml'], json_decode($this->posts[0][1]['body'], true));
        $boost = json_decode($this->posts[1][1]['body'], true);
        self::assertSame([12], $boost['sitemap_ids']);
        self::assertSame(['fr'], $boost['region']);
        self::assertSame(7, $boost['site_id']);
    }
}
