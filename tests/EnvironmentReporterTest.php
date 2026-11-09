<?php

declare(strict_types=1);

namespace CacheBoostWarmer\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CacheBoostWarmer\EnvironmentReporter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class EnvironmentReporterTest extends TestCase
{
    use WordPressStubs;

    /** @var array<int, array{method: string, url: string, body: ?array}> */
    private array $requests = [];

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubWordPress();
        $this->connect();
        (new ReflectionClass(EnvironmentReporter::class))->getProperty('scheduled')->setValue(null, false);

        $record = function (string $method) {
            return function (string $url, array $args) use ($method): array {
                $this->requests[] = [
                    'method' => $args['method'] ?? $method,
                    'url'    => $url,
                    'body'   => isset($args['body']) ? json_decode($args['body'], true) : null,
                    'auth'   => $args['headers']['Authorization'] ?? null,
                ];
                return ['status' => $this->status];
            };
        };
        Functions\when('wp_remote_post')->alias($record('POST'));
        Functions\when('wp_remote_request')->alias($record('GET'));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn (array $r): int => $r['status']);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"stored":true}');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Sending ───────────────────────────────────────────────────────────────

    public function test_first_send_posts_the_snapshot_with_the_site_key(): void
    {
        $result = EnvironmentReporter::send();

        self::assertSame('sent', $result['code']);
        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://api.cacheboost.io/v1/sites/42/environment', $this->requests[0]['url']);
        self::assertSame('Bearer cb_live_abc123', $this->requests[0]['auth']);
        self::assertArrayHasKey('cache_plugins', $this->requests[0]['body']);
        self::assertNotEmpty($this->options[EnvironmentReporter::STATE_OPTION]['hash']);
    }

    public function test_unchanged_snapshot_is_not_sent_again(): void
    {
        EnvironmentReporter::send();
        $result = EnvironmentReporter::send();

        self::assertSame('unchanged', $result['code']);
        self::assertCount(1, $this->requests);
    }

    public function test_changed_snapshot_is_sent(): void
    {
        EnvironmentReporter::send();
        $this->install('wp-super-cache/wp-cache.php', '3.1.5');
        EnvironmentReporter::send();

        self::assertCount(2, $this->requests);
        self::assertSame('wp-super-cache', $this->requests[1]['body']['cache_plugins'][0]['slug']);
    }

    public function test_send_now_ignores_the_hash(): void
    {
        EnvironmentReporter::send();
        EnvironmentReporter::send(true);

        self::assertCount(2, $this->requests);
    }

    public function test_failed_send_is_retried_by_the_next_trigger(): void
    {
        $this->status = 500;
        self::assertSame('error', EnvironmentReporter::send()['code']);
        self::assertArrayNotHasKey(EnvironmentReporter::STATE_OPTION, $this->options);

        $this->status = 200;
        EnvironmentReporter::send();

        self::assertCount(2, $this->requests);
    }

    public function test_nothing_is_sent_without_a_connection(): void
    {
        $this->connect(['site_id' => null]);

        self::assertSame('not_connected', EnvironmentReporter::send()['code']);
        self::assertSame([], $this->requests);
    }

    public function test_nothing_is_sent_while_the_plugin_is_disabled(): void
    {
        $this->connect(['enabled' => false]);

        self::assertSame('plugin_disabled', EnvironmentReporter::send()['code']);
        self::assertSame([], $this->requests);
    }

    // ── Sharing turned off ────────────────────────────────────────────────────

    public function test_sharing_off_sends_one_delete_then_nothing(): void
    {
        EnvironmentReporter::send();
        $this->connect(['share_environment' => false]);

        self::assertSame('deleted', EnvironmentReporter::send()['code']);
        self::assertSame('disabled', EnvironmentReporter::send()['code']);
        self::assertSame('disabled', EnvironmentReporter::send(true)['code']);

        self::assertCount(2, $this->requests);
        self::assertSame('DELETE', $this->requests[1]['method']);
        self::assertSame('https://api.cacheboost.io/v1/sites/42/environment', $this->requests[1]['url']);
        self::assertNull($this->requests[1]['body']);
    }

    public function test_failed_delete_is_retried(): void
    {
        $this->connect(['share_environment' => false]);
        $this->status = 503;
        EnvironmentReporter::send();
        $this->status = 200;

        self::assertSame('deleted', EnvironmentReporter::send()['code']);
        self::assertCount(2, $this->requests);
    }

    public function test_sharing_back_on_sends_the_snapshot_again(): void
    {
        $this->connect(['share_environment' => false]);
        EnvironmentReporter::send();
        $this->connect(['share_environment' => true]);

        self::assertSame('sent', EnvironmentReporter::send()['code']);
        self::assertSame('POST', $this->requests[1]['method']);
    }

    // ── Triggers ──────────────────────────────────────────────────────────────

    public function test_triggers_of_one_request_schedule_a_single_send(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_single_event')->once()->with(\Mockery::type('int'), EnvironmentReporter::SEND_HOOK);

        // e.g. a cache plugin's settings saved, then deactivated, in the same request.
        EnvironmentReporter::schedule();
        EnvironmentReporter::schedule();
        EnvironmentReporter::schedule();

        $this->addToAssertionCount(1);
    }

    public function test_pending_send_is_not_scheduled_twice(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(time() + 10);
        Functions\expect('wp_schedule_single_event')->never();

        EnvironmentReporter::schedule();

        $this->addToAssertionCount(1);
    }

    public function test_nothing_is_scheduled_without_an_api_key(): void
    {
        $this->connect(['api_key' => '']);
        Functions\expect('wp_schedule_single_event')->never();

        EnvironmentReporter::schedule();

        $this->addToAssertionCount(1);
    }

    public function test_register_hooks_the_triggers(): void
    {
        (new EnvironmentReporter())->register();

        foreach (['update_option_cbwarmer_options', 'activated_plugin', 'deactivated_plugin', 'update_option_wp_rocket_settings', 'w3tc_config_save'] as $hook) {
            self::assertNotFalse(has_action($hook, [EnvironmentReporter::class, 'schedule']), $hook);
        }
        self::assertNotFalse(has_action(EnvironmentReporter::DAILY_HOOK, [EnvironmentReporter::class, 'send']));
        self::assertNotFalse(has_action(EnvironmentReporter::SEND_HOOK, [EnvironmentReporter::class, 'send']));
    }
}
