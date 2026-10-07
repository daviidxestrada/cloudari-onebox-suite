<?php

namespace Cloudari\Onebox\Presentation\Elementor\Widgets;

use Cloudari\Onebox\Domain\Events\EventOverridesRepository;
use Cloudari\Onebox\Presentation\Elementor\Bootstrap;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cartelera por espacios con el diseño propio del Gran Teatro Pavón.
 *
 * Consume el mismo endpoint que `[cloudari_billboard_venues]` (OneBox + eventos
 * manuales), pero no hereda la paleta del Perfil MAIN: el diseño por defecto vive
 * en `assets/css/billboard-pavon.css` como variables CSS y los controles de estilo
 * solo las sobrescriben. Un control vacío equivale al valor del diseño.
 */
final class BillboardPavon extends Widget_Base
{
    public const NAME = 'cloudari_billboard_pavon';

    /** Selector sobre el que se declaran las variables del diseño. */
    private const ROOT = '{{WRAPPER}} .cld-billboard-pavon';

    public function get_name(): string
    {
        return self::NAME;
    }

    public function get_title(): string
    {
        return esc_html__('Cartelera - Gran Teatro Pavón', 'cloudari-onebox');
    }

    public function get_icon(): string
    {
        return 'eicon-posts-grid';
    }

    public function get_categories(): array
    {
        return [Bootstrap::CATEGORY_SLUG];
    }

    public function get_keywords(): array
    {
        return ['cartelera', 'billboard', 'espacios', 'venues', 'onebox', 'pavon', 'cloudari'];
    }

    public function get_style_depends(): array
    {
        return [Bootstrap::BILLBOARD_PAVON_ASSET_HANDLE];
    }

    public function get_script_depends(): array
    {
        return [Bootstrap::BILLBOARD_PAVON_ASSET_HANDLE];
    }

    protected function register_controls(): void
    {
        $this->registerVenuesSection();
        $this->registerFiltersSection();
        $this->registerTextsSection();
        $this->registerGeneralStyleSection();
        $this->registerSelectorStyleSection();
        $this->registerFiltersStyleSection();
        $this->registerCardsStyleSection();
        $this->registerCategoriesStyleSection();
    }

    /**
     * ==============================
     *  CONTROLES · CONTENIDO
     * ==============================
     */
    private function registerVenuesSection(): void
    {
        $this->start_controls_section(
            'section_venues',
            [
                'label' => esc_html__('Espacios', 'cloudari-onebox'),
            ]
        );

        $this->add_control(
            'data_source_note',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw' => esc_html__('Los espacios y sus espectáculos llegan de OneBox y de los eventos manuales, igual que en el shortcode de cartelera por espacios. Aquí solo se decide cómo se pintan.', 'cloudari-onebox'),
                'content_classes' => 'elementor-descriptor',
            ]
        );

        $this->add_control(
            'venue_labels',
            [
                'label' => esc_html__('Nombres en el selector', 'cloudari-onebox'),
                'type' => Controls_Manager::TEXTAREA,
                'rows' => 3,
                'default' => 'Ambigú Gran Teatro Pavón = Ambigú',
                'placeholder' => 'Ambigú Gran Teatro Pavón = Ambigú',
                'description' => esc_html__('Opcional, uno por línea: nombre (o slug) del espacio en OneBox = nombre que se muestra en el selector. Los espacios que no aparezcan aquí usan su nombre de OneBox. Las tarjetas siempre muestran el nombre de OneBox.', 'cloudari-onebox'),
            ]
        );

        $this->add_control(
            'show_count',
            [
                'label' => esc_html__('Mostrar nº de espectáculos en el selector', 'cloudari-onebox'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default' => '',
            ]
        );

        $this->end_controls_section();
    }

