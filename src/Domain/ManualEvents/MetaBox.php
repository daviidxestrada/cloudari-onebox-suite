<?php
namespace Cloudari\Onebox\Domain\ManualEvents;

final class MetaBox
{
    public const NONCE_FIELD  = 'evento_manual_nonce';
    public const NONCE_ACTION = 'guardar_evento_manual_nonce';

    // Meta keys (nuevo)
    private const META_MODE             = '_manual_event_mode'; // 'sessions' | 'range'
    private const META_RANGE_START      = '_manual_event_range_start'; // YYYY-MM-DD
    private const META_RANGE_END        = '_manual_event_range_end';   // YYYY-MM-DD
    private const META_RULES            = '_manual_event_schedule_rules'; // array
    private const META_EXCEPTIONS       = '_manual_event_schedule_exceptions'; // array
    private const META_SESIONES         = '_sesiones_evento'; // existente (ahora soporta hora_fin)
    private const META_URL              = '_url_evento';
    private const META_IMG_ID           = '_imagen_evento_id';

    // Nuevo: texto del botón (CTA) para manuales
    private const META_CTA_LABEL        = '_manual_event_cta_label';

    public static function register(): void
    {
        add_meta_box(
            'datos_evento_manual',
            'Detalles del evento',
            [static::class, 'render'],
            PostType::SLUG,
            'normal',
            'default'
        );
    }

