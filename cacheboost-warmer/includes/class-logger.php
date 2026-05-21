<?php
namespace CacheBoost;

if (!defined('ABSPATH')) exit;

class Logger {
    private const OPTION   = 'cacheboost_logs';
    private const MAX_LOGS = 100;

    public static function log(string $channel, string $message, string $level = 'info'): void {
        $logs   = get_option(self::OPTION, []);
        $logs[] = ['ts' => time(), 'ch' => $channel, 'lvl' => $level, 'msg' => $message];
        if (count($logs) > self::MAX_LOGS) {
            $logs = array_slice($logs, -self::MAX_LOGS);
        }
        update_option(self::OPTION, $logs, false);
    }

    public static function get_logs(): array {
        return array_reverse(get_option(self::OPTION, []));
    }

    public static function clear(): void {
        update_option(self::OPTION, [], false);
    }
}
