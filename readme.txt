=== Cloudari OneBox Suite ===
Contributors: cloudari
Tags: onebox, theatre, calendar, billboard, events
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 1.3.14

Suite Cloudari para integrar OneBox en WordPress: calendario, cartelera, cartelera por espacios, contador y eventos manuales, con soporte para multiples integraciones por teatro.

== Description ==
Cloudari OneBox Suite conecta OneBox con WordPress y pinta las experiencias principales de cartelera:

- Calendario con sesiones de OneBox y eventos manuales.
- Calendario opcional con el venue visible por sesion.
- Cartelera clasica con eventos de OneBox y manuales.
- Cartelera agrupada por espacios / venues.
- Contador de proximas sesiones por evento.

El modelo de datos se basa en un Perfil MAIN del teatro y una o varias integraciones OneBox dentro de ese perfil. Esto permite trabajar con carteleras multicanal, unificar venues equivalentes y mantener eventos manuales dentro del mismo flujo visual.

== Installation ==
1. Sube la carpeta `cloudari-onebox-suite` a `wp-content/plugins/`.
2. Activa el plugin en el panel de WordPress.
3. Ve a Cloudari OneBox > Perfil MAIN.
4. Configura los datos del teatro, las integraciones OneBox y la paleta.
5. Inserta los shortcodes en tus paginas o pega los widgets HTML donde corresponda.

== Configuration ==
Perfil MAIN:

- Nombre del perfil interno.
- Nombre del teatro usado como fallback en eventos manuales.
- Paleta global: primary, accent, bg, text y selected day.
- Overrides de color por widget.
- Prioridad manual de espacios para la cartelera por venues.
- Equivalencias de venues entre OneBox y eventos manuales.

Integraciones OneBox:

- Label.
- Channel ID.
- Client Secret.
- Catalog API URL.
- Auth API URL.
- Purchase base URL.

Notas:

- Debe existir al menos una integracion.
- Puedes marcar una integracion como default para el fallback de `purchaseBase`.
- Si un venue llega desde canales distintos, puedes unificarlo desde la tabla de equivalencias del Perfil MAIN.

Opcionales en `wp-config.php`:

- `define('CLOUDARI_ONEBOX_PUBLIC_REST', false);` restringe REST publico a administradores.
- `define('CLOUDARI_ONEBOX_REQUIRE_AJAX_NONCE', false);` desactiva el nonce AJAX si hay cache agresiva.
- `define('CLOUDARI_ONEBOX_DEBUG_LOG', true);` activa logs internos del plugin de forma explicita.
- `define('CLOUDARI_ONEBOX_GITHUB_TOKEN', '...');` permite updates desde repositorios privados.

== Shortcodes ==
`[cloudari_calendar]`

- Muestra el calendario principal con sesiones OneBox y eventos manuales.
- Es la opcion recomendada para la vista de calendario estandar.

`[cloudari_calendar_venues]`

- Muestra el calendario con el nombre del espacio en el detalle de cada sesion.
- Es util en instalaciones multisala o multiteatro.

`[cloudari_billboard]`

- Muestra la cartelera clasica con eventos OneBox y manuales.
- Mantiene el listado de eventos sin agrupar por espacios.

`[cloudari_billboard_venues]`

- Muestra la cartelera agrupada por espacios / venues.
- Permite priorizar manualmente espacios desde el Perfil MAIN.
- Permite unificar espacios equivalentes entre canales.
- Integra eventos manuales dentro del mismo agrupador.

`[cloudari_billboard_spaces]`

- Alias de `[cloudari_billboard_venues]`.

`[cloudari_event_countdown event_id="123" extra_days="180" duration="90 min" age="12+"]`

- Muestra un bloque de contador o ficha resumida para un evento concreto.
- `event_id` indica el evento objetivo.
- `extra_days`, `duration` y `age` permiten ajustar la ficha.

== Widgets HTML ==
Los widgets HTML estan en la carpeta `widgets/`.

Para usarlos en Elementor, anade un Widget HTML y pega el contenido del archivo correspondiente.

== Manual events ==
Los eventos manuales se crean con el CPT `evento_manual` y la taxonomia `evento_manual_cat`.

Se incluyen automaticamente en calendario, cartelera y cartelera por espacios.

Campos relevantes:

- URL del evento.
- Imagen.
- Categoria manual.
- Sesiones, rango de fechas o ficha permanente.
- Venue opcional.
- Texto de CTA opcional.

Modos de evento manual:

- `Funciones (sesiones)`: fechas concretas con hora de inicio opcional y hora fin opcional. Si la hora de inicio se deja vacia, el calendario muestra "Horario pendiente" en lugar de `00:00`.
- `Evento por rango`: genera sesiones diarias dentro de un rango con horarios por defecto para entre semana, fin de semana y excepciones.
- `Ficha permanente`: para espacios, servicios o propuestas sin fecha de cierre, como un Ambigu. Aparece en cartelera y cartelera por espacios con "Informacion permanente", pero no genera una sesion diaria en el calendario.

Venue manual:

