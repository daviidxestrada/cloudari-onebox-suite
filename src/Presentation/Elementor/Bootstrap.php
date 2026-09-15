<?php

namespace Cloudari\Onebox\Presentation\Elementor;

use Cloudari\Onebox\Presentation\Assets\Enqueue;
use Cloudari\Onebox\Presentation\Elementor\Widgets\HeroCarousel;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Punto de entrada de la integración con Elementor.
 *
 * Igual que el resto de módulos del plugin, es un simple conector de hooks. Todos
 * los hooks usados son propios de Elementor, así que en un sitio sin Elementor no
 * se disparan nunca y ninguna clase que extienda `\Elementor\Widget_Base` llega a
 * cargarse. No se comprueba `did_action('elementor/loaded')` porque `boot()` corre
 * en `plugins_loaded` y el orden entre plugins no está garantizado.
 */
final class Bootstrap
{
    public const CATEGORY_SLUG = 'cloudari';

    /**
     * Vive aquí y no en el widget porque `Enqueue` necesita el handle sin
     * provocar el autoload de una clase que extiende `\Elementor\Widget_Base`.
     */
    public const HERO_ASSET_HANDLE = 'cloudari-hero-carousel';

    public static function register(): void
    {
        add_action('elementor/elements/categories_registered', [self::class, 'registerCategory']);
        add_action('elementor/widgets/register', [self::class, 'registerWidgets']);
        add_action('elementor/frontend/after_register_styles', [Enqueue::class, 'registerHeroCarouselStyle']);
        add_action('elementor/frontend/after_register_scripts', [Enqueue::class, 'registerHeroCarouselScript']);
    }

    public static function registerCategory($elementsManager): void
    {
        $elementsManager->add_category(
            self::CATEGORY_SLUG,
            [
                'title' => esc_html__('Cloudari', 'cloudari-onebox'),
                'icon' => 'fa fa-plug',
            ]
        );
    }

    public static function registerWidgets($widgetsManager): void
    {
        $widgetsManager->register(new HeroCarousel());
    }
}
