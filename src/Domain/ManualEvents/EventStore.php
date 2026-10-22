<?php
namespace Cloudari\Onebox\Domain\ManualEvents;

use Cloudari\Onebox\Rest\Routes;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lectura y escritura de los datos de un evento manual.
 *
 * Es la única puerta a los metadatos del CPT: la usan el metabox clásico y el
 * Panel del teatro, así que los dos guardan exactamente los mismos campos con
 * las mismas reglas. Trabaja con arrays planos, sin saber de `$_POST` ni de REST.
 */
final class EventStore
{
    public const MODES = ['sessions', 'range', 'permanent'];

    private const META_MODE        = '_manual_event_mode';
    private const META_RANGE_START = '_manual_event_range_start';
    private const META_RANGE_END   = '_manual_event_range_end';
    private const META_RULES       = '_manual_event_schedule_rules';
    private const META_EXCEPTIONS  = '_manual_event_schedule_exceptions';
    private const META_SESIONES    = '_sesiones_evento';
    private const META_URL         = '_url_evento';
    private const META_IMG_ID      = '_imagen_evento_id';
    private const META_VENUE       = '_manual_event_venue';
    private const META_CTA_LABEL   = '_manual_event_cta_label';

    private const DEFAULT_RULES = [
        'weekday_start' => '15:00',
        'weekday_end'   => '21:00',
        'weekend_start' => '12:00',
        'weekend_end'   => '21:00',
    ];

    /**
     * @return array<string,mixed>
     */
    public static function read(int $postId): array
    {
        $mode = (string) get_post_meta($postId, self::META_MODE, true);
        $mode = in_array($mode, self::MODES, true) ? $mode : 'sessions';

        $rules = get_post_meta($postId, self::META_RULES, true);
        $rules = is_array($rules) ? array_merge(self::DEFAULT_RULES, array_map('strval', $rules)) : self::DEFAULT_RULES;

        $exceptions = get_post_meta($postId, self::META_EXCEPTIONS, true);
        $exceptions = is_array($exceptions) ? array_values(array_filter($exceptions, 'is_array')) : [];

        $terms = get_the_terms($postId, Taxonomy::TAXONOMY);
        $categoryId = (is_array($terms) && $terms !== []) ? (int) $terms[0]->term_id : 0;

        $imageId = (int) get_post_meta($postId, self::META_IMG_ID, true);

        return [
            'mode'        => $mode,
            'url'         => (string) get_post_meta($postId, self::META_URL, true),
            'venue'       => (string) get_post_meta($postId, self::META_VENUE, true),
            'cta_label'   => (string) get_post_meta($postId, self::META_CTA_LABEL, true),
            'image_id'    => $imageId,
            'sessions'    => self::readSessions($postId),
            'range_start' => (string) get_post_meta($postId, self::META_RANGE_START, true),
            'range_end'   => (string) get_post_meta($postId, self::META_RANGE_END, true),
            'rules'       => $rules,
            'exceptions'  => array_map(
                static fn(array $row): array => [
                    'date'  => (string) ($row['date'] ?? ''),
                    'start' => (string) ($row['start'] ?? ''),
                    'end'   => (string) ($row['end'] ?? ''),
                ],
                $exceptions
            ),
            'category_id' => $categoryId,
        ];
    }