- El campo `Espacio / venue` se muestra como un selector alimentado por los espacios detectados desde OneBox.
- Si se deja vacio, se usa el nombre del teatro del Perfil MAIN.
- Si un evento tenia guardado un venue antiguo que ya no aparece en OneBox, el selector conserva ese valor para no perder datos al editar.
- La cartelera por venues genera el slug con `sanitize_title(...)`, salvo que exista una equivalencia canonica configurada.
- Para identificar slugs reales, consulta `GET /wp-json/cloudari/v1/billboard-venues` y mira el campo `slug`.

Categorias manuales:

- Si una categoria manual coincide con una categoria canonica (`teatro`, `musica`, `musical`, `humor`, `talk`), el frontend mantiene el color tradicional de esa categoria.
- Los colores personalizados de la taxonomia solo se usan para categorias manuales no canonicas.

== REST ==
Endpoints:

- `GET /wp-json/cloudari/v1/ping`
- `GET /wp-json/cloudari/v1/billboard-events`
- `GET /wp-json/cloudari/v1/billboard-venues`
- `GET /wp-json/cloudari/v1/manual-events`

Notas:

- `ping` queda reservado para administradores autenticados.
- `billboard-events` devuelve la cartelera clasica.
- `billboard-venues` devuelve la cartelera agrupada por venue, con `id`, `name`, `slug`, `next_start`, `event_count` y `events`.
- `manual-events` devuelve los eventos manuales normalizados para el frontend.

== Data storage ==
Options:

- `cloudari_onebox_profiles`
- `cloudari_onebox_active_profile`
- `cloudari_onebox_event_overrides`

Transients:

- `cloudari_onebox_jwt_token_{integration}`
- `cloudari_onebox_refresh_token_{integration}`
- `cloudari_onebox_billboard_events_v2_{profile}`
- `cloudari_onebox_billboard_venues_v1_{profile}`
- `cloudari_onebox_sessions_v1_{profile}_{inicio}_{fin}`

Meta relevante en eventos manuales:

- `_manual_event_venue`
- `_manual_event_cta_label`
- `_manual_event_mode`
- `_manual_event_range_start`
- `_manual_event_range_end`
- `_manual_event_schedule_rules`
- `_manual_event_schedule_exceptions`
- `_sesiones_evento`
- `_url_evento`
- `_imagen_evento_id`

Custom post types / taxonomy:

- `evento_manual`
- `evento_manual_cat`

== Caching ==
- Cartelera: cache server-side de 5 minutos.
- Cartelera por espacios: cache server-side de 5 minutos.
- Sesiones calendario/contador: cache server-side por rango de 5 minutos.
- Tokens OneBox: cache por integracion con transients.
- Cartelera clasica en navegador: cache local con versionado interno.

Para limpiar caches server-side, guarda el Perfil MAIN o actualiza un evento manual.
Desde la version 1.3.7, esta limpieza tambien borra las caches de sesiones por rango para que los eventos manuales se reflejen inmediatamente en calendario, contador y carteleras por espacios.

== Production checklist ==
- Definir `WP_DEBUG` en false.
- Verificar credenciales OneBox y endpoints.
- Verificar que el Perfil MAIN tiene una integracion default.
- Revisar equivalencias de venues si hay varias integraciones o eventos manuales que deban caer en el mismo espacio.
- Comprobar que los slugs de `billboard-venues` son los esperados.
- Revisar que no haya colision de IDs entre integraciones cuando se usen overrides.
- Usar HTTPS y cache a nivel de servidor si aplica.

== Changelog ==
= 1.3.14 =
* Fix: las horas y fechas de los eventos en calendario, cartelera y cartelera por espacios se muestran siempre en horario de Espana (Europe/Madrid), sin depender de la zona horaria/IP del dispositivo del visitante.
* Antes, visitantes fuera de Espana (p. ej. Portugal) veian las horas desplazadas y algunos eventos cerca de medianoche caian en el dia equivocado.
* Fix: la invalidacion de cache (cartelera, cartelera por espacios y sesiones) pasa a un sistema de versionado en lugar de borrar transients por SQL. Ahora funciona tambien en instalaciones con object cache persistente (Redis/Memcached), donde antes la cache no se limpiaba al editar o borrar eventos.
* Seguridad: el client_secret de OneBox se guarda ahora cifrado (libsodium o AES-256-GCM) en lugar de en texto plano, y deja de mostrarse en el formulario de ajustes. Los secretos existentes se cifran automaticamente al actualizar. Opcional: define CLOUDARI_ONEBOX_ENC_KEY en wp-config.php para una clave de cifrado estable.

= 1.3.13 =
* Fix: borrar, mandar a papelera, restaurar o cambiar el estado de un evento manual limpia inmediatamente las caches de cartelera, cartelera por espacios y sesiones.
* La rutina de mantenimiento por version limpia caches al actualizar, para retirar manuales ya borrados antes de instalar este fix.
* Nueva clave de cache local de cartelera clasica para evitar que navegadores con datos antiguos sigan mostrando manuales eliminados.

