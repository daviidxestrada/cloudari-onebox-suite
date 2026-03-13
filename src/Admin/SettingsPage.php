<?php
namespace Cloudari\Onebox\Admin;

use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Domain\Theatre\TheatreProfile;
use Cloudari\Onebox\Domain\Theatre\OneboxIntegration;
use Cloudari\Onebox\Infrastructure\Onebox\Auth;

final class SettingsPage
{
    private const LEGACY_WIDGET_FALLBACKS = [
        'billboard.cta' => '#D14100',
        'billboard.card_bg' => '#FFFFFF',
        'billboard.text' => '#0B0F1A',
        'venue_filters.active_bg' => 'Transparente',
        'venue_filters.text' => '#0B0F1A',
        'countdown.panel_bg' => '#FFFFFF',
        'countdown.title' => 'Elementor Accent o #004743',
        'countdown.border' => 'Elementor Border o #E6E6E6',
        'countdown.number' => 'Heredado del tema',
        'countdown.poster_caption' => '#FFFFFF',
    ];

    public static function register(): void
    {
        add_menu_page(
            'Cloudari OneBox',
            'Cloudari OneBox',
            'manage_options',
            'cloudari-onebox',
            [static::class, 'render'],
            'dashicons-tickets',
            59
        );

        add_action('admin_enqueue_scripts', [static::class, 'enqueueAssets']);
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_cloudari-onebox') {
            return;
        }

