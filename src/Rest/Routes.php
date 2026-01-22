<?php
namespace Cloudari\Onebox\Rest;

use WP_REST_Request;
use WP_Error;
use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Domain\Theatre\OneboxIntegration;
use Cloudari\Onebox\Infrastructure\Onebox\Auth;
use Cloudari\Onebox\Domain\ManualEvents\Repository as ManualRepository;

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
    }

    public static function getBillboardEvents(WP_REST_Request $request)
    {
        $cacheKey = self::getBillboardCacheKey();
        $cached   = get_transient($cacheKey);

        if ($cached !== false && is_array($cached)) {
            return rest_ensure_response($cached);
        }

        $profile = ProfileRepository::getActive();
        $integrations = $profile->getIntegrations();

        $limit  = 100;
        $offset = 0;
        $all    = [];

        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        $didFetch = false;

        foreach ($integrations as $integration) {
            if (!$integration instanceof OneboxIntegration) {
                continue;
            }

            if ($integration->apiCatalogUrl === '' || !$integration->hasCredentials()) {
                continue;
            }

            $jwt = Auth::getJwt($integration);
            if (empty($jwt)) {
                continue;
            }

            $didFetch = true;
            $base   = rtrim($integration->apiCatalogUrl, '/');
            $offset = 0;
            $total  = null;

            while (true) {
                $query = [
                    'limit'      => $limit,
                    'offset'     => $offset,
                    'for_sale'   => 'true',
                    'on_catalog' => 'true',
                    'expand'     => 'media',
                    'start'      => 'gte:' . $nowIso,
                ];

                $url = $base . '/events?' . http_build_query($query);

                $resp = wp_remote_get(
                    $url,
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $jwt,
                        ],
                        'timeout' => 20,
                    ]
                );

                if (is_wp_error($resp)) {
                    continue;
                }

                $body = json_decode(wp_remote_retrieve_body($resp), true);
                $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

                if (!empty($data)) {
                    foreach ($data as $event) {
                        if (!is_array($event)) {
                            continue;
                        }
                        $all[] = self::applyIntegrationContext($event, $integration);
                    }
                }

                $meta = $body['metadata'] ?? [];
                if ($total === null) {
                    $total = isset($meta['total']) ? (int)$meta['total'] : count($data);
                }

                $offset += $limit;
                if ($offset >= $total || empty($data)) {
                    break;
                }
            }
        }

        if (!$didFetch) {
            return new WP_Error(
                'cloudari_missing_jwt',
                __('No se pudo obtener el token de OneBox', 'cloudari-onebox'),
                ['status' => 500]
            );
        }

        $metadata = [
            'limit'  => $limit,
            'total'  => count($all),
            'offset' => 0,
        ];

        $response = [
            'data'     => $all,
            'metadata' => $metadata,
        ];

        set_transient($cacheKey, $response, self::BILLBOARD_CACHE_TTL);

        return rest_ensure_response($response);
    }

    public static function getManualEvents(WP_REST_Request $request)
    {
        $data = ManualRepository::getForRest();
        return rest_ensure_response($data);
    }

    private static function applyIntegrationContext(array $event, OneboxIntegration $integration): array
    {
        $eventId = $event['id'] ?? null;
        if ($eventId && $integration->purchaseBaseUrl !== '') {
            $event['url'] = $integration->purchaseBaseUrl . $eventId;
        }

        if (!isset($event['cloudari']) || !is_array($event['cloudari'])) {
            $event['cloudari'] = [];
        }

        $event['cloudari']['integration'] = $integration->slug;
        $event['cloudari']['integration_label'] = $integration->label;
        if ($integration->purchaseBaseUrl !== '') {
            $event['cloudari']['purchase_base'] = $integration->purchaseBaseUrl;
        }

        return $event;
    }
}
