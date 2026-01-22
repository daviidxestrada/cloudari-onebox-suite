<?php
namespace Cloudari\Onebox\Infrastructure\Onebox;

use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Domain\Theatre\OneboxIntegration;
use Cloudari\Onebox\Domain\ManualEvents\Repository as ManualRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capa de acceso a sesiones de OneBox (con paginacion y filtros de fecha)
 */
final class Sessions
{
    private const SESSIONS_CACHE_KEY_PREFIX = 'cloudari_onebox_sessions_v1_';
    private const SESSIONS_CACHE_TTL        = 5 * MINUTE_IN_SECONDS;

    public static function getDefaultRangeSessions(): array
    {
        try {
            $tz   = wp_timezone();
            $hoy  = new \DateTime('today', $tz);
            $fin  = (clone $hoy)
                ->modify('first day of next month')
                ->modify('last day of this month');

            $inicio = $hoy->format('Y-m-d');
            $finStr = $fin->format('Y-m-d');

            return self::getRangeSessions($inicio, $finStr);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Cloudari OneBox] getDefaultRangeSessions error: ' . $e->getMessage());
            }
            return [
                'data'     => [],
                'metadata' => ['error' => 'default_range_exception'],
            ];
        }
    }

    /**
     * Rango arbitrario YYYY-MM-DD -> YYYY-MM-DD
     * Devuelve sesiones OneBox (multi-integracion) + manuales.
     */
    public static function getRangeSessions(string $inicio, string $fin): array
    {
        $inicio = substr(trim($inicio), 0, 10);
        $fin    = substr(trim($fin), 0, 10);

        $profile = ProfileRepository::getActive();
        $integrations = $profile->getIntegrations();

        $profileSlug = sanitize_key($profile->slug) ?: 'default';
        $cacheKey = self::buildCacheKey($profileSlug, $inicio, $fin);
        $cached = get_transient($cacheKey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $limit    = 100;
        $offset   = 0;
        $all      = [];
        $metadata = [];

        foreach ($integrations as $integration) {
            if (!$integration instanceof OneboxIntegration) {
                continue;
            }

            if ($integration->apiCatalogUrl === '' || !$integration->hasCredentials()) {
                continue;
            }

            $token = Auth::getJwt($integration);
            if (!$token) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Cloudari OneBox] getRangeSessions: no JWT token for ' . $integration->slug);
                }
                continue;
            }

            $base = rtrim($integration->apiCatalogUrl, '/');
            $offset = 0;

            try {
                while (true) {
                    $query = [
                        'type'  => 'SESSION',
                        'start' => sprintf(
                            'gte:%sT00:00:00Z,lte:%sT23:59:59Z',
                            $inicio,
                            $fin
                        ),
                        'limit' => $limit,
                        'offset'=> $offset,
                    ];

                    $url = $base . '/sessions?' . http_build_query($query);

                    $resp = wp_remote_get(
                        $url,
                        [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $token,
                            ],
                            'timeout' => 20,
                        ]
                    );

                    if (is_wp_error($resp)) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log(
                                '[Cloudari OneBox] getRangeSessions wp_remote_get error: ' .
                                $resp->get_error_message()
                            );
                        }
                        break;
                    }

                    $body = json_decode(wp_remote_retrieve_body($resp), true);
                    if (!is_array($body)) {
                        break;
                    }

                    $batch = [];
                    if (!empty($body['data']) && is_array($body['data'])) {
                        foreach ($body['data'] as $session) {
                            if (!is_array($session)) {
                                continue;
                            }
                            $batch[] = self::applyIntegrationContext($session, $integration);
                        }
                    }

                    if (!empty($batch)) {
                        $all = array_merge($all, $batch);
                    }

                    if (isset($body['metadata']) && is_array($body['metadata'])) {
                        $metadata = $body['metadata'];
                    }

                    $total = isset($body['metadata']['total']) ? (int) $body['metadata']['total'] : 0;
                    if (!$total) {
                        break;
                    }

                    $offset += $limit;
                    if ($offset >= $total) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(
                        sprintf(
                            '[Cloudari OneBox] getRangeSessions exception (%s -> %s) [%s]: %s @ %s:%d',
                            $inicio,
                            $fin,
                            $integration->slug,
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine()
                        )
                    );
                }
            }
        }

        try {
            $hoyUTC = new \DateTime('today', new \DateTimeZone('UTC'));
            $all = array_values(
                array_filter(
                    $all,
                    static function ($s) use ($hoyUTC) {
                        if (empty($s['date']['start'])) {
                            return false;
                        }
                        try {
                            $d = new \DateTime($s['date']['start']);
                            return $d >= $hoyUTC;
                        } catch (\Exception $e) {
                            return false;
                        }
                    }
                )
            );
        } catch (\Throwable $e) {
            // no-op
        }

        try {
            $manualSessions = ManualRepository::getForCalendar($inicio, $fin);
            if (is_array($manualSessions) && !empty($manualSessions)) {
                $all = array_merge($all, $manualSessions);
            }
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    sprintf(
                        '[Cloudari OneBox] getRangeSessions manual merge error (%s -> %s): %s',
                        $inicio,
                        $fin,
                        $e->getMessage()
                    )
                );
            }
        }

        $metadata['total']  = count($all);
        $metadata['offset'] = 0;
        $metadata['limit']  = $limit;

        $response = [
            'data'     => $all,
            'metadata' => $metadata,
        ];

        set_transient($cacheKey, $response, self::SESSIONS_CACHE_TTL);

        return $response;
    }

    private static function buildCacheKey(string $profileSlug, string $inicio, string $fin): string
    {
        return self::SESSIONS_CACHE_KEY_PREFIX . $profileSlug . '_' . $inicio . '_' . $fin;
    }

    private static function applyIntegrationContext(array $session, OneboxIntegration $integration): array
    {
        $eventId = $session['event']['id'] ?? null;
        if ($eventId && $integration->purchaseBaseUrl !== '') {
            $session['url'] = $integration->purchaseBaseUrl . $eventId;
        }

        if (!isset($session['cloudari']) || !is_array($session['cloudari'])) {
            $session['cloudari'] = [];
        }

        $session['cloudari']['integration'] = $integration->slug;
        $session['cloudari']['integration_label'] = $integration->label;
        if ($integration->purchaseBaseUrl !== '') {
            $session['cloudari']['purchase_base'] = $integration->purchaseBaseUrl;
        }

        return $session;
    }
}
