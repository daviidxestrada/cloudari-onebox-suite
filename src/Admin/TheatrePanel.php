<?php
namespace Cloudari\Onebox\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Panel del teatro: una página de wp-admin para el personal del teatro, con el
 * sistema visual de Abonoteatro, desde la que se gestionan los eventos manuales
 * y los carteles del Hero Carrusel sin pasar por Elementor.
 *
 * La página solo pinta el armazón; el contenido lo monta `theatre-panel.js`
 * contra la API `cloudari/v1/panel` (ver `Rest\PanelRoutes`).
 */
final class TheatrePanel
{
    public const PAGE_SLUG = 'cloudari-panel';
    public const CAPABILITY = 'edit_posts';

    private const HOOK_SUFFIX = 'toplevel_page_' . self::PAGE_SLUG;
    private const ASSET_HANDLE = 'cloudari-theatre-panel';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
        add_filter('login_redirect', [self::class, 'loginRedirect'], 10, 3);
    }

    public static function registerMenu(): void
    {
        add_menu_page(
            __('Panel del teatro', 'cloudari-onebox'),
            __('Panel del teatro', 'cloudari-onebox'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'render'],
            'dashicons-format-gallery',
            2
        );
    }

    public static function url(string $section = ''): string
    {
        $url = admin_url('admin.php?page=' . self::PAGE_SLUG);

        return $section === '' ? $url : $url . '#' . $section;
    }

    /**
     * Quien no administra el sitio (rol Editor) entra directamente al panel.
     * Solo se cambia el destino por defecto: si el login venía de otra pantalla, se respeta.
     *
     * Sin tipos escalares: `wp-login.php` y otros plugins pueden pasar arrays,
     * null o menos argumentos, y un TypeError aquí impediría iniciar sesión.
     *
     * @param mixed $redirectTo
     * @param mixed $requested
     * @param \WP_User|\WP_Error|null $user
     * @return mixed
     */
    public static function loginRedirect($redirectTo, $requested = '', $user = null)
    {
        if (!$user instanceof \WP_User || !$user->has_cap(self::CAPABILITY) || $user->has_cap('manage_options')) {
            return $redirectTo;
        }

        $requested = is_string($requested) ? $requested : '';
        $isDefault = $requested === '' || untrailingslashit($requested) === untrailingslashit(admin_url());

        return $isDefault ? self::url() : $redirectTo;
    }

    public static function bodyClass(string $classes): string
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || $screen->id !== self::HOOK_SUFFIX) {
            return $classes;
        }

        // El menú de WordPress se pliega a iconos para que la barra del panel mande.
        return $classes . ' folded cld-panel-screen';
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== self::HOOK_SUFFIX) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            self::ASSET_HANDLE,
            CLOUDARI_ONEBOX_URL . 'assets/css/theatre-panel.css',
            [],
            self::assetVersion('assets/css/theatre-panel.css')
        );

        wp_enqueue_script(
            self::ASSET_HANDLE,
            CLOUDARI_ONEBOX_URL . 'assets/js/theatre-panel.js',
            ['wp-api-fetch'],
            self::assetVersion('assets/js/theatre-panel.js'),
            true
        );

        wp_localize_script(self::ASSET_HANDLE, 'cloudariPanel', [
            'restPath'   => '/cloudari/v1/panel',
            'siteUrl'    => home_url('/'),
            'postEditUrl' => admin_url('post.php'),
            'canClassic' => current_user_can('manage_options'),
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('No tienes permisos suficientes para acceder a esta página.', 'cloudari-onebox'));
        }

        $siteName = get_bloginfo('name') ?: __('Tu teatro', 'cloudari-onebox');
        $user = wp_get_current_user();
        ?>
        <div class="cld-panel" data-cld-panel>
            <aside class="cld-panel__rail">
                <p class="cld-panel__brand">
                    <span class="cld-panel__brand-label"><?php esc_html_e('Panel del teatro', 'cloudari-onebox'); ?></span>
                    <span class="cld-panel__brand-name"><?php echo esc_html($siteName); ?></span>
                </p>
                <nav class="cld-panel__nav" aria-label="<?php esc_attr_e('Secciones del panel', 'cloudari-onebox'); ?>">
                    <a class="cld-panel__nav-link" href="#carteles" data-cld-nav="heroes">
                        <?php esc_html_e('Carteles de portada', 'cloudari-onebox'); ?>
                    </a>
                    <a class="cld-panel__nav-link" href="#eventos" data-cld-nav="events">
                        <?php esc_html_e('Eventos manuales', 'cloudari-onebox'); ?>
                    </a>
                </nav>
                <div class="cld-panel__rail-foot">
                    <a class="cld-panel__rail-link" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e('Ver la web', 'cloudari-onebox'); ?>
                    </a>
                    <?php if ($user->exists()) { ?>
                        <span class="cld-panel__user"><?php echo esc_html($user->display_name); ?></span>
                    <?php } ?>
                </div>
            </aside>
            <main class="cld-panel__main" data-cld-main tabindex="-1">
                <p class="cld-panel__loading" role="status"><?php esc_html_e('Cargando el panel…', 'cloudari-onebox'); ?></p>
                <noscript>
                    <p class="cld-panel__loading"><?php esc_html_e('El panel necesita JavaScript activado en el navegador.', 'cloudari-onebox'); ?></p>
                </noscript>
            </main>
            <p class="cld-panel__sr" role="status" aria-live="polite" data-cld-live></p>
        </div>
        <?php
    }

    private static function assetVersion(string $relativePath): string
    {
        $file = CLOUDARI_ONEBOX_DIR . $relativePath;
        $time = file_exists($file) ? filemtime($file) : false;

        return $time ? (string) $time : CLOUDARI_ONEBOX_VER;
    }
}
