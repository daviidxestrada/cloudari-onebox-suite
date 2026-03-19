<?php
namespace Cloudari\Onebox\Infrastructure\Onebox;

use Cloudari\Onebox\Domain\ManualEvents\Repository as ManualRepository;
use Cloudari\Onebox\Domain\Theatre\OneboxIntegration;
use Cloudari\Onebox\Domain\Theatre\ProfileRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class Sessions
{
    private const SESSIONS_CACHE_KEY_PREFIX = 'cloudari_onebox_sessions_v1_';
    private const SESSIONS_CACHE_TTL        = 5 * MINUTE_IN_SECONDS;

    public static function getDefaultRangeSessions(): array
    {
        try {
            $tz = wp_timezone();
            $hoy = new \DateTime('today', $tz);
            $fin = (clone $hoy)
                ->modify('first day of next month')
                ->modify('last day of this month');

            return self::getRangeSessions(
                $hoy->format('Y-m-d'),
                $fin->format('Y-m-d')
            );
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

    public static function getUpcomingSessions(): array
    {
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');

        $fetched = self::fetchOneboxSessions(
            static function (int $limit, int $offset) use ($nowIso): array {
                return [
                    'type'       => 'SESSION',
                    'for_sale'   => 'true',
                    'on_catalog' => 'true',
                    'start'      => 'gte:' . $nowIso,
                    'limit'      => $limit,
                    'offset'     => $offset,
                ];
            }
        );

        $all = isset($fetched['data']) && is_array($fetched['data'])
            ? $fetched['data']
            : [];

        try {
            $manualSessions = ManualRepository::getUpcomingSessions();
            if (is_array($manualSessions) && !empty($manualSessions)) {
                $all = array_merge($all, $manualSessions);
            }
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Cloudari OneBox] getUpcomingSessions manual merge error: ' . $e->getMessage());
            }
        }

        $all = self::sortSessionsByStart($all);

        return [
            'data'     => $all,
            'metadata' => [
                'total'  => count($all),
                'offset' => 0,
                'limit'  => (int) ($fetched['metadata']['limit'] ?? 100),
            ],
        ];
    }

    public static function getRangeSessions(string $inicio, string $fin): array
    {
        $inicio = substr(trim($inicio), 0, 10);
        $fin = substr(trim($fin), 0, 10);

        $profile = ProfileRepository::getActive();
        $profileSlug = sanitize_key($profile->slug) ?: 'default';
        $cacheKey = self::buildCacheKey($profileSlug, $inicio, $fin);
        $cached = get_transient($cacheKey);

        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $fetched = self::fetchOneboxSessions(
            static function (int $limit, int $offset) use ($inicio, $fin): array {
                return [
                    'type'   => 'SESSION',
                    'start'  => sprintf(
                        'gte:%sT00:00:00Z,lte:%sT23:59:59Z',
                        $inicio,
                        $fin
                    ),
                    'limit'  => $limit,
                    'offset' => $offset,
                ];
            }
        );

        $all = isset($fetched['data']) && is_array($fetched['data'])
            ? $fetched['data']
            : [];

        try {
            $hoyUTC = new \DateTime('today', new \DateTimeZone('UTC'));
            $all = array_values(
                array_filter(
                    $all,
                    static function ($session) use ($hoyUTC) {
                        if (empty($session['date']['start'])) {
                            return false;
                        }

                        try {
                            $date = new \DateTime($session['date']['start']);
                            return $date >= $hoyUTC;
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

        $all = self::sortSessionsByStart($all);

        $response = [
            'data'     => $all,
            'metadata' => [
                'total'  => count($all),
                'offset' => 0,
                'limit'  => (int) ($fetched['metadata']['limit'] ?? 100),
            ],
        ];

        set_transient($cacheKey, $response, self::SESSIONS_CACHE_TTL);

        return $response;
    }

    private static function fetchOneboxSessions(callable $buildQuery): array
    {
        $profile = ProfileRepository::getActive();
        $limit = 100;
        $all = [];
        $metadata = [
            'total'  => 0,
            'offset' => 0,
            'limit'  => $limit,
        ];

        foreach ($profile->getIntegrations() as $integration) {
            if (!$integration instanceof OneboxIntegration) {
                continue;
            }

            if ($integration->apiCatalogUrl === '' || !$integration->hasCredentials()) {
                continue;
            }

            $token = Auth::getJwt($integration);
            if (!$token) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Cloudari OneBox] fetchOneboxSessions: no JWT token for ' . $integration->slug);
                }
                continue;
            }

            $base = rtrim($integration->apiCatalogUrl, '/');
            $offset = 0;

            try {
                while (true) {
                    $query = $buildQuery($limit, $offset);
                    if (!is_array($query)) {
                        break;
                    }

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
                                '[Cloudari OneBox] fetchOneboxSessions wp_remote_get error: ' .
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
                        $metadata = array_merge($metadata, $body['metadata']);
                        $metadata['limit'] = $limit;
                        $metadata['offset'] = 0;
                    }

                    $total = isset($body['metadata']['total']) ? (int) $body['metadata']['total'] : 0;
                    if ($total <= 0 || empty($body['data'])) {
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
                            '[Cloudari OneBox] fetchOneboxSessions exception [%s]: %s @ %s:%d',
                            $integration->slug,
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine()
                        )
                    );
                }
            }
        }

        $metadata['total'] = count($all);

        return [
            'data'     => $all,
            'metadata' => $metadata,
        ];
    }

    private static function sortSessionsByStart(array $sessions): array
    {
        usort(
            $sessions,
            static function (array $left, array $right): int {
                $leftTs = isset($left['date']['start']) ? strtotime((string) $left['date']['start']) : false;
                $rightTs = isset($right['date']['start']) ? strtotime((string) $right['date']['start']) : false;

                $leftTs = $leftTs !== false ? $leftTs : PHP_INT_MAX;
                $rightTs = $rightTs !== false ? $rightTs : PHP_INT_MAX;

                if ($leftTs !== $rightTs) {
                    return $leftTs <=> $rightTs;
                }

                $leftTitle = (string) ($left['event']['name'] ?? $left['name'] ?? '');
                $rightTitle = (string) ($right['event']['name'] ?? $right['name'] ?? '');

                return strcasecmp($leftTitle, $rightTitle);
            }
        );

        return $sessions;
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

        return $session;
    }
}
