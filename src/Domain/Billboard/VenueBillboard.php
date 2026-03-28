<?php
namespace Cloudari\Onebox\Domain\Billboard;

use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Domain\Theatre\TheatreProfile;
use Cloudari\Onebox\Infrastructure\Onebox\Events;
use Cloudari\Onebox\Infrastructure\Onebox\Sessions;

if (!defined('ABSPATH')) {
    exit;
}

final class VenueBillboard
{
    private const CACHE_KEY_PREFIX = 'cloudari_onebox_billboard_venues_v1_';
    private const CACHE_TTL        = 5 * MINUTE_IN_SECONDS;
    private const SESSION_PREVIEW_LIMIT = 3;

    public static function get(): array
    {
        $cacheKey = self::getCacheKey();
        $cached = get_transient($cacheKey);

        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $sessionsResponse = Sessions::getUpcomingSessions();
        $eventsResponse = Events::getUpcomingEvents();

        $sessions = isset($sessionsResponse['data']) && is_array($sessionsResponse['data'])
            ? $sessionsResponse['data']
            : [];

        $events = isset($eventsResponse['data']) && is_array($eventsResponse['data'])
            ? $eventsResponse['data']
            : [];

        $venues = self::aggregate($sessions, self::indexEvents($events));

        $response = [
            'data'     => $venues,
            'metadata' => [
                'venues'       => count($venues),
                'events'       => self::countEvents($venues),
                'generated_at' => current_time('c'),
            ],
        ];

        set_transient($cacheKey, $response, self::CACHE_TTL);

        return $response;
    }

    public static function clearCache(): void
    {
        delete_transient(self::getCacheKey());
    }

    private static function getCacheKey(): string
    {
        $profile = ProfileRepository::getActive();
        $slug = !empty($profile->slug) ? sanitize_key((string) $profile->slug) : 'default';

        return self::CACHE_KEY_PREFIX . $slug;
    }

    private static function countEvents(array $venues): int
    {
        $count = 0;

        foreach ($venues as $venue) {
            $count += isset($venue['events']) && is_array($venue['events'])
                ? count($venue['events'])
                : 0;
        }

        return $count;
    }

    private static function aggregate(array $sessions, array $eventMap): array
    {
        $venues = [];
        $profile = ProfileRepository::getActive();
        $manualOrderMap = self::buildVenueOrderMap($profile->venueDisplayOrder ?? []);

        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }

            $resolved = self::resolveSession($session, $eventMap);
            if ($resolved === null) {
                continue;
            }

            $venueKey = $resolved['venue']['group_key'];

            if (!isset($venues[$venueKey])) {
                $venues[$venueKey] = [
                    'id'             => $resolved['venue']['slug'] !== ''
                        ? $resolved['venue']['slug']
                        : 'venue-' . substr(md5($resolved['venue']['name']), 0, 12),
                    'name'           => $resolved['venue']['name'],
                    'slug'           => $resolved['venue']['slug'],
                    'next_start'     => $resolved['start'],
                    'event_count'    => 0,
                    'source_context' => [
                        'sources'      => [],
                        'integrations' => [],
                        'venue_ids'    => [],
                        'source_venues'=> [],
                    ],
                    '_events'        => [],
                ];
            }

            if ($resolved['source'] !== '') {
                $venues[$venueKey]['source_context']['sources'][$resolved['source']] = $resolved['source'];
            }

            if ($resolved['integration'] !== '') {
                $venues[$venueKey]['source_context']['integrations'][$resolved['integration']] = $resolved['integration'];
            }

            if ($resolved['venue']['id'] !== null && $resolved['venue']['id'] !== '') {
                $venueId = (string) $resolved['venue']['id'];
                $venues[$venueKey]['source_context']['venue_ids'][$venueId] = $venueId;
            }

