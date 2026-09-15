<?php

namespace Cloudari\Onebox\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class Helpers
{
    public static function clampFloat($value, float $min, float $max, float $fallback): float
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (float) $value));
    }

    /**
     * Número de semana ISO acumulado desde el año 0, en la zona horaria del sitio.
     *
     * Crece de uno en uno cada semana, incluido el salto de año, de modo que usarlo
     * como desplazamiento recorre una lista en orden sin repetir dos semanas seguidas.
     */
    public static function isoWeekCounter(): int
    {
        return ((int) wp_date('o')) * 53 + ((int) wp_date('W'));
    }
}
