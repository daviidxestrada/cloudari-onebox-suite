<?php

namespace Cloudari\Onebox\Presentation\Assets;

use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Infrastructure\Onebox\Sessions;
use Cloudari\Onebox\Domain\Events\EventOverridesRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class Enqueue
{
    /**
     * Pequeño helper para versionar assets con filemtime sin romper
     */
    private static function assetVersion(string $relativePath): string
    {
        $file = CLOUDARI_ONEBOX_DIR . ltrim($relativePath, '/');
        if (file_exists($file)) {
            $ts = filemtime($file);
            return $ts ? (string)$ts : CLOUDARI_ONEBOX_VER;
        }

        return CLOUDARI_ONEBOX_VER;
    }

    /**
     * ==============================
     *  CALENDARIO
     * ==============================
     */
    public static function calendar(): void
    {
        $profile = ProfileRepository::getActive();

        // CSS principal del calendario
        wp_enqueue_style(
            'cloudari-calendar',
            CLOUDARI_ONEBOX_URL . 'assets/css/calendario.css',
            [],
            self::assetVersion('assets/css/calendario.css')
        );

        // Variables CSS (paleta por perfil)
        $inlineCss = sprintf(
            ':root{' .
                '--cloudari-primary:%1$s;' .
                '--cloudari-accent:%2$s;' .
                '--cloudari-bg:%3$s;' .
                '--cloudari-text:%4$s;' .
                '--cloudari-selected-day:%5$s;' .
            '}',
            esc_html($profile->colorPrimary),
            esc_html($profile->colorAccent),
            esc_html($profile->colorBackground),
            esc_html($profile->colorText),
            esc_html($profile->colorSelectedDay)
        );

        wp_add_inline_style('cloudari-calendar', $inlineCss);

        // JS principal del calendario
        wp_enqueue_script(
            'cloudari-calendar',
            CLOUDARI_ONEBOX_URL . 'assets/js/calendario.js',
            ['jquery'],
            self::assetVersion('assets/js/calendario.js'),
            true
        );

        // Sesiones precargadas (el JWT solo se usa en servidor)
        $sesiones = Sessions::getDefaultRangeSessions();

        $ajaxUrl = '/wp-admin/admin-ajax.php?action=cloudari_get_sessions';

        // Overrides desde repositorio (solo usaremos specialRedirects en el calendario)
        $overrideMaps = EventOverridesRepository::getEnvMaps();

        wp_localize_script(
            'cloudari-calendar',
            'oneboxData',
            [
                'sesiones'         => $sesiones,
                'nonce'            => wp_create_nonce('cloudari_calendar_nonce'),
                'ajaxSesiones'     => $ajaxUrl,
                'urlOnebox'        => $profile->apiCatalogUrl,
                'purchaseBase'     => $profile->purchaseBaseUrl,
                'specialRedirects' => $overrideMaps['specialRedirects'] ?? [],
            ]
        );
    }

    /**
     * ==============================
     *  CARTELERA
     * ==============================
     */
    public static function billboard(): void
    {
        $profile = ProfileRepository::getActive();

        /**
         * 1) Estilos inline para colores base (CSS vars)
         */
        wp_register_style('cloudari-billboard-inline', false);
        wp_enqueue_style('cloudari-billboard-inline');

        $inlineCss = sprintf(
            ':root{' .
                '--cloudari-primary:%1$s;' .
                '--cloudari-accent:%2$s;' .
            '}',
            esc_html($profile->colorPrimary),
            esc_html($profile->colorAccent)
        );

        wp_add_inline_style('cloudari-billboard-inline', $inlineCss);

        /**
         * 2) Estilos reales del widget
         */
        wp_enqueue_style(
            'cloudari-billboard-css',
            CLOUDARI_ONEBOX_URL . 'assets/css/billboard-inline.css',
            [],
            self::assetVersion('assets/css/billboard-inline.css')
        );

        /**
         * 3) Script vacío para ENV (oneboxCards) que usará el JS real
         */
        wp_register_script(
            'cloudari-billboard-bootstrap',
            '', // script vacío
            [],
            CLOUDARI_ONEBOX_VER,
            false
        );

        wp_enqueue_script('cloudari-billboard-bootstrap');

        /**
         * 4) ENV del plugin (perfil + overrides) - SIN JWT
         */
        $overrideMaps = EventOverridesRepository::getEnvMaps();

        wp_localize_script(
            'cloudari-billboard-bootstrap',
            'oneboxCards', // 👈 nombre que lee billboard-inline.js
            [
                // ✅ Proxy server-side (sin CORS y sin exponer JWT)
                'billboardEndpoint' => '/wp-json/cloudari/v1/billboard-events',

                // Mantener compat con tu lógica actual
                'purchaseBase'      => $profile->purchaseBaseUrl,
                'specialRedirects'  => $overrideMaps['specialRedirects']  ?? [],
                'categoryOverrides' => $overrideMaps['categoryOverrides'] ?? [],
            ]
        );

        /**
         * 5) Script real del widget de cartelera
         */
        wp_enqueue_script(
            'cloudari-billboard-js',
            CLOUDARI_ONEBOX_URL . 'assets/js/billboard-inline.js',
            ['cloudari-billboard-bootstrap'],
            self::assetVersion('assets/js/billboard-inline.js'),
            true
        );
    }

    /**
     * ==============================
     *  CONTADOR
     * ==============================
     */
    public static function countdown(): void
    {
        $profile      = ProfileRepository::getActive();
        $overrideMaps = EventOverridesRepository::getEnvMaps();

        // CSS
        wp_enqueue_style(
            'cloudari-countdown',
            CLOUDARI_ONEBOX_URL . 'assets/css/countdown.css',
            [],
            self::assetVersion('assets/css/countdown.css')
        );

        // JS
        wp_enqueue_script(
            'cloudari-countdown',
            CLOUDARI_ONEBOX_URL . 'assets/js/countdown.js',
            [],
            self::assetVersion('assets/js/countdown.js'),
            true
        );

        $ajaxUrl = '/wp-admin/admin-ajax.php?action=cloudari_get_sessions';

        wp_localize_script(
            'cloudari-countdown',
            'cloudariCountdown',
            [
                'ajaxSesiones'     => $ajaxUrl,
                'purchaseBase'     => $profile->purchaseBaseUrl,
                'specialRedirects' => $overrideMaps['specialRedirects'] ?? [],
                'extraDaysDefault' => 180,
                'cacheTtlMs'       => 6 * 60 * 60 * 1000,
            ]
        );
    }
}
