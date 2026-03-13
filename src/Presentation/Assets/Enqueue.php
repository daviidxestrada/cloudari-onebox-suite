<?php

namespace Cloudari\Onebox\Presentation\Assets;

use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Infrastructure\Onebox\Sessions;
use Cloudari\Onebox\Domain\Events\EventOverridesRepository;
use Cloudari\Onebox\Presentation\Ajax\CalendarAjax;

if (!defined('ABSPATH')) {
    exit;
}

final class Enqueue
{
    private static function assetVersion(string $relativePath): string
    {
        $file = CLOUDARI_ONEBOX_DIR . ltrim($relativePath, '/');
        if (file_exists($file)) {
            $ts = filemtime($file);
            return $ts ? (string)$ts : CLOUDARI_ONEBOX_VER;
        }

        return CLOUDARI_ONEBOX_VER;
    }

    private static function enqueueCalendarBaseStyle(): void
    {
        $profile = ProfileRepository::getActive();

        wp_enqueue_style(
            'cloudari-calendar',
            CLOUDARI_ONEBOX_URL . 'assets/css/calendario.css',
            [],
            self::assetVersion('assets/css/calendario.css')
        );

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
    }

    private static function getCalendarPayload(): array
    {
        $profile = ProfileRepository::getActive();
        $integration = $profile->getDefaultIntegration();
        $purchaseBase = $integration ? $integration->purchaseBaseUrl : '';
        $apiCatalogUrl = $integration ? $integration->apiCatalogUrl : '';
        $sesiones = Sessions::getDefaultRangeSessions();
        $ajaxUrl = add_query_arg('action', CalendarAjax::ACTION, admin_url('admin-ajax.php'));
        $overrideMaps = EventOverridesRepository::getEnvMaps();

        return [
            'sesiones'         => $sesiones,
            'nonce'            => wp_create_nonce('cloudari_calendar_nonce'),
            'ajaxSesiones'     => $ajaxUrl,
            'urlOnebox'        => $apiCatalogUrl,
            'purchaseBase'     => $purchaseBase,
            'clockIcon'        => add_query_arg(
                'ver',
                self::assetVersion('assets/images/clock-icon.svg'),
                CLOUDARI_ONEBOX_URL . 'assets/images/clock-icon.svg'
            ),
            'specialRedirects' => $overrideMaps['specialRedirects'] ?? [],
        ];
    }

    public static function calendar(): void
    {
        self::enqueueCalendarBaseStyle();

        wp_enqueue_script(
            'cloudari-calendar',
            CLOUDARI_ONEBOX_URL . 'assets/js/calendario.js',
            ['jquery'],
            self::assetVersion('assets/js/calendario.js'),
            true
        );

        wp_localize_script(
            'cloudari-calendar',
            'oneboxData',
            self::getCalendarPayload()
        );
    }

    public static function calendarVenues(): void
    {
        self::enqueueCalendarBaseStyle();

        wp_enqueue_style(
            'cloudari-calendar-venues',
            CLOUDARI_ONEBOX_URL . 'assets/css/calendario-venues.css',
            ['cloudari-calendar'],
            self::assetVersion('assets/css/calendario-venues.css')
        );

        wp_enqueue_script(
            'cloudari-calendar-venues',
            CLOUDARI_ONEBOX_URL . 'assets/js/calendario-venues.js',
            ['jquery'],
            self::assetVersion('assets/js/calendario-venues.js'),
            true
        );

        wp_localize_script(
            'cloudari-calendar-venues',
            'cloudariCalendarVenuesData',
            self::getCalendarPayload()
        );
    }

    public static function billboard(): void
    {
        $profile = ProfileRepository::getActive();
        $integration = $profile->getDefaultIntegration();
        $purchaseBase = $integration ? $integration->purchaseBaseUrl : '';

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

        wp_enqueue_style(
            'cloudari-billboard-css',
            CLOUDARI_ONEBOX_URL . 'assets/css/billboard-inline.css',
            [],
            self::assetVersion('assets/css/billboard-inline.css')
        );

        wp_register_script(
            'cloudari-billboard-bootstrap',
            '',
            [],
            CLOUDARI_ONEBOX_VER,
            false
        );

        wp_enqueue_script('cloudari-billboard-bootstrap');

        $overrideMaps = EventOverridesRepository::getEnvMaps();

        wp_localize_script(
            'cloudari-billboard-bootstrap',
            'oneboxCards',
            [
                'billboardEndpoint' => '/wp-json/cloudari/v1/billboard-events',
                'purchaseBase'      => $purchaseBase,
                'specialRedirects'  => $overrideMaps['specialRedirects']  ?? [],
                'categoryOverrides' => $overrideMaps['categoryOverrides'] ?? [],
            ]
        );

        wp_enqueue_script(
            'cloudari-billboard-js',
            CLOUDARI_ONEBOX_URL . 'assets/js/billboard-inline.js',
            ['cloudari-billboard-bootstrap'],
            self::assetVersion('assets/js/billboard-inline.js'),
            true
        );
    }

