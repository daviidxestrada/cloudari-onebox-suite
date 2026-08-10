<?php

namespace Cloudari\Onebox\Presentation\Popup;

use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Infrastructure\Onebox\Sessions;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

final class EventPopup
{
    private const EVENT_ID = 56934;
    private const REST_NAMESPACE = 'cloudari/v1';
    private const REST_ROUTE = '/featured-event-popup';
    private const FALLBACK_POSTER = 'https://s3.amazonaws.com/onebox-repository/pro/1/1652/evento/56934/9_1_334924_1780392256475.jpg';
    private const FALLBACK_PURCHASE_URL = 'https://tickets.oneboxtds.com/grancastillodepedraza/events/56934';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRestRoute']);
    }

    public static function registerRestRoute(): void
    {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods' => 'GET',
                'callback' => [self::class, 'getEventPayload'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    private static function enqueue(): void
    {
        wp_enqueue_style(
            'cloudari-featured-event-popup',
            CLOUDARI_ONEBOX_URL . 'assets/css/event-popup.css',
            [],
            self::assetVersion('assets/css/event-popup.css')
        );

        wp_enqueue_script(
            'cloudari-featured-event-popup',
            CLOUDARI_ONEBOX_URL . 'assets/js/event-popup.js',
            [],
            self::assetVersion('assets/js/event-popup.js'),
            true
        );

        wp_localize_script(
            'cloudari-featured-event-popup',
            'cloudariFeaturedPopup',
            [
                'endpoint' => rest_url(self::REST_NAMESPACE . self::REST_ROUTE),
            ]
        );
    }

    public static function shortcode($atts = [], $content = ''): string
    {
        if (!CLOUDARI_ONEBOX_ENABLE_OUTPUT) {
            return '<!-- Cloudari Event Popup desactivado por flag -->';
        }

        if (!is_front_page()) {
            return '<!-- Cloudari Event Popup: disponible solo en la portada -->';
        }

        self::enqueue();
        ob_start();
        ?>
        <dialog class="cloudari-obx56934-popup" data-cloudari-obx56934-popup hidden aria-labelledby="cloudari-obx56934-popup-title" aria-describedby="cloudari-obx56934-popup-description">
            <article class="cloudari-obx56934-popup__card">
                <button class="cloudari-obx56934-popup__close" type="button" aria-label="Cerrar" data-obx56934-role="close">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18" /></svg>
                </button>

                <div class="cloudari-obx56934-popup__layout">
                    <header class="cloudari-obx56934-popup__title-block">
                        <h2 id="cloudari-obx56934-popup-title">
                            <span>Los secretos del castillo</span>
                            <small>— Jorge Blass</small>
                        </h2>
                    </header>

                    <div class="cloudari-obx56934-popup__description">
                        <p class="cloudari-obx56934-popup__lead">Una experiencia mágica en el Castillo de Pedraza</p>
                        <p id="cloudari-obx56934-popup-description">Recorre las estancias de la fortaleza del siglo XIII guiado por ilusionistas. El viaje culmina con un espectáculo exclusivo de Jorge Blass al atardecer.</p>
                    </div>

                    <figure class="cloudari-obx56934-popup__poster">
                        <img data-obx56934-role="poster" width="680" height="370" alt="Cartel de Los secretos del castillo — Jorge Blass">
                    </figure>

                    <section class="cloudari-obx56934-popup__booking" aria-label="Próxima función y entradas">
                        <p class="cloudari-obx56934-popup__remaining" data-obx56934-role="days-label"></p>

                        <div class="cloudari-obx56934-popup__countdown" role="timer" aria-label="Tiempo restante para la próxima función">
                            <div><strong data-obx56934-role="days">--</strong><small>Días</small></div>
                            <i aria-hidden="true"></i>
                            <div><strong data-obx56934-role="hours">--</strong><small>Horas</small></div>
                            <i aria-hidden="true"></i>
                            <div><strong data-obx56934-role="minutes">--</strong><small>Min</small></div>
                            <i aria-hidden="true"></i>
                            <div><strong data-obx56934-role="seconds">--</strong><small>Seg</small></div>
                        </div>

                        <div class="cloudari-obx56934-popup__action">
                            <p>Entradas muy limitadas</p>
                            <a class="cloudari-obx56934-popup__cta" data-obx56934-role="purchase" href="#" hidden>
                                Comprar entradas
                                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 12h14m-7-7 7 7-7 7" /></svg>
                            </a>
                        </div>
                    </section>
                </div>
            </article>
        </dialog>
        <?php
        return ob_get_clean();
    }

    public static function getEventPayload(WP_REST_Request $request)
    {
        $eventId = self::eventId();
        $timezone = wp_timezone();
        $now = new \DateTimeImmutable('now', $timezone);
        $end = $now->modify('+180 days');
        $response = Sessions::getRangeSessions($now->format('Y-m-d'), $end->format('Y-m-d'));
        $sessions = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $future = [];

        foreach ($sessions as $session) {
            if (!is_array($session) || (int) ($session['event']['id'] ?? 0) !== $eventId) {
                continue;
            }

            $startRaw = trim((string) ($session['date']['start'] ?? ''));
            if ($startRaw === '') {
                continue;
            }

            try {
                $start = (new \DateTimeImmutable($startRaw))->setTimezone($timezone);
            } catch (\Throwable $e) {
                continue;
            }

            if ($start <= $now) {
                continue;
            }

            $future[] = ['session' => $session, 'start' => $start];
        }

        usort($future, static fn(array $left, array $right): int => $left['start'] <=> $right['start']);

        if ($future === []) {
            return rest_ensure_response(['active' => false]);
        }

        $remainingDates = [];
        foreach ($future as $item) {
            $remainingDates[$item['start']->format('Y-m-d')] = true;
        }

        $nextSession = $future[0]['session'];
        $purchaseUrl = self::purchaseUrl($nextSession, $eventId);

        return rest_ensure_response(
            [
                'active' => true,
                'event_id' => $eventId,
                'next_start' => $future[0]['start']->format(DATE_ATOM),
                'remaining_days' => count($remainingDates),
                'poster_url' => self::posterUrl($nextSession),
                'purchase_url' => $purchaseUrl,
            ]
        );
    }

    private static function eventId(): int
    {
        return max(1, (int) apply_filters('cloudari_onebox_featured_popup_event_id', self::EVENT_ID));
    }

    private static function posterUrl(array $session): string
    {
        $candidates = [
            $session['images']['landscape'][0]['es-ES'] ?? '',
            $session['images']['main']['es-ES'] ?? '',
            $session['event']['images']['landscape'][0]['es-ES'] ?? '',
            $session['event']['images']['main']['es-ES'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $url = esc_url_raw((string) $candidate);
            if ($url !== '') {
                return $url;
            }
        }

        return self::FALLBACK_POSTER;
    }

    private static function purchaseUrl(array $session, int $eventId): string
    {
        foreach ([$session['url'] ?? '', $session['event']['url'] ?? ''] as $candidate) {
            $url = esc_url_raw((string) $candidate);
            if ($url !== '') {
                return $url;
            }
        }

        $integration = ProfileRepository::getActive()->getDefaultIntegration();
        if ($integration && $integration->purchaseBaseUrl !== '') {
            return esc_url_raw($integration->purchaseBaseUrl . $eventId);
        }

        return (string) apply_filters(
            'cloudari_onebox_featured_popup_purchase_url',
            self::FALLBACK_PURCHASE_URL,
            $eventId
        );
    }

    private static function assetVersion(string $relativePath): string
    {
        $file = CLOUDARI_ONEBOX_DIR . ltrim($relativePath, '/');
        $timestamp = file_exists($file) ? filemtime($file) : false;
        return $timestamp ? (string) $timestamp : CLOUDARI_ONEBOX_VER;
    }
}