= 1.3.12 =
* Downgrade funcional: se retira la seccion Hero semanal, su shortcode y sus assets.
* El plugin vuelve al alcance previo a `1.3.9`, manteniendo las mejoras publicadas hasta `1.3.8`.

= 1.3.8 =
* El campo `Espacio / venue` de eventos manuales pasa de texto libre a selector con los espacios detectados desde OneBox.
* El selector conserva valores manuales ya guardados aunque no aparezcan en la API, para evitar perdida accidental de datos.
* Documentacion actualizada para explicar el nuevo selector de venues manuales.

= 1.3.7 =
* Nuevo modo de evento manual `Ficha permanente` para espacios o servicios estables sin fecha de cierre.
* Los eventos manuales sin hora de inicio ya no se muestran como `00:00`; el frontend los marca como "Horario pendiente".
* Las respuestas REST publicas conservan `cloudari.mode`, `cloudari.permanent` y `cloudari.time_tba` para que los widgets puedan pintar estos estados.
* Guardar el Perfil MAIN o un evento manual limpia tambien las caches de sesiones por rango.
* Nueva clave de cache local de cartelera clasica para recoger la normalizacion de modos manuales.

= 1.3.6 =
* Fix de colores de categorias en eventos manuales: las categorias canonicas vuelven a respetar la identidad visual tradicional (`teatro`, `musica`, `musical`, `humor`, `talk`).
* Los colores personalizados de taxonomia manual ya no pisan las clases canonicas cuando el frontend reconoce la categoria.
* La cartelera clasica fuerza una nueva clave de cache local para evitar normalizaciones antiguas en navegador.
* Documentacion ampliada sobre slugs de venues, eventos manuales y endpoint `billboard-venues`.

= 1.3.5 =
* Nueva unificacion manual de espacios entre canales desde el Perfil MAIN para evitar venues duplicados en carteleras multicanal.
* La unificacion tambien soporta eventos manuales sin romper el fallback actual cuando no hay reglas configuradas.
* Metadatos de release alineados para despliegue a produccion.

= 1.3.4 =
* Nueva prioridad manual de espacios en la cartelera por venues desde el Perfil MAIN con orden drag and drop.
* Hardening de release: los logs internos del plugin solo se activan con `CLOUDARI_ONEBOX_DEBUG_LOG`.
* Metadatos de version y documentacion alineados para despliegue a produccion.

= 1.2.8 =
* Preparacion de release: versionado y metadatos alineados para `v1.2.8`.
* Fix del shortcode `[cloudari_event_countdown]` para reiniciar correctamente el contador cuando cambia la proxima sesion cacheada.
* Limpieza de frontend para release, con debug de cartelera desactivado en produccion.

= 1.2.4 =
* Nueva cartelera opcional por espacios con shortcodes `[cloudari_billboard_venues]` y `[cloudari_billboard_spaces]`.
* Nuevo endpoint interno `GET /wp-json/cloudari/v1/billboard-venues`.
* Agrupacion por venue usando `session.venue` como fuente principal y fallback a `event.venues[0]`.
* Integracion de eventos manuales en la cartelera por espacios.
* Nuevo campo opcional de `venue` para eventos manuales.
* Nueva documentacion y metadatos de release alineados para merge a `main`.

= 1.1 =
* Fix de autoupdates con plugin-update-checker.
* AJAX del calendario/contador con nonce y URL admin-ajax dinamica.
* Cache de sesiones por rango.

= 1.0 =
* Release inicial con multi-integracion, eventos manuales y overrides.

== Upgrade Notice ==
= 1.3.14 =
Los horarios de los eventos se fijan a la hora de Espana (Madrid) para todos los visitantes, corrigiendo el desfase que veian desde otras zonas horarias. Ademas se corrige la invalidacion de cache en sitios con object cache (Redis/Memcached) y el client_secret de OneBox pasa a guardarse cifrado.

= 1.3.13 =
Limpia caches al actualizar y sincroniza borrados de eventos manuales con todas las carteleras.

= 1.3.12 =
Se retira el Hero semanal introducido en `1.3.9`; usa esta version para volver al comportamiento previo.

= 1.3.8 =
El venue de eventos manuales ahora se elige desde un selector alimentado por OneBox, manteniendo valores antiguos guardados.

= 1.3.7 =
Anade fichas permanentes, horario pendiente para manuales sin hora y limpieza completa de caches de sesiones al guardar manuales o el Perfil MAIN.

= 1.3.6 =
Corrige los colores de categorias canonicas en eventos manuales y actualiza la documentacion de venues/slugs.

= 1.3.5 =
Nueva unificacion manual de espacios entre canales para carteleras multicanal sin romper eventos manuales.

= 1.3.4 =
Nueva prioridad manual de espacios y endurecimiento de logs para release de produccion.

= 1.2.8 =
Actualizacion de release con fix del countdown cacheado y limpieza del frontend para produccion.

= 1.2.4 =
Nueva cartelera opcional por espacios, soporte de venue manual y documentacion de release alineada.

= 1.1 =
Actualizacion interna: autoupdates, nonce AJAX y cache de sesiones.

= 1.0 =
Primera version estable.
