<?php

namespace Cloudari\Onebox\Admin;

use Cloudari\Onebox\Domain\Hero\WeeklyHeroRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class WeeklyHeroPage
{
    private const PAGE_SLUG = 'cloudari-onebox-weekly-hero';

    public static function registerMenu(): void
    {
        add_submenu_page(
            'cloudari-onebox',
            'Hero semanal',
            'Hero semanal',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos suficientes para acceder a esta pagina.', 'cloudari-onebox'));
        }

        self::handlePost();

        $slides = WeeklyHeroRepository::all(true);
        $slides[] = [
            'enabled' => true,
            'title' => '',
            'url' => '',
            'desktop_image' => '',
            'mobile_image' => '',
            'alt' => '',
        ];

        ?>
        <div class="wrap cloudari-weekly-hero-admin">
            <h1>Hero semanal</h1>
            <p>
                Usa el shortcode <code>[cloudari_weekly_hero]</code> en Elementor. El plugin ordena los slides en PHP
                segun la semana actual, asi el primer slide ya llega correcto en el HTML.
            </p>

            <?php settings_errors('cloudari_weekly_hero'); ?>

            <form method="post" action="">
                <?php wp_nonce_field('cloudari_weekly_hero_save', 'cloudari_weekly_hero_nonce'); ?>
                <input type="hidden" name="cloudari_weekly_hero_action" value="save">

                <div class="cloudari-weekly-hero-list">
                    <?php foreach ($slides as $index => $slide) : ?>
                        <?php self::renderSlideFields($slide, $index); ?>
                    <?php endforeach; ?>
                </div>

                <?php submit_button('Guardar hero semanal'); ?>
            </form>
        </div>

        <?php self::renderInlineStyles(); ?>
        <?php
    }

    private static function handlePost(): void
    {
        if (($_POST['cloudari_weekly_hero_action'] ?? '') !== 'save') {
            return;
        }

        check_admin_referer('cloudari_weekly_hero_save', 'cloudari_weekly_hero_nonce');

        $slides = isset($_POST['slides']) && is_array($_POST['slides'])
            ? $_POST['slides']
            : [];

        WeeklyHeroRepository::save($slides);

        add_settings_error(
            'cloudari_weekly_hero',
            'cloudari_weekly_hero_saved',
            'Hero semanal guardado correctamente.',
            'updated'
        );
    }

    private static function renderSlideFields(array $slide, int $index): void
    {
        $fieldBase = 'slides[' . $index . ']';
        ?>
        <section class="cloudari-weekly-hero-card">
            <div class="cloudari-weekly-hero-card__head">
                <h2>Slide <?php echo esc_html((string) ($index + 1)); ?></h2>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr($fieldBase); ?>[enabled]" value="1" <?php checked(!empty($slide['enabled'])); ?>>
                    Activo
                </label>
            </div>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="cloudari_hero_title_<?php echo esc_attr((string) $index); ?>">Titulo</label></th>
                    <td>
                        <input
                            id="cloudari_hero_title_<?php echo esc_attr((string) $index); ?>"
                            name="<?php echo esc_attr($fieldBase); ?>[title]"
                            type="text"
                            class="regular-text"
                            value="<?php echo esc_attr((string) ($slide['title'] ?? '')); ?>"
                        >
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cloudari_hero_url_<?php echo esc_attr((string) $index); ?>">URL entrada</label></th>
                    <td>
                        <input
                            id="cloudari_hero_url_<?php echo esc_attr((string) $index); ?>"
                            name="<?php echo esc_attr($fieldBase); ?>[url]"
                            type="url"
                            class="large-text code"
                            value="<?php echo esc_attr((string) ($slide['url'] ?? '')); ?>"
                        >
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cloudari_hero_desktop_<?php echo esc_attr((string) $index); ?>">Imagen desktop</label></th>
                    <td>
                        <input
                            id="cloudari_hero_desktop_<?php echo esc_attr((string) $index); ?>"
                            name="<?php echo esc_attr($fieldBase); ?>[desktop_image]"
                            type="url"
                            class="large-text code"
                            value="<?php echo esc_attr((string) ($slide['desktop_image'] ?? '')); ?>"
                        >
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cloudari_hero_mobile_<?php echo esc_attr((string) $index); ?>">Imagen movil</label></th>
                    <td>
                        <input
                            id="cloudari_hero_mobile_<?php echo esc_attr((string) $index); ?>"
                            name="<?php echo esc_attr($fieldBase); ?>[mobile_image]"
                            type="url"
                            class="large-text code"
                            value="<?php echo esc_attr((string) ($slide['mobile_image'] ?? '')); ?>"
                        >
                        <p class="description">Si se deja vacia, se usa la imagen desktop.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cloudari_hero_alt_<?php echo esc_attr((string) $index); ?>">Texto alt</label></th>
                    <td>
                        <input
                            id="cloudari_hero_alt_<?php echo esc_attr((string) $index); ?>"
                            name="<?php echo esc_attr($fieldBase); ?>[alt]"
                            type="text"
                            class="regular-text"
                            value="<?php echo esc_attr((string) ($slide['alt'] ?? '')); ?>"
                        >
                    </td>
                </tr>
            </table>
        </section>
        <?php
    }

    private static function renderInlineStyles(): void
    {
        ?>
        <style>
            .cloudari-weekly-hero-list {
                display: grid;
                gap: 16px;
                max-width: 980px;
            }
            .cloudari-weekly-hero-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 8px;
                padding: 16px;
            }
            .cloudari-weekly-hero-card__head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }
            .cloudari-weekly-hero-card h2 {
                margin: 0;
            }
            .cloudari-weekly-hero-card .form-table {
                margin-top: 8px;
            }
        </style>
        <?php
    }
}
