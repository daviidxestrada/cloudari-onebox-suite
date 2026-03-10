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
    private const BILLBOARD_CACHE_KEY_PREFIX = 'cloudari_onebox_billboard_events_v1_';
    private const BILLBOARD_CACHE_TTL        = 5 * MINUTE_IN_SECONDS;

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
                'permission_callback' => [self::class, 'publicPermission'],
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
                'cloudari_missing_jwt',
                __('No se pudo obtener el token de OneBox', 'cloudari-onebox'),
                ['status' => 500]
            );
        }

        $response = [
            'data'     => isset($response['data']) && is_array($response['data']) ? $response['data'] : [],
            'metadata' => isset($response['metadata']) && is_array($response['metadata']) ? $response['metadata'] : [],
        ];

        set_transient($cacheKey, $response, self::BILLBOARD_CACHE_TTL);

        return rest_ensure_response($response);
    }

    public static function getBillboardVenues(WP_REST_Request $request)
    {
        return rest_ensure_response(VenueBillboard::get());
    }

    public static function getManualEvents(WP_REST_Request $request)
    {
        $data = ManualRepository::getForRest();
        return rest_ensure_response($data);
    }
}
