=== Cloudari OneBox Suite ===
Contributors: cloudari
Tags: onebox, theatre, calendar, billboard, events
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 1.2.3

Suite para integrar OneBox en WordPress: calendario, cartelera, contador y eventos manuales, con multiples integraciones por teatro.

== Description ==
Cloudari OneBox Suite conecta OneBox con WordPress y pinta:
- Calendario con sesiones de OneBox + eventos manuales.
- Cartelera con eventos de OneBox + manuales.
- Contador de proximas sesiones por evento.

El modelo de datos se basa en un Perfil MAIN (teatro) y una o varias integraciones OneBox dentro del perfil.

== Installation ==
1. Sube la carpeta `cloudari-onebox-suite` a `wp-content/plugins/`.
2. Activa el plugin en el panel de WordPress.
3. Ve a Cloudari OneBox > Perfil MAIN y configura el teatro e integraciones.
4. Inserta los shortcodes en tus paginas.

== Configuration ==
Perfil MAIN:
- Nombre del perfil (interno)
- Nombre del teatro (display, usado en eventos manuales)
- Paleta de colores (primary, accent, bg, text, selected day)

Integraciones OneBox (1..N):
- Label
- Channel ID
- Client Secret
- Catalog API URL
- Auth API URL
- Purchase base URL

Notas:
- Debe existir al menos una integracion.
- Puedes marcar una integracion como default (fallback para purchaseBase).

Opcionales (wp-config.php):
- `define('CLOUDARI_ONEBOX_PUBLIC_REST', false);` para restringir REST a admins.
- `define('CLOUDARI_ONEBOX_REQUIRE_AJAX_NONCE', false);` para desactivar nonce en AJAX si hay cache agresiva.

== Shortcodes ==
Calendario:
`[cloudari_calendar]`

Cartelera:
`[cloudari_billboard]`

Contador (por evento):
`[cloudari_event_countdown event_id="123" extra_days="180" duration="90 min" age="12+"]`

== Widgets (HTML) ==
Los widgets HTML estan en la carpeta `widgets/`.
Para usarlos en Elementor, anade un "Widget HTML" y pega el contenido del widget.

== Manual events ==
Se crean con el CPT `evento_manual` y la taxonomia `evento_manual_cat`.
Se incluyen automaticamente en calendario y cartelera.
El nombre del teatro se toma del Perfil MAIN.

== REST ==
- `GET /wp-json/cloudari/v1/ping`
- `GET /wp-json/cloudari/v1/billboard-events`
- `GET /wp-json/cloudari/v1/manual-events`

== Data storage ==
Options:
- `cloudari_onebox_profiles`
- `cloudari_onebox_active_profile`
- `cloudari_onebox_event_overrides`

Transients:
- `cloudari_onebox_jwt_token_{integration}`
- `cloudari_onebox_refresh_token_{integration}`
- `cloudari_onebox_billboard_events_v1_{profile}`

Custom post types / taxonomy:
- `evento_manual`
- `evento_manual_cat`

== Caching ==
- Cartelera: cache server-side (5 min).
- Sesiones calendario/contador: cache server-side por rango (5 min).
- Tokens OneBox: cache por integracion con transients.
- Para limpiar cache, guarda el Perfil MAIN.

== Production checklist ==
- Definir `WP_DEBUG` en false.
- Verificar credenciales OneBox y endpoints.
- Revisar que no haya colision de IDs entre integraciones (overrides y URLs usan el ID del evento).
- Usar HTTPS y cache a nivel de servidor si aplica.

== Changelog ==
= 1.2.0 =
* Carpeta `widgets/` con widgets HTML listos para pegar en Elementor.

= 1.1 =
* Fix de autoupdates (plugin-update-checker).
* AJAX del calendario/contador con nonce + URL admin-ajax dinamica.
* Cache de sesiones por rango.

= 1.0 =
* Release inicial con multi-integracion, eventos manuales y overrides.

== Upgrade Notice ==
= 1.2.0 =
Incluye widgets HTML listos para usar en Elementor (ver carpeta `widgets/`).

= 1.1 =
Actualizacion interna: autoupdates, nonce AJAX y cache de sesiones.

= 1.0 =
Primera version estable.
