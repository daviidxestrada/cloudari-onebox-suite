<?php
namespace Cloudari\Onebox\Rest;

use WP_REST_Request;
use WP_Error;
use Cloudari\Onebox\Domain\Billboard\VenueBillboard;
use Cloudari\Onebox\Domain\ManualEvents\Repository as ManualRepository;
use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Infrastructure\Onebox\Events;

if (!defined('ABSPATH')) {
    exit;
}

final class Routes
{
    private const BILLBOARD_CACHE_KEY_PREFIX = 'cloudari_onebox_billboard_events_v2_';
    private const BILLBOARD_CACHE_TTL        = 5 * MINUTE_IN_SECONDS;

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }

    public static function publicPermission(): bool
    {
        if (defined('CLOUDARI_ONEBOX_PUBLIC_REST') && !CLOUDARI_ONEBOX_PUBLIC_REST) {
            return current_user_can('manage_options');
        }

        return true;
    }

    public static function register(): void
    {
        register_rest_route(
            'cloudari/v1',
            '/ping',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'ping'],
                'permission_callback' => [self::class, 'adminPermission'],
            ]
        );

        register_rest_route(
            'cloudari/v1',
            '/billboard-events',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'getBillboardEvents'],
                'permission_callback' => [self::class, 'publicPermission'],
            ]
        );

        register_rest_route(
            'cloudari/v1',
            '/manual-events',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'getManualEvents'],
                'permission_callback' => [self::class, 'publicPermission'],
            ]
        );

        register_rest_route(
            'cloudari/v1',
            '/billboard-venues',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'getBillboardVenues'],
                'permission_callback' => [self::class, 'publicPermission'],
            ]
        );
    }

    public static function ping(WP_REST_Request $request)
    {
        return [
            'ok'     => true,
            'time'   => current_time('mysql'),
            'plugin' => 'Cloudari OneBox Suite',
        ];
    }

    private static function getBillboardCacheKey(): string
    {
        $profile = ProfileRepository::getActive();
        $slug = !empty($profile->slug) ? sanitize_key((string)$profile->slug) : 'default';
        return self::BILLBOARD_CACHE_KEY_PREFIX . $slug;
    }

    public static function clearBillboardCache(): void
    {
        delete_transient(self::getBillboardCacheKey());
        VenueBillboard::clearCache();
    }

    public static function getBillboardEvents(WP_REST_Request $request)
    {
        $cacheKey = self::getBillboardCacheKey();
        $cached   = get_transient($cacheKey);

        if ($cached !== false && is_array($cached)) {
            return rest_ensure_response($cached);
        }

        $response = Events::getUpcomingEvents();
        $didFetch = !empty($response['cloudari']['did_fetch']);

        if (!$didFetch) {
            return new WP_Error(
                'cloudari_service_unavailable',
                __('La cartelera no esta disponible en este momento.', 'cloudari-onebox'),
                ['status' => 503]
            );
        }

        $response = [
            'data'     => self::sanitizePublicBillboardEvents(
                isset($response['data']) && is_array($response['data']) ? $response['data'] : []
            ),
            'metadata' => isset($response['metadata']) && is_array($response['metadata']) ? $response['metadata'] : [],
        ];

        set_transient($cacheKey, $response, self::BILLBOARD_CACHE_TTL);

        return rest_ensure_response($response);
    }

    public static function getBillboardVenues(WP_REST_Request $request)
    {
        return rest_ensure_response(
            self::sanitizePublicVenueBillboard(
                VenueBillboard::get()
            )
        );
    }

    public static function getManualEvents(WP_REST_Request $request)
    {
        $data = ManualRepository::getForRest();
        return rest_ensure_response($data);
    }

    private static function sanitizePublicBillboardEvents(array $events): array
    {
        $clean = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $event['cloudari'] = self::sanitizePublicCloudari(
                isset($event['cloudari']) && is_array($event['cloudari'])
                    ? $event['cloudari']
                    : []
            );

            $clean[] = $event;
        }

        return $clean;
    }

    private static function sanitizePublicVenueBillboard(array $payload): array
    {
        $venues = isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : [];

        $cleanVenues = [];

        foreach ($venues as $venue) {
            if (!is_array($venue)) {
                continue;
            }

            $events = isset($venue['events']) && is_array($venue['events'])
                ? $venue['events']
                : [];

            $cleanEvents = [];

            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $nextDates = isset($event['next_dates']) && is_array($event['next_dates'])
                    ? $event['next_dates']
                    : [];

                $cleanEvents[] = [
                    'id'             => $event['id'] ?? '',
                    'event_id'       => $event['event_id'] ?? '',
                    'title'          => $event['title'] ?? '',
                    'image'          => $event['image'] ?? '',
                    'url'            => $event['url'] ?? '',
                    'start'          => $event['start'] ?? '',
                    'end'            => $event['end'] ?? '',
                    'category'       => isset($event['category']) && is_array($event['category'])
                        ? $event['category']
                        : null,
                    'venue'          => [
                        'name' => $event['venue']['name'] ?? '',
                        'slug' => $event['venue']['slug'] ?? '',
                    ],
                    'cloudari'       => self::sanitizePublicCloudari(
                        isset($event['cloudari']) && is_array($event['cloudari'])
                            ? $event['cloudari']
                            : []
                    ),
                    'sessions_count' => isset($event['sessions_count']) ? (int) $event['sessions_count'] : 0,
                    'next_dates'     => array_map(
                        static function ($nextDate): array {
                            return [
                                'start' => is_array($nextDate) ? (string) ($nextDate['start'] ?? '') : '',
                                'end'   => is_array($nextDate) ? (string) ($nextDate['end'] ?? '') : '',
                            ];
                        },
                        $nextDates
                    ),
                ];
            }

            $cleanVenues[] = [
                'id'          => $venue['id'] ?? '',
                'name'        => $venue['name'] ?? '',
                'slug'        => $venue['slug'] ?? '',
                'next_start'  => $venue['next_start'] ?? '',
                'event_count' => isset($venue['event_count']) ? (int) $venue['event_count'] : count($cleanEvents),
                'events'      => $cleanEvents,
            ];
        }

        return [
            'data'     => $cleanVenues,
            'metadata' => isset($payload['metadata']) && is_array($payload['metadata'])
                ? $payload['metadata']
                : [],
        ];
    }

    private static function sanitizePublicCloudari(array $cloudari): array
    {
        $clean = [];

        if (!empty($cloudari['manual'])) {
            $clean['manual'] = true;
        }

        $ctaLabel = trim((string) ($cloudari['cta_label'] ?? ''));
        if ($ctaLabel !== '') {
            $clean['cta_label'] = $ctaLabel;
        }

        $categoryColor = trim((string) ($cloudari['category_color'] ?? ''));
        if ($categoryColor !== '') {
            $clean['category_color'] = $categoryColor;
        }

        return $clean;
    }
}
