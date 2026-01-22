document.addEventListener('DOMContentLoaded', function () {
  if (typeof oneboxData === 'undefined' || !oneboxData.sesiones) {
    console.error("No se encontraron datos de sesiones");
    return;
  }

  // Por si el script se carga en páginas sin calendario
  if (!document.getElementById('calendario') || !document.getElementById('mes-anio')) {
    return;
  }

  /** =========================
   * ENV HELPERS
   * ========================= */
  function getEnv() {
    return (typeof oneboxData !== 'undefined' && oneboxData && typeof oneboxData === 'object')
      ? oneboxData
      : {};
  }

  function getPurchaseBase() {
    const env = getEnv();
    const raw =
      env.purchaseBase ??
      env.purchase_base ??
      env.purchasebase ??
      '';

    const v = String(raw || '').trim();
    if (!v) return null;
    // Siempre terminamos en "/"
    return v.replace(/\/?$/, '/');
  }

  function getSpecialRedirectUrl(eventId, url) {
    const env = getEnv();
    const map = env.specialRedirects || {};
    // El mapa viene de PHP como [id => url], ids en string pero el acceso numérico funciona igual
    return map[eventId] ? map[eventId] : url;
  }


  function buildAjaxUrl(inicio, fin) {
    const base = String(oneboxData.ajaxSesiones || '');
    const nonce = oneboxData.nonce ? encodeURIComponent(oneboxData.nonce) : '';
    const glue = base.includes('?') ? '&' : '?';
    let url = `${base}${glue}inicio=${encodeURIComponent(inicio)}&fin=${encodeURIComponent(fin)}`;
    if (nonce) {
      url += `&nonce=${nonce}`;
    }
    return url;
  }

  let sesiones = oneboxData.sesiones;

  // Configuración inicial
  const hoy = new Date();
  let mesActual = hoy.getMonth();
  let anioActual = hoy.getFullYear();
  let eventosPrecargados = {};

  // Inicializar eventosPrecargados con los datos recibidos de PHP
  inicializarEventosPrecargados();

  function inicializarEventosPrecargados() {
    let mesAnterior = mesActual - 1;
    let anioAnterior = anioActual;
    if (mesAnterior < 0) { mesAnterior = 11; anioAnterior--; }

    let mesSiguiente = mesActual + 1;
    let anioSiguiente = anioActual;
    if (mesSiguiente > 11) { mesSiguiente = 0; anioSiguiente++; }

    eventosPrecargados[`${anioAnterior}-${mesAnterior}`] = sesiones;
    eventosPrecargados[`${anioActual}-${mesActual}`] = sesiones;
    eventosPrecargados[`${anioSiguiente}-${mesSiguiente}`] = sesiones;
  }

  // Vista móvil si ancho <= 1200px
  const esMovil = () => window.innerWidth <= 1200;

  window.addEventListener('resize', () => {
    renderizarCalendario();
    // Recoloca cualquier popup visible tras el reflow
    document.querySelectorAll('.popup-eventos.popup-active').forEach(posicionarPopup);
  });

  // Nombres de los días
  const nombresDiasDesktop = ['Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa']; // getDay(): 0-dom
  const nombresDiasMobile = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa', 'Do']; // semana empezando en lunes
  const nombresDiasCompletos = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

  // Meses
  const nombresMeses = [
    'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
    'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'
  ];

  /** =========================
   * TIME HELPERS
   * ========================= */

  function pad2(n) {
    return String(n).padStart(2, '0');
  }

  function fmtTimeFromDate(dt) {
    return `${pad2(dt.getHours())}:${pad2(dt.getMinutes())}`;
  }

  // Convierte ISO/Date a Date válido o null
  function toDateSafe(v) {
    if (!v) return null;
    const d = new Date(v);
    return isNaN(+d) ? null : d;
  }

  // Hora para mostrar:
  // - Manual + tiene end => "HH:MM - HH:MM"
  // - Si no => "HH:MM"
  function buildHoraDisplay(eventoRaw, fechaEventoDate) {
    const startTime = fmtTimeFromDate(fechaEventoDate);

    const isManual = Boolean(eventoRaw?.cloudari?.manual);
    const endDt = isManual ? toDateSafe(eventoRaw?.date?.end) : null;

    if (isManual && endDt) {
      const endTime = fmtTimeFromDate(endDt);
      // Si por lo que sea end == start, no ponemos rango
      if (endTime && endTime !== startTime) {
        return `${startTime} - ${endTime}`;
      }
    }
    return startTime;
  }

  /** =========================
   * URL HELPERS (prioridades)
   * ========================= */

  // Prioridad:
  // 1) session/url explícita (manual)
  // 2) specialRedirects[eventId]
  // 3) purchaseBase + eventId
  function buildEventUrl(eventId, sessionUrl) {
    const baseCompra = getPurchaseBase();

    // 1) URL explícita
    let url = (typeof sessionUrl === 'string' && sessionUrl.trim() !== '') ? sessionUrl.trim() : '';

    // 2) specialRedirects
    url = getSpecialRedirectUrl(eventId, url);

    // 3) fallback purchaseBase
    if (!url && baseCompra) {
      url = baseCompra + String(eventId);
      // aplica redirect también por si el panel quiere sobreescribir el purchase
      url = getSpecialRedirectUrl(eventId, url);
    }

    return url || '';
  }

  // Parsear datos de OneBox/Manual a { 'YYYY-MM-DD': [eventos...] }
  function parseOneboxData() {
    const eventosAPI = sesiones || {};
    const eventos = {};

    if (eventosAPI && eventosAPI.data && Array.isArray(eventosAPI.data)) {
      eventosAPI.data.forEach(evento => {
        if (evento.date && evento.date.start) {
          const fechaEvento = new Date(evento.date.start);
          const fechaFormateada =
            `${fechaEvento.getFullYear()}-${String(fechaEvento.getMonth() + 1).padStart(2, '0')}-${String(fechaEvento.getDate()).padStart(2, '0')}`;

          if (!eventos[fechaFormateada]) eventos[fechaFormateada] = [];

          const idEvento = evento.event?.id ?? evento.id;

          const isManual = Boolean(evento?.cloudari?.manual);

          // ✅ CTA label: si viene del backend (manual), lo usamos. Si no, fallback "Tickets"
          const ctaLabel =
            (typeof evento?.cloudari?.cta_label === 'string' && evento.cloudari.cta_label.trim() !== '')
              ? evento.cloudari.cta_label.trim()
              : '';

          // ✅ URL con prioridades correctas
          const urlFinal = buildEventUrl(idEvento, evento.url);

          // 👇 Hora display con rango SOLO para manuales con date.end
          const horaEvento = buildHoraDisplay(evento, fechaEvento);

          const eventoInfo = {
            hora: horaEvento,
            titulo: evento.event?.texts?.title?.['es-ES'] ?? evento.event?.name ?? evento.name,
            id: idEvento,
            venue: evento.venue ? evento.venue.name : 'Sin ubicación',
            precio: evento.price && evento.price.min ? `${evento.price.min.value}€` : 'Precio no disponible',
            imagen: evento.images?.landscape?.[0]?.['es-ES'] || '',
            url: urlFinal,
            isManual: isManual,
            ctaLabel: ctaLabel,
          };

          eventos[fechaFormateada].push(eventoInfo);
        }
      });
    }
    return eventos;
  }

  function capitalizarPrimeraLetra(cadena) {
    if (!cadena || !cadena.length) return cadena;
    return cadena.charAt(0).toUpperCase() + cadena.slice(1).toLowerCase();
  }

  // Fechas pasadas
  function esFechaPasada(fecha) {
    const hoyLocal = new Date();
    hoyLocal.setHours(0, 0, 0, 0);
    return fecha < hoyLocal;
  }
  function esMesPasado(mes, anio) {
    const hoy = new Date();
    const fechaEvaluada = new Date(anio, mes, 1);
    return fechaEvaluada < new Date(hoy.getFullYear(), hoy.getMonth(), 1);
  }

  // ---------- Posicionamiento inteligente del popup ----------
  function posicionarPopup(popup) {
    if (!popup) return;
    // Quitar clases previas
    popup.classList.remove('align-left', 'align-right');

    // Medir y decidir
    const rect = popup.getBoundingClientRect();
    const vw = window.innerWidth;
    const margen = 8;

    if (rect.right > vw - margen) {
      popup.classList.add('align-right');
    } else if (rect.left < margen) {
      popup.classList.add('align-left');
    }
    // Si cabe centrado, no hacemos nada (usa el default left:50% translateX(-50%))
  }
  // -----------------------------------------------------------

  function renderizarCalendario() {
    const eventos = parseOneboxData();

    document.getElementById('mes-anio').textContent = `${nombresMeses[mesActual]} ${anioActual}`;

    // Estado de la flecha anterior
    const prevMesButton = document.getElementById('prev-mes');

    if (mesActual === 0) {
      // Miramos si el diciembre del año anterior es pasado
      if (esMesPasado(11, anioActual - 1)) {
        prevMesButton.classList.add('disabled');
      } else {
        prevMesButton.classList.remove('disabled');
      }
    } else {
      // Cualquier otro mes: miramos el mes anterior
      if (esMesPasado(mesActual - 1, anioActual)) {
        prevMesButton.classList.add('disabled');
      } else {
        prevMesButton.classList.remove('disabled');
      }
    }
    // El color de la flecha lo controla solo el CSS vía `color` + `currentColor`.

    // Contenedor
    const calendario = document.getElementById('calendario');
    calendario.innerHTML = '';

    // Días del mes
    const diasEnMes = new Date(anioActual, mesActual + 1, 0).getDate();

    // Encabezados
    const encabezadosDias = document.createElement('div');
    encabezadosDias.className = 'encabezados-dias';

    if (!esMovil()) {
      // Desktop: encabezado por cada día del mes (para 31 columnas)
      for (let i = 0; i < diasEnMes; i++) {
        const dia = new Date(anioActual, mesActual, i + 1);
        const diaSemana = dia.getDay(); // 0=Dom,...6=Sab
        const encabezadoDia = document.createElement('div');
        encabezadoDia.className = 'dia-header';
        encabezadoDia.textContent = nombresDiasDesktop[diaSemana];
        encabezadosDias.appendChild(encabezadoDia);
      }
    } else {
      // Responsive: fila fija Lu..Do
      nombresDiasMobile.forEach(dia => {
        const encabezadoDia = document.createElement('div');
        encabezadoDia.className = 'dia-header';
        encabezadoDia.textContent = dia;
        encabezadosDias.appendChild(encabezadoDia);
      });
    }

    calendario.appendChild(encabezadosDias);

    // Lista de días
    const listaDias = document.createElement('div');
    listaDias.className = 'calendario';

    if (esMovil()) {
      // Semana empieza en lunes: rotación (getDay 0=Dom => 6)
      let primerDiaSemana = new Date(anioActual, mesActual, 1).getDay();
      primerDiaSemana = (primerDiaSemana + 6) % 7;
      for (let i = 0; i < primerDiaSemana; i++) {
        const celdaVacia = document.createElement('div');
        celdaVacia.className = 'celda-dia vacia';
        listaDias.appendChild(celdaVacia);
      }
    }

    // Celdas
    for (let dia = 1; dia <= diasEnMes; dia++) {
      const celdaDia = document.createElement('div');
      celdaDia.className = 'celda-dia';

      const fechaActual = new Date(anioActual, mesActual, dia);
      if (
        fechaActual.getDate() === hoy.getDate() &&
        fechaActual.getMonth() === hoy.getMonth() &&
        fechaActual.getFullYear() === hoy.getFullYear()
      ) {
        celdaDia.classList.add('dia-seleccionado');
      }
      if (esFechaPasada(fechaActual)) celdaDia.classList.add('dia-pasado');

      const numeroDia = document.createElement('div');
      numeroDia.className = 'numero-dia';
      numeroDia.textContent = dia;
      celdaDia.appendChild(numeroDia);

      const fechaFormateada =
        `${anioActual}-${String(mesActual + 1).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;

      if (eventos[fechaFormateada] && eventos[fechaFormateada].length > 0) {
        const indicador = document.createElement('div');
        indicador.className = 'indicador-evento';
        celdaDia.appendChild(indicador);

        const popupEventos = document.createElement('div');
        popupEventos.className = 'popup-eventos';
        popupEventos.id = `popup-${fechaFormateada}`;

        const fechaEvento = document.createElement('div');
        fechaEvento.className = 'evento-fecha';
        fechaEvento.textContent =
          `${nombresDiasCompletos[fechaActual.getDay()]} ${dia} de ${capitalizarPrimeraLetra(nombresMeses[fechaActual.getMonth()])}`;
        popupEventos.appendChild(fechaEvento);

        // Rellenar eventos
        eventos[fechaFormateada].forEach(evento => {
          const eventoItem = document.createElement('div');
          eventoItem.className = 'evento-item';

          // Contenedor principal con imagen y contenido
          const eventoContenido = document.createElement('div');
          eventoContenido.className = 'evento-contenido';

          // Imagen del evento (lado izquierdo)
          if (evento.imagen) {
            const eventoImagen = document.createElement('div');
            eventoImagen.className = 'evento-imagen';
            const img = document.createElement('img');
            img.src = evento.imagen;
            img.alt = evento.titulo;
            img.onerror = function () {
              eventoImagen.style.display = 'none';
              eventoContenido.classList.add('sin-imagen');
            };
            eventoImagen.appendChild(img);
            eventoContenido.appendChild(eventoImagen);
          }

          // Contenedor de información (lado derecho)
          const eventoInfo = document.createElement('div');
          eventoInfo.className = 'evento-info';

          const eventoLugar = document.createElement('div');
          eventoLugar.className = 'evento-lugar';
          eventoLugar.textContent = evento.titulo;
          eventoInfo.appendChild(eventoLugar);

          const eventoTitulo = document.createElement('div');
          eventoTitulo.className = 'evento-titulo';
          eventoTitulo.textContent = evento.hora;
          eventoInfo.appendChild(eventoTitulo);

          const eventoAccion = document.createElement('div');
          eventoAccion.className = 'evento-accion';

          const botonTickets = document.createElement('button');
          botonTickets.className = 'btn-tickets';

          // ✅ Texto del botón: manual usa ctaLabel si existe
          const btnLabel = (evento.isManual && evento.ctaLabel) ? evento.ctaLabel : 'Tickets';

          botonTickets.innerHTML = `
            <svg fill="currentColor" height="20px" width="20px" viewBox="0 0 512.005 512.005" xmlns="http://www.w3.org/2000/svg">
              <g><g><g>
                <path d="M511.513,223.904L452.508,42.326c-1.708-5.251-7.348-8.125-12.602-6.42L6.912,176.612 c-5.252,1.707-8.126,7.349-6.42,12.602l27.93,85.949c-0.008,0.168-0.025,0.333-0.025,0.503v190.925c0,5.522,4.478,10,10,10 H493.68c5.522,0,10-4.478,10-10V275.666c0-5.522-4.478-10-10-10h-78.32l89.734-29.16 C510.345,234.799,513.219,229.157,511.513,223.904z M483.679,285.666v170.925H48.396V285.666h55.392v111.408 c0,5.522,4.478,10,10,10c5.522,0,10-4.478,10-10V285.666h228.441H483.679z M350.645,265.666H46.365l-23.762-73.123l52.711-17.129 l20.162,61.276c1.385,4.208,5.296,6.877,9.497,6.877c1.036,0,2.09-0.162,3.128-0.504c5.246-1.727,8.1-7.378,6.373-12.625 l-20.139-61.206L436.577,58.017l52.825,162.558L350.645,265.666z"></path>
                <path d="M421.405,101.849c-1.708-5.251-7.349-8.124-12.602-6.42l-260.728,84.727c-5.252,1.707-8.126,7.349-6.42,12.602 c1.374,4.226,5.293,6.912,9.509,6.912c1.024,0,2.066-0.159,3.093-0.492l260.728-84.727 C420.237,112.744,423.112,107.102,421.405,101.849z"></path>
              </g></g></g>
            </svg>
            ${btnLabel}
          `;

          botonTickets.addEventListener('click', function (e) {
            e.stopPropagation();

            const redirectUrl = (typeof evento.url === 'string') ? evento.url.trim() : '';

            if (!redirectUrl) {
              console.warn('No hay URL disponible (manual/special/purchaseBase) para el evento', evento.id);
              return;
            }

            window.open(redirectUrl, '_blank', 'noopener,noreferrer');
          });

          eventoAccion.appendChild(botonTickets);
          eventoInfo.appendChild(eventoAccion);

          eventoContenido.appendChild(eventoInfo);
          eventoItem.appendChild(eventoContenido);
          popupEventos.appendChild(eventoItem);
        });

        celdaDia.appendChild(popupEventos);

        // ------- Desktop (hover) -------
        celdaDia.addEventListener('mouseenter', function () {
          document.querySelectorAll('.popup-active').forEach(p => {
            if (p !== popupEventos) p.classList.remove('popup-active');
          });
          popupEventos.classList.add('popup-active');
          posicionarPopup(popupEventos);
        });

        celdaDia.addEventListener('mouseleave', function () {
          setTimeout(() => {
            if (!popupEventos.matches(':hover')) {
              popupEventos.classList.remove('popup-active');
            }
          }, 200);
        });

        popupEventos.addEventListener('mouseenter', function () {
          clearTimeout(popupEventos.timeout);
        });
        popupEventos.addEventListener('mouseleave', function () {
          setTimeout(() => { popupEventos.classList.remove('popup-active'); }, 200);
        });
      }

      listaDias.appendChild(celdaDia);
    }

    calendario.appendChild(listaDias);
  }

  // ---------- Precarga silenciosa ----------
  function cargarEventosSilencioso(mes, anio) {
    const cacheKey = `${anio}-${mes}`;
    if (eventosPrecargados[cacheKey]) return;

    const rango = obtenerRangoFechas(mes, anio);
    const urlWP = buildAjaxUrl(rango.inicio, rango.fin);

    fetch(urlWP, { method: 'GET', credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
      .then(data => {
        sesiones = data || [];
        eventosPrecargados[cacheKey] = sesiones;
        // si quieres, aquí podrías re-renderizar si el mes está visible
      })
      .catch(err => console.error("Error al precargar desde WordPress:", err));
  }

  function precargarDatosAdyacentes(mesActual, anioActual) {
    let mesSiguiente = mesActual + 1;
    let anioSiguiente = anioActual;
    if (mesSiguiente > 11) { mesSiguiente = 0; anioSiguiente++; }

    cargarEventosSilencioso(mesSiguiente, anioSiguiente);
  }

  let mesCargando = null;
  let anioCargando = null;

  function cargarEventos(mes, anio) {
    if (esMesPasado(mes, anio)) return;
    if (mes === mesCargando && anio === anioCargando) return;
    mesCargando = mes;
    anioCargando = anio;

    const rango = obtenerRangoFechas(mes, anio);
    const urlWP = buildAjaxUrl(rango.inicio, rango.fin);
    const cacheKey = `${anio}-${mes}`;

    if (eventosPrecargados[cacheKey]) {
      sesiones = eventosPrecargados[cacheKey];
      renderizarCalendario();
      precargarDatosAdyacentes(mes, anio);
      return;
    }

    // Renderizamos con los datos actuales mientras llega la respuesta
    renderizarCalendario();

    fetch(urlWP, { method: 'GET', credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
      .then(data => {
        sesiones = data || [];
        eventosPrecargados[cacheKey] = sesiones;
        renderizarCalendario();
        precargarDatosAdyacentes(mes, anio);
      })
      .catch(error => console.error("Error al obtener sesiones:", error));
  }

  function obtenerRangoFechas(mes, anio) {
    let inicio = new Date(anio, mes, 1);
    let fin = new Date(anio, mes + 1, 0);
    let inicioFormato =
      `${inicio.getFullYear()}-${String(inicio.getMonth() + 1).padStart(2, '0')}-01`;
    let finFormato =
      `${fin.getFullYear()}-${String(fin.getMonth() + 1).padStart(2, '0')}-${String(fin.getDate()).padStart(2, '0')}`;
    return { inicio: inicioFormato, fin: finFormato };
  }

  // =======================
  //   SOPORTE TÁCTIL (tap)
  // =======================
  (function () {
    const isTouch = window.matchMedia('(pointer: coarse)').matches;
    if (!isTouch) return;

    const root = document.getElementById('calendario-container');
    if (!root) return;

    const closeAll = () => {
      root.querySelectorAll('.popup-eventos.popup-active')
        .forEach(p => p.classList.remove('popup-active'));
    };

    root.addEventListener('click', function (e) {
      if (e.target.closest('#prev-mes') ||
        e.target.closest('#next-mes') ||
        e.target.closest('.nav-buttons') ||
        e.target.closest('.btn-tickets') ||
        e.target.closest('a, button')) {
        return;
      }
      if (e.target.closest('.popup-eventos')) return;

      const celda = e.target.closest('.celda-dia');
      if (!celda) { closeAll(); return; }

      const popup = celda.querySelector('.popup-eventos');
      if (!popup) return;

      const abierto = popup.classList.contains('popup-active');
      closeAll();
      if (!abierto) {
        popup.classList.add('popup-active');
        posicionarPopup(popup);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeAll();
    });

    root.addEventListener('click', function (e) {
      if (e.target.closest('#prev-mes') || e.target.closest('#next-mes')) {
        closeAll();
      }
    });
  })();

  // Inicializar
  renderizarCalendario();

  // Navegación
  document.getElementById('prev-mes').addEventListener('click', function () {
    let mesAnterior = mesActual - 1;
    let anioAnterior = anioActual;
    if (mesAnterior < 0) { mesAnterior = 11; anioAnterior--; }
    if (esMesPasado(mesAnterior, anioAnterior)) return;
    mesActual = mesAnterior;
    anioActual = anioAnterior;
    cargarEventos(mesActual, anioActual);
  });

  document.getElementById('next-mes').addEventListener('click', function () {
    mesActual++;
    if (mesActual > 11) { mesActual = 0; anioActual++; }
    cargarEventos(mesActual, anioActual);
  });

});
