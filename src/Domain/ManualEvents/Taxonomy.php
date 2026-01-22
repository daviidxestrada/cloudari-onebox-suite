<?php
namespace Cloudari\Onebox\Domain\ManualEvents;

final class Taxonomy
{
    public const TAXONOMY = 'evento_manual_cat';

    private const META_COLOR = '_cloudari_manual_cat_color';

    public static function register(): void
    {
        $labels = [
            'name'          => 'Categorías (evento manual)',
            'singular_name' => 'Categoría',
            'search_items'  => 'Buscar categorías',
            'all_items'     => 'Todas las categorías',
            'edit_item'     => 'Editar categoría',
            'update_item'   => 'Actualizar categoría',
            'add_new_item'  => 'Añadir nueva categoría',
            'new_item_name' => 'Nueva categoría',
            'menu_name'     => 'Categorías',
        ];

        $args = [
            'hierarchical'      => false,
            'labels'            => $labels,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => false,
            'show_tagcloud'     => false,
            'meta_box_cb'       => false, // usamos meta box custom (dropdown único)
        ];

        register_taxonomy(self::TAXONOMY, [PostType::SLUG], $args);

        // UI: campo color en add/edit
        add_action(self::TAXONOMY . '_add_form_fields', [self::class, 'renderAddColorField']);
        add_action(self::TAXONOMY . '_edit_form_fields', [self::class, 'renderEditColorField'], 10, 2);

        // Guardado term meta
        add_action('created_' . self::TAXONOMY, [self::class, 'saveColorMeta'], 10, 2);
        add_action('edited_'  . self::TAXONOMY, [self::class, 'saveColorMeta'], 10, 2);

        // Columna en listado
        add_filter('manage_edit-' . self::TAXONOMY . '_columns', [self::class, 'addColorColumn']);
        add_filter('manage_' . self::TAXONOMY . '_custom_column', [self::class, 'renderColorColumn'], 10, 3);
    }

    public static function seedDefaults(): void
    {
        $taxonomy = self::TAXONOMY;

        // Puedes ajustar esta paleta a tu gusto
        $defaults = [
            'teatro'  => ['name' => 'Teatro',  'color' => '#009AD8'],
            'musica'  => ['name' => 'Música',  'color' => '#7E57C2'],
            'musical' => ['name' => 'Musical', 'color' => '#D14100'],
            'humor'   => ['name' => 'Humor',   'color' => '#2E7D32'],
            'talk'    => ['name' => 'Talk',    'color' => '#455A64'],
        ];

        foreach ($defaults as $slug => $data) {
            $name  = (string)($data['name'] ?? $slug);
            $color = (string)($data['color'] ?? '');

            $term = term_exists($slug, $taxonomy);

            if (!$term) {
                $created = wp_insert_term($name, $taxonomy, [
                    'slug'        => $slug,
                    'description' => $name,
                ]);

                if (!is_wp_error($created) && !empty($created['term_id']) && $color) {
                    update_term_meta((int)$created['term_id'], self::META_COLOR, $color);
                }
            } else {
                // Si ya existe, no pisamos color existente; solo ponemos uno si está vacío
                $term_id = is_array($term) ? (int)($term['term_id'] ?? 0) : (int)$term;
                if ($term_id > 0 && $color) {
                    $current = (string)get_term_meta($term_id, self::META_COLOR, true);
                    if ($current === '') {
                        update_term_meta($term_id, self::META_COLOR, $color);
                    }
                }
            }
        }
    }

    /**
     * ============
     * UI Fields
     * ============
     */
    public static function renderAddColorField(): void
    {
        ?>
        <div class="form-field term-color-wrap">
            <label for="cloudari_manual_cat_color">Color</label>
            <input type="color"
                   id="cloudari_manual_cat_color"
                   name="cloudari_manual_cat_color"
                   value="#009AD8"
                   style="width:80px;padding:0;border:none;background:transparent;">
            <p class="description">Color opcional para esta categoría (se usará para pintar etiquetas en el front si lo necesitas).</p>
        </div>
        <?php
    }

    public static function renderEditColorField($term, $taxonomy): void
    {
        if (!is_object($term) || empty($term->term_id)) return;

        $value = (string)get_term_meta((int)$term->term_id, self::META_COLOR, true);
        if ($value === '') $value = '#009AD8';
        ?>
        <tr class="form-field term-color-wrap">
            <th scope="row">
                <label for="cloudari_manual_cat_color">Color</label>
            </th>
            <td>
                <input type="color"
                       id="cloudari_manual_cat_color"
                       name="cloudari_manual_cat_color"
                       value="<?php echo esc_attr($value); ?>"
                       style="width:80px;padding:0;border:none;background:transparent;">
                <p class="description">Color opcional para esta categoría.</p>
            </td>
        </tr>
        <?php
    }

    /**
     * ============
     * Save Meta
     * ============
     */
    public static function saveColorMeta(int $term_id, int $tt_id): void
    {
        if (!isset($_POST['cloudari_manual_cat_color'])) {
            return;
        }

        $raw = (string)wp_unslash($_POST['cloudari_manual_cat_color']);
        $raw = trim($raw);

        // Aceptamos #RRGGBB (con o sin #)
        if ($raw === '') {
            delete_term_meta($term_id, self::META_COLOR);
            return;
        }

        if ($raw[0] !== '#') {
            $raw = '#' . $raw;
        }

        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $raw)) {
            // Si viene mal, no guardamos (evita basura)
            return;
        }

        update_term_meta($term_id, self::META_COLOR, $raw);
    }

    /**
     * ============
     * Admin Column
     * ============
     */
    public static function addColorColumn(array $columns): array
    {
        // Insertar columna "Color" tras "Nombre" si existe
        $out = [];
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ($key === 'name') {
                $out['cloudari_color'] = 'Color';
            }
        }
        if (!isset($out['cloudari_color'])) {
            $out['cloudari_color'] = 'Color';
        }
        return $out;
    }

    public static function renderColorColumn($content, string $column_name, int $term_id)
    {
        if ($column_name !== 'cloudari_color') {
            return $content;
        }

        $value = (string)get_term_meta($term_id, self::META_COLOR, true);
        if ($value === '') {
            return '—';
        }

        $swatch = sprintf(
            '<span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:%1$s;border:1px solid rgba(0,0,0,.2);vertical-align:middle;margin-right:6px;"></span>%2$s',
            esc_attr($value),
            esc_html($value)
        );

        return $swatch;
    }

    /**
     * Helper público por si luego lo necesitas en Repository/REST/JS env
     */
    public static function getTermColor(int $term_id, string $fallback = ''): string
    {
        $v = (string)get_term_meta($term_id, self::META_COLOR, true);
        return $v !== '' ? $v : $fallback;
    }
}
