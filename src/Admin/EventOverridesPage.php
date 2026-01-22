<?php

namespace Cloudari\Onebox\Admin;

use Cloudari\Onebox\Domain\Events\EventOverridesRepository;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pantalla de administración para overrides de eventos:
 * - URL especial de redirección (specialRedirects)
 * - Categoría canónica (categoryOverrides)
 */
final class EventOverridesPage
{
    /**
     * Slug de la página en el admin.
     */
    private const PAGE_SLUG = 'cloudari-onebox-event-overrides';

    /**
     * Claves canónicas permitidas (deben casar con las del JS).
     */
    private const CATEGORY_KEYS = [
        ''        => '— (auto / ninguna) —',
        'teatro'  => 'Teatro',
        'musica'  => 'Música',
        'musical' => 'Musical',
        'humor'   => 'Humor',
        'talk'    => 'Talk',
    ];

    /**
     * Hook para registrar la página en el menú (lo llamamos desde Plugin::boot()).
     */
    public static function registerMenu(): void
    {
        add_submenu_page(
            'cloudari-onebox',                    // Menú padre
            'Overrides de eventos OneBox',        // Título de la página
            'Eventos OneBox (overrides)',         // Texto del menú
            'manage_options',                     // Capacidad
            self::PAGE_SLUG,                      // Slug
            [self::class, 'renderPage']           // Callback
        );
    }

