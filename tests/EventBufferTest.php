<?php

declare(strict_types=1);

namespace CacheBoostWarmer\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use CacheBoostWarmer\EventBuffer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class EventBufferTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->resetStaticState();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // EventBuffer uses static properties to accumulate events across multiple
    // object instances within a single request. We must reset them between tests.
    private function resetStaticState(): void
    {
        $ref = new ReflectionClass(EventBuffer::class);

        foreach (['registered' => false, 'flushed' => false, 'mode' => 'smart', 'urls' => []] as $name => $default) {
            $ref->getProperty($name)->setValue(null, $default);
        }
    }

    private function getStatic(string $name): mixed
    {
        return (new ReflectionClass(EventBuffer::class))->getProperty($name)->getValue(null);
    }

    // Stub only Logger's WP calls (get_option/update_option) for tests that don't
    // exercise send() — used by all push_* tests since EventBuffer logs on every push.
    private function stubLogger(): void
    {
        Functions\stubs(['get_option' => [], 'update_option' => true]);
    }

    private function stubSendDependencies(): void
    {
        Functions\stubs([
            'get_option'     => ['enabled' => true, 'api_key' => 'cb_live_abc123', 'api_endpoint' => 'https://api.cacheboost.io', 'site_id' => 42, 'boost_id' => 5],
            'wp_json_encode' => fn (mixed $data) => json_encode($data),
            'update_option'  => true,
        ]);
    }

    // ── push_full ─────────────────────────────────────────────────────────────

    public function test_push_full_sets_mode_to_full(): void
    {
        $this->stubLogger();
        (new EventBuffer())->push_full();

        self::assertSame('full', $this->getStatic('mode'));
    }

    public function test_push_full_registers_shutdown_hook(): void
    {
        $this->stubLogger();
        Actions\expectAdded('shutdown')->once()->with(\Mockery::type('array'), 9999);

        (new EventBuffer())->push_full();

        $this->addToAssertionCount(1); // assertion is the Mockery ->once() expectation above
    }

    // ── push_urls ─────────────────────────────────────────────────────────────

    public function test_push_urls_accumulates_urls(): void
    {
        $this->stubLogger();
        $buffer = new EventBuffer();
        $buffer->push_urls(['https://example.com/a/']);
        $buffer->push_urls(['https://example.com/b/']);

        self::assertSame(['https://example.com/a/', 'https://example.com/b/'], $this->getStatic('urls'));
    }

    public function test_push_urls_deduplicates(): void
    {
        $this->stubLogger();
        $buffer = new EventBuffer();
        $buffer->push_urls(['https://example.com/a/']);
        $buffer->push_urls(['https://example.com/a/', 'https://example.com/b/']);

        self::assertSame(['https://example.com/a/', 'https://example.com/b/'], $this->getStatic('urls'));
    }

    public function test_push_urls_ignored_when_full_mode_already_set(): void
    {
        $this->stubLogger();
        $buffer = new EventBuffer();
        $buffer->push_full();
        $buffer->push_urls(['https://example.com/page/']);

        self::assertEmpty($this->getStatic('urls'));
    }

    // ── shutdown registered only once ─────────────────────────────────────────

    public function test_shutdown_registered_only_once_across_multiple_pushes(): void
    {
        $this->stubLogger();
        // add_action('shutdown', ...) must be called exactly once even with several pushes
        Actions\expectAdded('shutdown')->once();

        $buffer = new EventBuffer();
        $buffer->push_full();
        $buffer->push_full();
        $buffer->push_urls(['https://example.com/']);

        $this->addToAssertionCount(1); // assertion is the Mockery ->once() expectation above
    }

    // ── flush ─────────────────────────────────────────────────────────────────

    public function test_flush_sends_flush_all_in_full_mode(): void
    {
        $this->stubSendDependencies();

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function (string $url, array $args): array {
                self::assertSame('https://api.cacheboost.io/v1/boosts/5/run', $url);
                self::assertFalse($args['blocking']);
                return [];
            });

        $buffer = new EventBuffer();
        $buffer->push_full();
        $buffer->flush();
    }

    public function test_flush_sends_urls_in_smart_mode(): void
    {
        $this->stubSendDependencies();

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function (string $url, array $args): array {
                self::assertSame('https://api.cacheboost.io/v1/sites/42/warm', $url);
                $body = json_decode($args['body'], true);
                self::assertSame(['https://example.com/page/'], $body['urls']);
                self::assertArrayNotHasKey('event', $body);
                return [];
            });

        $buffer = new EventBuffer();
        $buffer->push_urls(['https://example.com/page/']);
        $buffer->flush();
    }

    public function test_flush_does_nothing_when_no_events(): void
    {
        Functions\expect('wp_remote_post')->never();

        (new EventBuffer())->flush();

        $this->addToAssertionCount(1); // assertion is the Mockery ->never() expectation above
    }

    public function test_flush_guard_prevents_double_send(): void
    {
        $this->stubSendDependencies();

        // wp_remote_post must be called exactly once even if flush() is called twice
        Functions\expect('wp_remote_post')->once()->andReturn([]);

        $buffer = new EventBuffer();
        $buffer->push_full();
        $buffer->flush();
        $buffer->flush();

        $this->addToAssertionCount(1); // assertion is the Mockery ->once() expectation above
    }
}
