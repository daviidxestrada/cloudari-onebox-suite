---
version: 1
slug: "src-admin-theatrepanel-php"
primary_target: "src/Admin/TheatrePanel.php"
related_targets: ["assets/css/theatre-panel.css","assets/js/theatre-panel.js"]
---

# Panel del teatro (wp-admin)

Scope: página de administración `Panel del teatro` del plugin (src/Admin/TheatrePanel.php, assets/css/theatre-panel.css, assets/js/theatre-panel.js). Mode: Operate.

Audience: personal del teatro de 40-50 años, poco técnico, escritorio. Tareas: añadir/editar eventos manuales; cambiar, reordenar y añadir carteles del Hero Carrusel. Constraints: mismos datos que el metabox y el widget; sin build; WCAG 2.2 AA; nada se borra sin confirmación.

## Direction contract

THESIS: Lista y ficha. Cada tarea es una lista en el orden real de la web a la izquierda y la ficha del elemento elegido a la derecha, con sus imágenes grandes. Rechaza la tabla de WordPress con enlaces "Editar" que abren otra pantalla y el editor de Elementor como lugar donde cambiar un cartel.

OWN-WORLD: Abonoteatro. Barra lateral tinta #1E1E2E con el nombre del teatro y navegación en pastillas; la activa en lima #BCCF42 con texto tinta. Lienzo blanco, bordes #D1D5DB, radios 8/12/16, pastillas 999. Títulos de sección en rosa #EE206B (orientación, nunca acción). Acción principal cian #06B6D4 con texto tinta; secundaria gris #F3F4F6; rojo solo para quitar. Barlow 16/24 base, 32 título, 22 ficha. Estado siempre punto + texto.

STORY: El usuario entra, ve sus carteles en el orden de la web con miniaturas reales, pulsa uno, lo cambia a la derecha y guarda; o abre Eventos, pulsa "Añadir evento" y rellena una ficha guiada en lenguaje del teatro.

FIRST VIEWPORT: Barra lateral 260 px (Inicio no: dos secciones, Carteles de portada y Eventos manuales). Columna lista ~500 px: título rosa, una línea de ayuda, botón cian "+ Añadir", filas con número grande rosa, miniatura 16:9, nombre y tipo de medio, botones Subir/Bajar. Ficha a la derecha: título "Cartel 1 · Nombre", campo nombre, dos vistas previas grandes (ordenador 16:9 y móvil vertical) con "Cambiar", enlace de compra, barra inferior fija "Guardar cambios" + "Quitar cartel".

FORM: Lista y ficha (master-detail), posición 6 de mi lista ordenada, dealt lead. seed:1dada8e485bc4d77. Signature move: el cartel que se mueve en el orden se desplaza en su sitio y queda marcado ("Orden cambiado, sin guardar") hasta que se guarda, prestado del tablero de embarque que reordena una fila sin hacerla desaparecer.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