        wp_enqueue_script(
            'cloudari-onebox-admin-settings',
            CLOUDARI_ONEBOX_URL . 'assets/js/admin-settings.js',
            [],
            CLOUDARI_ONEBOX_VER,
            true
        );
    }

    private static function sanitizeWidgetColors($raw): array
    {
        $normalized = [];

        if (!is_array($raw)) {
            return $normalized;
        }

        foreach ($raw as $widget => $tokens) {
            $widgetKey = sanitize_key((string) $widget);
            if ($widgetKey === '' || !is_array($tokens)) {
                continue;
            }

            foreach ($tokens as $token => $value) {
                $tokenKey = sanitize_key((string) $token);
                if ($tokenKey === '') {
                    continue;
                }

                $sanitized = sanitize_hex_color(is_string($value) ? $value : '');
                $normalized[$widgetKey][$tokenKey] = is_string($sanitized) ? $sanitized : '';
            }
        }

        return $normalized;
    }

    private static function getWidgetFallbackValue(TheatreProfile $profile, string $widgetKey, string $fieldKey): string
    {
        $mapKey = $widgetKey . '.' . $fieldKey;

        if (isset(self::LEGACY_WIDGET_FALLBACKS[$mapKey])) {
            return self::LEGACY_WIDGET_FALLBACKS[$mapKey];
        }

        return match ($mapKey) {
            'calendar.nav' => $profile->colorPrimary,
            'calendar.text' => $profile->colorText,
            'billboard.topbar' => $profile->colorPrimary,
            'venue_filters.active_text' => $profile->colorAccent,
            'venue_filters.border' => $profile->colorPrimary,
            'venue_filters.indicator' => $profile->colorAccent,
            default => '',
        };
    }

    private static function getWidgetCurrentValue(TheatreProfile $profile, string $widgetKey, string $fieldKey): string
    {
        $override = $profile->getWidgetColor($widgetKey, $fieldKey);
        if ($override !== '') {
            return $override;
        }

        return self::getWidgetFallbackValue($profile, $widgetKey, $fieldKey);
    }

    private static function getCurrentPreviewColor(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/#[0-9A-Fa-f]{6}/', $value, $matches) === 1) {
            return strtoupper($matches[0]);
        }

        if (stripos($value, 'transpar') !== false) {
            return 'transparent';
        }

        return '';
    }

    private static function getWidgetColorSections(TheatreProfile $profile): array
    {
        return [
            'calendar' => [
                'title' => 'Calendario',
                'description' => 'Ajustes especificos del calendario. El dia seleccionado sigue teniendo su propio color base.',
                'legacy_fields' => [
                    [
                        'name' => 'color_selected_day',
                        'label' => 'Color de dia seleccionado',
                        'value' => $profile->colorSelectedDay,
                        'current' => $profile->colorSelectedDay,
                        'description' => 'Color base del dia activo en el calendario.',
                        'placeholder' => '',
                    ],
                ],
                'fields' => [
                    'nav' => [
                        'label' => 'Color de flecha',
                        'inherit' => self::getWidgetFallbackValue($profile, 'calendar', 'nav'),
                        'current' => self::getWidgetCurrentValue($profile, 'calendar', 'nav'),
                    ],
                    'text' => [
                        'label' => 'Color de texto',
                        'inherit' => self::getWidgetFallbackValue($profile, 'calendar', 'text'),
                        'current' => self::getWidgetCurrentValue($profile, 'calendar', 'text'),
                    ],
                ],
            ],
            'billboard' => [
                'title' => 'Cartelera',
                'description' => 'Overrides para las cards y el CTA de la cartelera.',
                'fields' => [
                    'topbar' => [
                        'label' => 'Color barra superior',
                        'inherit' => self::getWidgetFallbackValue($profile, 'billboard', 'topbar'),
                        'current' => self::getWidgetCurrentValue($profile, 'billboard', 'topbar'),
                    ],
                    'cta' => [
                        'label' => 'Color boton CTA',
                        'inherit' => self::getWidgetFallbackValue($profile, 'billboard', 'cta'),
                        'current' => self::getWidgetCurrentValue($profile, 'billboard', 'cta'),
                    ],
                    'card_bg' => [
                        'label' => 'Color fondo tarjeta',
                        'inherit' => self::getWidgetFallbackValue($profile, 'billboard', 'card_bg'),
                        'current' => self::getWidgetCurrentValue($profile, 'billboard', 'card_bg'),
                    ],
                    'text' => [
                        'label' => 'Color texto tarjeta',
                        'inherit' => self::getWidgetFallbackValue($profile, 'billboard', 'text'),
                        'current' => self::getWidgetCurrentValue($profile, 'billboard', 'text'),
                    ],
                ],
            ],
            'venue_filters' => [
                'title' => 'Sistema de filtros por espacios',
                'description' => 'Colores de las tabs/filtros que cambian de espacio en la cartelera por venues.',
                'fields' => [
                    'active_bg' => [
                        'label' => 'Color fondo tab activa',
                        'inherit' => self::getWidgetFallbackValue($profile, 'venue_filters', 'active_bg'),
                        'current' => self::getWidgetCurrentValue($profile, 'venue_filters', 'active_bg'),
                    ],
                    'active_text' => [
                        'label' => 'Color texto tab activa',
                        'inherit' => self::getWidgetFallbackValue($profile, 'venue_filters', 'active_text'),
                        'current' => self::getWidgetCurrentValue($profile, 'venue_filters', 'active_text'),
                    ],
                    'text' => [
                        'label' => 'Color texto tabs inactivas',
                        'inherit' => self::getWidgetFallbackValue($profile, 'venue_filters', 'text'),
                        'current' => self::getWidgetCurrentValue($profile, 'venue_filters', 'text'),
                    ],
                    'border' => [
                        'label' => 'Color linea base',
                        'inherit' => self::getWidgetFallbackValue($profile, 'venue_filters', 'border'),
                        'current' => self::getWidgetCurrentValue($profile, 'venue_filters', 'border'),
                    ],
                    'indicator' => [
                        'label' => 'Color indicador activo',
                        'inherit' => self::getWidgetFallbackValue($profile, 'venue_filters', 'indicator'),
                        'current' => self::getWidgetCurrentValue($profile, 'venue_filters', 'indicator'),
                    ],
                ],
            ],
            'countdown' => [
                'title' => 'Contador',
                'description' => 'Colores de fondo, titulos y cajas del widget countdown.',
                'fields' => [
                    'panel_bg' => [
                        'label' => 'Color fondo panel',
                        'inherit' => self::getWidgetFallbackValue($profile, 'countdown', 'panel_bg'),
                        'current' => self::getWidgetCurrentValue($profile, 'countdown', 'panel_bg'),
                    ],
                    'title' => [
                        'label' => 'Color titulo',
                        'inherit' => self::getWidgetFallbackValue($profile, 'countdown', 'title'),
                        'current' => self::getWidgetCurrentValue($profile, 'countdown', 'title'),
                    ],
                    'border' => [
                        'label' => 'Color borde',
                        'inherit' => self::getWidgetFallbackValue($profile, 'countdown', 'border'),
                        'current' => self::getWidgetCurrentValue($profile, 'countdown', 'border'),
                    ],
                    'number' => [
                        'label' => 'Color numeros',
                        'inherit' => self::getWidgetFallbackValue($profile, 'countdown', 'number'),
                        'current' => self::getWidgetCurrentValue($profile, 'countdown', 'number'),
                    ],
                    'poster_caption' => [
                        'label' => 'Color texto poster',
                        'inherit' => self::getWidgetFallbackValue($profile, 'countdown', 'poster_caption'),
                        'current' => self::getWidgetCurrentValue($profile, 'countdown', 'poster_caption'),
                    ],
                ],
            ],
        ];
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active = ProfileRepository::getActive();

        if (isset($_POST['cloudari_onebox_save'])) {
            check_admin_referer('cloudari_onebox_settings');

            $slug  = 'default';
            $label = sanitize_text_field($_POST['profile_label'] ?? 'Perfil principal');

            $venueName = sanitize_text_field($_POST['venue_name'] ?? '');

            $colorPrimary = sanitize_hex_color($_POST['color_primary'] ?? '#009AD8');
            $colorAccent  = sanitize_hex_color($_POST['color_accent'] ?? '#D14100');
            $colorBg      = sanitize_hex_color($_POST['color_bg'] ?? '#FFFFFF');
            $colorText    = sanitize_hex_color($_POST['color_text'] ?? '#000000');
            $colorSelectedDay = sanitize_hex_color(
                $_POST['color_selected_day'] ?? ($colorPrimary ?: '#009AD8')
            );
            $widgetColors = self::sanitizeWidgetColors($_POST['widget_colors'] ?? []);

            $rawIntegrations = $_POST['integrations'] ?? [];
            $integrations = [];
            $idx = 0;

            if (is_array($rawIntegrations)) {
                foreach ($rawIntegrations as $key => $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $intSlug = sanitize_key($row['slug'] ?? $key);
                    if ($intSlug === '') {
                        $intSlug = 'integration_' . $idx;
                    }

                    $existing = $active->getIntegration($intSlug);

                    $intLabel = sanitize_text_field($row['label'] ?? '');
                    if ($intLabel === '') {
                        $intLabel = 'OneBox';
                    }

                    $channelId = sanitize_text_field($row['channel_id'] ?? '');
                    if ($channelId === '' && $existing instanceof OneboxIntegration) {
                        $channelId = $existing->channelId;
                    }

                    $clientSecret = sanitize_text_field($row['client_secret'] ?? '');
                    if ($clientSecret === '' && $existing instanceof OneboxIntegration) {
                        $clientSecret = $existing->clientSecret;
                    }

                    $apiCatalog = esc_url_raw($row['api_catalog_url'] ?? '');
                    if ($apiCatalog === '') {
                        $apiCatalog = $existing instanceof OneboxIntegration
                            ? $existing->apiCatalogUrl
                            : 'https://api.oneboxtds.com/catalog-api/v1';
                    }

                    $apiAuth = esc_url_raw($row['api_auth_url'] ?? '');
                    if ($apiAuth === '') {
                        $apiAuth = $existing instanceof OneboxIntegration
                            ? $existing->apiAuthUrl
                            : 'https://oauth2.oneboxtds.com/oauth/token';
                    }

                    $purchaseBase = esc_url_raw($row['purchase_base'] ?? '');
                    if ($purchaseBase === '' && $existing instanceof OneboxIntegration) {
                        $purchaseBase = $existing->purchaseBaseUrl;
                    }

                    $integrations[$intSlug] = new OneboxIntegration(
                        $intSlug,
                        $intLabel,
                        $channelId,
                        $clientSecret,
                        $apiCatalog,
                        $apiAuth,
                        $purchaseBase
                    );

                    $idx++;
                }
            }

            if (empty($integrations)) {
                $integrations['default'] = new OneboxIntegration(
                    'default',
                    'OneBox',
                    '',
                    '',
                    'https://api.oneboxtds.com/catalog-api/v1',
                    'https://oauth2.oneboxtds.com/oauth/token',
                    ''
                );
            }

            $defaultIntegration = sanitize_key($_POST['default_integration'] ?? '');
            if ($defaultIntegration === '' || !isset($integrations[$defaultIntegration])) {
                $defaultIntegration = (string) array_key_first($integrations);
            }

            $profile = new TheatreProfile(
                $slug,
                $label,
                $venueName,
                $colorPrimary ?: '#009AD8',
                $colorAccent ?: '#D14100',
                $colorBg ?: '#FFFFFF',
                $colorText ?: '#000000',
                $colorSelectedDay ?: ($colorPrimary ?: '#009AD8'),
                $integrations,
                $defaultIntegration,
                $widgetColors
            );

            ProfileRepository::save($profile);

            Auth::resetTokens();
            \Cloudari\Onebox\Rest\Routes::clearBillboardCache();

            echo '<div class="notice notice-success is-dismissible"><p>Perfil guardado correctamente. Se han refrescado las credenciales de OneBox.</p></div>';
        }

        $integrations = $active->getIntegrations();
        $defaultIntegration = $active->defaultIntegrationSlug;
        if ($defaultIntegration === '' || !isset($integrations[$defaultIntegration])) {
            $defaultIntegration = (string) array_key_first($integrations);
        }
        $widgetColors = $active->getWidgetColors();
        $widgetSections = self::getWidgetColorSections($active);
        ?>
        <div class="wrap">
            <h1>Cloudari OneBox - Perfil MAIN</h1>
            <p>Configura aqui los datos del teatro y las integraciones OneBox.</p>

            <form method="post" action="">
                <?php wp_nonce_field('cloudari_onebox_settings'); ?>

                <h2 class="title">Datos del teatro</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="profile_label">Nombre del perfil</label></th>
                        <td>
                            <input name="profile_label" id="profile_label" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->label); ?>">
                            <p class="description">Solo interno, para distinguir perfiles.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="venue_name">Nombre del teatro</label></th>
                        <td>
                            <input name="venue_name" id="venue_name" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->venueName); ?>">
                            <p class="description">Se usa como texto por defecto en eventos manuales.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Integraciones OneBox</h2>
                <p class="description">Puedes tener varias integraciones activas dentro del mismo calendario/cartelera.</p>

                <div id="cloudari-integrations">
                    <?php if (!empty($integrations)) : ?>
                        <?php foreach ($integrations as $key => $integration) : ?>
                            <?php
                                if (!$integration instanceof OneboxIntegration) {
                                    continue;
                                }
                                $safeKey = sanitize_key((string)$key);
                                $secretId = 'cloudari_client_secret_' . $safeKey;
                                $isDefault = ($defaultIntegration === $integration->slug);
                            ?>
                            <div class="cloudari-integration" data-integration>
                                <div class="cloudari-integration-header">
                                    <strong>Integracion OneBox</strong>
                                    <label style="margin-left:12px;">
                                        <input type="radio" name="default_integration" value="<?php echo esc_attr($integration->slug); ?>" <?php checked($isDefault); ?>>
                                        Usar como default
                                    </label>
                                    <button type="button" class="button-link-delete" data-remove-integration style="margin-left:auto;">Eliminar</button>
                                </div>

                                <input type="hidden" name="integrations[<?php echo esc_attr($integration->slug); ?>][slug]" value="<?php echo esc_attr($integration->slug); ?>">

                                <table class="form-table cloudari-integration-table" role="presentation">
                                    <tr>
                                        <th scope="row"><label>Nombre (label)</label></th>
                                        <td>
                                            <input name="integrations[<?php echo esc_attr($integration->slug); ?>][label]" type="text"
                                                   class="regular-text"
                                                   value="<?php echo esc_attr($integration->label); ?>">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label>Channel ID</label></th>
                                        <td>
                                            <input name="integrations[<?php echo esc_attr($integration->slug); ?>][channel_id]" type="text"
                                                   class="regular-text"
                                                   value="<?php echo esc_attr($integration->channelId); ?>">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="<?php echo esc_attr($secretId); ?>">Client Secret</label></th>
                                        <td>
                                            <div style="display:flex; gap:8px; align-items:center; max-width:520px;">
                                                <input
                                                    type="password"
                                                    id="<?php echo esc_attr($secretId); ?>"
                                                    name="integrations[<?php echo esc_attr($integration->slug); ?>][client_secret]"
                                                    value="<?php echo esc_attr($integration->clientSecret); ?>"
                                                    class="regular-text"
                                                    autocomplete="off"
                                                    data-secret-input
                                                />
                                                <button
                                                    type="button"
                                                    class="button"
                                                    data-toggle-secret
                                                    aria-label="Mostrar client secret"
                                                >
                                                    Mostrar
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label>Catalog API URL</label></th>
                                        <td>
                                            <input name="integrations[<?php echo esc_attr($integration->slug); ?>][api_catalog_url]" type="url"
                                                   class="regular-text code"
                                                   value="<?php echo esc_url($integration->apiCatalogUrl); ?>">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label>Auth API URL</label></th>
                                        <td>
                                            <input name="integrations[<?php echo esc_attr($integration->slug); ?>][api_auth_url]" type="url"
                                                   class="regular-text code"
                                                   value="<?php echo esc_url($integration->apiAuthUrl); ?>">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label>URL base de compra</label></th>
                                        <td>
                                            <input name="integrations[<?php echo esc_attr($integration->slug); ?>][purchase_base]" type="url"
                                                   class="regular-text code"
                                                   value="<?php echo esc_url($integration->purchaseBaseUrl); ?>">
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p>
                    <button type="button" class="button" id="cloudari-add-integration">Anadir integracion</button>
                </p>

                <h2 class="title">Paleta global</h2>
                <p class="description">Estos colores actuan como base compartida para todos los widgets. Debajo puedes sobrescribirlos por widget.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="color_primary">Color principal</label></th>
                        <td>
                            <input name="color_primary" id="color_primary" type="text"
                                   class="regular-text code"
                                   value="<?php echo esc_attr($active->colorPrimary); ?>">
                            <p class="description cloudari-color-current">
                                <span>Color actual: <code><?php echo esc_html($active->colorPrimary); ?></code></span>
                                <span class="cloudari-color-chip" style="--cloudari-chip-color: <?php echo esc_attr(self::getCurrentPreviewColor($active->colorPrimary)); ?>"></span>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="color_accent">Color de llamada a la accion</label></th>
                        <td>
                            <input name="color_accent" id="color_accent" type="text"
                                   class="regular-text code"
                                   value="<?php echo esc_attr($active->colorAccent); ?>">
                            <p class="description cloudari-color-current">
                                <span>Color actual: <code><?php echo esc_html($active->colorAccent); ?></code></span>
                                <span class="cloudari-color-chip" style="--cloudari-chip-color: <?php echo esc_attr(self::getCurrentPreviewColor($active->colorAccent)); ?>"></span>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="color_bg">Fondo widgets</label></th>
                        <td>
                            <input name="color_bg" id="color_bg" type="text"
                                   class="regular-text code"
                                   value="<?php echo esc_attr($active->colorBackground); ?>">
                            <p class="description cloudari-color-current">
                                <span>Color actual: <code><?php echo esc_html($active->colorBackground); ?></code></span>
                                <span class="cloudari-color-chip" style="--cloudari-chip-color: <?php echo esc_attr(self::getCurrentPreviewColor($active->colorBackground)); ?>"></span>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="color_text">Color texto</label></th>
                        <td>
                            <input name="color_text" id="color_text" type="text"
                                   class="regular-text code"
                                   value="<?php echo esc_attr($active->colorText); ?>">
                            <p class="description cloudari-color-current">
                                <span>Color actual: <code><?php echo esc_html($active->colorText); ?></code></span>
                                <span class="cloudari-color-chip" style="--cloudari-chip-color: <?php echo esc_attr(self::getCurrentPreviewColor($active->colorText)); ?>"></span>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Colores por widget</h2>
                <p class="description">Si dejas un campo vacio, el widget hereda la paleta global o el fallback indicado.</p>

                <div class="cloudari-widget-sections">
                    <?php foreach ($widgetSections as $widgetKey => $section) : ?>
                        <section class="cloudari-widget-card">
                            <h3><?php echo esc_html($section['title']); ?></h3>
                            <p class="description"><?php echo esc_html($section['description']); ?></p>

                            <table class="form-table" role="presentation">
                                <?php foreach (($section['legacy_fields'] ?? []) as $field) : ?>
                                    <?php $legacyPreview = self::getCurrentPreviewColor((string) ($field['current'] ?? '')); ?>
                                    <tr>
                                        <th scope="row">
                                            <label for="<?php echo esc_attr($field['name']); ?>">
                                                <?php echo esc_html($field['label']); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <input name="<?php echo esc_attr($field['name']); ?>"
                                                   id="<?php echo esc_attr($field['name']); ?>"
                                                   type="text"
                                                   class="regular-text code"
                                                   value="<?php echo esc_attr($field['value']); ?>"
                                                   placeholder="<?php echo esc_attr($field['placeholder']); ?>">
                                            <?php if (!empty($field['description'])) : ?>
                                                <p class="description"><?php echo esc_html($field['description']); ?></p>
                                            <?php endif; ?>
                                            <p class="description cloudari-color-current">
                                                <span>Color actual: <code><?php echo esc_html((string) ($field['current'] ?? '')); ?></code></span>
                                                <?php if ($legacyPreview !== '') : ?>
                                                    <span class="cloudari-color-chip" style="--cloudari-chip-color: <?php echo esc_attr($legacyPreview); ?>"></span>
                                                <?php endif; ?>
                                            </p>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php foreach ($section['fields'] as $fieldKey => $field) : ?>
                                    <?php
                                        $inputId = 'widget_colors_' . $widgetKey . '_' . $fieldKey;
                                        $inputName = sprintf('widget_colors[%s][%s]', $widgetKey, $fieldKey);
                                        $value = $widgetColors[$widgetKey][$fieldKey] ?? '';
                                        $inherit = (string) ($field['inherit'] ?? '');
                                        $current = (string) ($field['current'] ?? $inherit);
                                        $preview = self::getCurrentPreviewColor($current);
                                    ?>
                                    <tr>
                                        <th scope="row">
                                            <label for="<?php echo esc_attr($inputId); ?>">
                                                <?php echo esc_html($field['label']); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <input name="<?php echo esc_attr($inputName); ?>"
                                                   id="<?php echo esc_attr($inputId); ?>"
                                                   type="text"
                                                   class="regular-text code"
                                                   value="<?php echo esc_attr($value); ?>"
                                                   placeholder="<?php echo esc_attr($inherit); ?>">
                                            <p class="description">
                                                Vacio = hereda <?php echo esc_html($inherit); ?>
                                            </p>
                                            <p class="description cloudari-color-current">
                                                <span>Color actual: <code><?php echo esc_html($current); ?></code></span>
                                                <?php if ($preview !== '') : ?>
                                                    <span class="cloudari-color-chip" style="--cloudari-chip-color: <?php echo esc_attr($preview); ?>"></span>
                                                <?php endif; ?>
                                            </p>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </section>
                    <?php endforeach; ?>
                </div>

                <p class="submit">
                    <button type="submit" name="cloudari_onebox_save" class="button button-primary">
                        Guardar perfil
                    </button>
                </p>
            </form>

            <hr>
            <p><strong>Estado actual:</strong></p>
            <ul>
                <li>Perfil activo: <code><?php echo esc_html($active->slug); ?></code> (<?php echo esc_html($active->label); ?>)</li>
                <li>Integraciones: <?php echo count($integrations); ?></li>
            </ul>
        </div>

        <template id="cloudari-integration-template">
            <div class="cloudari-integration" data-integration>
                <div class="cloudari-integration-header">
                    <strong>Integracion OneBox</strong>
                    <label style="margin-left:12px;">
                        <input type="radio" name="default_integration" value="__KEY__">
                        Usar como default
                    </label>
                    <button type="button" class="button-link-delete" data-remove-integration style="margin-left:auto;">Eliminar</button>
                </div>

                <input type="hidden" name="integrations[__KEY__][slug]" value="__KEY__">

                <table class="form-table cloudari-integration-table" role="presentation">
                    <tr>
                        <th scope="row"><label>Nombre (label)</label></th>
                        <td>
                            <input name="integrations[__KEY__][label]" type="text"
                                   class="regular-text"
                                   value="OneBox">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Channel ID</label></th>
                        <td>
                            <input name="integrations[__KEY__][channel_id]" type="text"
                                   class="regular-text"
                                   value="">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cloudari_client_secret___KEY__">Client Secret</label></th>
                        <td>
                            <div style="display:flex; gap:8px; align-items:center; max-width:520px;">
                                <input
                                    type="password"
                                    id="cloudari_client_secret___KEY__"
                                    name="integrations[__KEY__][client_secret]"
                                    value=""
                                    class="regular-text"
                                    autocomplete="off"
                                    data-secret-input
                                />
                                <button
                                    type="button"
                                    class="button"
                                    data-toggle-secret
                                    aria-label="Mostrar client secret"
                                >
                                    Mostrar
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Catalog API URL</label></th>
                        <td>
                            <input name="integrations[__KEY__][api_catalog_url]" type="url"
                                   class="regular-text code"
                                   value="https://api.oneboxtds.com/catalog-api/v1">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Auth API URL</label></th>
                        <td>
                            <input name="integrations[__KEY__][api_auth_url]" type="url"
                                   class="regular-text code"
                                   value="https://oauth2.oneboxtds.com/oauth/token">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>URL base de compra</label></th>
                        <td>
                            <input name="integrations[__KEY__][purchase_base]" type="url"
                                   class="regular-text code"
                                   value="">
                        </td>
                    </tr>
                </table>
            </div>
        </template>

        <style>
            .cloudari-integration {
                border: 1px solid #ccd0d4;
                padding: 12px 16px;
                margin: 12px 0;
                background: #fff;
            }
            .cloudari-integration-header {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 8px;
            }
            .cloudari-integration-table {
                margin-top: 0;
            }
            .cloudari-widget-sections {
                display: grid;
                gap: 16px;
                margin-top: 12px;
            }
            .cloudari-widget-card {
                border: 1px solid #ccd0d4;
                border-radius: 8px;
                padding: 16px;
                background: #fff;
            }
            .cloudari-widget-card h3 {
                margin: 0 0 6px;
            }
            .cloudari-widget-card .form-table {
                margin-top: 8px;
            }
            .cloudari-color-current {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                margin-top: 6px;
            }
            .cloudari-color-chip {
                width: 16px;
                height: 16px;
                border-radius: 999px;
                border: 1px solid #ccd0d4;
                background:
                    linear-gradient(45deg, #f1f1f1 25%, transparent 25%, transparent 75%, #f1f1f1 75%, #f1f1f1),
                    linear-gradient(45deg, #f1f1f1 25%, transparent 25%, transparent 75%, #f1f1f1 75%, #f1f1f1);
                background-position: 0 0, 4px 4px;
                background-size: 8px 8px;
                position: relative;
                overflow: hidden;
            }
            .cloudari-color-chip::after {
                content: "";
                position: absolute;
                inset: 0;
                background: var(--cloudari-chip-color, transparent);
            }
        </style>
        <?php
    }
}
