=== Cloudari OneBox Suite ===
Contributors: cloudari
Tags: onebox, theatre, calendar, billboard, events
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 1.2.4

Suite para integrar OneBox en WordPress: calendario, cartelera, cartelera por espacios, contador y eventos manuales, con multiples integraciones por teatro.

== Description ==
Cloudari OneBox Suite conecta OneBox con WordPress y pinta:
- Calendario con sesiones de OneBox + eventos manuales.
- Cartelera con eventos de OneBox + manuales.
- Cartelera opcional agrupada por espacios / venues.
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

Cartelera por espacios:
`[cloudari_billboard_venues]`
Alias:
`[cloudari_billboard_spaces]`

Notas de uso:
- `[cloudari_billboard]` sigue siendo la cartelera clasica y no cambia su contrato.
- `[cloudari_billboard_venues]` es una vista adicional, pensada para instalaciones multiteatro o multiespacio.
- La cartelera por espacios usa sesiones de OneBox como fuente principal para agrupar y ordenar por la proxima funcion disponible.

Contador (por evento):
`[cloudari_event_countdown event_id="123" extra_days="180" duration="90 min" age="12+"]`

== Manual events ==
Se crean con el CPT `evento_manual` y la taxonomia `evento_manual_cat`.
Se incluyen automaticamente en calendario y cartelera.
El nombre del teatro se toma del Perfil MAIN.
Opcionalmente cada manual puede definir su propio `venue`.
Opcionalmente cada manual puede definir su propio texto de CTA.
Si el `venue` manual esta vacio, la cartelera por espacios usa `venue_name` del perfil activo como fallback.

== REST ==
- `GET /wp-json/cloudari/v1/ping`
- `GET /wp-json/cloudari/v1/billboard-events`
- `GET /wp-json/cloudari/v1/billboard-venues`
- `GET /wp-json/cloudari/v1/manual-events`

Notas:
- `billboard-events` mantiene la salida de la cartelera clasica.
- `billboard-venues` devuelve la cartelera agrupada por venue, con venues ordenados por proxima fecha y eventos ordenados por fecha ascendente.

== Data storage ==
Options:
- `cloudari_onebox_profiles`
- `cloudari_onebox_active_profile`
- `cloudari_onebox_event_overrides`

Transients:
- `cloudari_onebox_jwt_token_{integration}`
- `cloudari_onebox_refresh_token_{integration}`
- `cloudari_onebox_billboard_events_v1_{profile}`
- `cloudari_onebox_billboard_venues_v1_{profile}`

Meta relevante en eventos manuales:
- `_manual_event_venue`
- `_manual_event_cta_label`

Custom post types / taxonomy:
- `evento_manual`
- `evento_manual_cat`

== Caching ==
- Cartelera: cache server-side (5 min).
- Cartelera por espacios: cache server-side (5 min).
- Sesiones calendario/contador: cache server-side por rango (5 min).
- Tokens OneBox: cache por integracion con transients.
- Para limpiar cache, guarda el Perfil MAIN o actualiza un evento manual.

== Production checklist ==
- Definir `WP_DEBUG` en false.
- Verificar credenciales OneBox y endpoints.
- Revisar que no haya colision de IDs entre integraciones (overrides y URLs usan el ID del evento).
- Usar HTTPS y cache a nivel de servidor si aplica.

== Changelog ==
= 1.2.4 =
* Nueva cartelera opcional por espacios con shortcodes `[cloudari_billboard_venues]` y `[cloudari_billboard_spaces]`.
* Nuevo endpoint interno `GET /wp-json/cloudari/v1/billboard-venues`.
* Agrupacion por venue usando `session.venue` como fuente principal y fallback a `event.venues[0]`.
* Integracion de eventos manuales en la cartelera por espacios.
* Nuevo campo opcional de `venue` para eventos manuales.
* Nueva documentacion y metadatos de release alineados para merge a `main`.

= 1.1 =
* Fix de autoupdates (plugin-update-checker).
* AJAX del calendario/contador con nonce + URL admin-ajax dinamica.
* Cache de sesiones por rango.

= 1.0 =
* Release inicial con multi-integracion, eventos manuales y overrides.

== Upgrade Notice ==
= 1.2.4 =
Nueva cartelera opcional por espacios, soporte de venue manual y documentacion de release alineada.

= 1.1 =
Actualizacion interna: autoupdates, nonce AJAX y cache de sesiones.

= 1.0 =
Primera version estable.
