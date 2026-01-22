<?php

namespace Cloudari\Onebox\Presentation\Shortcodes;

use Cloudari\Onebox\Presentation\Assets\Enqueue;

final class Register
{
    public static function register(): void
    {
        add_shortcode('cloudari_calendar', [static::class, 'calendar']);
        add_shortcode('cloudari_billboard', [static::class, 'billboard']);
        add_shortcode('cloudari_event_countdown', [static::class, 'eventCountdown']);

    }

    /**
     * ==============================
     *  CALENDARIO
     * ==============================
     */
    public static function calendar($atts = [], $content = ''): string
    {
        Enqueue::calendar();

        if (!CLOUDARI_ONEBOX_ENABLE_OUTPUT) {
            return '<!-- Cloudari Calendar desactivado por flag -->';
        }

        ob_start(); ?>

        <div id="calendario-container" class="calendario-container">
            <div class="header">

                <button id="prev-mes" class="nav-buttons" aria-label="Mes Anterior" title="Mes Anterior" style="background:transparent;border:none">
                    <svg fill="currentColor" width="28" height="28" viewBox="0 0 306 306" aria-hidden="true">
                        <polygon points="247.35,267.75 130.05,153 247.35,35.7 211.65,0 58.65,153 211.65,306"/>
                    </svg>
                </button>

                <div class="mes-anio" id="mes-anio"></div>

                <button id="next-mes" class="nav-buttons" aria-label="Siguiente mes" title="Siguiente mes" style="background:transparent;border:none">
                    <svg fill="currentColor" width="28" height="28" viewBox="0 0 306 306" aria-hidden="true">
                        <polygon points="58.65,267.75 175.95,153 58.65,35.7 94.35,0 247.35,153 94.35,306"/>
                    </svg>
                </button>

            </div>

            <div id="calendario"></div>
        </div>

        <?php
        return ob_get_clean();
    }

    /**
     * ==============================
     *  CARTELERA
     * ==============================
     */
    public static function billboard($atts = [], $content = ''): string
    {
        Enqueue::billboard();

        if (!CLOUDARI_ONEBOX_ENABLE_OUTPUT) {
            return '<!-- Cloudari Billboard desactivado por flag -->';
        }

        ob_start(); ?>

        <section id="obx-cards" class="obx-cards" aria-labelledby="obx-title">

            <header class="obx-head">
                <div class="obx-actions" role="search">

                    <label class="sr-only" for="obx-q">Buscar espectáculos</label>
                    <input id="obx-q" type="search" placeholder="Buscar espectáculos…" aria-label="Buscar espectáculos" />

                    <label class="sr-only" for="obx-cat">Filtrar por categoría</label>
                    <select id="obx-cat" aria-label="Filtrar por categoría">
                        <option value="all">Todas las categorías</option>
                    </select>

                </div>
            </header>

            <div id="obx-grid" class="obx-grid" aria-live="polite"></div>

        </section>

        <?php
        return ob_get_clean();
    }

     /**
     * ==============================
     *  CONTADOR
     * ==============================
     */
    public static function eventCountdown($atts = [], $content = ''): string
{
    Enqueue::countdown();

    if (!CLOUDARI_ONEBOX_ENABLE_OUTPUT) {
        return '<!-- Cloudari Event Countdown desactivado por flag -->';
    }

    $atts = shortcode_atts(
        [
            'id'         => '',
            'event_id'   => '',
            'extra_days' => 180,
            'duration'   => '',
            'age'        => '',
        ],
        $atts,
        'cloudari_event_countdown'
    );

    $eventId   = $atts['event_id'] ?: $atts['id'];
    $eventId   = (int) $eventId;
    $extraDays = (int) $atts['extra_days'];

    if ($eventId <= 0) {
        return '<!-- Cloudari Event Countdown: falta event_id -->';
    }

    $duration = trim((string) $atts['duration']);
    $age      = trim((string) $atts['age']);

    ob_start(); ?>

    <section class="cloudari-ce-wrap"
             data-cloudari-countdown
             data-event-id="<?php echo esc_attr($eventId); ?>"
             data-extra-days="<?php echo esc_attr($extraDays); ?>">
        <div class="cloudari-ce-grid">

            <div class="cloudari-ce-item">
                <h3 class="cloudari-ce-h3">Próxima función</h3>
                <p>
                    <time data-role="next-date" datetime="">—</time>
                </p>
                <div class="cloudari-ce-countdown">
                    <div><span data-role="d">00</span><small>días</small></div>
                    <div><span data-role="h">00</span><small>h</small></div>
                    <div><span data-role="m">00</span><small>min</small></div>
                    <div><span data-role="s">00</span><small>s</small></div>
                </div>
            </div>

            <?php if ($duration !== '') : ?>
                <div class="cloudari-ce-item">
                    <h3 class="cloudari-ce-h3">Duración</h3>
                    <p><?php echo esc_html($duration); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($age !== '') : ?>
                <div class="cloudari-ce-item">
                    <h3 class="cloudari-ce-h3">Edad recomendada</h3>
                    <p><?php echo esc_html($age); ?></p>
                </div>
            <?php endif; ?>

            <div class="cloudari-ce-item cloudari-ce-poster">
                <figure class="cloudari-ce-poster-figure">
                    <a data-role="poster-link" href="#" target="_blank" rel="noopener noreferrer">
                        <img data-role="poster-img" alt="Cartel del evento" loading="lazy" decoding="async" />
                        <figcaption data-role="poster-title" class="cloudari-ce-poster-cap">Evento</figcaption>
                    </a>
                </figure>
            </div>

        </div>
    </section>

    <?php
    return ob_get_clean();
}

}
