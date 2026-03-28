<?php
namespace Cloudari\Onebox\Infrastructure\Onebox;

use Cloudari\Onebox\Domain\Theatre\OneboxIntegration;
use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class Events
{
    public static function getUpcomingEvents(): array
    {
        $profile = ProfileRepository::getActive();
        $limit = 100;
        $all = [];
        $didFetch = false;

        $nowIso = gmdate('Y-m-d\TH:i:s\Z');

        foreach ($profile->getIntegrations() as $integration) {
            if (!$integration instanceof OneboxIntegration) {
                continue;
            }

            if ($integration->apiCatalogUrl === '' || !$integration->hasCredentials()) {
                continue;
            }

            $jwt = Auth::getJwt($integration);
            if (empty($jwt)) {
                Logger::error('[Cloudari OneBox] getUpcomingEvents: no JWT token for ' . $integration->slug);
                continue;
            }

            $didFetch = true;
            $base = rtrim($integration->apiCatalogUrl, '/');
            $offset = 0;
            $total = null;

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
                    Logger::error(
                        '[Cloudari OneBox] getUpcomingEvents wp_remote_get error: ' .
                        $resp->get_error_message()
                    );
                    break;
                }

                $body = json_decode(wp_remote_retrieve_body($resp), true);
                if (!is_array($body)) {
                    break;
                }

                $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
                foreach ($data as $event) {
                    if (!is_array($event)) {
                        continue;
                    }
                    $all[] = self::applyIntegrationContext($event, $integration);
                }

                $meta = isset($body['metadata']) && is_array($body['metadata'])
                    ? $body['metadata']
                    : [];

                if ($total === null) {
                    $total = isset($meta['total']) ? (int) $meta['total'] : count($data);
                }

                $offset += $limit;
                if ($offset >= (int) $total || empty($data)) {
                    break;
                }
            }
        }

        return [
            'data'     => $all,
            'metadata' => [
                'limit'  => $limit,
                'offset' => 0,
                'total'  => count($all),
            ],
            'cloudari' => [
                'did_fetch' => $didFetch,
            ],
        ];
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

        return $event;
    }
}