            if (!empty($resolved['venue']['source_key'])) {
                $sourceKey = (string) $resolved['venue']['source_key'];

                if (!isset($venues[$venueKey]['source_context']['source_venues'][$sourceKey])) {
                    $venues[$venueKey]['source_context']['source_venues'][$sourceKey] = [
                        'source_key'      => $sourceKey,
                        'source'          => $resolved['source'],
                        'integration'     => $resolved['integration'],
                        'raw_id'          => $resolved['venue']['raw_id'],
                        'raw_name'        => $resolved['venue']['raw_name'],
                        'raw_slug'        => $resolved['venue']['raw_slug'],
                        'canonical_name'  => $resolved['venue']['name'],
                        'canonical_slug'  => $resolved['venue']['slug'],
                        'next_start'      => $resolved['start'],
                        'sessions_count'  => 0,
                    ];
                }

                $venues[$venueKey]['source_context']['source_venues'][$sourceKey]['sessions_count']++;

                if (
                    self::compareDateStrings(
                        $resolved['start'],
                        (string) ($venues[$venueKey]['source_context']['source_venues'][$sourceKey]['next_start'] ?? '')
                    ) < 0
                ) {
                    $venues[$venueKey]['source_context']['source_venues'][$sourceKey]['next_start'] = $resolved['start'];
                }
            }

            $eventKey = self::buildAggregateEventKey(
                $resolved['source'],
                $resolved['integration'],
                $resolved['event_id'],
                $resolved['venue']['slug']
            );

            if (!isset($venues[$venueKey]['_events'][$eventKey])) {
                $venues[$venueKey]['_events'][$eventKey] = [
                    'id'                => $eventKey,
                    'event_id'          => self::normalizePublicId($resolved['event_id']),
                    'title'             => $resolved['title'],
                    'image'             => $resolved['image'],
                    'url'               => $resolved['url'],
                    'start'             => $resolved['start'],
                    'end'               => $resolved['end'],
                    'category'          => $resolved['category'],
                    'source'            => $resolved['source'],
                    'integration'       => $resolved['integration'] !== '' ? $resolved['integration'] : null,
                    'venue'             => [
                        'id'   => $resolved['venue']['id'],
                        'name' => $resolved['venue']['name'],
                        'slug' => $resolved['venue']['slug'],
                    ],
                    'cloudari'          => $resolved['cloudari'],
                    '_sessions'         => [],
                ];
            }

