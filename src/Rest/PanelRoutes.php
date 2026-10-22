<?php
namespace Cloudari\Onebox\Rest;

use Cloudari\Onebox\Domain\Hero\HeroRepository;
use Cloudari\Onebox\Domain\ManualEvents\EventStore;
use Cloudari\Onebox\Domain\ManualEvents\MetaBox;
use Cloudari\Onebox\Domain\ManualEvents\PostType;
use Cloudari\Onebox\Domain\ManualEvents\Taxonomy;
use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * API del Panel del teatro (`/cloudari/v1/panel/...`).
 *
 * Solo para usuarios con sesión iniciada: la cookie de WordPress más el nonce
 * `wp_rest` que imprime la página del panel. Cada escritura comprueba además el
 * permiso sobre el evento o la página concreta.
 */
final class PanelRoutes
{
    private const NS = 'cloudari/v1';

    private const DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';
    private const TIME_RE = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public static function register(): void
    {
        register_rest_route(self::NS, '/panel/overview', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'overview'],
            'permission_callback' => [self::class, 'canUsePanel'],
        ]);

        register_rest_route(self::NS, '/panel/events', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'createEvent'],
            'permission_callback' => [self::class, 'canUsePanel'],
        ]);

        register_rest_route(self::NS, '/panel/events/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'getEvent'],
                'permission_callback' => [self::class, 'canEditEvent'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [self::class, 'updateEvent'],
                'permission_callback' => [self::class, 'canEditEvent'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'deleteEvent'],
                'permission_callback' => [self::class, 'canDeleteEvent'],
            ],
        ]);

        register_rest_route(self::NS, '/panel/heroes/(?P<post>\d+)/(?P<element>[a-z0-9]+)', [
            'methods'             => 'PUT',
            'callback'            => [self::class, 'saveHero'],
            'permission_callback' => [self::class, 'canUsePanel'],
        ]);
    }

    public static function canUsePanel(): bool
    {
        return current_user_can('edit_posts');
    }

    public static function canEditEvent(WP_REST_Request $request): bool
    {
        $id = (int) $request['id'];

        return get_post_type($id) === PostType::SLUG && current_user_can('edit_post', $id);
    }

    public static function canDeleteEvent(WP_REST_Request $request): bool
    {
        $id = (int) $request['id'];

        return get_post_type($id) === PostType::SLUG && current_user_can('delete_post', $id);
    }

    public static function overview(): array
    {
        $posts = get_posts([
            'post_type'      => PostType::SLUG,
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 500,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        $events = [];

        foreach ($posts as $post) {
            $events[] = self::eventPayload($post->ID);
        }

        $categories = [];
        $terms = get_terms(['taxonomy' => Taxonomy::TAXONOMY, 'hide_empty' => false]);

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories[] = [
                    'id'    => (int) $term->term_id,
                    'name'  => $term->name,
                    'color' => Taxonomy::getTermColor((int) $term->term_id),
                ];
            }
        }

        return [
            'events'          => $events,
            'categories'      => $categories,
            'venues'          => MetaBox::getOneboxVenueOptions(),
            'heroes'          => HeroRepository::all(),
            'heroesAvailable' => HeroRepository::isAvailable(),
        ];
    }

    public static function getEvent(WP_REST_Request $request): array
    {
        return self::eventPayload((int) $request['id']);
    }

    public static function createEvent(WP_REST_Request $request)
    {
        $input = self::eventInput($request);
        $errors = self::validateEvent($input);

        if ($errors !== []) {
            return self::invalid($errors);
        }

        // wp_insert_post() espera datos con barras: sin wp_slash() se perderían las "\" del título.
        $postId = wp_insert_post(wp_slash([
            'post_type'   => PostType::SLUG,
            'post_title'  => $input['title'],
            'post_status' => self::requestedStatus($input),
        ]), true);

        if (is_wp_error($postId)) {
            return $postId;
        }

        EventStore::save((int) $postId, $input);

        return rest_ensure_response(self::eventPayload((int) $postId));
    }

    public static function updateEvent(WP_REST_Request $request)
    {
        $postId = (int) $request['id'];
        $input = self::eventInput($request);
        $errors = self::validateEvent($input);

        if ($errors !== []) {
            return self::invalid($errors);
        }

        $updated = wp_update_post(wp_slash([
            'ID'          => $postId,
            'post_title'  => $input['title'],
            'post_status' => self::requestedStatus($input, $postId),
        ]), true);

        if (is_wp_error($updated)) {
            return $updated;
        }

        EventStore::save($postId, $input);

        return rest_ensure_response(self::eventPayload($postId));
    }

    /**
     * Se manda a la papelera, nunca se borra: se puede recuperar desde WordPress.
     * Si la papelera está desactivada (`EMPTY_TRASH_DAYS` a 0) WordPress borraría
     * para siempre, así que el panel se niega y lo deja en manos del administrador.
     */
    public static function deleteEvent(WP_REST_Request $request)
    {
        $postId = (int) $request['id'];

        if (!EMPTY_TRASH_DAYS) {
            return new WP_Error(
                'cloudari_no_trash',
                __('La papelera de WordPress está desactivada en esta web, así que quitarlo lo borraría para siempre. Desactiva "Mostrar en la web" para ocultarlo, o pide a quien administra la web que lo borre.', 'cloudari-onebox'),
                ['status' => 409]
            );
        }

        if (!wp_trash_post($postId)) {
            return new WP_Error('cloudari_trash_failed', __('No se ha podido quitar el evento.', 'cloudari-onebox'), ['status' => 500]);
        }

        return ['deleted' => true, 'id' => $postId];
    }

    public static function saveHero(WP_REST_Request $request)
    {
        $postId = (int) $request['post'];
        $elementId = (string) $request['element'];
        $slides = $request->get_param('slides');

        if (!is_array($slides)) {
            return new WP_Error('cloudari_invalid', __('Faltan los carteles.', 'cloudari-onebox'), ['status' => 400]);
        }

        // El repositorio comprueba el medio de ordenador ya combinado con lo guardado.
        $result = HeroRepository::saveSlides($postId, $elementId, array_values($slides));

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(HeroRepository::find($postId, $elementId));
    }

    /**
     * @return array<string,mixed>
     */
    private static function eventInput(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();
        $json = is_array($json) ? $json : [];

        $mode = (string) ($json['mode'] ?? 'sessions');

        return [
            'title'       => trim(sanitize_text_field((string) ($json['title'] ?? ''))),
            'visible'     => !empty($json['visible']),
            'url'         => trim((string) ($json['url'] ?? '')),
            'venue'       => (string) ($json['venue'] ?? ''),
            'cta_label'   => (string) ($json['cta_label'] ?? ''),
            'image_id'    => (int) ($json['image_id'] ?? 0),
            'mode'        => in_array($mode, EventStore::MODES, true) ? $mode : 'sessions',
            'sessions'    => is_array($json['sessions'] ?? null) ? array_values($json['sessions']) : [],
            'range_start' => (string) ($json['range_start'] ?? ''),
            'range_end'   => (string) ($json['range_end'] ?? ''),
            'rules'       => is_array($json['rules'] ?? null) ? $json['rules'] : [],
            'exceptions'  => is_array($json['exceptions'] ?? null) ? array_values($json['exceptions']) : [],
            'category_id' => (int) ($json['category_id'] ?? 0),
        ];
    }

    /**
     * Mensajes pensados para leerse junto al campo, en el idioma del teatro.
     *
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    private static function validateEvent(array $input): array
    {
        $errors = [];

        if ($input['title'] === '') {
            $errors['title'] = __('Escribe el nombre del evento.', 'cloudari-onebox');
        }

        if ($input['url'] !== '' && !self::isWebUrl($input['url'])) {
            $errors['url'] = __('El enlace tiene que empezar por https:// (cópialo de la barra del navegador).', 'cloudari-onebox');
        }

        if ($input['mode'] === 'sessions') {
            $hasDate = false;

            foreach ($input['sessions'] as $index => $row) {
                $date = (string) ($row['fecha'] ?? '');

                if ($date === '') {
                    continue;
                }

                $hasDate = true;

                if (!preg_match(self::DATE_RE, $date) || !self::isTimeOrEmpty($row['hora'] ?? '') || !self::isTimeOrEmpty($row['hora_fin'] ?? '')) {
                    $errors['session-' . $index] = __('Revisa la fecha y las horas de esta función.', 'cloudari-onebox');
                }
            }

            if (!$hasDate) {
                $errors['sessions'] = __('Añade al menos una fecha.', 'cloudari-onebox');
            }
        }

        if ($input['mode'] === 'range') {
            $start = $input['range_start'];
            $end = $input['range_end'];

            if (!preg_match(self::DATE_RE, $start)) {
                $errors['range_start'] = __('Elige el primer día.', 'cloudari-onebox');
            }

            if (!preg_match(self::DATE_RE, $end)) {
                $errors['range_end'] = __('Elige el último día.', 'cloudari-onebox');
            } elseif (preg_match(self::DATE_RE, $start) && $end < $start) {
                $errors['range_end'] = __('El último día no puede ser anterior al primero.', 'cloudari-onebox');
            }

            foreach ($input['rules'] as $value) {
                if (!self::isTimeOrEmpty($value)) {
                    $errors['rules'] = __('Revisa los horarios: usa el formato 20:30.', 'cloudari-onebox');
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Solo forma: http(s) y un dominio. `wp_http_validate_url()` no sirve aquí
     * porque resuelve el DNS desde el servidor y rechaza enlaces válidos.
     */
    private static function isWebUrl(string $url): bool
    {
        $parts = wp_parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host']);
    }

    private static function isTimeOrEmpty($value): bool
    {
        $value = (string) $value;

        return $value === '' || (bool) preg_match(self::TIME_RE, $value);
    }

    /**
     * El estado solo cambia si cambia el interruptor "Mostrar en la web": guardar
     * otro dato no debe publicar un evento pendiente ni despublicar uno privado.
     *
     * @param array<string,mixed> $input
     */
    private static function requestedStatus(array $input, int $postId = 0): string
    {
        if ($postId > 0) {
            $current = (string) get_post_status($postId);

            if (self::isVisibleStatus($current) === $input['visible']) {
                return $current;
            }
        }

        if (!$input['visible']) {
            return 'draft';
        }

        return current_user_can('publish_posts') ? 'publish' : 'pending';
    }

    private static function isVisibleStatus(string $status): bool
    {
        return in_array($status, ['publish', 'future', 'pending'], true);
    }

    /**
     * @param array<string,string> $errors
     */
    private static function invalid(array $errors): WP_Error
    {
        return new WP_Error(
            'cloudari_invalid',
            __('Hay datos que revisar antes de guardar.', 'cloudari-onebox'),
            ['status' => 400, 'fields' => $errors]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function eventPayload(int $postId): array
    {
        $data = EventStore::read($postId);
        $imageId = (int) $data['image_id'];

        return array_merge($data, [
            'id'        => $postId,
            // Título en crudo: get_the_title() lo texturiza ("&" pasa a "&#038;") y se guardaría así.
            'title'     => (string) get_post_field('post_title', $postId, 'raw'),
            'status'    => (string) get_post_status($postId),
            'visible'   => self::isVisibleStatus((string) get_post_status($postId)),
            'image_url' => $imageId > 0 ? (string) wp_get_attachment_image_url($imageId, 'medium_large') : '',
            'when'      => self::describeWhen($data),
            'next_date' => self::nextDate($data),
        ]);
    }

    /**
     * Fecha de la próxima función o del final del rango, para ordenar y para avisar de lo pasado.
     *
     * @param array<string,mixed> $data
     */
    private static function nextDate(array $data): string
    {
        $today = wp_date('Y-m-d');

        if ($data['mode'] === 'range') {
            return (string) $data['range_end'];
        }

        if ($data['mode'] === 'permanent') {
            return '';
        }

        $dates = array_filter(array_map(static fn(array $row): string => (string) $row['fecha'], $data['sessions']));
        sort($dates);

        foreach ($dates as $date) {
            if ($date >= $today) {
                return $date;
            }
        }

        return $dates === [] ? '' : (string) end($dates);
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function describeWhen(array $data): string
    {
        if ($data['mode'] === 'permanent') {
            return __('Siempre visible (sin fechas)', 'cloudari-onebox');
        }

        if ($data['mode'] === 'range') {
            if ($data['range_start'] === '' || $data['range_end'] === '') {
                return __('Sin fechas', 'cloudari-onebox');
            }

            /* translators: 1: primer día, 2: último día. */
            return sprintf(__('Del %1$s al %2$s', 'cloudari-onebox'), self::shortDate($data['range_start']), self::shortDate($data['range_end']));
        }

        $count = count(array_filter($data['sessions'], static fn(array $row): bool => $row['fecha'] !== ''));

        if ($count === 0) {
            return __('Sin fechas', 'cloudari-onebox');
        }

        $next = self::nextDate($data);

        if ($count === 1) {
            return self::shortDate($next);
        }

        /* translators: 1: número de funciones, 2: fecha de la próxima. */
        return sprintf(__('%1$d funciones · próxima %2$s', 'cloudari-onebox'), $count, self::shortDate($next));
    }

    private static function shortDate(string $date): string
    {
        $timestamp = strtotime($date . ' 12:00:00');

        return $timestamp ? wp_date('j M Y', $timestamp) : $date;
    }
}