    /**
     * Render principal: procesa POST y pinta la tabla.
     */
    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos suficientes para acceder a esta página.', 'cloudari-onebox'));
        }

        // Procesar acciones (add/update/delete/bulk) antes de pintar nada
        self::handlePost();

        $overrides      = EventOverridesRepository::all();
        $currentEvents  = self::getCurrentBillboardEvents(); // eventos que se pintan en la cartelera

        if (!is_array($overrides)) {
            $overrides = [];
        }

        ?>
        <div class="wrap cloudari-overrides-wrap">
            <h1><?php esc_html_e('Overrides de eventos OneBox', 'cloudari-onebox'); ?></h1>

            <p>
                Aquí puedes definir overrides para eventos concretos:
            </p>
            <ul style="list-style:disc;margin-left:1.5em;">
                <li><strong>ID de evento</strong>: el ID del evento OneBox (o manual) que se usa en la URL de compra.</li>
                <li><strong>URL de redirección</strong>: si se rellena, se usará en lugar de la URL estándar de compra.</li>
                <li><strong>Categoría</strong>: fuerza una categoría canónica para la cartelera (si se deja vacío, se usará la automática).</li>
            </ul>

            <?php self::renderNotices(); ?>

            <hr>

            <h2><?php esc_html_e('Añadir / editar override rápido', 'cloudari-onebox'); ?></h2>
            <?php self::renderQuickForm(); ?>

            <hr>

            <h2><?php esc_html_e('Overrides existentes', 'cloudari-onebox'); ?></h2>
            <?php self::renderOverridesTable($overrides); ?>

            <hr>

            <h2><?php esc_html_e('Eventos actuales en cartelera (edición en bloque)', 'cloudari-onebox'); ?></h2>
            <p class="description">
                Esta tabla muestra los eventos que se devuelven del endpoint interno
                <code>/cloudari/v1/billboard-events</code> (los mismos que ve la cartelera).
                Aquí puedes ajustar overrides de URL y categoría de varios eventos a la vez.
            </p>

            <?php self::renderEventsTable($currentEvents, $overrides); ?>
        </div>

        <?php self::renderInlineStyles(); ?>

        <?php
    }

    /**
     * Procesa el POST (guardar rápido / eliminar rápido / guardar bulk).
     */
    private static function handlePost(): void
    {
        if (empty($_POST)) {
            return;
        }

        // Guardar / actualizar rápido
        if (isset($_POST['cloudari_overrides_action']) && $_POST['cloudari_overrides_action'] === 'save_quick') {
            check_admin_referer('cloudari_overrides_save_quick', 'cloudari_overrides_quick_nonce');

            $eventIdRaw = isset($_POST['event_id']) ? wp_unslash($_POST['event_id']) : '';
            $eventId    = trim((string) $eventIdRaw);

            $redirectRaw = isset($_POST['redirect_url']) ? wp_unslash($_POST['redirect_url']) : '';
            $redirectUrl = esc_url_raw(trim((string) $redirectRaw));

            $catRaw      = isset($_POST['category_key']) ? wp_unslash($_POST['category_key']) : '';
            $categoryKey = sanitize_text_field(trim((string) $catRaw));

            if (!array_key_exists($categoryKey, self::CATEGORY_KEYS)) {
                $categoryKey = '';
            }

            if ($eventId === '') {
                add_settings_error(
                    'cloudari_onebox_overrides',
                    'cloudari_overrides_missing_id',
                    __('Debes indicar un ID de evento.', 'cloudari-onebox'),
                    'error'
                );
                return;
            }

            EventOverridesRepository::save($eventId, $redirectUrl, $categoryKey);

            add_settings_error(
                'cloudari_onebox_overrides',
                'cloudari_overrides_saved',
                __('Override guardado correctamente.', 'cloudari-onebox'),
                'updated'
            );

            return;
        }

        // Eliminar rápido (fila de la tabla de overrides guardados)
        if (isset($_POST['cloudari_overrides_action']) && $_POST['cloudari_overrides_action'] === 'delete_single') {
            check_admin_referer('cloudari_overrides_delete_single', 'cloudari_overrides_delete_single_nonce');

            $eventIdRaw = isset($_POST['delete_event_id']) ? wp_unslash($_POST['delete_event_id']) : '';
            $eventId    = trim((string) $eventIdRaw);

            if ($eventId !== '') {
                EventOverridesRepository::delete($eventId);

                add_settings_error(
                    'cloudari_onebox_overrides',
                    'cloudari_overrides_deleted',
                    sprintf(
                        /* translators: %s: ID de evento */
                        __('Override eliminado para el evento %s.', 'cloudari-onebox'),
                        esc_html($eventId)
                    ),
                    'updated'
                );
            }

            return;
        }

        // Guardado en bloque desde la tabla de "Eventos actuales en cartelera"
        if (isset($_POST['cloudari_overrides_action']) && $_POST['cloudari_overrides_action'] === 'bulk_save') {
            check_admin_referer('cloudari_overrides_bulk_save', 'cloudari_overrides_bulk_nonce');

            $items = isset($_POST['event_overrides']) && is_array($_POST['event_overrides'])
                ? $_POST['event_overrides']
                : [];

            foreach ($items as $eventId => $row) {
                $id = trim((string) $eventId);

                $redirectRaw = isset($row['redirect_url']) ? wp_unslash($row['redirect_url']) : '';
                $redirectUrl = esc_url_raw(trim((string) $redirectRaw));

                $catRaw      = isset($row['category_key']) ? wp_unslash($row['category_key']) : '';
                $categoryKey = sanitize_text_field(trim((string) $catRaw));

                // Normalizar categoría
                if (!array_key_exists($categoryKey, self::CATEGORY_KEYS)) {
                    $categoryKey = '';
                }

                // ¿Marcar para borrar?
                $deleteFlag = !empty($row['delete']);

                if ($deleteFlag || ($redirectUrl === '' && $categoryKey === '')) {
                    EventOverridesRepository::delete($id);
                } else {
                    EventOverridesRepository::save($id, $redirectUrl, $categoryKey);
                }
            }

            add_settings_error(
                'cloudari_onebox_overrides',
                'cloudari_overrides_bulk_saved',
                __('Overrides actualizados correctamente.', 'cloudari-onebox'),
                'updated'
            );
        }
    }

    /**
     * Pinta los notices generados con add_settings_error.
     */
    private static function renderNotices(): void
    {
        settings_errors('cloudari_onebox_overrides');
    }

    /**
     * Formulario de alta/edición rápida.
     */
    private static function renderQuickForm(): void
    {
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('cloudari_overrides_save_quick', 'cloudari_overrides_quick_nonce'); ?>
            <input type="hidden" name="cloudari_overrides_action" value="save_quick">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="event_id"><?php esc_html_e('ID de evento', 'cloudari-onebox'); ?></label>
                        </th>
                        <td>
                            <input
                                name="event_id"
                                id="event_id"
                                type="text"
                                class="regular-text"
                                required
                                placeholder="Ej: 38865"
                            />
                            <p class="description">
                                <?php esc_html_e('ID del evento tal y como aparece en OneBox / URL de tickets.', 'cloudari-onebox'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="redirect_url"><?php esc_html_e('URL especial de redirección', 'cloudari-onebox'); ?></label>
                        </th>
                        <td>
                            <input
                                name="redirect_url"
                                id="redirect_url"
                                type="url"
                                class="regular-text code"
                                placeholder="https://laestacion.com/redireccion/?goto=..."
                            />
                            <p class="description">
                                <?php esc_html_e('Si se rellena, la cartelera y el calendario usarán esta URL en lugar de la URL estándar de compra.', 'cloudari-onebox'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="category_key"><?php esc_html_e('Categoría canónica forzada', 'cloudari-onebox'); ?></label>
                        </th>
                        <td>
                            <select name="category_key" id="category_key">
                                <?php foreach (self::CATEGORY_KEYS as $key => $label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>">
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Si se selecciona una categoría, la cartelera ignorará la clasificación automática para este evento.', 'cloudari-onebox'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button(__('Guardar override', 'cloudari-onebox')); ?>
        </form>
        <?php
    }

    /**
     * Tabla de overrides ya existentes (vista compacta).
     *
     * @param array $overrides
     */
    private static function renderOverridesTable(array $overrides): void
    {
        if (empty($overrides)) {
            ?>
            <p><?php esc_html_e('No hay overrides definidos todavía.', 'cloudari-onebox'); ?></p>
            <?php
            return;
        }
        ?>
        <table class="widefat fixed striped cloudari-overrides-table">
            <thead>
                <tr>
                    <th style="width:90px;"><?php esc_html_e('ID de evento', 'cloudari-onebox'); ?></th>
                    <th><?php esc_html_e('URL especial', 'cloudari-onebox'); ?></th>
                    <th style="width:200px;"><?php esc_html_e('Categoría canónica', 'cloudari-onebox'); ?></th>
                    <th style="width:120px;"><?php esc_html_e('Acciones', 'cloudari-onebox'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($overrides as $eventId => $ov) : ?>
                    <?php
                        $id        = (string) $eventId;
                        $redirect  = isset($ov['redirect_url']) ? (string) $ov['redirect_url'] : '';
                        $catKey    = isset($ov['category_key']) ? (string) $ov['category_key'] : '';
                        $catLabel  = self::CATEGORY_KEYS[$catKey] ?? $catKey;
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($id); ?></code></td>
                        <td class="cloudari-col-url">
                            <?php if ($redirect) : ?>
                                <a href="<?php echo esc_url($redirect); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html($redirect); ?>
                                </a>
                            <?php else : ?>
                                <em><?php esc_html_e('Sin override (usa URL estándar)', 'cloudari-onebox'); ?></em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($catKey !== '') : ?>
                                <?php echo esc_html($catLabel); ?> <code>(<?php echo esc_html($catKey); ?>)</code>
                            <?php else : ?>
                                <em><?php esc_html_e('Automática', 'cloudari-onebox'); ?></em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('cloudari_overrides_delete_single', 'cloudari_overrides_delete_single_nonce'); ?>
                                <input type="hidden" name="cloudari_overrides_action" value="delete_single">
                                <input type="hidden" name="delete_event_id" value="<?php echo esc_attr($id); ?>">
                                <?php submit_button(
                                    __('Eliminar', 'cloudari-onebox'),
                                    'delete small',
                                    'submit',
                                    false,
                                    ['onclick' => "return confirm('¿Seguro que quieres eliminar este override?');"]
                                ); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Tabla de eventos actuales en cartelera con edición en bloque.
     *
     * @param array $events    Eventos actuales (de /cloudari/v1/billboard-events)
     * @param array $overrides Overrides existentes (para precargar)
     */
    private static function renderEventsTable(array $events, array $overrides): void
    {
        if (empty($events)) {
            ?>
            <p><?php esc_html_e('No se han podido cargar eventos de la cartelera (revisa las credenciales de OneBox).', 'cloudari-onebox'); ?></p>
            <?php
            return;
        }
        ?>
        <form method="post" action="" class="cloudari-events-bulk-form">
            <?php wp_nonce_field('cloudari_overrides_bulk_save', 'cloudari_overrides_bulk_nonce'); ?>
            <input type="hidden" name="cloudari_overrides_action" value="bulk_save">

            <table class="widefat fixed striped cloudari-events-table">
                <thead>
                    <tr>
                        <th style="width:70px;"><?php esc_html_e('ID', 'cloudari-onebox'); ?></th>
                        <th><?php esc_html_e('Título', 'cloudari-onebox'); ?></th>
                        <th style="width:220px;"><?php esc_html_e('Fechas', 'cloudari-onebox'); ?></th>
                        <th style="width:140px;"><?php esc_html_e('Categoría OneBox', 'cloudari-onebox'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Categoría override', 'cloudari-onebox'); ?></th>
                        <th><?php esc_html_e('URL override', 'cloudari-onebox'); ?></th>
                        <th style="width:60px;"><?php esc_html_e('Borrar', 'cloudari-onebox'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $ev) : ?>
                        <?php
                            $id         = (string) ($ev['id'] ?? '');
                            if ($id === '') {
                                continue;
                            }

                            $title      = (string) ($ev['title'] ?? '');
                            $dates      = (string) ($ev['date_range'] ?? '');
                            $catOnebox  = (string) ($ev['category_label'] ?? '');

                            $override   = isset($overrides[$id]) && is_array($overrides[$id])
                                ? $overrides[$id]
                                : ['redirect_url' => '', 'category_key' => ''];

                            $ovUrl      = (string) ($override['redirect_url'] ?? '');
                            $ovCatKey   = (string) ($override['category_key'] ?? '');
                            $rowClass   = $ovUrl !== '' || $ovCatKey !== '' ? 'cloudari-has-override' : '';
                        ?>
                        <tr class="<?php echo esc_attr($rowClass); ?>">
                            <td><code><?php echo esc_html($id); ?></code></td>
                            <td><?php echo esc_html($title); ?></td>
                            <td><?php echo esc_html($dates); ?></td>
                            <td><?php echo esc_html($catOnebox); ?></td>
                            <td>
                                <select
                                    name="event_overrides[<?php echo esc_attr($id); ?>][category_key]"
                                >
                                    <?php foreach (self::CATEGORY_KEYS as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($ovCatKey, $key); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="cloudari-col-url">
                                <input
                                    type="url"
                                    name="event_overrides[<?php echo esc_attr($id); ?>][redirect_url]"
                                    class="regular-text code"
                                    value="<?php echo esc_attr($ovUrl); ?>"
                                    placeholder="https://…"
                                />
                            </td>
                            <td style="text-align:center;">
                                <label class="screen-reader-text" for="delete_<?php echo esc_attr($id); ?>">
                                    <?php esc_html_e('Eliminar override para este evento', 'cloudari-onebox'); ?>
                                </label>
                                <input
                                    id="delete_<?php echo esc_attr($id); ?>"
                                    type="checkbox"
                                    name="event_overrides[<?php echo esc_attr($id); ?>][delete]"
                                    value="1"
                                />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button(__('Guardar overrides seleccionados', 'cloudari-onebox')); ?>
        </form>
        <?php
    }

    /**
     * Obtiene los eventos que ve la cartelera llamando internamente al endpoint REST
     * /cloudari/v1/billboard-events.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function getCurrentBillboardEvents(): array
    {
        if (!function_exists('rest_do_request')) {
            return [];
        }

        try {
            $request  = new WP_REST_Request('GET', '/cloudari/v1/billboard-events');
            $response = rest_do_request($request);

            if ($response->is_error()) {
                return [];
            }

            $payload = $response->get_data();
            $data    = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

            $events = [];

            foreach ($data as $ev) {
                // ID de evento (mismo criterio que en JS)
                $id = $ev['event']['id'] ?? $ev['id'] ?? null;
                if (!$id) {
                    continue;
                }

                // Título
                $title =
                    $ev['texts']['title']['es-ES'] ??
                    $ev['texts']['title']['es'] ??
                    $ev['name'] ??
                    '';

                // Fechas (formato simple)
                $start = !empty($ev['date']['start']) ? strtotime($ev['date']['start']) : null;
                $end   = !empty($ev['date']['end'])   ? strtotime($ev['date']['end'])   : $start;

                $dateLabel = '';
                if ($start) {
                    $startStr = date_i18n('d/m/Y', $start);
                    if ($end && $end !== $start) {
                        $endStr   = date_i18n('d/m/Y', $end);
                        $dateLabel = sprintf(__('Del %1$s al %2$s', 'cloudari-onebox'), $startStr, $endStr);
                    } else {
                        $dateLabel = $startStr;
                    }
                }

                // Categoría OneBox (códigos concatenados)
                $cat  = $ev['category'] ?? [];
                $c1   = $cat['parent']['code'] ?? '';
                $c2   = $cat['code'] ?? '';
                $catLabel = trim($c1 . ($c1 && $c2 ? ' / ' : '') . $c2);

                $events[] = [
                    'id'             => (string) $id,
                    'title'          => (string) $title,
                    'date_range'     => $dateLabel,
                    'category_label' => $catLabel,
                ];
            }

            return $events;
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Cloudari OneBox] getCurrentBillboardEvents error: ' . $e->getMessage());
            }
            return [];
        }
    }

    /**
     * Pequeño CSS inline para arreglar anchos y estilos de la tabla.
     * Lo dejamos scoped a .cloudari-overrides-wrap para no tocar otros sitios del admin.
     */
    private static function renderInlineStyles(): void
    {
        ?>
        <style>
            .cloudari-overrides-wrap .cloudari-overrides-table .cloudari-col-url,
            .cloudari-overrides-wrap .cloudari-events-table .cloudari-col-url {
                max-width: 420px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .cloudari-overrides-wrap .cloudari-events-table input.regular-text.code {
                width: 100%;
                max-width: none;
            }

            .cloudari-overrides-wrap .cloudari-events-table select {
                max-width: 100%;
            }

            .cloudari-overrides-wrap .cloudari-events-table tr.cloudari-has-override {
                background-color: #f0fff4; /* verde muy clarito para filas con override */
            }

            @media (max-width: 1024px) {
                .cloudari-overrides-wrap .cloudari-events-table .cloudari-col-url {
                    max-width: 260px;
                }
            }
        </style>
        <?php
    }
}