    public static function render(\WP_Post $post): void
    {
        // Nonce
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $post_id = $post->ID;

        // URL del evento
        $url_evento = get_post_meta($post_id, self::META_URL, true);

        // CTA label
        $cta_label = (string) get_post_meta($post_id, self::META_CTA_LABEL, true);

        // Imagen
        $imagen_id  = (int) get_post_meta($post_id, self::META_IMG_ID, true);
        $imagen_url = $imagen_id ? wp_get_attachment_image_url($imagen_id, 'medium') : '';

        // Modo (nuevo)
        $mode = get_post_meta($post_id, self::META_MODE, true);
        $mode = in_array($mode, ['sessions', 'range'], true) ? $mode : 'sessions';

        // =========================
        // SESIONES (modo clásico)
        // =========================
        $sesiones = get_post_meta($post_id, self::META_SESIONES, true);
        if (!is_array($sesiones) || empty($sesiones)) {
            // Backwards compat: _fecha_evento/_hora_evento
            $old_fecha = get_post_meta($post_id, '_fecha_evento', true);
            $old_hora  = get_post_meta($post_id, '_hora_evento', true);
            if ($old_fecha) {
                $sesiones = [
                    [
                        'fecha'    => $old_fecha,
                        'hora'     => $old_hora,
                        'hora_fin' => '',
                    ],
                ];
            }
        }
        if (!is_array($sesiones) || empty($sesiones)) {
            $sesiones = [
                [
                    'fecha'    => '',
                    'hora'     => '',
                    'hora_fin' => '',
                ],
            ];
        } else {
            // Normalizar estructura (por si vienen sin hora_fin)
            foreach ($sesiones as $i => $s) {
                if (!is_array($s)) $s = [];
                $sesiones[$i] = [
                    'fecha'    => (string)($s['fecha'] ?? ''),
                    'hora'     => (string)($s['hora'] ?? ''),
                    'hora_fin' => (string)($s['hora_fin'] ?? ''),
                ];
            }
        }

        // =========================
        // RANGO + REGLAS (nuevo)
        // =========================
        $range_start = (string) get_post_meta($post_id, self::META_RANGE_START, true);
        $range_end   = (string) get_post_meta($post_id, self::META_RANGE_END, true);

        $rules = get_post_meta($post_id, self::META_RULES, true);
        if (!is_array($rules)) $rules = [];

        $weekday_start = (string)($rules['weekday_start'] ?? '15:00');
        $weekday_end   = (string)($rules['weekday_end']   ?? '21:00');
        $weekend_start = (string)($rules['weekend_start'] ?? '12:00');
        $weekend_end   = (string)($rules['weekend_end']   ?? '21:00');

        $exceptions = get_post_meta($post_id, self::META_EXCEPTIONS, true);
        if (!is_array($exceptions)) $exceptions = [];
        $exceptions = array_values(array_filter($exceptions, fn($row) => is_array($row)));

        // Categoría (taxonomía evento_manual_cat)
        $terms      = wp_get_object_terms($post_id, Taxonomy::TAXONOMY);
        $term_id    = (!is_wp_error($terms) && !empty($terms)) ? (int) $terms[0]->term_id : 0;
        $all_terms  = get_terms([
            'taxonomy'   => Taxonomy::TAXONOMY,
            'hide_empty' => false,
        ]);
        ?>
        <div class="cloudari-evento-manual-metabox">

            <p>
                <label for="url_evento"><strong>URL del evento (entradas / información):</strong></label><br>
                <input type="url"
                       id="url_evento"
                       name="url_evento"
                       class="widefat"
                       value="<?php echo esc_attr($url_evento); ?>"
                       placeholder="https://...">
            </p>

            <!-- Nuevo: texto del botón -->
            <p>
                <label for="manual_cta_label"><strong>Texto del botón (opcional):</strong></label><br>
                <input type="text"
                       id="manual_cta_label"
                       name="manual_cta_label"
                       class="widefat"
                       value="<?php echo esc_attr($cta_label); ?>"
                       placeholder="Ej: Entradas · Reservar · Gratis · Inscripción · Info">
            </p>
            <p class="description" style="margin-top:-6px;">
                Si lo dejas vacío, se usará “Entradas” por defecto.
            </p>

            <hr>

            <p><strong>Imagen del evento:</strong></p>
            <div class="cloudari-evento-imagen-wrapper">
                <input type="hidden"
                       id="imagen_evento_id"
                       name="imagen_evento_id"
                       value="<?php echo esc_attr($imagen_id); ?>">

                <div id="imagen_evento_preview" style="margin-bottom:8px;">
                    <?php if ($imagen_url): ?>
                        <img src="<?php echo esc_url($imagen_url); ?>"
                             alt=""
                             style="max-width:100%;height:auto;border:1px solid #ccd0d4;padding:2px;">
                    <?php endif; ?>
                </div>

                <button type="button" class="button" id="imagen_evento_select">
                    <?php echo $imagen_id ? 'Cambiar imagen' : 'Seleccionar imagen'; ?>
                </button>
                <button type="button" class="button" id="imagen_evento_remove" <?php echo $imagen_id ? '' : 'style="display:none"'; ?>>
                    Quitar imagen
                </button>
            </div>

            <hr>

            <h3 style="margin-top:0;">Tipo de evento manual</h3>

            <p class="description">
                <strong>Funciones (sesiones)</strong>: como un espectáculo normal con fechas/hora de inicio (y opcional hora fin).<br>
                <strong>Evento por rango</strong>: útil para mercados/exposiciones con horarios por día (entre semana, fin de semana y excepciones).
            </p>

            <p>
                <label style="display:inline-flex;gap:10px;align-items:center;">
                    <input type="radio" name="manual_event_mode" value="sessions" <?php checked($mode, 'sessions'); ?>>
                    <strong>Funciones (sesiones)</strong>
                </label>
                &nbsp;&nbsp;&nbsp;
                <label style="display:inline-flex;gap:10px;align-items:center;">
                    <input type="radio" name="manual_event_mode" value="range" <?php checked($mode, 'range'); ?>>
                    <strong>Evento por rango (mercado / expo)</strong>
                </label>
            </p>

            <!-- =========================
                 MODO SESIONES
                 ========================= -->
            <div id="cloudari-mode-sessions" style="<?php echo $mode === 'sessions' ? '' : 'display:none;'; ?>">

                <p><strong>Sesiones del evento</strong></p>
                <p class="description">
                    Añade todas las fechas y horas del evento. La <strong>hora fin</strong> es opcional (si se rellena, el calendario podrá mostrar “HH:MM - HH:MM”).
                </p>

                <table class="widefat fixed striped" id="sesiones-evento-table">
                    <thead>
                    <tr>
                        <th style="width: 35%;">Fecha</th>
                        <th style="width: 25%;">Hora inicio</th>
                        <th style="width: 25%;">Hora fin (opcional)</th>
                        <th style="width: 15%;"></th>
                    </tr>
                    </thead>
                    <tbody id="sesiones-evento-rows">
                    <?php
                    $index = 0;
                    foreach ($sesiones as $ses) {
                        $fecha    = (string)($ses['fecha'] ?? '');
                        $hora     = (string)($ses['hora'] ?? '');
                        $hora_fin = (string)($ses['hora_fin'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <input type="date"
                                       name="sesiones_evento[<?php echo esc_attr($index); ?>][fecha]"
                                       value="<?php echo esc_attr($fecha); ?>"
                                       class="widefat">
                            </td>
                            <td>
                                <input type="time"
                                       name="sesiones_evento[<?php echo esc_attr($index); ?>][hora]"
                                       value="<?php echo esc_attr($hora); ?>"
                                       class="widefat">
                            </td>
                            <td>
                                <input type="time"
                                       name="sesiones_evento[<?php echo esc_attr($index); ?>][hora_fin]"
                                       value="<?php echo esc_attr($hora_fin); ?>"
                                       class="widefat">
                            </td>
                            <td>
                                <button type="button" class="button button-secondary remove-sesion">
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                        <?php
                        $index++;
                    }
                    ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="add-sesion">Añadir sesión</button>
                </p>
            </div>

            <!-- =========================
                 MODO RANGO + REGLAS
                 ========================= -->
            <div id="cloudari-mode-range" style="<?php echo $mode === 'range' ? '' : 'display:none;'; ?>">

                <p><strong>Rango de fechas</strong></p>

                <table class="form-table" role="presentation" style="margin-top:0;">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="manual_range_start">Fecha inicio</label></th>
                            <td>
                                <input type="date" id="manual_range_start" name="manual_range_start"
                                       value="<?php echo esc_attr($range_start); ?>">
                                <p class="description">Ej: 2025-12-20</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="manual_range_end">Fecha fin</label></th>
                            <td>
                                <input type="date" id="manual_range_end" name="manual_range_end"
                                       value="<?php echo esc_attr($range_end); ?>">
                                <p class="description">Ej: 2026-01-03</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <hr>

                <p><strong>Horarios por defecto</strong></p>
                <p class="description">
                    Se aplican automáticamente a cada día del rango:
                    <strong>entre semana</strong> (lunes-viernes) y <strong>fin de semana</strong> (sábado-domingo).
                </p>

                <table class="form-table" role="presentation" style="margin-top:0;">
                    <tbody>
                        <tr>
                            <th scope="row">Entre semana (Lu–Vi)</th>
                            <td style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <label>Inicio
                                    <input type="time" name="manual_weekday_start" value="<?php echo esc_attr($weekday_start); ?>">
                                </label>
                                <label>Fin
                                    <input type="time" name="manual_weekday_end" value="<?php echo esc_attr($weekday_end); ?>">
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Fin de semana (Sá–Do)</th>
                            <td style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <label>Inicio
                                    <input type="time" name="manual_weekend_start" value="<?php echo esc_attr($weekend_start); ?>">
                                </label>
                                <label>Fin
                                    <input type="time" name="manual_weekend_end" value="<?php echo esc_attr($weekend_end); ?>">
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <hr>

                <p><strong>Excepciones por fecha</strong></p>
                <p class="description">
                    Para fechas especiales (ej: 24 y 31 dic, 25 dic y 1 ene). Si una fecha está aquí, <strong>sobrescribe</strong> el horario por defecto.
                </p>

                <table class="widefat fixed striped" id="manual-exceptions-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Fecha</th>
                            <th style="width: 25%;">Hora inicio</th>
                            <th style="width: 25%;">Hora fin</th>
                            <th style="width: 10%;"></th>
                        </tr>
                    </thead>
                    <tbody id="manual-exceptions-rows">
                        <?php
                        $exIndex = 0;
                        if (empty($exceptions)) {
                            $exceptions = [
                                ['date' => '', 'start' => '', 'end' => ''],
                            ];
                        }
                        foreach ($exceptions as $row) {
                            $d = (string)($row['date'] ?? '');
                            $s = (string)($row['start'] ?? '');
                            $e = (string)($row['end'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <input type="date"
                                           class="widefat"
                                           name="manual_exceptions[<?php echo esc_attr($exIndex); ?>][date]"
                                           value="<?php echo esc_attr($d); ?>">
                                </td>
                                <td>
                                    <input type="time"
                                           class="widefat"
                                           name="manual_exceptions[<?php echo esc_attr($exIndex); ?>][start]"
                                           value="<?php echo esc_attr($s); ?>">
                                </td>
                                <td>
                                    <input type="time"
                                           class="widefat"
                                           name="manual_exceptions[<?php echo esc_attr($exIndex); ?>][end]"
                                           value="<?php echo esc_attr($e); ?>">
                                </td>
                                <td>
                                    <button type="button" class="button button-secondary remove-exception">Eliminar</button>
                                </td>
                            </tr>
                            <?php
                            $exIndex++;
                        }
                        ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="add-exception">Añadir excepción</button>
                </p>
            </div>

            <hr>

            <p>
                <label for="evento_manual_cat"><strong>Categoría del evento:</strong></label><br>
                <select name="evento_manual_cat" id="evento_manual_cat" class="regular-text">
                    <option value="0">— Selecciona una categoría —</option>
                    <?php if (!is_wp_error($all_terms) && !empty($all_terms)) : ?>
                        <?php foreach ($all_terms as $term) : ?>
                            <option value="<?php echo esc_attr($term->term_id); ?>"
                                <?php selected($term_id, (int) $term->term_id); ?>>
                                <?php echo esc_html($term->name); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </p>

        </div>

        <style>
            .cloudari-evento-manual-metabox .widefat input[type="date"],
            .cloudari-evento-manual-metabox .widefat input[type="time"] {
                width: 100%;
            }
        </style>

        <script>
            (function(){
                // ---------- Toggle modos ----------
                function setMode(mode) {
                    const s = document.getElementById('cloudari-mode-sessions');
                    const r = document.getElementById('cloudari-mode-range');
                    if (!s || !r) return;
                    if (mode === 'range') {
                        s.style.display = 'none';
                        r.style.display = '';
                    } else {
                        r.style.display = 'none';
                        s.style.display = '';
                    }
                }

                document.querySelectorAll('input[name="manual_event_mode"]').forEach(function(radio){
                    radio.addEventListener('change', function(){
                        setMode(this.value);
                    });
                });

                // ---------- Imagen (media uploader) ----------
                const selectBtn = document.getElementById('imagen_evento_select');
                const removeBtn = document.getElementById('imagen_evento_remove');
                const inputId   = document.getElementById('imagen_evento_id');
                const preview   = document.getElementById('imagen_evento_preview');

                if (selectBtn) {
                    selectBtn.addEventListener('click', function(e){
                        e.preventDefault();
                        if (typeof wp === 'undefined' || !wp.media) return;

                        const frame = wp.media({
                            title: 'Seleccionar imagen del evento',
                            button: { text: 'Usar imagen' },
                            multiple: false
                        });

                        frame.on('select', function(){
                            const attachment = frame.state().get('selection').first().toJSON();
                            inputId.value = attachment.id || '';
                            if (attachment.url) {
                                preview.innerHTML =
                                    '<img src="' + attachment.url + '" style="max-width:100%;height:auto;border:1px solid #ccd0d4;padding:2px;" />';
                            }
                            if (removeBtn) {
                                removeBtn.style.display = '';
                            }
                            selectBtn.textContent = 'Cambiar imagen';
                        });

                        frame.open();
                    });
                }

                if (removeBtn) {
                    removeBtn.addEventListener('click', function(e){
                        e.preventDefault();
                        inputId.value = '';
                        preview.innerHTML = '';
                        removeBtn.style.display = 'none';
                        if (selectBtn) {
                            selectBtn.textContent = 'Seleccionar imagen';
                        }
                    });
                }

                // ---------- Sesiones (repeater) ----------
                const tbody  = document.getElementById('sesiones-evento-rows');
                const addBtn = document.getElementById('add-sesion');

                function renumberSesiones() {
                    if (!tbody) return;
                    const rows = tbody.querySelectorAll('tr');
                    rows.forEach(function(row, idx){
                        const fechaInput = row.querySelector('input[type="date"]');
                        const horaIni    = row.querySelectorAll('input[type="time"]')[0] || null;
                        const horaFin    = row.querySelectorAll('input[type="time"]')[1] || null;

                        if (fechaInput) fechaInput.name = 'sesiones_evento[' + idx + '][fecha]';
                        if (horaIni)    horaIni.name    = 'sesiones_evento[' + idx + '][hora]';
                        if (horaFin)    horaFin.name    = 'sesiones_evento[' + idx + '][hora_fin]';
                    });
                }

                if (addBtn && tbody) {
                    addBtn.addEventListener('click', function(e){
                        e.preventDefault();
                        const rows = tbody.querySelectorAll('tr');
                        let clone;
                        if (rows.length) {
                            clone = rows[rows.length - 1].cloneNode(true);
                        } else {
                            clone = document.createElement('tr');
                            clone.innerHTML =
                                '<td><input type="date" name="sesiones_evento[0][fecha]" class="widefat"></td>' +
                                '<td><input type="time" name="sesiones_evento[0][hora]" class="widefat"></td>' +
                                '<td><input type="time" name="sesiones_evento[0][hora_fin]" class="widefat"></td>' +
                                '<td><button type="button" class="button button-secondary remove-sesion">Eliminar</button></td>';
                        }

                        // Limpiar valores
                        clone.querySelectorAll('input').forEach(function(input){ input.value = ''; });

                        tbody.appendChild(clone);
                        renumberSesiones();
                    });
                }

                if (tbody) {
                    tbody.addEventListener('click', function(e){
                        if (e.target && e.target.classList.contains('remove-sesion')) {
                            e.preventDefault();
                            const rows = tbody.querySelectorAll('tr');
                            if (rows.length <= 1) {
                                rows[0].querySelectorAll('input').forEach(function(input){ input.value = ''; });
                            } else {
                                const row = e.target.closest('tr');
                                if (row) row.remove();
                            }
                            renumberSesiones();
                        }
                    });
                }

                // ---------- Excepciones (repeater) ----------
                const exBody = document.getElementById('manual-exceptions-rows');
                const exAdd  = document.getElementById('add-exception');

                function renumberExceptions() {
                    if (!exBody) return;
                    const rows = exBody.querySelectorAll('tr');
                    rows.forEach(function(row, idx){
                        const d = row.querySelector('input[type="date"]');
                        const t = row.querySelectorAll('input[type="time"]');
                        const s = t[0] || null;
                        const e = t[1] || null;

                        if (d) d.name = 'manual_exceptions[' + idx + '][date]';
                        if (s) s.name = 'manual_exceptions[' + idx + '][start]';
                        if (e) e.name = 'manual_exceptions[' + idx + '][end]';
                    });
                }

                if (exAdd && exBody) {
                    exAdd.addEventListener('click', function(e){
                        e.preventDefault();
                        const rows = exBody.querySelectorAll('tr');
                        let clone;
                        if (rows.length) {
                            clone = rows[rows.length - 1].cloneNode(true);
                        } else {
                            clone = document.createElement('tr');
                            clone.innerHTML =
                                '<td><input type="date" class="widefat" name="manual_exceptions[0][date]"></td>' +
                                '<td><input type="time" class="widefat" name="manual_exceptions[0][start]"></td>' +
                                '<td><input type="time" class="widefat" name="manual_exceptions[0][end]"></td>' +
                                '<td><button type="button" class="button button-secondary remove-exception">Eliminar</button></td>';
                        }
                        clone.querySelectorAll('input').forEach(function(input){ input.value = ''; });
                        exBody.appendChild(clone);
                        renumberExceptions();
                    });
                }

                if (exBody) {
                    exBody.addEventListener('click', function(e){
                        if (e.target && e.target.classList.contains('remove-exception')) {
                            e.preventDefault();
                            const rows = exBody.querySelectorAll('tr');
                            if (rows.length <= 1) {
                                rows[0].querySelectorAll('input').forEach(function(input){ input.value = ''; });
                            } else {
                                const row = e.target.closest('tr');
                                if (row) row.remove();
                            }
                            renumberExceptions();
                        }
                    });
                }

                // Init renumeraciones
                document.addEventListener('DOMContentLoaded', function(){
                    renumberSesiones();
                    renumberExceptions();
                }, { once: true });

            })();
        </script>
        <?php
    }

    public static function enqueueAdminAssets(string $hook): void
    {
        global $post_type;
        if ($post_type !== PostType::SLUG) {
            return;
        }
        // Necesario para el media uploader
        wp_enqueue_media();
    }

    public static function save(int $post_id): void
    {
        // Seguridad básica
        if (!isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // URL del evento
        $url = isset($_POST['url_evento'])
            ? esc_url_raw(wp_unslash($_POST['url_evento']))
            : '';
        update_post_meta($post_id, self::META_URL, $url);

        // CTA label
        $cta = isset($_POST['manual_cta_label'])
            ? sanitize_text_field(wp_unslash($_POST['manual_cta_label']))
            : '';
        $cta = trim((string)$cta);
        if ($cta !== '') {
            update_post_meta($post_id, self::META_CTA_LABEL, $cta);
        } else {
            delete_post_meta($post_id, self::META_CTA_LABEL);
        }

        // Imagen
        $img_id = isset($_POST['imagen_evento_id'])
            ? (int) $_POST['imagen_evento_id']
            : 0;

        if ($img_id > 0) {
            update_post_meta($post_id, self::META_IMG_ID, $img_id);
        } else {
            delete_post_meta($post_id, self::META_IMG_ID);
        }

        // =========================
        // Modo
        // =========================
        $mode = isset($_POST['manual_event_mode'])
            ? sanitize_key(wp_unslash($_POST['manual_event_mode']))
            : 'sessions';
        if (!in_array($mode, ['sessions', 'range'], true)) {
            $mode = 'sessions';
        }
        update_post_meta($post_id, self::META_MODE, $mode);

        // =========================
        // Sesiones (modo clásico)
        // =========================
        $sesiones_raw = isset($_POST['sesiones_evento']) ? $_POST['sesiones_evento'] : [];
        $sesiones_limpias = [];

        if (is_array($sesiones_raw)) {
            foreach ($sesiones_raw as $fila) {
                $fecha = isset($fila['fecha']) ? sanitize_text_field(wp_unslash($fila['fecha'])) : '';
                $hora  = isset($fila['hora'])  ? sanitize_text_field(wp_unslash($fila['hora']))  : '';
                $fin   = isset($fila['hora_fin']) ? sanitize_text_field(wp_unslash($fila['hora_fin'])) : '';

                if ($fecha === '') {
                    continue;
                }

                $sesiones_limpias[] = [
                    'fecha'    => $fecha,
                    'hora'     => $hora,
                    'hora_fin' => $fin,
                ];
            }
        }

        if (!empty($sesiones_limpias)) {
            update_post_meta($post_id, self::META_SESIONES, $sesiones_limpias);

            // Backwards-compat: primera fecha/hora
            update_post_meta($post_id, '_fecha_evento', $sesiones_limpias[0]['fecha']);
            update_post_meta($post_id, '_hora_evento', $sesiones_limpias[0]['hora']);
        } else {
            delete_post_meta($post_id, self::META_SESIONES);
            delete_post_meta($post_id, '_fecha_evento');
            delete_post_meta($post_id, '_hora_evento');
        }

        // =========================
        // Rango + reglas
        // =========================
        $rangeStart = isset($_POST['manual_range_start']) ? sanitize_text_field(wp_unslash($_POST['manual_range_start'])) : '';
        $rangeEnd   = isset($_POST['manual_range_end'])   ? sanitize_text_field(wp_unslash($_POST['manual_range_end']))   : '';

        if ($rangeStart) {
            update_post_meta($post_id, self::META_RANGE_START, $rangeStart);
        } else {
            delete_post_meta($post_id, self::META_RANGE_START);
        }

        if ($rangeEnd) {
            update_post_meta($post_id, self::META_RANGE_END, $rangeEnd);
        } else {
            delete_post_meta($post_id, self::META_RANGE_END);
        }

        $rules = [
            'weekday_start' => isset($_POST['manual_weekday_start']) ? sanitize_text_field(wp_unslash($_POST['manual_weekday_start'])) : '',
            'weekday_end'   => isset($_POST['manual_weekday_end'])   ? sanitize_text_field(wp_unslash($_POST['manual_weekday_end']))   : '',
            'weekend_start' => isset($_POST['manual_weekend_start']) ? sanitize_text_field(wp_unslash($_POST['manual_weekend_start'])) : '',
            'weekend_end'   => isset($_POST['manual_weekend_end'])   ? sanitize_text_field(wp_unslash($_POST['manual_weekend_end']))   : '',
        ];

        foreach ($rules as $k => $v) {
            $rules[$k] = trim((string)$v);
        }

        if (array_filter($rules)) {
            update_post_meta($post_id, self::META_RULES, $rules);
        } else {
            delete_post_meta($post_id, self::META_RULES);
        }

        // Excepciones
        $exRaw = isset($_POST['manual_exceptions']) ? $_POST['manual_exceptions'] : [];
        $exOut = [];

        if (is_array($exRaw)) {
            foreach ($exRaw as $row) {
                $d = isset($row['date'])  ? sanitize_text_field(wp_unslash($row['date']))  : '';
                $s = isset($row['start']) ? sanitize_text_field(wp_unslash($row['start'])) : '';
                $e = isset($row['end'])   ? sanitize_text_field(wp_unslash($row['end']))   : '';
                $d = trim($d); $s = trim($s); $e = trim($e);

                if ($d === '' || $s === '' || $e === '') {
                    continue;
                }

                $exOut[] = [
                    'date'  => $d,
                    'start' => $s,
                    'end'   => $e,
                ];
            }
        }

        if (!empty($exOut)) {
            update_post_meta($post_id, self::META_EXCEPTIONS, $exOut);
        } else {
            delete_post_meta($post_id, self::META_EXCEPTIONS);
        }

        // Categoría (taxonomía evento_manual_cat)
        if (isset($_POST['evento_manual_cat'])) {
            $term_id = (int) $_POST['evento_manual_cat'];
            if ($term_id > 0) {
                wp_set_object_terms($post_id, [$term_id], Taxonomy::TAXONOMY, false);
            } else {
                // Permitir limpiar categoria desde el select
                wp_set_object_terms($post_id, [], Taxonomy::TAXONOMY, false);
            }
        }
    }
}
