<?php
namespace Cloudari\Onebox\Admin;

use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Domain\Theatre\TheatreProfile;
use Cloudari\Onebox\Domain\Theatre\OneboxIntegration;
use Cloudari\Onebox\Infrastructure\Onebox\Auth;

final class SettingsPage
{
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
                $defaultIntegration
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

                <h2 class="title">Paleta de colores</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="color_primary">Color principal</label></th>
                        <td>
                            <input name="color_primary" id="color_primary" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->colorPrimary); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="color_accent">Color de llamada a la accion</label></th>
                        <td>
                            <input name="color_accent" id="color_accent" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->colorAccent); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="color_bg">Fondo widgets</label></th>
                        <td>
                            <input name="color_bg" id="color_bg" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->colorBackground); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="color_text">Color texto</label></th>
                        <td>
                            <input name="color_text" id="color_text" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->colorText); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="color_selected_day">Color fondo dia seleccionado</label></th>
                        <td>
                            <input name="color_selected_day" id="color_selected_day" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->colorSelectedDay); ?>">
                        </td>
                    </tr>
                </table>

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
        </style>
        <?php
    }
}
