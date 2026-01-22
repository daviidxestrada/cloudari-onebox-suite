<?php
namespace Cloudari\Onebox\Rest;

use WP_REST_Request;
use WP_Error;
use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Infrastructure\Onebox\Auth;
use Cloudari\Onebox\Domain\ManualEvents\Repository as ManualRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class Routes
{
    /**
     * Cache server-side para /billboard-events
     */
    private const BILLBOARD_CACHE_KEY_PREFIX = 'cloudari_onebox_billboard_events_v1_';
    private const BILLBOARD_CACHE_TTL        = 5 * MINUTE_IN_SECONDS; // 300s

    public static function register(): void
    {
        // Ping de salud
        register_rest_route(
            'cloudari/v1',
            '/ping',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'ping'],
                'permission_callback' => '__return_true',
            ]
        );

        // 📌 Eventos OneBox para la cartelera (proxy server-side)
        register_rest_route(
            'cloudari/v1',
            '/billboard-events',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'getBillboardEvents'],
                'permission_callback' => '__return_true',
            ]
        );

        // 📌 Eventos manuales normalizados para la cartelera
        register_rest_route(
            'cloudari/v1',
            '/manual-events',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'getManualEvents'],
                'permission_callback' => '__return_true',
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

    /**
     * Helper: key de caché por perfil activo
     */
    private static function getBillboardCacheKey(): string
    {
        $profile = ProfileRepository::getActive();
        $slug = !empty($profile->slug) ? sanitize_key((string)$profile->slug) : 'default';
        return self::BILLBOARD_CACHE_KEY_PREFIX . $slug;
    }

    /**
     * Permite invalidar la cache desde otras partes (ej: al guardar credenciales).
     */
    public static function clearBillboardCache(): void
    {
        delete_transient(self::getBillboardCacheKey());
    }

    /**
     * Devuelve todos los eventos de OneBox (desde ahora en adelante) para la cartelera.
     * Proxy server-side: sin CORS y sin exponer el JWT en el front.
     */
    public static function getBillboardEvents(WP_REST_Request $request)
    {
        // ✅ Cache hit → devolvemos sin tocar OneBox
        $cacheKey = self::getBillboardCacheKey();
        $cached   = get_transient($cacheKey);

        if ($cached !== false && is_array($cached)) {
            return rest_ensure_response($cached);
        }

        $profile = ProfileRepository::getActive();
        $jwt     = Auth::getJwt();

        if (empty($jwt)) {
            return new WP_Error(
                'cloudari_missing_jwt',
                __('No se pudo obtener el token de OneBox', 'cloudari-onebox'),
                ['status' => 500]
            );
        }

        $base   = rtrim($profile->apiCatalogUrl, '/');
        $limit  = 100;
        $offset = 0;
        $total  = null;
        $all    = [];

        // Hora actual en UTC como referencia para "a partir de ahora"
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');

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
                return new WP_Error(
                    'cloudari_onebox_http',
                    __('Error al consultar eventos en OneBox', 'cloudari-onebox'),
                    [
                        'status'  => 500,
                        'details' => $resp->get_error_message(),
                    ]
                );
            }

            $body = json_decode(wp_remote_retrieve_body($resp), true);
            $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

            $all = array_merge($all, $data);

            $meta = $body['metadata'] ?? [];
            if ($total === null) {
                $total = isset($meta['total']) ? (int)$meta['total'] : count($data);
            }

            $offset += $limit;
            if ($offset >= $total || empty($data)) {
                break;
            }
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

        // ✅ Cache set
        set_transient($cacheKey, $response, self::BILLBOARD_CACHE_TTL);

        return rest_ensure_response($response);
    }

    /**
     * Devuelve 1 item por evento manual (no por sesión) para la cartelera.
     */
    public static function getManualEvents(WP_REST_Request $request)
    {
        $data = ManualRepository::getForRest();
        return rest_ensure_response($data);
    }
}
