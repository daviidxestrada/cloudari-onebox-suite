<?php

namespace Cloudari\Onebox\Presentation\Hero;

use Cloudari\Onebox\Support\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Genera el path de la curva inferior del hero.
 *
 * El hero original llevaba el SVG incrustado como data-URI con un path fijo; aquí
 * se reconstruye con una curva cuadrática para poder exponer la profundidad como
 * control. Con `depth = 97` el trazado coincide con el diseño original, porque el
 * punto medio de una cuadrática con control en `2 * depth` cae exactamente en `depth`.
 */
final class CurveShape
{
    public const VIEWBOX_WIDTH = 1000;
    public const VIEWBOX_HEIGHT = 100;
    public const DEFAULT_DEPTH = 97.0;

    public static function path($depth, bool $flipped): string
    {
        $depth = Helpers::clampFloat($depth, 0, 100, self::DEFAULT_DEPTH);

        if ($flipped) {
            $control = round(self::VIEWBOX_HEIGHT - ($depth * 2), 2);

            return sprintf(
                'M0,%1$d Q%2$d,%3$s %4$d,%1$d L%4$d,0 L0,0 Z',
                self::VIEWBOX_HEIGHT,
                self::VIEWBOX_WIDTH / 2,
                $control,
                self::VIEWBOX_WIDTH
            );
        }

        return sprintf(
            'M0,0 Q%1$d,%2$s %3$d,0 L%3$d,%4$d L0,%4$d Z',
            self::VIEWBOX_WIDTH / 2,
            round($depth * 2, 2),
            self::VIEWBOX_WIDTH,
            self::VIEWBOX_HEIGHT
        );
    }

    public static function viewBox(): string
    {
        return sprintf('0 0 %d %d', self::VIEWBOX_WIDTH, self::VIEWBOX_HEIGHT);
    }
}
