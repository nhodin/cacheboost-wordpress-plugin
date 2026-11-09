<?php

declare(strict_types=1);

namespace CacheBoostWarmer\Tests;

use Brain\Monkey\Functions;

/**
 * In-memory options and plugin list for the environment snapshot tests.
 * Call stubWordPress() after Monkey\setUp().
 */
trait WordPressStubs
{
    /** @var array<string, mixed> */
    protected array $options = [];

    /** @var array<string, array<string, string>> get_plugins() result */
    protected array $installed = [];

    /** @var string[] Active plugin basenames */
    protected array $active = [];

    protected function stubWordPress(): void
    {
        $read = fn (string $name, mixed $default = false): mixed => array_key_exists($name, $this->options) ? $this->options[$name] : $default;

        Functions\when('get_option')->alias($read);
        Functions\when('get_site_option')->alias($read);
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('get_plugins')->alias(fn (): array => $this->installed);
        Functions\when('is_plugin_active')->alias(fn (string $basename): bool => in_array($basename, $this->active, true));
        Functions\when('get_bloginfo')->justReturn('6.8.3');
        Functions\when('wp_using_ext_object_cache')->justReturn(false);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('wp_json_encode')->alias(fn (mixed $data): string => (string) json_encode($data));
        Functions\when('__')->returnArg();
    }

    protected function install(string $basename, string $version, bool $active = true): void
    {
        $this->installed[$basename] = ['Name' => $basename, 'Version' => $version];
        if ($active) {
            $this->active[] = $basename;
        }
    }

    protected function connect(array $extra = []): void
    {
        $this->options['cbwarmer_options'] = $extra + [
            'enabled'      => true,
            'api_key'      => 'cb_live_abc123',
            'api_endpoint' => 'https://api.cacheboost.io',
            'site_id'      => 42,
            'boost_id'     => 5,
        ];
    }
}