            self::mergeSession($venues[$venueKey]['_events'][$eventKey], $resolved['session']);
        }

        $result = [];

        foreach ($venues as $venue) {
            $events = [];

            foreach ($venue['_events'] as $event) {
                $events[] = self::finalizeEvent($event);
            }

            usort($events, [self::class, 'compareEventBuckets']);

            $venue['events'] = $events;
            $venue['event_count'] = count($events);
            $venue['next_start'] = $events[0]['start'] ?? $venue['next_start'];
            $venue['source_context'] = [
                'sources'      => array_values($venue['source_context']['sources']),
                'integrations' => array_values($venue['source_context']['integrations']),
                'venue_ids'    => array_values($venue['source_context']['venue_ids']),
                'source_venues'=> array_values($venue['source_context']['source_venues']),
            ];

            unset($venue['_events']);

            $result[] = $venue;
        }

        usort(
            $result,
            static function (array $left, array $right) use ($manualOrderMap): int {
                $leftOrder = self::resolveVenueManualOrderPosition($left, $manualOrderMap);
                $rightOrder = self::resolveVenueManualOrderPosition($right, $manualOrderMap);

                if ($leftOrder !== $rightOrder) {
                    return $leftOrder <=> $rightOrder;
                }

                $cmp = self::compareDateStrings($left['next_start'] ?? '', $right['next_start'] ?? '');
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            }
        );

        return $result;
    }

    private static function resolveSession(array $session, array $eventMap): ?array
    {
        $eventId = isset($session['event']['id']) ? (string) $session['event']['id'] : '';
        if ($eventId === '') {
            $eventId = isset($session['id']) ? (string) $session['id'] : '';
        }

        if ($eventId === '') {
            return null;
        }

        $cloudari = isset($session['cloudari']) && is_array($session['cloudari'])
            ? $session['cloudari']
            : [];

        $integration = sanitize_key((string) ($cloudari['integration'] ?? ''));
        $eventMeta = $eventMap[self::buildEventLookupKey($integration, $eventId)] ?? null;

        $start = (string) ($session['date']['start'] ?? '');
        if ($start === '') {
            return null;
        }

        $end = (string) ($session['date']['end'] ?? $start);

        $source = !empty($cloudari['manual']) ? 'manual' : 'onebox';
        $venue = self::resolveVenue($session, $eventMeta, $source, $integration);

        return [
            'event_id'          => $eventId,
            'source'            => $source,
            'integration'       => $integration,
            'title'             => self::resolveTitle($session, $eventMeta),
            'image'             => self::resolveImage($session, $eventMeta),
            'url'               => self::resolveUrl($session, $eventMeta),
            'category'          => self::resolveCategory($session, $eventMeta),
            'start'             => $start,
            'end'               => $end,
            'venue'             => $venue,
            'cloudari'          => self::resolveCloudari($cloudari, $eventMeta),
            'session'           => [
                'id'    => (string) ($session['id'] ?? md5($eventId . '|' . $start)),
                'start' => $start,
                'end'   => $end,
            ],
        ];
    }

    private static function resolveCloudari(array $sessionCloudari, ?array $eventMeta): array
    {
        $eventCloudari = isset($eventMeta['cloudari']) && is_array($eventMeta['cloudari'])
            ? $eventMeta['cloudari']
            : [];

        $cloudari = array_merge($eventCloudari, $sessionCloudari);

        if (!isset($cloudari['manual'])) {
            $cloudari['manual'] = false;
        }

        if (!isset($cloudari['cta_label']) || trim((string) $cloudari['cta_label']) === '') {
            $cloudari['cta_label'] = 'Entradas';
        }

        return $cloudari;
    }

    private static function resolveVenue(
        array $session,
        ?array $eventMeta,
        string $source,
        string $integration
    ): array
    {
        $profile = ProfileRepository::getActive();

        $sessionVenue = isset($session['venue']) && is_array($session['venue'])
            ? $session['venue']
            : [];

        $eventVenue = [];
        if (isset($eventMeta['venues'][0]) && is_array($eventMeta['venues'][0])) {
            $eventVenue = $eventMeta['venues'][0];
        }

        $venueData = $sessionVenue;
        if (trim((string) ($venueData['name'] ?? '')) === '' && !empty($eventVenue)) {
            $venueData = $eventVenue;
        }

        $rawVenueId = self::normalizeVenueId($venueData['id'] ?? null);
        $rawVenueName = trim((string) ($venueData['name'] ?? ''));
        if ($rawVenueName === '') {
            $rawVenueName = trim((string) $profile->venueName);
        }
        if ($rawVenueName === '') {
            $rawVenueName = 'Espacio';
        }

        $rawVenueSlug = sanitize_title($rawVenueName);
        $sourceKey = self::buildVenueSourceKey($source, $integration, $rawVenueId, $rawVenueName);
        $mapping = self::resolveVenueSourceMapping($profile, $sourceKey);

        $venueName = trim((string) ($mapping['canonical_name'] ?? ''));
        if ($venueName === '') {
            $venueName = $rawVenueName;
        }

        $slug = sanitize_title((string) ($mapping['canonical_slug'] ?? ''));
        if ($slug === '') {
            $slug = sanitize_title($venueName);
        }
        $groupKey = $slug !== ''
            ? $slug
            : 'venue-' . substr(md5(strtolower($venueName)), 0, 12);

        return [
            'id'         => $rawVenueId,
            'name'       => $venueName,
            'slug'       => $slug,
            'group_key'  => $groupKey,
            'raw_id'     => $rawVenueId,
            'raw_name'   => $rawVenueName,
            'raw_slug'   => $rawVenueSlug,
            'source_key' => $sourceKey,
        ];
    }

    private static function resolveTitle(array $session, ?array $eventMeta): string
    {
        $candidates = [
            self::pickLocalizedText($session['event']['texts']['title'] ?? null),
            self::pickLocalizedText($eventMeta['texts']['title'] ?? null),
            self::pickLocalizedText($eventMeta['media']['texts']['es_ES']['TITLE']['value'] ?? null),
            (string) ($session['event']['name'] ?? ''),
            (string) ($eventMeta['name'] ?? ''),
            (string) ($session['name'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return 'Evento';
    }

    private static function resolveImage(array $session, ?array $eventMeta): string
    {
        $candidates = [
            self::pickImage($eventMeta['images'] ?? null),
            self::pickMediaImage($eventMeta['media']['images'] ?? null),
            self::pickImage($session['event']['images'] ?? null),
            self::pickImage($session['images'] ?? null),
            self::pickMediaImage($session['media']['images'] ?? null),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function resolveUrl(array $session, ?array $eventMeta): string
    {
        $url = trim((string) ($session['url'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        return trim((string) ($eventMeta['url'] ?? ''));
    }

    private static function resolveCategory(array $session, ?array $eventMeta): ?array
    {
        if (isset($session['category']) && is_array($session['category'])) {
            return $session['category'];
        }

        if (isset($eventMeta['category']) && is_array($eventMeta['category'])) {
            return $eventMeta['category'];
        }

        return null;
    }

    private static function mergeSession(array &$event, array $sessionData): void
    {
        $sessionKey = (string) ($sessionData['id'] ?? md5(($sessionData['start'] ?? '') . '|' . ($sessionData['end'] ?? '')));
        $event['_sessions'][$sessionKey] = [
            'id'    => $sessionKey,
            'start' => (string) ($sessionData['start'] ?? ''),
            'end'   => (string) ($sessionData['end'] ?? ''),
        ];

        if (self::compareDateStrings($sessionData['start'] ?? '', $event['start'] ?? '') < 0) {
            $event['start'] = (string) ($sessionData['start'] ?? '');
        }

        if (self::compareDateStrings($sessionData['end'] ?? '', $event['end'] ?? '') > 0) {
            $event['end'] = (string) ($sessionData['end'] ?? '');
        }
    }

    private static function finalizeEvent(array $event): array
    {
        $sessions = array_values($event['_sessions']);
        usort(
            $sessions,
            static function (array $left, array $right): int {
                return self::compareDateStrings($left['start'] ?? '', $right['start'] ?? '');
            }
        );

        $event['sessions_count'] = count($sessions);
        $event['next_dates'] = array_slice($sessions, 0, self::SESSION_PREVIEW_LIMIT);

        unset($event['_sessions']);

        return $event;
    }

    private static function compareEventBuckets(array $left, array $right): int
    {
        $cmp = self::compareDateStrings($left['start'] ?? '', $right['start'] ?? '');
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
    }

    private static function compareDateStrings(string $left, string $right): int
    {
        return self::toTimestamp($left) <=> self::toTimestamp($right);
    }

    private static function toTimestamp(string $value): int
    {
        $ts = strtotime($value);
        if ($ts === false) {
            return PHP_INT_MAX;
        }

        return $ts;
    }

    private static function normalizePublicId(string $id)
    {
        if (is_numeric($id)) {
            return (int) $id;
        }

        return $id;
    }

    private static function normalizeVenueId($id)
    {
        if (is_numeric($id)) {
            return (int) $id;
        }

        if (is_string($id) && trim($id) !== '') {
            return trim($id);
        }

        return null;
    }

    private static function buildVenueSourceKey(
        string $source,
        string $integration,
        $rawVenueId,
        string $rawVenueName
    ): string {
        $parts = [
            sanitize_key($source !== '' ? $source : 'venue'),
            sanitize_key($integration !== '' ? $integration : ($source !== '' ? $source : 'default')),
        ];

        $normalizedId = sanitize_key((string) $rawVenueId);
        if ($normalizedId !== '') {
            $parts[] = 'id-' . $normalizedId;
        } else {
            $normalizedName = sanitize_key(sanitize_title($rawVenueName));
            if ($normalizedName === '') {
                $normalizedName = 'venue-' . substr(md5(strtolower($rawVenueName)), 0, 12);
            }

            $parts[] = 'name-' . $normalizedName;
        }

        return implode('__', $parts);
    }

    private static function resolveVenueSourceMapping(TheatreProfile $profile, string $sourceKey): array
    {
        if ($sourceKey === '' || empty($profile->venueSourceMappings[$sourceKey])) {
            return [];
        }

        $mapping = $profile->venueSourceMappings[$sourceKey];

        return is_array($mapping) ? $mapping : [];
    }

    private static function buildVenueOrderMap(array $venueDisplayOrder): array
    {
        $map = [];

        foreach ($venueDisplayOrder as $index => $key) {
            $normalizedKey = sanitize_key((string) $key);
            if ($normalizedKey === '' || isset($map[$normalizedKey])) {
                continue;
            }

            $map[$normalizedKey] = $index;
        }

        return $map;
    }

    private static function resolveVenueManualOrderPosition(array $venue, array $manualOrderMap): int
    {
        $keys = [
            sanitize_key((string) ($venue['id'] ?? '')),
            sanitize_key((string) ($venue['slug'] ?? '')),
            sanitize_key((string) ($venue['name'] ?? '')),
        ];

        foreach ($keys as $key) {
            if ($key !== '' && isset($manualOrderMap[$key])) {
                return $manualOrderMap[$key];
            }
        }

        return PHP_INT_MAX;
    }

    private static function buildAggregateEventKey(
        string $source,
        string $integration,
        string $eventId,
        string $venueSlug
    ): string {
        $parts = [
            $source !== '' ? $source : 'event',
            $integration !== '' ? $integration : 'local',
            $eventId !== '' ? $eventId : '0',
            $venueSlug !== '' ? $venueSlug : 'venue',
        ];

        return implode(':', $parts);
    }

    private static function buildEventLookupKey(string $integration, string $eventId): string
    {
        return ($integration !== '' ? $integration : 'default') . ':' . $eventId;
    }

    private static function indexEvents(array $events): array
    {
        $map = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventId = isset($event['id']) ? (string) $event['id'] : '';
            if ($eventId === '') {
                continue;
            }

            $integration = sanitize_key((string) ($event['cloudari']['integration'] ?? ''));
            $map[self::buildEventLookupKey($integration, $eventId)] = $event;
        }

        return $map;
    }

    private static function pickLocalizedText($value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return '';
        }

        $preferredKeys = ['es-ES', 'es_ES', 'es', 'ca-ES', 'ca_ES', 'en-US', 'en_US', 'en'];
        foreach ($preferredKeys as $key) {
            if (!empty($value[$key]) && is_string($value[$key])) {
                return $value[$key];
            }
        }

        foreach ($value as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private static function pickImage($images): string
    {
        if (!is_array($images)) {
            return '';
        }

        $priority = ['landscape', 'secondary', 'main', 'portrait', 'banner_header'];

        foreach ($priority as $key) {
            if (empty($images[$key])) {
                continue;
            }

            $picked = self::pickImageValue($images[$key]);
            if ($picked !== '') {
                return $picked;
            }
        }

        return '';
    }

    private static function pickMediaImage($images): string
    {
        if (!is_array($images)) {
            return '';
        }

        $preferredLocales = ['es_ES', 'es-ES', 'es', 'ca_ES', 'ca-ES', 'en_US', 'en-US', 'en'];
        $priority = ['LANDSCAPE', 'SECONDARY', 'MAIN', 'PORTRAIT', 'BANNER_HEADER'];

        foreach ($preferredLocales as $locale) {
            if (empty($images[$locale]) || !is_array($images[$locale])) {
                continue;
            }

            foreach ($priority as $key) {
                if (empty($images[$locale][$key])) {
                    continue;
                }

                $picked = self::pickImageValue($images[$locale][$key], 'value');
                if ($picked !== '') {
                    return $picked;
                }
            }
        }

        return '';
    }

    private static function pickImageValue($value, string $nestedKey = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            if ($nestedKey !== '' && isset($value[$nestedKey]) && is_string($value[$nestedKey])) {
                return $value[$nestedKey];
            }

            $direct = self::pickLocalizedText($value);
            if ($direct !== '') {
                return $direct;
            }

            foreach ($value as $item) {
                $picked = self::pickImageValue($item, $nestedKey);
                if ($picked !== '') {
                    return $picked;
                }
            }
        }

        return '';
    }
}
