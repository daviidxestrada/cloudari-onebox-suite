# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Personal del teatro (cliente):** gente de 40-50 años, poco técnica, que entra en el WordPress del teatro para mantener al día la web. Su trabajo es dar de alta eventos que no vienen de OneBox (eventos manuales) y cambiar los carteles del hero de portada: sustituir imágenes, reordenarlos o añadir uno nuevo. Usan sobre todo escritorio.
- **Administrador (Cloudari):** configura credenciales OneBox, perfiles, overrides y Elementor. Sigue teniendo acceso a todas las pantallas de WordPress.

## Product Purpose

Cloudari OneBox Suite integra OneBox en WordPress para teatros (calendario, cartelera, cartelera por espacios, eventos manuales, widgets de Elementor). El panel del cliente reúne en un solo sitio las dos tareas que el personal hace a menudo, sin pasar por el editor de Elementor ni por las pantallas genéricas de WordPress. Éxito: el cliente añade un evento o cambia un cartel sin pedir ayuda.

## Operating Context

- Los eventos manuales son el CPT `evento_manual` con taxonomía `evento_manual_cat` y metadatos (modo sesiones / rango / permanente, URL, imagen, espacio, texto del botón, categoría).
- Los carteles del hero viven dentro de los datos de Elementor (`_elementor_data`) de la página que contiene el widget "Cloudari Hero Carrusel"; puede haber más de una página con el widget.
- Plugin actualizado vía Plugin Update Checker desde GitHub (tag + release por versión).

## Capabilities and Constraints

- Acceso: el panel es la portada para el rol Editor (entra directo al iniciar sesión). Las pantallas antiguas de "Eventos manuales" se mantienen para el administrador.
- Carteles editables desde el panel: nombre, medio de escritorio, medio de móvil/tablet, imagen de reserva del vídeo, enlace de compra, orden y quitar. Encuadre, texto alternativo y estilo siguen en Elementor.
- Debe escribir sobre los mismos datos que usan el metabox y el widget: nada de una segunda fuente de verdad.
- Sin dependencias de build: PHP + JS/CSS planos, como el resto del plugin.

## Brand Commitments

- Cabecera con el nombre del teatro (nombre del sitio).
- Sistema visual adaptado del de Abonoteatro (abonoteatro.com y su área de gestión de promotores): Barlow, tinta `#1E1E2E`, rosa de orientación, cian de acción, lima de marca, amarillo de cabecera de tabla, controles en pastilla.

## Product Principles

1. Una tarea por pantalla, con verbos claros ("Añadir evento", "Cambiar imagen", "Guardar orden").
2. Lo que se ve en el panel es lo que se verá en la web: miniaturas reales de carteles e imágenes.
3. Nada se borra sin confirmación y nada se pierde por un error: guardar es explícito y avisa.
4. Lenguaje del teatro, no de WordPress ni de Elementor.

## Accessibility & Inclusion

Usuarios de 40-50 años: texto base de 16 px, objetivos táctiles de 44 px, contraste WCAG 2.2 AA, foco visible y etiquetas siempre visibles (no solo placeholders).
