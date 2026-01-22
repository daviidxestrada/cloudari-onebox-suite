<?php
namespace Cloudari\Onebox\Infrastructure\Onebox;

use Cloudari\Onebox\Domain\Onebox\OneboxIntegrationRepository;
use Cloudari\Onebox\Domain\ManualEvents\Repository as ManualRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capa de acceso a sesiones de OneBox (con paginación y filtros de fecha)
 */
final class Sessions
{
    /**
     * Rango por defecto: hoy → fin del mes siguiente (como tenías en el functions.php)
     */
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

            // Usamos el mismo método que el AJAX, que ahora también incluye manuales
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
     * Rango arbitrario YYYY-MM-DD → YYYY-MM-DD
     * Usado por el AJAX: cloudari_get_sessions&inicio=...&fin=...
     * Ahora devuelve sesiones de OneBox + sesiones de eventos manuales en ese rango.
     */
    public static function getRangeSessions(string $inicio, string $fin): array
    {
        // Sanitizar por si llegan cosas raras
        $inicio = substr(trim($inicio), 0, 10);
        $fin    = substr(trim($fin), 0, 10);

        // @todo Cuando existan multiples integraciones, combinar sesiones de cada una.
        $profile = OneboxIntegrationRepository::getActive();
        if (!$profile || empty($profile->apiCatalogUrl)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Cloudari OneBox] getRangeSessions: no profile or apiCatalogUrl');
            }
            return [
                'data'     => [],
                'metadata' => ['error' => 'no_profile'],
            ];
        }

        $base = rtrim($profile->apiCatalogUrl, '/');

        $token = Auth::getJwt();
        if (!$token) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Cloudari OneBox] getRangeSessions: no JWT token');
            }
            return [
                'data'     => [],
                'metadata' => ['error' => 'no_token'],
            ];
        }

        $limit    = 100;
        $offset   = 0;
        $all      = [];
        $metadata = [];

        try {
            // 1) Sesiones de OneBox (paginadas)
            while (true) {
                $query = [
                    'type'  => 'SESSION',
                    // mismo formato que usabas: start=gte:YYYY-mm-ddT00...,lte:YYYY-mm-ddT23...
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

                if (!empty($body['data']) && is_array($body['data'])) {
                    $all = array_merge($all, $body['data']);
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

            // 2) Filtro extra: por si API devuelve algo anterior a hoy
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
                // Si falla el filtro, no rompemos
            }

            // 3) Añadir sesiones de eventos manuales dentro del mismo rango
            try {
                $manualSessions = ManualRepository::getForCalendar($inicio, $fin);
                if (is_array($manualSessions) && !empty($manualSessions)) {
                    $all = array_merge($all, $manualSessions);
                }
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(
                        sprintf(
                            '[Cloudari OneBox] getRangeSessions manual merge error (%s → %s): %s',
                            $inicio,
                            $fin,
                            $e->getMessage()
                        )
                    );
                }
            }

            // Ajustar metadata total al total real combinado
            $metadata['total'] = count($all);
            $metadata['offset'] = 0;
            $metadata['limit']  = $limit;

            return [
                'data'     => $all,
                'metadata' => $metadata,
            ];
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    sprintf(
                        '[Cloudari OneBox] getRangeSessions exception (%s → %s): %s @ %s:%d',
                        $inicio,
                        $fin,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    )
                );
            }

            return [
                'data'     => [],
                'metadata' => ['error' => 'range_exception'],
            ];
        }
    }
}
