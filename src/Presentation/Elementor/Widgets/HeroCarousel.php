<?php

namespace Cloudari\Onebox\Presentation\Elementor\Widgets;

use Cloudari\Onebox\Presentation\Elementor\Bootstrap;
use Cloudari\Onebox\Presentation\Hero\CurveShape;
use Cloudari\Onebox\Presentation\Hero\HeroSlide;
use Cloudari\Onebox\Support\Helpers;
use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Widget_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Carrusel hero de carteles con enlace de compra.
 *
 * El markup se genera en servidor (no por `innerHTML` como el prototipo original)
 * para que la imagen LCP viaje en el HTML y para que el JS no tenga que construir
 * cadenas con datos del usuario.
 */
final class HeroCarousel extends Widget_Base
{
    public const NAME = 'cloudari_hero_carousel';

    /** Punto de corte del `<source>`, heredado del hero original. */
    private const MOBILE_BREAKPOINT = 1024;

    public function get_name(): string
    {
        return self::NAME;
    }

    public function get_title(): string
    {
        return esc_html__('Cloudari Hero Carrusel', 'cloudari-onebox');
    }

    public function get_icon(): string
    {
        return 'eicon-slides';
    }

    public function get_categories(): array
    {
        return [Bootstrap::CATEGORY_SLUG];
    }

    public function get_keywords(): array
    {
        return ['hero', 'carrusel', 'carousel', 'slider', 'cartel', 'cloudari'];
    }

    public function get_style_depends(): array
    {
        return [Bootstrap::HERO_ASSET_HANDLE];
    }

    public function get_script_depends(): array
    {
        return [Bootstrap::HERO_ASSET_HANDLE];
    }

    protected function register_controls(): void
    {
        $this->registerSlidesSection();
        $this->registerCurveSection();
        $this->registerBehaviourSection();
        $this->registerLayoutStyleSection();
        $this->registerArrowsStyleSection();
    }

