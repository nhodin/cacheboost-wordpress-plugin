<?php

declare(strict_types=1);

namespace CacheBoostWarmer\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CacheBoostWarmer\SiteValidation;
use PHPUnit\Framework\TestCase;

class SiteValidationTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** In-memory stand-in for the wp_options row holding the pending token. */
    private ?string $storedToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->storedToken = null;

        Functions\stubs([
            '__'                      => fn (string $text) => $text,
            'update_option'           => function (string $name, mixed $value): bool {
                if ($name === SiteValidation::OPTION) $this->storedToken = $value;
                return true;
            },
            'delete_option'           => function (string $name): bool {
                if ($name === SiteValidation::OPTION) $this->storedToken = null;
                return true;
            },
            'is_wp_error'             => false,
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** get_option serves both the plugin config (Config) and the pending token. */
    private function stubGetOption(): void
    {
        Functions\when('get_option')->alias(fn (string $name, mixed $default = false) =>
            $name === SiteValidation::OPTION ? ($this->storedToken ?? $default) : ['api_endpoint' => 'https://api.cache-boost.com']
        );
    }

    private function stubTokenFetch(int $code, string $body): void
    {
        Functions\expect('wp_remote_get')
            ->once()
            ->andReturnUsing(function (string $url): array {
                self::assertSame('https://api.cache-boost.com/v1/sites/42', $url);
                return ['kind' => 'get'];
            });
        $this->stubResponses(['get' => [$code, $body]]);
    }

    /** @param array<string, array{int,string}> $byKind */
    private function stubResponses(array $byKind): void
    {
        Functions\when('wp_remote_retrieve_response_code')->alias(fn (array $r) => $byKind[$r['kind']][0]);
        Functions\when('wp_remote_retrieve_body')->alias(fn (array $r) => $byKind[$r['kind']][1]);
    }

    // ── validate ──────────────────────────────────────────────────────────────

    public function test_token_file_is_live_while_cacheboost_checks_the_domain(): void
    {
        // The whole point: CacheBoost fetches /{token}.html during the POST, so the
        // token must already be stored (and servable) when the request goes out.
        $this->stubGetOption();
        $this->stubTokenFetch(200, json_encode(['id' => 42, 'validation_token' => self::TOKEN]));

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function (string $url): array {
                self::assertSame('https://api.cache-boost.com/v1/sites/42/validate', $url);
                self::assertSame(self::TOKEN, SiteValidation::token_for_path('/' . self::TOKEN . '.html'));
                return ['kind' => 'post'];
            });
        $this->stubResponses([
            'get'  => [200, json_encode(['validation_token' => self::TOKEN])],
            'post' => [200, '{"validated":true}'],
        ]);

        $result = SiteValidation::validate('cb_live_abc123', 42);

        self::assertTrue($result['success']);
    }

    public function test_token_is_withdrawn_after_success(): void
    {
        $this->stubGetOption();
        $this->stubTokenFetch(200, '');
        Functions\expect('wp_remote_post')->once()->andReturn(['kind' => 'post']);
        $this->stubResponses([
            'get'  => [200, json_encode(['validation_token' => self::TOKEN])],
            'post' => [200, '{"validated":true}'],
        ]);

        SiteValidation::validate('cb_live_abc123', 42);

        self::assertNull($this->storedToken);
        self::assertNull(SiteValidation::token_for_path('/' . self::TOKEN . '.html'));
    }

    public function test_token_is_withdrawn_after_failure(): void
    {
        // A failed validation must not leave the token publicly served on the site.
        $this->stubGetOption();
        $this->stubTokenFetch(200, '');
        Functions\expect('wp_remote_post')->once()->andReturn(['kind' => 'post']);
        $this->stubResponses([
            'get'  => [200, json_encode(['validation_token' => self::TOKEN])],
            'post' => [422, '{"validated":false,"error":"Validation token not found on the site."}'],
        ]);

        $result = SiteValidation::validate('cb_live_abc123', 42);

        self::assertFalse($result['success']);
        self::assertSame('Validation token not found on the site.', $result['message']);
        self::assertNull($this->storedToken);
    }

    public function test_missing_scope_explains_sites_write_is_needed(): void
    {
        // Existing keys only require sites:read; the user must know why auto-validation failed.
        $this->stubGetOption();
        $this->stubTokenFetch(200, '');
        Functions\expect('wp_remote_post')->once()->andReturn(['kind' => 'post']);
        $this->stubResponses([
            'get'  => [200, json_encode(['validation_token' => self::TOKEN])],
            'post' => [403, '{"error":"Scope \'sites:write\' required."}'],
        ]);

        $result = SiteValidation::validate('cb_live_abc123', 42);

        self::assertFalse($result['success']);
        self::assertStringContainsString('sites:write', $result['message']);
    }

    public function test_no_validation_call_when_token_cannot_be_read(): void
    {
        $this->stubGetOption();
        $this->stubTokenFetch(404, '{"error":"Site not found."}');
        Functions\expect('wp_remote_post')->never();

        $result = SiteValidation::validate('cb_live_abc123', 42);

        self::assertFalse($result['success']);
        self::assertNull($this->storedToken);
    }

    // ── token_for_path ────────────────────────────────────────────────────────

    public function test_regular_requests_never_touch_the_options_table(): void
    {
        // Hooked on every front-end request: must stay free for normal URLs.
        Functions\expect('get_option')->never();

        self::assertNull(SiteValidation::token_for_path('/'));
        self::assertNull(SiteValidation::token_for_path('/blog/hello-world/'));
        self::assertNull(SiteValidation::token_for_path('/wp-content/uploads/a.html'));
    }

    public function test_nothing_served_when_no_validation_is_pending(): void
    {
        $this->stubGetOption();

        self::assertNull(SiteValidation::token_for_path('/' . self::TOKEN . '.html'));
    }

    public function test_only_the_pending_token_is_served(): void
    {
        // Any other {x}.html must fall through to WordPress, or anyone could "prove" any token.
        $this->storedToken = self::TOKEN;
        $this->stubGetOption();

        self::assertSame(self::TOKEN, SiteValidation::token_for_path('/' . self::TOKEN . '.html'));
        self::assertNull(SiteValidation::token_for_path('/deadbeef.html'));
    }
}