    public static function billboardVenues(): void
    {
        $profile = ProfileRepository::getActive();
        $overrideMaps = EventOverridesRepository::getEnvMaps();

        wp_register_style('cloudari-billboard-venues-inline', false);
        wp_enqueue_style('cloudari-billboard-venues-inline');

        $inlineCss = sprintf(
            ':root{' .
                '--cloudari-primary:%1$s;' .
                '--cloudari-accent:%2$s;' .
                '--cloudari-bg:%3$s;' .
                '--cloudari-text:%4$s;' .
            '}',
            esc_html($profile->colorPrimary),
            esc_html($profile->colorAccent),
            esc_html($profile->colorBackground),
            esc_html($profile->colorText)
        );

        wp_add_inline_style('cloudari-billboard-venues-inline', $inlineCss);

        wp_enqueue_style(
            'cloudari-billboard-css',
            CLOUDARI_ONEBOX_URL . 'assets/css/billboard-inline.css',
            [],
            self::assetVersion('assets/css/billboard-inline.css')
        );

        wp_enqueue_style(
            'cloudari-billboard-venues-css',
            CLOUDARI_ONEBOX_URL . 'assets/css/billboard-venues.css',
            ['cloudari-billboard-css'],
            self::assetVersion('assets/css/billboard-venues.css')
        );

        wp_register_script(
            'cloudari-billboard-venues-bootstrap',
            '',
            [],
            CLOUDARI_ONEBOX_VER,
            false
        );

        wp_enqueue_script('cloudari-billboard-venues-bootstrap');

        wp_localize_script(
            'cloudari-billboard-venues-bootstrap',
            'cloudariBillboardVenues',
            [
                'endpoint'          => '/wp-json/cloudari/v1/billboard-venues',
                'specialRedirects'  => $overrideMaps['specialRedirects'] ?? [],
                'categoryOverrides' => $overrideMaps['categoryOverrides'] ?? [],
            ]
        );

        wp_enqueue_script(
            'cloudari-billboard-venues-js',
            CLOUDARI_ONEBOX_URL . 'assets/js/billboard-venues.js',
            ['cloudari-billboard-venues-bootstrap'],
            self::assetVersion('assets/js/billboard-venues.js'),
            true
        );
    }

    public static function countdown(): void
    {
        $profile = ProfileRepository::getActive();
        $integration = $profile->getDefaultIntegration();
        $purchaseBase = $integration ? $integration->purchaseBaseUrl : '';
        $overrideMaps = EventOverridesRepository::getEnvMaps();

        wp_enqueue_style(
            'cloudari-countdown',
            CLOUDARI_ONEBOX_URL . 'assets/css/countdown.css',
            [],
            self::assetVersion('assets/css/countdown.css')
        );

        wp_enqueue_script(
            'cloudari-countdown',
            CLOUDARI_ONEBOX_URL . 'assets/js/countdown.js',
            [],
            self::assetVersion('assets/js/countdown.js'),
            true
        );

        $ajaxUrl = add_query_arg('action', CalendarAjax::ACTION, admin_url('admin-ajax.php'));

        wp_localize_script(
            'cloudari-countdown',
            'cloudariCountdown',
            [
                'ajaxSesiones'     => $ajaxUrl,
                'nonce'            => wp_create_nonce('cloudari_calendar_nonce'),
                'purchaseBase'     => $purchaseBase,
                'specialRedirects' => $overrideMaps['specialRedirects'] ?? [],
                'extraDaysDefault' => 180,
                'cacheTtlMs'       => 6 * 60 * 60 * 1000,
            ]
        );
    }
}