    /**
     * ==============================
     *  CONTROLES
     * ==============================
     */
    private function registerSlidesSection(): void
    {
        $this->start_controls_section(
            'section_slides',
            [
                'label' => esc_html__('Carteles', 'cloudari-onebox'),
                'tab' => Controls_Manager::TAB_CONTENT,
            ]
        );

        $repeater = new Repeater();

        $repeater->add_control(
            'title',
            [
                'label' => esc_html__('Nombre del espectáculo', 'cloudari-onebox'),
                'type' => Controls_Manager::TEXT,
                'label_block' => true,
                'description' => esc_html__('Se usa para anunciar el cartel a lectores de pantalla y en la etiqueta del botón de compra.', 'cloudari-onebox'),
            ]
        );

        $repeater->add_control(
            'image_desktop',
            [
                'label' => esc_html__('Medio de escritorio', 'cloudari-onebox'),
                'type' => Controls_Manager::MEDIA,
                'media_types' => ['image', 'video'],
                'description' => esc_html__('Imagen o vídeo de la Mediateca. Proporción recomendada 1920 × 800 px.', 'cloudari-onebox'),
            ]
        );

        $repeater->add_control(
            'image_mobile',
            [
                'label' => esc_html__('Medio para móvil y tablet', 'cloudari-onebox'),
                'type' => Controls_Manager::MEDIA,
                'media_types' => ['image', 'video'],
                'description' => sprintf(
                    /* translators: %d: ancho en píxeles del punto de corte. */
                    esc_html__('Se sirve por debajo de %d px. Puede ser de distinto tipo que el de escritorio (por ejemplo, vídeo arriba e imagen abajo). Si se deja vacío se reutiliza el de escritorio.', 'cloudari-onebox'),
                    self::MOBILE_BREAKPOINT
                ),
            ]
        );

        $repeater->add_control(
            'video_poster',
            [
                'label' => esc_html__('Imagen de reserva del vídeo', 'cloudari-onebox'),
                'type' => Controls_Manager::MEDIA,
                'media_types' => ['image'],
                'description' => esc_html__('Se ve mientras el vídeo carga, y en lugar del vídeo cuando el visitante pide reducir las animaciones. Muy recomendable.', 'cloudari-onebox'),
            ]
        );

        $repeater->add_control(
            'alt_text',
            [
                'label' => esc_html__('Texto alternativo', 'cloudari-onebox'),
                'type' => Controls_Manager::TEXTAREA,
                'rows' => 3,
                'label_block' => true,
                'description' => esc_html__('Describe lo que se ve en el cartel, incluido el texto incrustado en la imagen. Si se deja vacío se usa el texto alternativo de la Mediateca.', 'cloudari-onebox'),
            ]
        );

        $repeater->add_control(
            'link',
            [
                'label' => esc_html__('Enlace de compra', 'cloudari-onebox'),
                'type' => Controls_Manager::URL,
                'options' => ['url', 'is_external', 'nofollow'],
                'label_block' => true,
                'default' => [
                    'url' => '',
                    'is_external' => true,
                    'nofollow' => false,
                ],
                'description' => esc_html__('Déjalo vacío para un cartel puramente informativo, sin enlace.', 'cloudari-onebox'),
            ]
        );

        $repeater->add_control(
            'framing_heading',
            [
                'label' => esc_html__('Encuadre del cartel', 'cloudari-onebox'),
                'type' => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $repeater->add_responsive_control(
            'focal_x',
            [
                'label' => esc_html__('Posición horizontal', 'cloudari-onebox'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['%'],
                'range' => ['%' => ['min' => 0, 'max' => 100, 'step' => 1]],
                'default' => ['unit' => '%', 'size' => 50],
                'description' => esc_html__('Mueve el recorte para que no se corte el texto incrustado en el cartel.', 'cloudari-onebox'),
                'selectors' => [
                    '{{WRAPPER}} {{CURRENT_ITEM}}' => '--cld-hero-pos-x: {{SIZE}}%;',
                ],
            ]
        );

        $repeater->add_responsive_control(
            'focal_y',
            [
                'label' => esc_html__('Posición vertical', 'cloudari-onebox'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['%'],
                'range' => ['%' => ['min' => 0, 'max' => 100, 'step' => 1]],
                'default' => ['unit' => '%', 'size' => 50],
                'selectors' => [
                    '{{WRAPPER}} {{CURRENT_ITEM}}' => '--cld-hero-pos-y: {{SIZE}}%;',
                ],
            ]
        );

        $repeater->add_control(
            'contain_mobile',
            [
                'label' => esc_html__('Mostrar el cartel entero en móvil', 'cloudari-onebox'),
                'type' => Controls_Manager::SWITCHER,
                'label_on' => esc_html__('Sí', 'cloudari-onebox'),
                'label_off' => esc_html__('No', 'cloudari-onebox'),
                'return_value' => 'yes',
                'default' => '',
                'description' => esc_html__('Evita recortar el cartel en pantallas pequeñas y rellena los laterales con una copia desenfocada.', 'cloudari-onebox'),
            ]
        );

        $this->add_control(
            'slides',
            [
                'label' => esc_html__('Carteles', 'cloudari-onebox'),
                'type' => Controls_Manager::REPEATER,
                'fields' => $repeater->get_controls(),
                'default' => [],
                // `{{ }}` escapa en las plantillas del editor; `{{{ }}}` no.
                'title_field' => '{{ title || "' . esc_js(__('Cartel', 'cloudari-onebox')) . '" }}',
                'prevent_empty' => false,
            ]
        );

        $this->end_controls_section();
    }

    private function registerCurveSection(): void
    {
        $this->start_controls_section(
            'section_curve',
            [
                'label' => esc_html__('Curva inferior', 'cloudari-onebox'),
                'tab' => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'curve_enabled',
            [
                'label' => esc_html__('Mostrar curva', 'cloudari-onebox'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default' => 'yes',
            ]
        );

        $this->add_control(
            'curve_color',
            [
                'label' => esc_html__('Color', 'cloudari-onebox'),
                'type' => Controls_Manager::COLOR,
                'default' => '#ffffff',
                'description' => esc_html__('Debe coincidir con el fondo de la sección que va justo debajo del hero.', 'cloudari-onebox'),
                'condition' => ['curve_enabled' => 'yes'],
                'selectors' => [
                    '{{WRAPPER}} .cld-hero' => '--cld-hero-curve-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'curve_depth',
            [
                'label' => esc_html__('Profundidad', 'cloudari-onebox'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => [''],
                'range' => ['' => ['min' => 0, 'max' => 100, 'step' => 1]],
                'default' => ['size' => CurveShape::DEFAULT_DEPTH],
                'description' => esc_html__('0 deja el borde recto; 100 es la curva más pronunciada.', 'cloudari-onebox'),
                'condition' => ['curve_enabled' => 'yes'],
            ]
        );

        $this->add_responsive_control(
            'curve_height',
            [
                'label' => esc_html__('Altura', 'cloudari-onebox'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => ['px' => ['min' => 0, 'max' => 200, 'step' => 1]],
                'default' => ['unit' => 'px', 'size' => 62],
                'tablet_default' => ['unit' => 'px', 'size' => 0],
                'mobile_default' => ['unit' => 'px', 'size' => 0],
                'description' => esc_html__('Ponla a 0 para ocultar la curva en ese dispositivo.', 'cloudari-onebox'),
                'condition' => ['curve_enabled' => 'yes'],
                'selectors' => [
                    '{{WRAPPER}} .cld-hero' => '--cld-hero-curve-height: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    private function registerBehaviourSection(): void
    {
        $this->start_controls_section(
            'section_behaviour',
            [
                'label' => esc_html__('Comportamiento', 'cloudari-onebox'),
                'tab' => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'aria_label',
            [
                'label' => esc_html__('Etiqueta del carrusel', 'cloudari-onebox'),
                'type' => Controls_Manager::TEXT,
                'label_block' => true,
                // Sin escapar: el valor se escapa en el render y no antes.
                'default' => __('Carrusel principal', 'cloudari-onebox'),
                'description' => esc_html__('Cómo se anuncia el carrusel completo a lectores de pantalla.', 'cloudari-onebox'),
            ]
        );

        $this->add_control(
            'show_arrows',
            [
                'label' => esc_html__('Mostrar flechas', 'cloudari-onebox'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default' => 'yes',
            ]
        );

        $this->add_control(
            'autoplay',
            [
                'label' => esc_html__('Reproducción automática', 'cloudari-onebox'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default' => 'yes',
                'separator' => 'before',
                'description' => esc_html__('Añade siempre un botón de pausa: WCAG 2.2.2 exige poder detener todo movimiento automático de más de 5 segundos.', 'cloudari-onebox'),
            ]
        );

        $this->add_control(
            'autoplay_delay',
            [
                'label' => esc_html__('Tiempo por cartel (ms)', 'cloudari-onebox'),
                'type' => Controls_Manager::NUMBER,
                'min' => 2000,
                'max' => 20000,
                'step' => 500,
                'default' => 5000,
                'condition' => ['autoplay' => 'yes'],
            ]
        );

        $this->add_control(
            'pause_on_hover',
            [
                'label' => esc_html__('Pausar al pasar el ratón', 'cloudari-onebox'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default' => 'yes',
                'condition' => ['autoplay' => 'yes'],
            ]
        );

        $this->add_control(
            'weekly_rotation',
            [
                'label' => esc_html__('Rotar el primer cartel cada semana', 'cloudari-onebox'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default' => '',
                'separator' => 'before',
                'description' => esc_html__('Cada semana ISO arranca por el siguiente cartel, manteniendo el orden relativo. El orden se calcula al generar la página, así que una caché de página lo congela hasta que se purgue.', 'cloudari-onebox'),
            ]
        );

        $this->end_controls_section();
    }

    private function registerLayoutStyleSection(): void
    {
        $this->start_controls_section(
            'section_style_layout',
            [
                'label' => esc_html__('Diseño', 'cloudari-onebox'),
                'tab' => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'height',
            [
                'label' => esc_html__('Altura', 'cloudari-onebox'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['vh', 'px'],
                'range' => [
                    'vh' => ['min' => 20, 'max' => 100, 'step' => 1],
                    'px' => ['min' => 180, 'max' => 1200, 'step' => 10],
                ],
                'default' => ['unit' => 'vh', 'size' => 83],
                'tablet_default' => ['unit' => 'vh', 'size' => 65],
                'mobile_default' => ['unit' => 'vh', 'size' => 35],
                'selectors' => [
                    '{{WRAPPER}} .cld-hero' => '--cld-hero-height: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'background_color',
            [
                'label' => esc_html__('Color de fondo', 'cloudari-onebox'),
                'type' => Controls_Manager::COLOR,
                'default' => '#050505',
                'selectors' => [
                    '{{WRAPPER}} .cld-hero' => '--cld-hero-bg: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'hover_zoom',
            [
                'label' => esc_html__('Zoom al pasar el ratón (%)', 'cloudari-onebox'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['%'],
                'range' => ['%' => ['min' => 100, 'max' => 120, 'step' => 0.5]],
                'default' => ['unit' => '%', 'size' => 103.5],
                'selectors' => [
                    '{{WRAPPER}} .cld-hero' => '--cld-hero-zoom: calc({{SIZE}} / 100);',
                ],
            ]
        );

        $this->end_controls_section();
    }

    private function registerArrowsStyleSection(): void
    {
        $this->start_controls_section(
            'section_style_arrows',
            [
                'label' => esc_html__('Flechas', 'cloudari-onebox'),
                'tab' => Controls_Manager::TAB_STYLE,
                'condition' => ['show_arrows' => 'yes'],
            ]
        );

        $this->add_control(
            'arrow_color',
            [
                'label' => esc_html__('Color', 'cloudari-onebox'),
                'type' => Controls_Manager::COLOR,
                'default' => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .cld-hero' => '--cld-hero-control-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'arrow_size',
            [
                'label' => esc_html__('Tamaño', 'cloudari-onebox'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => ['px' => ['min' => 32, 'max' => 96, 'step' => 1]],
                'default' => ['unit' => 'px', 'size' => 48],
                'mobile_default' => ['unit' => 'px', 'size' => 38],
                'selectors' => [
                    '{{WRAPPER}} .cld-hero' => '--cld-hero-arrow-size: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'arrow_thickness',
            [
                'label' => esc_html__('Grosor del trazo', 'cloudari-onebox'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => ['px' => ['min' => 1, 'max' => 8, 'step' => 1]],
                'default' => ['unit' => 'px', 'size' => 3],
                'selectors' => [
                    '{{WRAPPER}} .cld-hero' => '--cld-hero-arrow-thickness: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'arrow_offset',
            [
                'label' => esc_html__('Separación del borde', 'cloudari-onebox'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => ['px' => ['min' => 0, 'max' => 120, 'step' => 1]],
                'default' => ['unit' => 'px', 'size' => 24],
                'mobile_default' => ['unit' => 'px', 'size' => 12],
                'selectors' => [
                    '{{WRAPPER}} .cld-hero' => '--cld-hero-arrow-offset: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    /**
     * ==============================
     *  RENDER
     * ==============================
     */
    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $isEditMode = self::isEditMode();

        if (!CLOUDARI_ONEBOX_ENABLE_OUTPUT && !$isEditMode) {
            echo '<!-- Cloudari Hero Carousel desactivado por flag -->';
            return;
        }

        $slides = $this->collectSlides($settings);

        if ($slides === []) {
            if ($isEditMode) {
                printf(
                    '<div class="cld-hero__placeholder">%s</div>',
                    esc_html__('Añade al menos un cartel con imagen de escritorio.', 'cloudari-onebox')
                );
            }

            return;
        }

        if (($settings['weekly_rotation'] ?? '') === 'yes' && !$isEditMode) {
            $slides = self::rotateByWeek($slides);
        }

        $total = count($slides);
        $showArrows = ($settings['show_arrows'] ?? '') === 'yes' && $total > 1;
        // En el editor el autoplay estorba: el lienzo se movería mientras se ajustan los controles.
        $autoplay = ($settings['autoplay'] ?? '') === 'yes' && $total > 1 && !$isEditMode;

        $hasVideo = false;

        foreach ($slides as $slide) {
            if ($slide->hasVideo()) {
                $hasVideo = true;
                break;
            }
        }

        // Un vídeo en bucle es movimiento automático aunque el carrusel no avance
        // solo, así que también exige un control de pausa (WCAG 2.2.2). El JS lo
        // oculta si acaba no habiendo nada en movimiento.
        $showToggle = $autoplay || ($hasVideo && !$isEditMode);

        $config = [
            'autoplay' => $autoplay,
            'delay' => (int) Helpers::clampFloat($settings['autoplay_delay'] ?? 5000, 2000, 20000, 5000),
            'pauseOnHover' => ($settings['pause_on_hover'] ?? '') === 'yes',
        ];

        $ariaLabel = sanitize_text_field((string) ($settings['aria_label'] ?? ''));

        if ($ariaLabel === '') {
            $ariaLabel = __('Carrusel principal', 'cloudari-onebox');
        }

        $hasCurve = ($settings['curve_enabled'] ?? '') === 'yes';
        $rootClasses = 'cld-hero' . ($hasCurve ? '' : ' cld-hero--no-curve');
        ?>
        <div class="<?php echo esc_attr($rootClasses); ?>"
             data-cld-hero
             data-cld-hero-config="<?php echo esc_attr(wp_json_encode($config) ?: '{}'); ?>"
             role="region"
             aria-roledescription="<?php esc_attr_e('carrusel', 'cloudari-onebox'); ?>"
             aria-label="<?php echo esc_attr($ariaLabel); ?>">

            <div class="cld-hero__viewport">
                <div class="cld-hero__track" data-cld-hero-track>
                    <?php foreach ($slides as $index => $slide) {
                        $this->renderSlide($slide, $index, $total);
                    } ?>
                </div>
            </div>

            <?php if ($showArrows) { ?>
                <button class="cld-hero__arrow cld-hero__arrow--prev"
                        type="button"
                        data-cld-hero-prev
                        aria-label="<?php esc_attr_e('Cartel anterior', 'cloudari-onebox'); ?>">
                    <?php $this->renderIcon('M15.5 4 7.5 12l8 8'); ?>
                </button>
                <button class="cld-hero__arrow cld-hero__arrow--next"
                        type="button"
                        data-cld-hero-next
                        aria-label="<?php esc_attr_e('Cartel siguiente', 'cloudari-onebox'); ?>">
                    <?php $this->renderIcon('M8.5 4l8 8-8 8'); ?>
                </button>
            <?php } ?>

            <?php if ($showToggle) { ?>
                <button class="cld-hero__toggle"
                        type="button"
                        data-cld-hero-toggle
                        data-paused="false"
                        aria-label="<?php esc_attr_e('Pausar el carrusel', 'cloudari-onebox'); ?>"
                        data-label-pause="<?php esc_attr_e('Pausar el carrusel', 'cloudari-onebox'); ?>"
                        data-label-play="<?php esc_attr_e('Reanudar el carrusel', 'cloudari-onebox'); ?>">
                    <svg class="cld-hero__icon cld-hero__icon--pause"
                         viewBox="0 0 16 16"
                         aria-hidden="true"
                         focusable="false"
                         role="presentation">
                        <rect x="3" y="2" width="3.5" height="12" rx="0.75"></rect>
                        <rect x="9.5" y="2" width="3.5" height="12" rx="0.75"></rect>
                    </svg>
                    <svg class="cld-hero__icon cld-hero__icon--play"
                         viewBox="0 0 16 16"
                         aria-hidden="true"
                         focusable="false"
                         role="presentation">
                        <path d="M5 2.5 13.5 8 5 13.5Z"></path>
                    </svg>
                </button>
            <?php } ?>

            <?php $this->renderCurve($settings); ?>

            <p class="cld-hero__live" aria-live="polite" data-cld-hero-live></p>
        </div>
        <?php
    }

    private function renderSlide(HeroSlide $slide, int $index, int $total): void
    {
        $classes = ['cld-hero__slide'];

        if ($slide->itemId !== '') {
            $classes[] = 'elementor-repeater-item-' . $slide->itemId;
        }

        if ($slide->containMobile) {
            $classes[] = 'cld-hero__slide--contain-mobile';
        }

        /* translators: 1: posición del cartel, 2: total de carteles, 3: nombre del espectáculo. */
        $slideLabel = $slide->title !== ''
            ? sprintf(__('%1$d de %2$d: %3$s', 'cloudari-onebox'), $index + 1, $total, $slide->title)
            /* translators: 1: posición del cartel, 2: total de carteles. */
            : sprintf(__('%1$d de %2$d', 'cloudari-onebox'), $index + 1, $total);
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>"
             role="group"
             aria-roledescription="<?php esc_attr_e('cartel', 'cloudari-onebox'); ?>"
             aria-label="<?php echo esc_attr($slideLabel); ?>"
             data-cld-hero-slide>
            <?php if ($slide->hasLink()) { ?>
                <a class="cld-hero__media"
                   href="<?php echo esc_url($slide->linkUrl); ?>"
                   <?php if ($slide->linkNewTab) { ?>target="_blank"<?php } ?>
                   <?php if ($slide->relAttribute() !== '') { ?>rel="<?php echo esc_attr($slide->relAttribute()); ?>"<?php } ?>
                   aria-label="<?php echo esc_attr($slide->linkLabel()); ?>">
                    <?php $this->renderSlideImages($slide, $index); ?>
                </a>
            <?php } else { ?>
                <div class="cld-hero__media cld-hero__media--static">
                    <?php $this->renderSlideImages($slide, $index); ?>
                </div>
            <?php } ?>
        </div>
        <?php
    }

    private function renderSlideImages(HeroSlide $slide, int $index): void
    {
        // El backdrop desenfocado rellena las bandas que deja el "contain" en móvil.
        if ($slide->containMobile) {
            $backdropUrl = $slide->backdropImageUrl();

            if ($backdropUrl !== '') {
                $this->renderBackdrop($backdropUrl, $index);
            }
        }

        if (!$slide->hasVideo()) {
            $this->renderPicture($slide, $index);
            return;
        }

        $this->renderResponsivePair($slide, $index);
    }

    /**
     * Caso mayoritario: dos imágenes. `<picture>` deja que el navegador descargue
     * solo la que corresponde al breakpoint, así que se prefiere siempre que se pueda.
     */
    private function renderPicture(HeroSlide $slide, int $index): void
    {
        $isFirst = $index === 0;
        ?>
        <picture class="cld-hero__fg">
            <source media="(max-width: <?php echo esc_attr((string) self::MOBILE_BREAKPOINT); ?>px)"
                    srcset="<?php echo esc_url($slide->mobileUrl); ?>">
            <img class="cld-hero__img"
                 src="<?php echo esc_url($slide->desktopUrl); ?>"
                 alt="<?php echo esc_attr($slide->alt); ?>"
                 loading="<?php echo $isFirst ? 'eager' : 'lazy'; ?>"
                 decoding="<?php echo $isFirst ? 'sync' : 'async'; ?>"
                 <?php if ($isFirst) { ?>fetchpriority="high"<?php } ?>>
        </picture>
        <?php
    }

    /**
     * Con vídeo por medio no sirve `<picture>`: un `<video>` no puede convivir con
     * un `<img>` dentro del mismo elemento. Se emiten los dos medios y el CSS
     * decide cuál se ve; el que sobra lleva `preload="none"` o `loading="lazy"`,
     * así que no llega a descargarse.
     */
    private function renderResponsivePair(HeroSlide $slide, int $index): void
    {
        ?>
        <div class="cld-hero__fg">
            <?php
            $this->renderSingleMedia(
                $slide,
                $slide->desktopUrl,
                $slide->desktopIsVideo,
                'cld-hero__source--desktop',
                $index === 0
            );

            $this->renderSingleMedia(
                $slide,
                $slide->mobileUrl,
                $slide->mobileIsVideo,
                'cld-hero__source--mobile',
                false
            );
            ?>
        </div>
        <?php
    }

    private function renderSingleMedia(
        HeroSlide $slide,
        string $url,
        bool $isVideo,
        string $visibilityClass,
        bool $isPriority
    ): void {
        if (!$isVideo) {
            ?>
            <img class="cld-hero__img <?php echo esc_attr($visibilityClass); ?>"
                 src="<?php echo esc_url($url); ?>"
                 alt="<?php echo esc_attr($slide->alt); ?>"
                 loading="<?php echo $isPriority ? 'eager' : 'lazy'; ?>"
                 decoding="<?php echo $isPriority ? 'sync' : 'async'; ?>"
                 <?php if ($isPriority) { ?>fetchpriority="high"<?php } ?>>
            <?php
            return;
        }
        ?>
        <video class="cld-hero__img cld-hero__video <?php echo esc_attr($visibilityClass); ?>"
               data-cld-hero-video
               src="<?php echo esc_url($url); ?>"
               <?php if ($slide->posterUrl !== '') { ?>poster="<?php echo esc_url($slide->posterUrl); ?>"<?php } ?>
               <?php if ($slide->alt !== '') { ?>aria-label="<?php echo esc_attr($slide->alt); ?>"<?php } ?>
               preload="<?php echo $isPriority ? 'metadata' : 'none'; ?>"
               muted
               loop
               playsinline
               disablepictureinpicture></video>
        <?php
    }

    /**
     * Chevron de las flechas. Va en SVG y no en bordes rotados sobre un
     * pseudo-elemento porque los temas pisan `border` con facilidad y el icono
     * acaba descuadrado; `non-scaling-stroke` mantiene el grosor en px reales.
     */
    private function renderIcon(string $path): void
    {
        ?>
        <svg class="cld-hero__icon cld-hero__icon--chevron"
             viewBox="0 0 24 24"
             aria-hidden="true"
             focusable="false"
             role="presentation">
            <path d="<?php echo esc_attr($path); ?>" vector-effect="non-scaling-stroke"></path>
        </svg>
        <?php
    }

    private function renderBackdrop(string $url, int $index): void
    {
        ?>
        <img class="cld-hero__img cld-hero__backdrop"
             src="<?php echo esc_url($url); ?>"
             alt=""
             aria-hidden="true"
             loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>"
             decoding="async">
        <?php
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function renderCurve(array $settings): void
    {
        if (($settings['curve_enabled'] ?? '') !== 'yes') {
            return;
        }

        $path = CurveShape::path($settings['curve_depth']['size'] ?? CurveShape::DEFAULT_DEPTH);
        ?>
        <div class="cld-hero__curve" aria-hidden="true">
            <svg viewBox="<?php echo esc_attr(CurveShape::viewBox()); ?>"
                 preserveAspectRatio="none"
                 focusable="false"
                 role="presentation">
                <path class="cld-hero__curve-path" d="<?php echo esc_attr($path); ?>"></path>
            </svg>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $settings
     * @return list<HeroSlide>
     */
    private function collectSlides(array $settings): array
    {
        $raw = $settings['slides'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $slides = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $slide = HeroSlide::fromRepeaterItem($item);

            if ($slide instanceof HeroSlide) {
                $slides[] = $slide;
            }
        }

        return $slides;
    }

    /**
     * @param list<HeroSlide> $slides
     * @return list<HeroSlide>
     */
    private static function rotateByWeek(array $slides): array
    {
        $count = count($slides);

        if ($count < 2) {
            return $slides;
        }

        $offset = Helpers::isoWeekCounter() % $count;

        return array_merge(array_slice($slides, $offset), array_slice($slides, 0, $offset));
    }

    private static function isEditMode(): bool
    {
        $elementor = \Elementor\Plugin::$instance ?? null;

        return $elementor !== null
            && isset($elementor->editor)
            && $elementor->editor->is_edit_mode();
    }
}
