<?php
namespace Cloudari\Onebox\Admin;

use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Domain\Theatre\TheatreProfile;
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

        // ✅ Encolar JS solo en esta pantalla
        add_action('admin_enqueue_scripts', [static::class, 'enqueueAssets']);
    }

    /**
     * Carga assets SOLO en la página del plugin.
     */
    public static function enqueueAssets(string $hook): void
    {
        // Esta pantalla es: toplevel_page_cloudari-onebox
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

        // Guardado
        if (isset($_POST['cloudari_onebox_save'])) {
            check_admin_referer('cloudari_onebox_settings');

            $slug  = 'default';
            $label = sanitize_text_field($_POST['profile_label'] ?? 'Perfil por defecto');

            $profile = new TheatreProfile(
                $slug,
                $label,
                sanitize_text_field($_POST['channel_id'] ?? ''),
                sanitize_text_field($_POST['client_secret'] ?? ''),
                esc_url_raw($_POST['api_catalog_url'] ?? ''),
                esc_url_raw($_POST['api_auth_url'] ?? ''),
                esc_url_raw($_POST['purchase_base'] ?? ''),
                sanitize_text_field($_POST['venue_name'] ?? ''),
                sanitize_hex_color($_POST['color_primary'] ?? '#009AD8'),
                sanitize_hex_color($_POST['color_accent'] ?? '#D14100'),
                sanitize_hex_color($_POST['color_bg'] ?? '#FFFFFF'),
                sanitize_hex_color($_POST['color_text'] ?? '#000000'),
                sanitize_hex_color(
                    $_POST['color_selected_day'] ?? ($_POST['color_primary'] ?? '#009AD8')
                )
            );

            // Guardar perfil en opciones
            ProfileRepository::save($profile);

            // Resetear tokens y caché
            Auth::resetTokens();
            \Cloudari\Onebox\Rest\Routes::clearBillboardCache();

            echo '<div class="notice notice-success is-dismissible"><p>Perfil guardado correctamente. Se han refrescado las credenciales de OneBox.</p></div>';
        }

        $active = ProfileRepository::getActive();
        ?>
        <div class="wrap">
            <h1>Cloudari OneBox – Perfil de teatro</h1>
            <p>Configura aquí las credenciales de OneBox y los ajustes visuales para este teatro.</p>

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
                            <p class="description">Solo interno, para distinguir perfiles. Ej: “La Estación (producción)”.</p>
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

                <h2 class="title">Credenciales OneBox</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="channel_id">Channel ID</label></th>
                        <td>
                            <input name="channel_id" id="channel_id" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->channelId); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="cloudari_client_secret">Client Secret</label></th>
                        <td>
                            <div style="display:flex; gap:8px; align-items:center; max-width:520px;">
                                <input
                                    type="password"
                                    id="cloudari_client_secret"
                                    name="client_secret"
                                    value="<?php echo esc_attr($active->clientSecret); ?>"
                                    class="regular-text"
                                    autocomplete="off"
                                />

                                <button
                                    type="button"
                                    class="button"
                                    id="cloudari-toggle-client-secret"
                                    aria-label="Mostrar client secret"
                                >
                                    Mostrar
                                </button>
                            </div>

                            <p class="description">Credencial sensible. Ya no será necesario definirla en wp-config.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="api_catalog_url">Catálogo API URL</label></th>
                        <td>
                            <input name="api_catalog_url" id="api_catalog_url" type="url"
                                   class="regular-text code"
                                   value="<?php echo esc_url($active->apiCatalogUrl); ?>">
                            <p class="description">Normalmente: https://api.oneboxtds.com/catalog-api/v1</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="api_auth_url">Auth API URL</label></th>
                        <td>
                            <input name="api_auth_url" id="api_auth_url" type="url"
                                   class="regular-text code"
                                   value="<?php echo esc_url($active->apiAuthUrl); ?>">
                            <p class="description">Normalmente: https://oauth2.oneboxtds.com/oauth/token</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Entradas y URLs</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="purchase_base">URL base de compra</label></th>
                        <td>
                            <input name="purchase_base" id="purchase_base" type="url"
                                   class="regular-text code"
                                   value="<?php echo esc_url($active->purchaseBaseUrl); ?>">
                            <p class="description">Ejemplo: https://tickets.oneboxtds.com/laestacion/events/</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Paleta de colores</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="color_primary">Color principal</label></th>
                        <td>
                            <input name="color_primary" id="color_primary" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->colorPrimary); ?>">
                            <p class="description">Ejemplo: #009AD8 (azul del calendario).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="color_accent">Color de llamada a la acción</label></th>
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
                        <th scope="row"><label for="color_selected_day">Color fondo día seleccionado</label></th>
                        <td>
                            <input name="color_selected_day" id="color_selected_day" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->colorSelectedDay); ?>">
                            <p class="description">
                                Color de fondo para la celda activa del calendario (.celda-dia.dia-seleccionado).
                            </p>
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
                <li>Credenciales: <?php echo $active->hasCredentials() ? '✅ configuradas' : '⚠️ incompletas'; ?></li>
            </ul>
        </div>
        <?php
    }
}
