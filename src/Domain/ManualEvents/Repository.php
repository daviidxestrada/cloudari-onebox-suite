<?php
namespace Cloudari\Onebox\Domain\ManualEvents;

use DateTime;
use DateInterval;
use Exception;

final class Repository
{
    /**
     * Meta keys (de MetaBox.php)
     */
    private const META_MODE        = '_manual_event_mode'; // 'sessions' | 'range'
    private const META_RANGE_START = '_manual_event_range_start'; // YYYY-MM-DD
    private const META_RANGE_END   = '_manual_event_range_end';   // YYYY-MM-DD
    private const META_RULES       = '_manual_event_schedule_rules'; // array
    private const META_EXCEPTIONS  = '_manual_event_schedule_exceptions'; // array

    // Meta keys legacy / existentes
    private const META_SESIONES    = '_sesiones_evento';
    private const META_URL         = '_url_evento';
    private const META_IMG_ID      = '_imagen_evento_id';

    // ✅ CTA label (texto del botón)
    private const META_CTA_LABEL   = '_manual_event_cta_label';

    /**
     * Term meta key: color de categoría (hex #RRGGBB)
     */
    private const TERM_META_COLOR = '_cloudari_manual_cat_color';

    /**
     * Helper: genera ISO local con offset real (igual que em_iso_from_local)
     */
    private static function isoFromLocal(string $fecha, string $hora = '00:00:00'): string
    {
        $tz = wp_timezone();
        $hora = $hora ?: '00:00:00';

        try {
            $dt  = new DateTime($fecha . ' ' . $hora, $tz);
            $off = $tz->getOffset($dt); // segundos
            $sign = $off >= 0 ? '+' : '-';
            $abs  = abs($off);
            $hh   = str_pad((string) floor($abs / 3600), 2, '0', STR_PAD_LEFT);
            $mm   = str_pad((string) floor(($abs % 3600) / 60), 2, '0', STR_PAD_LEFT);

            return $dt->format('Y-m-d\TH:i:s') . $sign . $hh . ':' . $mm;
        } catch (Exception $e) {
            return $fecha . 'T' . $hora;
        }
    }

    /**
     * Normaliza HH:MM (por si viene vacío o raro)
     */
    private static function normTime(?string $t, string $fallback = ''): string
    {
        $t = trim((string)$t);
        if ($t === '') return $fallback;
        if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t;
        return $fallback;
    }

    /**
     * Normaliza color HEX a #RRGGBB o '' si inválido
     */
    private static function normHexColor(?string $raw): string
    {
        $v = trim((string)$raw);
        if ($v === '') return '';
        if ($v[0] !== '#') $v = '#' . $v;
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? $v : '';
    }

    /**
     * Devuelve el color de una categoría (term) desde term_meta
     */
    private static function getCategoryColor(?\WP_Term $term): string
    {
        if (!$term || is_wp_error($term)) return '';
        $raw = get_term_meta((int)$term->term_id, self::TERM_META_COLOR, true);
        return self::normHexColor($raw);
    }

    /**
     * ✅ Lee y limpia CTA label del post (máx 30 chars aprox para UI)
     */
    private static function getCtaLabel(int $post_id): string
    {
        $v = (string) get_post_meta($post_id, self::META_CTA_LABEL, true);
        $v = trim($v);

        if ($v === '') return '';
        // Evita botones gigantes si alguien pega texto largo
        if (function_exists('mb_substr')) {
            $v = mb_substr($v, 0, 30);
        } else {
            $v = substr($v, 0, 30);
        }
        return trim($v);
    }

    /**
     * Construye mapa de excepciones [YYYY-MM-DD => ['start'=>'HH:MM','end'=>'HH:MM']]
     */
    private static function buildExceptionsMap($raw): array
    {
        if (!is_array($raw)) return [];

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) continue;
            $d = trim((string)($row['date'] ?? ''));
            $s = trim((string)($row['start'] ?? ''));
            $e = trim((string)($row['end'] ?? ''));
            if ($d === '' || $s === '' || $e === '') continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;

            $s = self::normTime($s, '');
            $e = self::normTime($e, '');
            if ($s === '' || $e === '') continue;

