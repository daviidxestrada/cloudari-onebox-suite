<?php
/**
 * Plugin Name:       Cloudari OneBox Suite (Calendario + Cartelera)
 * Description:       Suite Cloudari para integrar OneBox (calendario, cartelera y eventos manuales) en múltiples teatros con Elementor vía shortcodes.
 * Version:           1.2.3
 * Author:            Cloudari
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Text Domain:       cloudari-onebox
 */

$pucFile = __DIR__ . '/lib/plugin-update-checker-5.6/plugin-update-checker.php';
if (!file_exists($pucFile)) {
    $pucFile = __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
}

if (file_exists($pucFile)) {
    if (!class_exists('PucReadmeParser', false)) {
        $pucReadmeParser = __DIR__ . '/lib/plugin-update-checker-5.6/vendor/PucReadmeParser.php';
        $pucReadmeParserShim = __DIR__ . '/lib/plugin-update-checker-5.6/Puc/v5p6/vendor/PucReadmeParser.php';

        if (file_exists($pucReadmeParser)) {
            require_once $pucReadmeParser;
        } elseif (file_exists($pucReadmeParserShim)) {
            require_once $pucReadmeParserShim;
        } else {
            class PucReadmeParser {
                public function parse_readme_contents($contents) {
                    return array();
                }
            }
        }
    }

    require_once $pucFile;

    $factory = null;
    if (class_exists('\YahnisElsts\PluginUpdateChecker\v5p6\PucFactory')) {
        $factory = '\YahnisElsts\PluginUpdateChecker\v5p6\PucFactory';
    } elseif (class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
        $factory = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';
    }

    if ($factory) {
        $updateChecker = $factory::buildUpdateChecker(
            'https://github.com/daviidxestrada/cloudari-onebox-suite',
            __FILE__,
            'cloudari-onebox-suite'
        );

        // Rama que usas
        $updateChecker->setBranch('main');

        // Token opcional para repos privados (definir en wp-config.php).
        if ( defined( 'CLOUDARI_ONEBOX_GITHUB_TOKEN' ) && CLOUDARI_ONEBOX_GITHUB_TOKEN ) {
            $updateChecker->setAuthentication( CLOUDARI_ONEBOX_GITHUB_TOKEN );
        }
    }
}

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Constantes básicas del plugin
 */
define( 'CLOUDARI_ONEBOX_VER',  '1.2.3' );
define( 'CLOUDARI_ONEBOX_FILE', __FILE__ );
define( 'CLOUDARI_ONEBOX_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CLOUDARI_ONEBOX_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Flag global para controlar si el plugin puede pintar cosas en el front.
 *
 * - Se puede sobreescribir desde wp-config.php:
 *      define('CLOUDARI_ONEBOX_ENABLE_OUTPUT', false);
 * - Más adelante lo combinaremos con el flag `outputEnabled` del perfil de teatro.
 */
if ( ! defined( 'CLOUDARI_ONEBOX_ENABLE_OUTPUT' ) ) {
    define( 'CLOUDARI_ONEBOX_ENABLE_OUTPUT', true );
}

/**
 * Autoloader de la librería
 */
require_once CLOUDARI_ONEBOX_DIR . 'src/Support/Autoloader.php';

Cloudari\Onebox\Support\Autoloader::register(
    'Cloudari\\Onebox\\',
    CLOUDARI_ONEBOX_DIR . 'src/'
);

/**
 * Bootstrap principal del plugin
 */
add_action( 'plugins_loaded', static function () {

    // i18n
    load_plugin_textdomain(
        'cloudari-onebox',
        false,
        dirname( plugin_basename( CLOUDARI_ONEBOX_FILE ) ) . '/languages'
    );

    // Boot principal (menús, shortcodes, AJAX, REST, etc.)
    $plugin = new Cloudari\Onebox\Plugin();
    $plugin->boot();
} );

/**
 * Hooks de activación / desactivación
 * (útiles para sembrar taxonomías, crear opciones por defecto, limpiar cron, etc.)
 */
register_activation_hook( __FILE__, function () {
    // Sembrar categorías por defecto de eventos manuales, por si acaso
    if ( class_exists( '\Cloudari\Onebox\Domain\ManualEvents\Taxonomy' ) ) {
        \Cloudari\Onebox\Domain\ManualEvents\Taxonomy::register();
        \Cloudari\Onebox\Domain\ManualEvents\Taxonomy::seedDefaults();
    }

    // Aquí podríamos crear una opción de perfil con valores por defecto si no existe.
} );

register_deactivation_hook( __FILE__, function () {
    // Aquí, si en el futuro añadimos cron jobs o transients especiales,
    // podríamos limpiarlos. De momento lo dejamos como placeholder.
} );
