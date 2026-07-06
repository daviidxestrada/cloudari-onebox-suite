<?php
namespace Cloudari\Onebox\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Versionado de caché.
 *
 * En lugar de borrar transients con DELETE ... LIKE sobre wp_options (que NO
 * funciona cuando hay un object cache persistente tipo Redis/Memcached, porque
 * los transients no viven en la BD), incluimos un numero de version en la clave
 * de cada transient. "Invalidar" toda la cache es simplemente incrementar ese
 * numero: las entradas viejas quedan huerfanas y expiran solas por su TTL, y las
 * nuevas peticiones generan claves nuevas. Es O(1) y correcto en cualquier backend.
 */
final class CacheVersion
{
    private const OPTION = 'cloudari_onebox_cache_ver';

    /**
     * Version actual (>= 1).
     */
    public static function current(): int
    {
        $ver = (int) get_option(self::OPTION, 1);

        return $ver > 0 ? $ver : 1;
    }

    /**
     * Fragmento listo para incrustar en una clave de transient, p. ej. "cv7_".
     */
    public static function token(): string
    {
        return 'cv' . self::current() . '_';
    }

    /**
     * Invalida toda la cache versionada incrementando el contador.
     */
    public static function bump(): void
    {
        update_option(self::OPTION, self::current() + 1);
    }
}
