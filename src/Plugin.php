<?php

namespace Cloudari\Onebox;



use Cloudari\Onebox\Admin\SettingsPage;

use Cloudari\Onebox\Admin\EventOverridesPage;

use Cloudari\Onebox\Rest\Routes;

use Cloudari\Onebox\Presentation\Shortcodes\Register as ShortcodeRegister;

use Cloudari\Onebox\Presentation\Ajax\CalendarAjax;

use Cloudari\Onebox\Domain\ManualEvents\PostType as ManualPostType;

use Cloudari\Onebox\Domain\ManualEvents\Taxonomy as ManualTaxonomy;

use Cloudari\Onebox\Domain\ManualEvents\MetaBox as ManualMetaBox;



if ( ! defined( 'ABSPATH' ) ) {

    exit;

}



/**

 * Núcleo del plugin: aquí simplemente "conectamos" los distintos módulos

 * a los hooks de WordPress. Nada de lógica gorda.

 */

final class Plugin

{

    public function boot(): void

    {

        /**

         * ADMIN

         * - Página de ajustes para el perfil de teatro (credenciales OneBox, colores, etc.)

         */

        add_action( 'admin_menu', [ SettingsPage::class, 'register' ] );
        add_action( 'admin_menu', [ EventOverridesPage::class, 'registerMenu' ] );



        /**

         * REST API interna

         * - /cloudari/v1/ping

         * - /cloudari/v1/billboard-events

         * - /cloudari/v1/manual-events

         */

        add_action( 'rest_api_init', [ Routes::class, 'register' ] );



        /**

         * SHORTCODES

         * - [cloudari_calendar]

         * - [cloudari_billboard]

         */

        add_action( 'init', [ ShortcodeRegister::class, 'register' ] );



        /**

         * AJAX del calendario (sessions por rango)

         * - wp_ajax_cloudari_get_sessions

         * - wp_ajax_nopriv_cloudari_get_sessions

         */

        CalendarAjax::register();



        /**

         * EVENTOS MANUALES

         * - CPT "evento_manual"

         * - Taxonomía "evento_manual_cat" (con términos por defecto)

         * - Metabox de detalles (sesiones, URL, imagen, categoría)

         */

        add_action( 'init', [ ManualPostType::class, 'register' ] );

        add_action( 'init', [ ManualTaxonomy::class, 'register' ] );

        add_action( 'init', [ ManualTaxonomy::class, 'seedDefaults' ], 20 );



        add_action( 'add_meta_boxes', [ ManualMetaBox::class, 'register' ] );

        add_action( 'admin_enqueue_scripts', [ ManualMetaBox::class, 'enqueueAdminAssets' ] );

        add_action( 'save_post_' . ManualPostType::SLUG, [ ManualMetaBox::class, 'save' ] );

    }

}

