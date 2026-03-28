<?php
namespace Cloudari\Onebox\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class Logger
{
    public static function enabled(): bool
    {
        return defined('CLOUDARI_ONEBOX_DEBUG_LOG') && CLOUDARI_ONEBOX_DEBUG_LOG;
    }

    public static function error(string $message): void
    {
        if (!self::enabled()) {
            return;
        }

        error_log($message);
    }
}