    /**
     * Guarda los campos presentes en `$data`. Un campo ausente no se toca; uno
     * presente y vacío se borra, igual que hacía el metabox.
     *
     * @param array<string,mixed> $data Valores ya sin barras (`wp_unslash`).
     */
    public static function save(int $postId, array $data): void
    {
        if (array_key_exists('url', $data)) {
            update_post_meta($postId, self::META_URL, esc_url_raw((string) $data['url']));
        }

        if (array_key_exists('venue', $data)) {
            self::updateOrDelete($postId, self::META_VENUE, trim(sanitize_text_field((string) $data['venue'])));
        }

        if (array_key_exists('cta_label', $data)) {
            self::updateOrDelete($postId, self::META_CTA_LABEL, trim(sanitize_text_field((string) $data['cta_label'])));
        }

        if (array_key_exists('image_id', $data)) {
            $imageId = (int) $data['image_id'];

            if ($imageId > 0) {
                update_post_meta($postId, self::META_IMG_ID, $imageId);
            } else {
                delete_post_meta($postId, self::META_IMG_ID);
            }
        }

        if (array_key_exists('mode', $data)) {
            $mode = sanitize_key((string) $data['mode']);
            update_post_meta($postId, self::META_MODE, in_array($mode, self::MODES, true) ? $mode : 'sessions');
        }

        if (array_key_exists('sessions', $data)) {
            self::saveSessions($postId, is_array($data['sessions']) ? $data['sessions'] : []);
        }

        if (array_key_exists('range_start', $data)) {
            self::updateOrDelete($postId, self::META_RANGE_START, sanitize_text_field((string) $data['range_start']));
        }

        if (array_key_exists('range_end', $data)) {
            self::updateOrDelete($postId, self::META_RANGE_END, sanitize_text_field((string) $data['range_end']));
        }

        if (array_key_exists('rules', $data)) {
            $raw = is_array($data['rules']) ? $data['rules'] : [];
            $rules = [];

            foreach (array_keys(self::DEFAULT_RULES) as $key) {
                $rules[$key] = trim(sanitize_text_field((string) ($raw[$key] ?? '')));
            }

            if (array_filter($rules)) {
                update_post_meta($postId, self::META_RULES, $rules);
            } else {
                delete_post_meta($postId, self::META_RULES);
            }
        }

        if (array_key_exists('exceptions', $data)) {
            self::saveExceptions($postId, is_array($data['exceptions']) ? $data['exceptions'] : []);
        }

        if (array_key_exists('category_id', $data)) {
            $termId = (int) $data['category_id'];
            wp_set_object_terms($postId, $termId > 0 ? [$termId] : [], Taxonomy::TAXONOMY, false);
        }

        Routes::clearBillboardCache();
    }

    /**
     * @return list<array{fecha:string,hora:string,hora_fin:string}>
     */
    private static function readSessions(int $postId): array
    {
        $sessions = get_post_meta($postId, self::META_SESIONES, true);

        if (!is_array($sessions) || empty($sessions)) {
            // Compatibilidad con los eventos antiguos de una sola fecha.
            $oldDate = (string) get_post_meta($postId, '_fecha_evento', true);

            if ($oldDate === '') {
                return [];
            }

            $sessions = [['fecha' => $oldDate, 'hora' => (string) get_post_meta($postId, '_hora_evento', true)]];
        }

        $out = [];

        foreach ($sessions as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = [
                'fecha'    => (string) ($row['fecha'] ?? ''),
                'hora'     => (string) ($row['hora'] ?? ''),
                'hora_fin' => (string) ($row['hora_fin'] ?? ''),
            ];
        }

        return $out;
    }

    private static function saveSessions(int $postId, array $rows): void
    {
        $clean = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = sanitize_text_field((string) ($row['fecha'] ?? ''));

            if ($date === '') {
                continue;
            }

            $clean[] = [
                'fecha'    => $date,
                'hora'     => sanitize_text_field((string) ($row['hora'] ?? '')),
                'hora_fin' => sanitize_text_field((string) ($row['hora_fin'] ?? '')),
            ];
        }

        if ($clean === []) {
            delete_post_meta($postId, self::META_SESIONES);
            delete_post_meta($postId, '_fecha_evento');
            delete_post_meta($postId, '_hora_evento');
            return;
        }

        update_post_meta($postId, self::META_SESIONES, $clean);

        // Compatibilidad: primera fecha/hora.
        update_post_meta($postId, '_fecha_evento', $clean[0]['fecha']);
        update_post_meta($postId, '_hora_evento', $clean[0]['hora']);
    }

    private static function saveExceptions(int $postId, array $rows): void
    {
        $clean = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date  = trim(sanitize_text_field((string) ($row['date'] ?? '')));
            $start = trim(sanitize_text_field((string) ($row['start'] ?? '')));
            $end   = trim(sanitize_text_field((string) ($row['end'] ?? '')));

            if ($date === '' || $start === '' || $end === '') {
                continue;
            }

            $clean[] = ['date' => $date, 'start' => $start, 'end' => $end];
        }

        if ($clean === []) {
            delete_post_meta($postId, self::META_EXCEPTIONS);
            return;
        }

        update_post_meta($postId, self::META_EXCEPTIONS, $clean);
    }

    private static function updateOrDelete(int $postId, string $key, string $value): void
    {
        if ($value !== '') {
            update_post_meta($postId, $key, $value);
        } else {
            delete_post_meta($postId, $key);
        }
    }
}