            $out[$d] = ['start' => $s, 'end' => $e];
        }
        return $out;
    }

    /**
     * Devuelve el horario (start/end HH:MM) para una fecha concreta
     * usando rules + exceptions.
     */
    private static function pickScheduleForDate(string $ymd, array $rules, array $exceptionsMap): ?array
    {
        if (isset($exceptionsMap[$ymd])) {
            return $exceptionsMap[$ymd];
        }

        try {
            $dt = new DateTime($ymd . ' 00:00:00', wp_timezone());
            $w  = (int)$dt->format('w'); // 0 dom ... 6 sáb
        } catch (Exception $e) {
            return null;
        }

        $weekdayStart = self::normTime($rules['weekday_start'] ?? '', '');
        $weekdayEnd   = self::normTime($rules['weekday_end']   ?? '', '');
        $weekendStart = self::normTime($rules['weekend_start'] ?? '', '');
        $weekendEnd   = self::normTime($rules['weekend_end']   ?? '', '');

        $isWeekend = ($w === 0 || $w === 6);

        if ($isWeekend) {
            if ($weekendStart === '' || $weekendEnd === '') return null;
            return ['start' => $weekendStart, 'end' => $weekendEnd];
        }

        if ($weekdayStart === '' || $weekdayEnd === '') return null;
        return ['start' => $weekdayStart, 'end' => $weekdayEnd];
    }

    /**
     * Genera sesiones diarias para un evento "range"
     * entre range_start y range_end, aplicando rules/exceptions.
     */
    private static function buildRangeSessions(int $post_id, string $titulo, string $url, string $img_url, ?string $inicio, ?string $fin): array
    {
        $mode = get_post_meta($post_id, self::META_MODE, true);
        if ($mode !== 'range') return [];

        $rangeStart = trim((string)get_post_meta($post_id, self::META_RANGE_START, true));
        $rangeEnd   = trim((string)get_post_meta($post_id, self::META_RANGE_END, true));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEnd)) {
            return [];
        }

        $rules = get_post_meta($post_id, self::META_RULES, true);
        if (!is_array($rules)) $rules = [];

        $exceptionsMap = self::buildExceptionsMap(get_post_meta($post_id, self::META_EXCEPTIONS, true));

        $tz = wp_timezone();

        try {
            $dtStart = new DateTime($rangeStart . ' 00:00:00', $tz);
            $dtEnd   = new DateTime($rangeEnd   . ' 00:00:00', $tz);
        } catch (Exception $e) {
            return [];
        }

        if ($dtEnd < $dtStart) return [];

        // ✅ CTA label por evento (se copia a cada sesión)
        $ctaLabel = self::getCtaLabel($post_id);

        // Filtrado por rango pedido (inicio/fin del AJAX)
        $startBound = null;
        $endBound   = null;

        if (!empty($inicio) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$inicio)) {
            try { $startBound = new DateTime($inicio . ' 00:00:00', $tz); } catch (Exception $e) { $startBound = null; }
        }
        if (!empty($fin) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fin)) {
            try { $endBound = new DateTime($fin . ' 23:59:59', $tz); } catch (Exception $e) { $endBound = null; }
        }

        $sessions = [];
        $idx = 0;

        $cur = clone $dtStart;
        while ($cur <= $dtEnd) {
            $ymd = $cur->format('Y-m-d');

            $sched = self::pickScheduleForDate($ymd, $rules, $exceptionsMap);
            if ($sched) {
                $start_iso = self::isoFromLocal($ymd, $sched['start'] . ':00');
                $end_iso   = self::isoFromLocal($ymd, $sched['end']   . ':00');

                try {
                    $sessionDt = new DateTime($ymd . ' ' . $sched['start'] . ':00', $tz);
                    if ($startBound && $sessionDt < $startBound) {
                        $cur->add(new DateInterval('P1D'));
                        continue;
                    }
                    if ($endBound && $sessionDt > $endBound) {
                        $cur->add(new DateInterval('P1D'));
                        continue;
                    }
                } catch (Exception $e) {
                    $cur->add(new DateInterval('P1D'));
                    continue;
                }

                $cloudari = [
                    'manual' => true,
                    'mode'   => 'range',
                ];
                if ($ctaLabel !== '') {
                    $cloudari['cta_label'] = $ctaLabel;
                }

                $fake = [
                    'id'   => 'manual-' . $post_id . '-range-' . $idx,
                    'name' => $titulo,
                    'type' => 'SESSION',
                    'event' => [
                        'id'    => $post_id,
                        'name'  => $titulo,
                        'texts' => ['title' => ['es-ES' => $titulo]],
                    ],
                    'venue' => ['name' => 'La Estación'],
                    'date'  => ['start' => $start_iso, 'end' => $end_iso],
                    'images'=> ['landscape' => [['es-ES' => $img_url]]],
                    'price' => ['min' => ['value' => '']],
                    'url'   => $url,
                    'cloudari' => $cloudari,
                ];

                $sessions[] = $fake;
                $idx++;
            }

            $cur->add(new DateInterval('P1D'));
        }

        return $sessions;
    }

    /**
     * Datos "fake sessions" para el calendario
     */
    public static function getForCalendar(?string $inicio = null, ?string $fin = null): array
    {
        $posts = get_posts([
            'post_type'      => PostType::SLUG,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);

        $eventos = [];

        $tz         = wp_timezone();
        $startBound = null;
        $endBound   = null;

        if (!empty($inicio)) {
            try { $startBound = new DateTime($inicio . ' 00:00:00', $tz); } catch (Exception $e) { $startBound = null; }
        }
        if (!empty($fin)) {
            try { $endBound = new DateTime($fin . ' 23:59:59', $tz); } catch (Exception $e) { $endBound = null; }
        }

        foreach ($posts as $post) {
            $post_id = (int)$post->ID;
            $titulo  = (string)$post->post_title;
            $url     = (string)get_post_meta($post_id, self::META_URL, true);
            $img_id  = (int)get_post_meta($post_id, self::META_IMG_ID, true);
            $img_url = $img_id ? (string)wp_get_attachment_image_url($img_id, 'full') : '';

            $ctaLabel = self::getCtaLabel($post_id);

            $mode = get_post_meta($post_id, self::META_MODE, true);
            $mode = in_array($mode, ['sessions', 'range'], true) ? $mode : 'sessions';

            if ($mode === 'range') {
                $rangeSessions = self::buildRangeSessions($post_id, $titulo, $url, $img_url, $inicio, $fin);
                if (!empty($rangeSessions)) {
                    $eventos = array_merge($eventos, $rangeSessions);
                }
                continue;
            }

            $sesiones = get_post_meta($post_id, self::META_SESIONES, true);
            if (!is_array($sesiones) || empty($sesiones)) {
                $old_fecha = get_post_meta($post_id, '_fecha_evento', true);
                $old_hora  = get_post_meta($post_id, '_hora_evento', true);
                $sesiones  = $old_fecha ? [['fecha' => $old_fecha, 'hora' => $old_hora, 'hora_fin' => '']] : [];
            }
            if (empty($sesiones)) continue;

            $idx = 0;
            foreach ($sesiones as $sesion) {
                if (!is_array($sesion)) continue;

                $fecha    = (string)($sesion['fecha'] ?? '');
                $hora     = (string)($sesion['hora']  ?? '');
                $hora_fin = (string)($sesion['hora_fin'] ?? '');

                if (empty($fecha)) continue;

                try {
                    $horaCompleta = ($hora ?: '00:00') . ':00';
                    $sessionDt    = new DateTime($fecha . ' ' . $horaCompleta, $tz);

                    if ($startBound && $sessionDt < $startBound) continue;
                    if ($endBound && $sessionDt > $endBound) continue;
                } catch (Exception $e) {
                    continue;
                }

                $start_iso = self::isoFromLocal($fecha, ($hora ?: '00:00') . ':00');

                $end_iso = '';
                $hora_fin = self::normTime($hora_fin, '');
                if ($hora_fin !== '') {
                    $end_iso = self::isoFromLocal($fecha, $hora_fin . ':00');
                }

                $cloudari = [
                    'manual' => true,
                    'mode'   => 'sessions',
                ];
                if ($ctaLabel !== '') {
                    $cloudari['cta_label'] = $ctaLabel;
                }

                $fake = [
                    'id'   => 'manual-' . $post_id . '-' . $idx,
                    'name' => $titulo,
                    'type' => 'SESSION',
                    'event' => [
                        'id'    => $post_id,
                        'name'  => $titulo,
                        'texts' => ['title' => ['es-ES' => $titulo]],
                    ],
                    'venue' => ['name' => 'La Estación'],
                    'date'  => $end_iso ? ['start' => $start_iso, 'end' => $end_iso] : ['start' => $start_iso],
                    'images'=> ['landscape' => [['es-ES' => $img_url]]],
                    'price' => ['min' => ['value' => '']],
                    'url'   => $url,
                    'cloudari' => $cloudari,
                ];

                $eventos[] = $fake;
                $idx++;
            }
        }

        return $eventos;
    }

    /**
     * Items para REST cartelera
     */
    public static function getForRest(): array
    {
        $posts = get_posts([
            'post_type'      => PostType::SLUG,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);

        $salida = [];

        foreach ($posts as $post) {
            $post_id = (int)$post->ID;
            $titulo  = (string)$post->post_title;
            $url     = (string)get_post_meta($post_id, self::META_URL, true);
            $img_id  = (int)get_post_meta($post_id, self::META_IMG_ID, true);
            $img_url = $img_id ? (string)wp_get_attachment_image_url($img_id, 'full') : '';

            $ctaLabel = self::getCtaLabel($post_id);

            $mode = get_post_meta($post_id, self::META_MODE, true);
            $mode = in_array($mode, ['sessions', 'range'], true) ? $mode : 'sessions';

            $start_iso = '';
            $end_iso   = '';

            if ($mode === 'range') {
                $rangeStart = trim((string)get_post_meta($post_id, self::META_RANGE_START, true));
                $rangeEnd   = trim((string)get_post_meta($post_id, self::META_RANGE_END, true));

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEnd)) {
                    continue;
                }

                $start_iso = self::isoFromLocal($rangeStart, '00:00:00');
                $end_iso   = self::isoFromLocal($rangeEnd, '23:59:59');
            } else {
                $sesiones  = get_post_meta($post_id, self::META_SESIONES, true);
                if (!is_array($sesiones) || empty($sesiones)) {
                    $old_fecha = get_post_meta($post_id, '_fecha_evento', true);
                    $old_hora  = get_post_meta($post_id, '_hora_evento', true);
                    $sesiones  = $old_fecha ? [['fecha' => $old_fecha, 'hora' => $old_hora, 'hora_fin' => '']] : [];
                }
                if (empty($sesiones)) continue;

                usort($sesiones, function ($a, $b) {
                    $fa = trim(($a['fecha'] ?? '') . ' ' . ($a['hora'] ?? '00:00'));
                    $fb = trim(($b['fecha'] ?? '') . ' ' . ($b['hora'] ?? '00:00'));
                    return strcmp($fa, $fb);
                });

                $primera = $sesiones[0];
                $ultima  = $sesiones[count($sesiones) - 1];

                $start_iso = !empty($primera['fecha'])
                    ? self::isoFromLocal((string)$primera['fecha'], ((string)($primera['hora'] ?: '00:00')) . ':00')
                    : '';

                $ultimaFecha = (string)($ultima['fecha'] ?? '');
                $ultimaHora  = (string)($ultima['hora'] ?? '00:00');
                $ultimaFin   = self::normTime((string)($ultima['hora_fin'] ?? ''), '');

                if ($ultimaFecha !== '') {
                    $end_iso = self::isoFromLocal(
                        $ultimaFecha,
                        ($ultimaFin !== '' ? $ultimaFin : $ultimaHora) . ':00'
                    );
                } else {
                    $end_iso = $start_iso;
                }
            }

            if ($start_iso === '' || $end_iso === '') continue;

            try {
                $now    = new DateTime('now', wp_timezone());
                $end_dt = new DateTime(substr($end_iso, 0, 19), wp_timezone());
                if ($end_dt < $now) continue;
            } catch (Exception $e) {
                // ignore
            }

            $term_obj = null;
            $terms    = wp_get_object_terms($post_id, Taxonomy::TAXONOMY);
            if (is_array($terms) && !empty($terms) && !is_wp_error($terms)) {
                $term_obj = $terms[0];
            } else {
                $term_obj = get_term_by('slug', 'teatro', Taxonomy::TAXONOMY);
            }

            $slug    = ($term_obj && !is_wp_error($term_obj)) ? $term_obj->slug : 'teatro';
            $code_up = strtoupper($slug);

            $cat_color = self::getCategoryColor($term_obj);

            $category = null;
            if ($term_obj && !is_wp_error($term_obj)) {
                $custom = ['code' => $code_up];
                if ($cat_color !== '') $custom['color'] = $cat_color;

                $category = [
                    'id'          => (int) $term_obj->term_id,
                    'slug'        => $term_obj->slug,
                    'name'        => $term_obj->name,
                    'description' => $term_obj->name,
                    'custom'      => $custom,
                    'code'        => $code_up,
                    'parent'      => ['code' => $code_up],
                ];
            }

            $cloudari = [
                'manual' => true,
                'mode'   => $mode,
            ];
            if ($cat_color !== '') $cloudari['category_color'] = $cat_color;
            if ($ctaLabel !== '')  $cloudari['cta_label']      = $ctaLabel;

            $salida[] = [
                'id'       => 'manual-' . $post_id,
                'name'     => $titulo,
                'texts'    => ['title' => ['es-ES' => $titulo]],
                'images'   => ['landscape' => [['es-ES' => $img_url]]],
                'date'     => ['start' => $start_iso, 'end' => $end_iso],
                'url'      => $url,
                'category' => $category,
                'cloudari' => $cloudari,
            ];
        }

        return $salida;
    }
}
