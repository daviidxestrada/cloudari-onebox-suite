<?php
namespace Cloudari\Onebox\Support;

final class Autoloader {
    public static function register(string $prefix, string $baseDir): void {
        spl_autoload_register(function ($class) use ($prefix, $baseDir) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) return;

            $relative = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) { require $file; }
        });
    }
}
