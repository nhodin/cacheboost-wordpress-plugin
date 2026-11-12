<?php

declare(strict_types=1);

/** Stands in for the Sucuri plugin's option API (src/option.lib.php), whose settings live in its own data file. */
final class SucuriScanOption
{
    public static array $enabled = [];

    public static function isEnabled(string $option = ''): bool
    {
        return in_array($option, self::$enabled, true);
    }
}
