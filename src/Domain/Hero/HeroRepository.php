<?php
namespace Cloudari\Onebox\Domain\Hero;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Carteles del widget "Cloudari Hero Carrusel" leídos y escritos fuera del editor.
 *
 * Los carteles no tienen tabla propia: viven en `_elementor_data` de la página
 * que contiene el widget. Se escriben a través de `Document::save()` para que
 * Elementor haga lo mismo que al pulsar "Actualizar" (revisión, purgado del CSS
 * de la página y de su caché), y solo se tocan los campos que el panel edita;
 * el encuadre, el texto alternativo y el resto de ajustes del widget se conservan.
 */
final class HeroRepository
{
    /**
     * Igual que `HeroCarousel::NAME`. No se referencia la clase porque extiende
     * `\Elementor\Widget_Base` y cargarla sin Elementor activo sería un error fatal.
     */
    private const WIDGET_NAME = 'cloudari_hero_carousel';

    public static function isAvailable(): bool
    {
        return class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->documents);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $carousels = [];

        foreach (self::candidatePostIds() as $postId) {
            if (!current_user_can('edit_post', $postId)) {
                continue;
            }

            $elements = self::elementsOf($postId);

            if ($elements === null) {
                continue;
            }

            foreach (self::findWidgets($elements) as $widget) {
                $carousels[] = self::describe($postId, $widget);
            }
        }

