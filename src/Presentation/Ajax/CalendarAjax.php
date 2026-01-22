<?php
namespace Cloudari\Onebox\Presentation\Ajax;

use Cloudari\Onebox\Infrastructure\Onebox\Sessions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX del calendario:
 * action=cloudari_get_sessions&inicio=YYYY-MM-DD&fin=YYYY-MM-DD
 */
final class CalendarAjax
{
    public const ACTION = 'cloudari_get_sessions';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'handle']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [self::class, 'handle']);
    }

    /**
     * Handler principal: devuelve siempre JSON (200/4xx/5xx) y NUNCA peta en blanco.
     */
    public static function handle(): void
    {
        // Aseguramos cabeceras de JSON
        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));

        $requireNonce = !defined('CLOUDARI_ONEBOX_REQUIRE_AJAX_NONCE') || CLOUDARI_ONEBOX_REQUIRE_AJAX_NONCE;
        if ($requireNonce) {
            $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'cloudari_calendar_nonce')) {
                wp_send_json_error(
                    [
                        'error' => 'Invalid nonce',
                    ],
                    403
                );
            }
        }

        $inicio = isset($_GET['inicio']) ? sanitize_text_field(wp_unslash($_GET['inicio'])) : '';
        $fin    = isset($_GET['fin'])    ? sanitize_text_field(wp_unslash($_GET['fin']))    : '';

        // Validación básica de fechas
        $pattern = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($pattern, $inicio) || !preg_match($pattern, $fin)) {
            wp_send_json_error(
                [
                    'error'      => 'Fechas inválidas',
                    'inicio_raw' => $inicio,
                    'fin_raw'    => $fin,
                ],
                400
            );
        }

        try {
            // Llamamos a la capa de infraestructura (OneBox + caché)
            // Esta función debe devolver el mismo formato que usas en PHP:
            // [ 'data' => [...], 'metadata' => [...] ]
            $sesiones = Sessions::getRangeSessions($inicio, $fin);

            if (!is_array($sesiones)) {
                $sesiones = [];
            }

            wp_send_json($sesiones);

        } catch (\Throwable $e) {
            // Log en debug para poder ver qué pasa si revienta
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    sprintf(
                        '[Cloudari OneBox] Error en cloudari_get_sessions (%s → %s): %s @ %s:%d',
                        $inicio,
                        $fin,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    )
                );
            }

            wp_send_json_error(
                [
                    'error' => 'Internal server error',
                ],
                500
            );
        }
    }
}