    private function registerFiltersSection(): void
    {
        $this->start_controls_section(
            'section_filters',
            [
                'label' => esc_html__('Buscador y categorías', 'cloudari-onebox'),
            ]
        );

        $this->add_control(
            'show_search',
            [
                'label' => esc_html__('Mostrar buscador', 'cloudari-onebox'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default' => 'yes',
            ]
        );

        $this->add_control(
            'show_category',
            [
                'label' => esc_html__('Mostrar filtro de categorías', 'cloudari-onebox'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default' => 'yes',
            ]
        );

        $this->end_controls_section();
    }

    private function registerTextsSection(): void
    {
        $this->start_controls_section(
            'section_texts',
            [
                'label' => esc_html__('Textos', 'cloudari-onebox'),
            ]
        );

        $this->add_control(
            'texts_note',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw' => esc_html__('Textos del diseño. Los títulos, fechas, espacios y categorías vienen de la API y no se editan aquí.', 'cloudari-onebox'),
                'content_classes' => 'elementor-descriptor',
            ]
        );

        foreach (self::textControls() as $id => $control) {
            $this->add_control(
                $id,
                [
                    'label' => $control['label'],
                    'type' => Controls_Manager::TEXT,
                    'default' => $control['default'],
                    'placeholder' => $control['default'],
                    'label_block' => true,
                    'separator' => $control['separator'] ?? 'default',
                    'description' => $control['description'] ?? '',
                ]
            );
        }

        $this->end_controls_section();
    }

    /**
     * Textos editables y su valor por defecto. Un campo vaciado vuelve al valor
     * por defecto en `text()`: ninguno de ellos tiene sentido en blanco.
     *
     * @return array<string,array<string,string>>
     */
    private static function textControls(): array
    {
        return [
            'text_heading' => [
                'label' => esc_html__('Título accesible de la sección', 'cloudari-onebox'),
                'default' => 'Cartelera por espacios',
                'description' => esc_html__('No se ve: lo leen los lectores de pantalla.', 'cloudari-onebox'),
            ],
            'text_search_placeholder' => [
                'label' => esc_html__('Placeholder del buscador', 'cloudari-onebox'),
                'default' => 'Buscar espectáculos...',
                'separator' => 'before',
            ],
            'text_all_categories' => [
                'label' => esc_html__('Opción "todas" del filtro', 'cloudari-onebox'),
                'default' => 'Todas las categorías',
            ],
            'text_cta' => [
                'label' => esc_html__('Botón de compra', 'cloudari-onebox'),
                'default' => 'Entradas',
                'separator' => 'before',
                'description' => esc_html__('Un evento manual con su propia etiqueta de botón la conserva.', 'cloudari-onebox'),
            ],
            'text_show_singular' => [
                'label' => esc_html__('Contador · singular', 'cloudari-onebox'),
                'default' => 'espectáculo',
                'separator' => 'before',
            ],
            'text_show_plural' => [
                'label' => esc_html__('Contador · plural', 'cloudari-onebox'),
                'default' => 'espectáculos',
            ],
            'text_empty_filtered' => [
                'label' => esc_html__('Sin resultados con los filtros', 'cloudari-onebox'),
                'default' => 'No hay espectáculos que coincidan con los filtros.',
                'separator' => 'before',
            ],
            'text_empty_venue' => [
                'label' => esc_html__('Espacio sin programación', 'cloudari-onebox'),
                'default' => 'No hay eventos próximos para este espacio.',
            ],
            'text_empty_all' => [
                'label' => esc_html__('Sin ningún espacio con eventos', 'cloudari-onebox'),
                'default' => 'No hay espacios con eventos próximos.',
            ],
            'text_error' => [
                'label' => esc_html__('Error al cargar', 'cloudari-onebox'),
                'default' => 'No se pudo cargar la cartelera por espacios.',
            ],
        ];
    }

    /**
     * ==============================
     *  CONTROLES · ESTILO
     * ==============================
     */
    private function registerGeneralStyleSection(): void
    {
        $this->start_controls_section(
            'section_style_general',
            [
                'label' => esc_html__('General', 'cloudari-onebox'),
                'tab' => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'style_note',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw' => esc_html__('El widget ya trae el diseño del Gran Teatro Pavón. Un color vacío usa el del diseño; los bordes y fondos derivados siguen al color de marca mientras no se fijen a mano.', 'cloudari-onebox'),
                'content_classes' => 'elementor-descriptor',
            ]
        );

        $this->addColor('color_brand', esc_html__('Color de marca', 'cloudari-onebox'), '--cbp-brand', '#F0C35B');
        $this->addColor('color_ink', esc_html__('Texto', 'cloudari-onebox'), '--cbp-ink', '#0B0F1A');
        $this->addColor('color_muted', esc_html__('Texto secundario', 'cloudari-onebox'), '--cbp-muted', '#475569');
        $this->addColor('color_cta', esc_html__('Botón de compra', 'cloudari-onebox'), '--cbp-cta', '#D14100', 'before');
        $this->addColor('color_cta_text', esc_html__('Texto del botón de compra', 'cloudari-onebox'), '--cbp-cta-text', '#FFFFFF');

        $this->add_control(
            'font_display',
            [
                'label' => esc_html__('Tipografía de titulares', 'cloudari-onebox'),
                'type' => Controls_Manager::FONT,
                'default' => 'Staatliches',
                'separator' => 'before',
                'selectors' => [
                    self::ROOT => '--cbp-font-display: "{{VALUE}}", "Arial Narrow", sans-serif;',
                ],
            ]
        );

        $this->add_control(
            'font_text',
            [
                'label' => esc_html__('Tipografía de texto', 'cloudari-onebox'),
                'type' => Controls_Manager::FONT,
                'default' => 'Poppins',
                'selectors' => [
                    self::ROOT => '--cbp-font-text: "{{VALUE}}", system-ui, sans-serif;',
                ],
            ]
        );

        $this->end_controls_section();
    }

    private function registerSelectorStyleSection(): void
    {
        $this->start_controls_section(
            'section_style_selector',
            [
                'label' => esc_html__('Selector de espacios', 'cloudari-onebox'),
                'tab' => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->addColor('selector_bg', esc_html__('Fondo', 'cloudari-onebox'), '--cbp-selector-bg', '#FFFFFF');
        $this->addColor('selector_line', esc_html__('Borde', 'cloudari-onebox'), '--cbp-selector-line');
        $this->addColor('selector_thumb', esc_html__('Pastilla del espacio activo', 'cloudari-onebox'), '--cbp-thumb');
        $this->addColor('selector_text', esc_html__('Texto', 'cloudari-onebox'), '--cbp-tab-text', '', 'before');
        $this->addColor('selector_active_text', esc_html__('Texto del espacio activo', 'cloudari-onebox'), '--cbp-tab-active-text');
        $this->addColor('selector_hover_bg', esc_html__('Fondo al pasar el ratón', 'cloudari-onebox'), '--cbp-tab-hover-bg');

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name' => 'selector_typography',
                'label' => esc_html__('Tipografía del nombre', 'cloudari-onebox'),
                'selector' => self::ROOT . ' .cbp-tab__label',
                'separator' => 'before',
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name' => 'selector_count_typography',
                'label' => esc_html__('Tipografía del contador', 'cloudari-onebox'),
                'selector' => self::ROOT . ' .cbp-tab__count',
            ]
        );

        $this->end_controls_section();
    }

    private function registerFiltersStyleSection(): void
    {
        $this->start_controls_section(
            'section_style_filters',
            [
                'label' => esc_html__('Buscador y categorías', 'cloudari-onebox'),
                'tab' => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->addColor('field_bg', esc_html__('Fondo', 'cloudari-onebox'), '--cbp-field-bg', '#FFFFFF');
        $this->addColor('field_line', esc_html__('Borde', 'cloudari-onebox'), '--cbp-field-line', '#E5E7EB');
        $this->addColor('field_text', esc_html__('Texto', 'cloudari-onebox'), '--cbp-field-text');
        $this->addColor('field_placeholder', esc_html__('Placeholder', 'cloudari-onebox'), '--cbp-field-placeholder', '#6B7280');
        $this->addColor('field_focus', esc_html__('Foco', 'cloudari-onebox'), '--cbp-focus');

        $this->end_controls_section();
    }

    private function registerCardsStyleSection(): void
    {
        $this->start_controls_section(
            'section_style_cards',
            [
                'label' => esc_html__('Tarjetas', 'cloudari-onebox'),
                'tab' => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->addColor('card_bg', esc_html__('Fondo', 'cloudari-onebox'), '--cbp-card-bg', '#FFFFFF');
        $this->addColor('card_line', esc_html__('Borde', 'cloudari-onebox'), '--cbp-card-line', '#E5E7EB');
        $this->addColor('card_topbar', esc_html__('Franja bajo el cartel', 'cloudari-onebox'), '--cbp-card-topbar');
        $this->addColor('card_title', esc_html__('Título', 'cloudari-onebox'), '--cbp-card-title', '', 'before');
        $this->addColor('card_text', esc_html__('Fecha y espacio', 'cloudari-onebox'), '--cbp-card-text');
        $this->addColor('card_icon', esc_html__('Iconos', 'cloudari-onebox'), '--cbp-card-icon');

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name' => 'card_title_typography',
                'label' => esc_html__('Tipografía del título', 'cloudari-onebox'),
                'selector' => self::ROOT . ' .cbp-title',
            ]
        );

        $this->add_control(
            'card_radius',
            [
                'label' => esc_html__('Radio de las esquinas', 'cloudari-onebox'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => ['px' => ['min' => 0, 'max' => 32, 'step' => 1]],
                'separator' => 'before',
                'selectors' => [
                    self::ROOT => '--cbp-radius: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'card_gap',
            [
                'label' => esc_html__('Separación entre tarjetas', 'cloudari-onebox'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => ['px' => ['min' => 0, 'max' => 48, 'step' => 1]],
                'selectors' => [
                    self::ROOT . ' .cbp-grid' => 'gap: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    private function registerCategoriesStyleSection(): void
    {
        $this->start_controls_section(
            'section_style_categories',
            [
                'label' => esc_html__('Etiquetas de categoría', 'cloudari-onebox'),
                'tab' => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->addColor('cat_teatro', esc_html__('Teatro', 'cloudari-onebox'), '--cbp-cat-teatro', '#3B0764');
        $this->addColor('cat_musica', esc_html__('Música', 'cloudari-onebox'), '--cbp-cat-musica', '#047857');
        $this->addColor('cat_musical', esc_html__('Musical', 'cloudari-onebox'), '--cbp-cat-musical', '#5F61F2');
        $this->addColor('cat_humor', esc_html__('Humor', 'cloudari-onebox'), '--cbp-cat-humor', '#B45309');
        $this->addColor('cat_talk', esc_html__('Talk', 'cloudari-onebox'), '--cbp-cat-talk', '#0369A1');

        $this->end_controls_section();
    }

    /**
     * Control de color que escribe una variable CSS del diseño.
     *
     * Sin `$default` el control arranca vacío: la variable conserva el valor de
     * la hoja de estilos, que para los colores derivados es un `color-mix()`
     * sobre el color de marca.
     */
    private function addColor(string $id, string $label, string $cssVar, string $default = '', string $separator = 'default'): void
    {
        $args = [
            'label' => $label,
            'type' => Controls_Manager::COLOR,
            'separator' => $separator,
            'selectors' => [
                self::ROOT => $cssVar . ': {{VALUE}};',
            ],
        ];

        if ($default !== '') {
            $args['default'] = $default;
        }

        $this->add_control($id, $args);
    }

    /**
     * ==============================
     *  RENDER
     * ==============================
     */
    protected function render(): void
    {
        if (!CLOUDARI_ONEBOX_ENABLE_OUTPUT && !self::isEditMode()) {
            echo '<!-- Cloudari Cartelera Gran Teatro Pavon desactivada por flag -->';
            return;
        }

        $settings = $this->get_settings_for_display();
        $id = $this->get_id();
        $titleId = 'cloudari-billboard-pavon-title-' . $id;
        $searchId = 'cloudari-billboard-pavon-search-' . $id;
        $categoryId = 'cloudari-billboard-pavon-category-' . $id;

        $showSearch = ($settings['show_search'] ?? '') === 'yes';
        $showCategory = ($settings['show_category'] ?? '') === 'yes';
        $searchPlaceholder = self::text($settings, 'text_search_placeholder');
        $allCategories = self::text($settings, 'text_all_categories');
        ?>
        <section
            class="cld-billboard-pavon"
            data-cloudari-billboard-pavon
            data-config="<?php echo esc_attr((string) wp_json_encode(self::buildConfig($settings))); ?>"
            aria-labelledby="<?php echo esc_attr($titleId); ?>"
        >
            <h2 id="<?php echo esc_attr($titleId); ?>" class="cbp-sr-only"><?php echo esc_html(self::text($settings, 'text_heading')); ?></h2>

            <header class="cbp-head">
                <div class="cbp-tabs-scroller" data-role="tabs-scroller">
                    <div class="cbp-tabs" data-role="tabs" role="tablist" aria-labelledby="<?php echo esc_attr($titleId); ?>"></div>
                </div>
            </header>

            <?php if ($showSearch || $showCategory) : ?>
                <div class="cbp-filters">
                    <div class="cbp-actions<?php echo ($showSearch && $showCategory) ? '' : ' cbp-actions--single'; ?>" role="search">
                        <?php if ($showSearch) : ?>
                            <label class="cbp-sr-only" for="<?php echo esc_attr($searchId); ?>"><?php echo esc_html($searchPlaceholder); ?></label>
                            <input id="<?php echo esc_attr($searchId); ?>" data-role="search" type="search" placeholder="<?php echo esc_attr($searchPlaceholder); ?>" />
                        <?php endif; ?>

                        <?php if ($showCategory) : ?>
                            <label class="cbp-sr-only" for="<?php echo esc_attr($categoryId); ?>"><?php echo esc_html__('Filtrar por categoría', 'cloudari-onebox'); ?></label>
                            <select id="<?php echo esc_attr($categoryId); ?>" data-role="category">
                                <option value="all"><?php echo esc_html($allCategories); ?></option>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="cbp-list" data-role="list" aria-live="polite"></div>
        </section>
        <?php
    }

    /**
     * Configuración que lee `billboard-pavon.js` desde `data-config`.
     *
     * Va por instancia y no por `wp_localize_script` para que dos widgets en la
     * misma página puedan tener textos distintos.
     */
    private static function buildConfig(array $settings): array
    {
        $overrideMaps = EventOverridesRepository::getEnvMaps();

        return [
            'endpoint' => esc_url_raw(rest_url('cloudari/v1/billboard-venues')),
            // `(object)` para que un mapa vacío viaje como `{}` y no como `[]`.
            'specialRedirects' => (object) ($overrideMaps['specialRedirects'] ?? []),
            'categoryOverrides' => (object) ($overrideMaps['categoryOverrides'] ?? []),
            'venueLabels' => (object) self::parseVenueLabels((string) ($settings['venue_labels'] ?? '')),
            'showCount' => ($settings['show_count'] ?? '') === 'yes',
            'texts' => [
                'allCategories' => self::text($settings, 'text_all_categories'),
                'cta' => self::text($settings, 'text_cta'),
                'showSingular' => self::text($settings, 'text_show_singular'),
                'showPlural' => self::text($settings, 'text_show_plural'),
                'emptyFiltered' => self::text($settings, 'text_empty_filtered'),
                'emptyVenue' => self::text($settings, 'text_empty_venue'),
                'emptyAll' => self::text($settings, 'text_empty_all'),
                'error' => self::text($settings, 'text_error'),
            ],
        ];
    }

    /**
     * Líneas `Nombre en OneBox = Nombre en el selector`.
     *
     * @return array<string,string>
     */
    private static function parseVenueLabels(string $raw): array
    {
        $labels = [];

        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $source = sanitize_text_field($parts[0]);
            $label = sanitize_text_field($parts[1]);

            if ($source !== '' && $label !== '') {
                $labels[$source] = $label;
            }
        }

        return $labels;
    }

    private static function text(array $settings, string $key): string
    {
        $value = trim((string) ($settings[$key] ?? ''));

        return $value !== '' ? $value : (self::textControls()[$key]['default'] ?? '');
    }

    private static function isEditMode(): bool
    {
        $elementor = \Elementor\Plugin::$instance ?? null;

        return $elementor !== null
            && isset($elementor->editor)
            && $elementor->editor->is_edit_mode();
    }
}