        return $carousels;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $postId, string $elementId): ?array
    {
        $elements = self::isAvailable() ? self::elementsOf($postId) : null;

        if ($elements === null) {
            return null;
        }

        foreach (self::findWidgets($elements) as $widget) {
            if (($widget['id'] ?? '') === $elementId) {
                return self::describe($postId, $widget);
            }
        }

        return null;
    }

    /**
     * Sustituye la lista de carteles respetando el orden recibido.
     *
     * @param list<array<string,mixed>> $slides Carteles en el formato de `describeSlide()`.
     * @return true|\WP_Error
     */
    public static function saveSlides(int $postId, string $elementId, array $slides)
    {
        if (!self::isAvailable()) {
            return new \WP_Error('cloudari_no_elementor', __('Elementor no está activo.', 'cloudari-onebox'), ['status' => 409]);
        }

        if (!current_user_can('edit_post', $postId)) {
            return new \WP_Error('cloudari_forbidden', __('No tienes permiso para cambiar esta página.', 'cloudari-onebox'), ['status' => 403]);
        }

        $document = \Elementor\Plugin::$instance->documents->get($postId);
        $elements = $document ? $document->get_elements_data() : null;

        if (!is_array($elements)) {
            return new \WP_Error('cloudari_not_found', __('No se encuentra la página del carrusel.', 'cloudari-onebox'), ['status' => 404]);
        }

        $found = false;
        $elements = self::mapWidgets($elements, static function (array $widget) use ($elementId, $slides, &$found): array {
            if (($widget['id'] ?? '') !== $elementId) {
                return $widget;
            }

            $found = true;
            $current = is_array($widget['settings']['slides'] ?? null) ? $widget['settings']['slides'] : [];
            $widget['settings']['slides'] = self::mergeSlides($current, $slides);

            return $widget;
        });

        if (!$found) {
            return new \WP_Error('cloudari_not_found', __('El carrusel ya no está en esa página. Recarga el panel.', 'cloudari-onebox'), ['status' => 404]);
        }

        if (!$document->save(['elements' => $elements])) {
            return new \WP_Error('cloudari_save_failed', __('No se ha podido guardar. Inténtalo de nuevo.', 'cloudari-onebox'), ['status' => 500]);
        }

        return true;
    }

    /**
     * @param list<array<string,mixed>> $current Items del repeater tal como están guardados.
     * @param list<array<string,mixed>> $incoming
     * @return list<array<string,mixed>>
     */
    private static function mergeSlides(array $current, array $incoming): array
    {
        $byId = [];

        foreach ($current as $item) {
            if (is_array($item) && !empty($item['_id'])) {
                $byId[(string) $item['_id']] = $item;
            }
        }

        $result = [];

        foreach ($incoming as $slide) {
            if (!is_array($slide)) {
                continue;
            }

            $id = sanitize_key((string) ($slide['id'] ?? ''));
            // Un cartel nuevo arranca con los mismos valores por defecto que en el editor.
            $item = $byId[$id] ?? [
                '_id'            => self::newItemId(),
                'alt_text'       => '',
                'contain_mobile' => '',
                'focal_x'        => ['unit' => '%', 'size' => 50, 'sizes' => []],
                'focal_y'        => ['unit' => '%', 'size' => 50, 'sizes' => []],
            ];

            $item['title'] = sanitize_text_field((string) ($slide['title'] ?? ''));

            foreach (['desktop' => 'image_desktop', 'mobile' => 'image_mobile', 'poster' => 'video_poster'] as $key => $field) {
                $item[$field] = self::mediaValue($slide[$key] ?? null);
            }

            $link = is_array($item['link'] ?? null) ? $item['link'] : [];
            $link['url'] = esc_url_raw((string) ($slide['link'] ?? ''));
            $link['is_external'] = $link['is_external'] ?? 'on';
            $link['nofollow'] = $link['nofollow'] ?? '';
            $item['link'] = $link;

            $result[] = $item;
        }

        return $result;
    }

    /**
     * @return array{url:string,id:int|string}
     */
    private static function mediaValue($media): array
    {
        $id = is_array($media) ? (int) ($media['id'] ?? 0) : 0;

        if ($id > 0 && get_post_type($id) === 'attachment') {
            return ['url' => (string) wp_get_attachment_url($id), 'id' => $id, 'source' => 'library'];
        }

        return ['url' => '', 'id' => ''];
    }

    private static function newItemId(): string
    {
        return substr(md5(wp_generate_uuid4()), 0, 7);
    }

    /**
     * @param array<string,mixed> $widget
     * @return array<string,mixed>
     */
    private static function describe(int $postId, array $widget): array
    {
        $slides = is_array($widget['settings']['slides'] ?? null) ? $widget['settings']['slides'] : [];

        return [
            'key'        => $postId . ':' . $widget['id'],
            'post_id'    => $postId,
            'element_id' => (string) $widget['id'],
            'page_title' => get_the_title($postId) ?: __('(sin título)', 'cloudari-onebox'),
            'page_url'   => (string) get_permalink($postId),
            'edit_url'   => add_query_arg(['post' => $postId, 'action' => 'elementor'], admin_url('post.php')),
            'slides'     => array_values(array_map([self::class, 'describeSlide'], array_filter($slides, 'is_array'))),
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function describeSlide(array $item): array
    {
        $link = is_array($item['link'] ?? null) ? $item['link'] : [];

        return [
            'id'      => (string) ($item['_id'] ?? ''),
            'title'   => (string) ($item['title'] ?? ''),
            'desktop' => self::describeMedia($item['image_desktop'] ?? null),
            'mobile'  => self::describeMedia($item['image_mobile'] ?? null),
            'poster'  => self::describeMedia($item['video_poster'] ?? null),
            'link'    => (string) ($link['url'] ?? ''),
        ];
    }

    /**
     * @return array{id:int,url:string,type:string,thumb:string,name:string}|null
     */
    private static function describeMedia($media): ?array
    {
        $url = is_array($media) ? (string) ($media['url'] ?? '') : '';

        if ($url === '') {
            return null;
        }

        $id = (int) ($media['id'] ?? 0);
        $isVideo = $id > 0 ? wp_attachment_is('video', $id) : (bool) preg_match('/\.(mp4|webm|ogv|ogg|m4v|mov)(\?|#|$)/i', $url);
        $thumb = $isVideo ? '' : ($id > 0 ? (string) wp_get_attachment_image_url($id, 'medium_large') : $url);

        return [
            'id'    => $id,
            'url'   => $url,
            'type'  => $isVideo ? 'video' : 'image',
            'thumb' => $thumb ?: ($isVideo ? '' : $url),
            'name'  => $id > 0 ? (string) get_the_title($id) : wp_basename((string) wp_parse_url($url, PHP_URL_PATH)),
        ];
    }

    /**
     * Páginas, plantillas y entradas cuyo JSON de Elementor menciona el widget.
     *
     * @return list<int>
     */
    private static function candidatePostIds(): array
    {
        global $wpdb;

        $like = '%' . $wpdb->esc_like('"widgetType":"' . self::WIDGET_NAME . '"') . '%';

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_elementor_data'
               AND pm.meta_value LIKE %s
               AND p.post_type NOT IN ('revision', 'nav_menu_item')
               AND p.post_status IN ('publish', 'private', 'future', 'draft')
             ORDER BY p.menu_order ASC, p.ID ASC",
            $like
        ));

        return array_map('intval', $ids);
    }

    /**
     * @return array<int,mixed>|null
     */
    private static function elementsOf(int $postId): ?array
    {
        $document = \Elementor\Plugin::$instance->documents->get($postId);

        if (!$document || !$document->is_built_with_elementor()) {
            return null;
        }

        $elements = $document->get_elements_data();

        return is_array($elements) ? $elements : null;
    }

    /**
     * @param array<int,mixed> $elements
     * @return list<array<string,mixed>>
     */
    private static function findWidgets(array $elements): array
    {
        $found = [];

        self::mapWidgets($elements, static function (array $widget) use (&$found): array {
            $found[] = $widget;
            return $widget;
        });

        return $found;
    }

    /**
     * Recorre el árbol de Elementor aplicando `$callback` a cada widget del hero.
     *
     * @param array<int,mixed> $elements
     * @param callable(array<string,mixed>):array<string,mixed> $callback
     * @return array<int,mixed>
     */
    private static function mapWidgets(array $elements, callable $callback): array
    {
        foreach ($elements as $index => $element) {
            if (!is_array($element)) {
                continue;
            }

            if (($element['elType'] ?? '') === 'widget' && ($element['widgetType'] ?? '') === self::WIDGET_NAME && !empty($element['id'])) {
                $elements[$index] = $callback($element);
                continue;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $elements[$index]['elements'] = self::mapWidgets($element['elements'], $callback);
            }
        }

        return $elements;
    }
}
