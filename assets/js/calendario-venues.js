(function () {
  "use strict";

  const STAGE_ICON =
    '<svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">' +
    '<path d="M4 5h16a1 1 0 0 1 1 1v9a3 3 0 0 1-3 3h-3.5l1.2 2.4a1 1 0 1 1-1.8.9L12.9 18h-1.8l-1.1 2.3a1 1 0 0 1-1.8-.9L9.5 18H6a3 3 0 0 1-3-3V6a1 1 0 0 1 1-1Zm1 2v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V7H5Zm2 1.5a1 1 0 0 1 1 1V13a1 1 0 1 1-2 0V9.5a1 1 0 0 1 1-1Zm10 0a1 1 0 0 1 1 1V13a1 1 0 1 1-2 0V9.5a1 1 0 0 1 1-1Z" fill="currentColor"/>' +
    "</svg>";

  function getEnv() {
    return typeof window.cloudariCalendarVenuesData === "object" &&
      window.cloudariCalendarVenuesData
      ? window.cloudariCalendarVenuesData
      : {};
  }

  function getPurchaseBase(env) {
    const raw = env.purchaseBase ?? env.purchase_base ?? env.purchasebase ?? "";
    const value = String(raw || "").trim();
    return value ? value.replace(/\/?$/, "/") : null;
  }

  function getSpecialRedirectUrl(env, eventId, url) {
    const map = env.specialRedirects || {};
    return map[eventId] ? map[eventId] : url;
  }

  function buildAjaxUrl(env, inicio, fin) {
    const base = String(env.ajaxSesiones || "");
    const nonce = env.nonce ? encodeURIComponent(env.nonce) : "";
    const glue = base.includes("?") ? "&" : "?";
    let url = `${base}${glue}inicio=${encodeURIComponent(inicio)}&fin=${encodeURIComponent(fin)}`;

    if (nonce) {
      url += `&nonce=${nonce}`;
    }

    return url;
  }

  function pad2(value) {
    return String(value).padStart(2, "0");
  }

  function fmtTimeFromDate(date) {
    return `${pad2(date.getHours())}:${pad2(date.getMinutes())}`;
  }

  function toDateSafe(value) {
    if (!value) {
      return null;
    }

    const date = new Date(value);
    return Number.isNaN(+date) ? null : date;
  }

  function buildHoraDisplay(eventoRaw, fechaEventoDate) {
    const startTime = fmtTimeFromDate(fechaEventoDate);
    const isManual = Boolean(eventoRaw?.cloudari?.manual);
    const endDt = isManual ? toDateSafe(eventoRaw?.date?.end) : null;

    if (isManual && endDt) {
      const endTime = fmtTimeFromDate(endDt);
      if (endTime && endTime !== startTime) {
        return `${startTime} - ${endTime}`;
      }
    }

    return startTime;
  }

  function buildEventUrl(env, eventId, sessionUrl) {
    const baseCompra = getPurchaseBase(env);
    let url = typeof sessionUrl === "string" && sessionUrl.trim() !== ""
      ? sessionUrl.trim()
      : "";

    url = getSpecialRedirectUrl(env, eventId, url);

    if (!url && baseCompra) {
      url = baseCompra + String(eventId);
      url = getSpecialRedirectUrl(env, eventId, url);
    }

    return url || "";
  }

  function getVenueName(rawEvent) {
    const directVenue = String(rawEvent?.venue?.name || "").trim();
    if (directVenue) {
      return directVenue;
    }

    const fallbackVenue = Array.isArray(rawEvent?.event?.venues)
      ? String(rawEvent.event.venues[0]?.name || "").trim()
      : "";

    return fallbackVenue || "Sin ubicacion";
  }

  function getImageUrl(rawEvent) {
    return rawEvent?.images?.landscape?.[0]?.["es-ES"] || "";
  }

  function parseOneboxData(sesiones, env) {
    const eventosAPI = sesiones || {};
    const eventos = {};

    if (eventosAPI && Array.isArray(eventosAPI.data)) {
      eventosAPI.data.forEach(function (evento) {
        if (!evento?.date?.start) {
          return;
        }

        const fechaEvento = new Date(evento.date.start);
        if (Number.isNaN(fechaEvento.getTime())) {
          return;
        }

        const fechaFormateada =
          `${fechaEvento.getFullYear()}-${String(fechaEvento.getMonth() + 1).padStart(2, "0")}-${String(fechaEvento.getDate()).padStart(2, "0")}`;

        if (!eventos[fechaFormateada]) {
          eventos[fechaFormateada] = [];
        }

        const idEvento = evento.event?.id ?? evento.id;
        const isManual = Boolean(evento?.cloudari?.manual);
        const ctaLabel =
          typeof evento?.cloudari?.cta_label === "string" && evento.cloudari.cta_label.trim() !== ""
            ? evento.cloudari.cta_label.trim()
            : "";

        eventos[fechaFormateada].push({
          hora: buildHoraDisplay(evento, fechaEvento),
          titulo: evento.event?.texts?.title?.["es-ES"] ?? evento.event?.name ?? evento.name,
          id: idEvento,
          venue: getVenueName(evento),
          precio: evento.price?.min?.value ? `${evento.price.min.value}EUR` : "Precio no disponible",
          imagen: getImageUrl(evento),
          url: buildEventUrl(env, idEvento, evento.url),
          isManual: isManual,
          ctaLabel: ctaLabel,
        });
      });
    }

    return eventos;
  }

  function capitalizarPrimeraLetra(cadena) {
    if (!cadena || !cadena.length) {
      return cadena;
    }

    return cadena.charAt(0).toUpperCase() + cadena.slice(1).toLowerCase();
  }

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

  function posicionarPopup(popup) {
    if (!popup) {
      return;
    }

    popup.classList.remove("align-left", "align-right");

    const rect = popup.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const margin = 8;

    if (rect.right > viewportWidth - margin) {
      popup.classList.add("align-right");
    } else if (rect.left < margin) {
      popup.classList.add("align-left");
    }
  }

  function obtenerRangoFechas(mes, anio) {
    const inicio = new Date(anio, mes, 1);
    const fin = new Date(anio, mes + 1, 0);

    return {
      inicio: `${inicio.getFullYear()}-${String(inicio.getMonth() + 1).padStart(2, "0")}-01`,
      fin: `${fin.getFullYear()}-${String(fin.getMonth() + 1).padStart(2, "0")}-${String(fin.getDate()).padStart(2, "0")}`,
    };
  }

  function createVenueRow(venueName) {
    const eventoVenue = document.createElement("div");
    eventoVenue.className = "evento-venue";
    eventoVenue.insertAdjacentHTML("beforeend", STAGE_ICON);

    const label = document.createElement("span");
    label.className = "evento-venue-text";
    label.textContent = venueName || "Sin ubicacion";

    eventoVenue.appendChild(label);

    return eventoVenue;
  }

  function initCalendar(root, env) {
    if (!root || root.dataset.cloudariCalendarVenuesReady === "1") {
      return;
    }

    root.dataset.cloudariCalendarVenuesReady = "1";

    const calendarHost = root.querySelector('[data-role="calendar"]');
    const monthLabel = root.querySelector('[data-role="month-label"]');
    const prevMonthButton = root.querySelector('[data-role="prev-month"]');
    const nextMonthButton = root.querySelector('[data-role="next-month"]');

    if (!calendarHost || !monthLabel || !prevMonthButton || !nextMonthButton) {
      return;
    }

    let sesiones = env.sesiones;

    const hoy = new Date();
    let mesActual = hoy.getMonth();
    let anioActual = hoy.getFullYear();
    let mesCargando = null;
    let anioCargando = null;
    const eventosPrecargados = {};

    const nombresDiasDesktop = ["Do", "Lu", "Ma", "Mi", "Ju", "Vi", "Sa"];
    const nombresDiasMobile = ["Lu", "Ma", "Mi", "Ju", "Vi", "Sa", "Do"];
    const nombresDiasCompletos = [
      "Domingo",
      "Lunes",
      "Martes",
      "Miercoles",
      "Jueves",
      "Viernes",
      "Sabado",
    ];
    const nombresMeses = [
      "ENERO", "FEBRERO", "MARZO", "ABRIL", "MAYO", "JUNIO",
      "JULIO", "AGOSTO", "SEPTIEMBRE", "OCTUBRE", "NOVIEMBRE", "DICIEMBRE",
    ];

    function inicializarEventosPrecargados() {
      let mesAnterior = mesActual - 1;
      let anioAnterior = anioActual;
      if (mesAnterior < 0) {
        mesAnterior = 11;
        anioAnterior--;
      }

      let mesSiguiente = mesActual + 1;
      let anioSiguiente = anioActual;
      if (mesSiguiente > 11) {
        mesSiguiente = 0;
        anioSiguiente++;
      }

      eventosPrecargados[`${anioAnterior}-${mesAnterior}`] = sesiones;
      eventosPrecargados[`${anioActual}-${mesActual}`] = sesiones;
      eventosPrecargados[`${anioSiguiente}-${mesSiguiente}`] = sesiones;
    }

    function esMovil() {
      return window.innerWidth <= 1200;
    }

    function closeAllPopups() {
      root.querySelectorAll(".popup-eventos.popup-active").forEach(function (popup) {
        popup.classList.remove("popup-active");
      });
    }

    function renderizarCalendario() {
      const eventos = parseOneboxData(sesiones, env);

      monthLabel.textContent = `${nombresMeses[mesActual]} ${anioActual}`;

      if (mesActual === 0) {
        if (esMesPasado(11, anioActual - 1)) {
          prevMonthButton.classList.add("disabled");
        } else {
          prevMonthButton.classList.remove("disabled");
        }
      } else if (esMesPasado(mesActual - 1, anioActual)) {
        prevMonthButton.classList.add("disabled");
      } else {
        prevMonthButton.classList.remove("disabled");
      }

      calendarHost.innerHTML = "";

      const diasEnMes = new Date(anioActual, mesActual + 1, 0).getDate();

      const encabezadosDias = document.createElement("div");
      encabezadosDias.className = "encabezados-dias";

      if (!esMovil()) {
        for (let i = 0; i < diasEnMes; i++) {
          const dia = new Date(anioActual, mesActual, i + 1);
          const encabezadoDia = document.createElement("div");
          encabezadoDia.className = "dia-header";
          encabezadoDia.textContent = nombresDiasDesktop[dia.getDay()];
          encabezadosDias.appendChild(encabezadoDia);
        }
      } else {
        nombresDiasMobile.forEach(function (dia) {
          const encabezadoDia = document.createElement("div");
          encabezadoDia.className = "dia-header";
          encabezadoDia.textContent = dia;
          encabezadosDias.appendChild(encabezadoDia);
        });
      }

      calendarHost.appendChild(encabezadosDias);

      const listaDias = document.createElement("div");
      listaDias.className = "calendario";

      if (esMovil()) {
        let primerDiaSemana = new Date(anioActual, mesActual, 1).getDay();
        primerDiaSemana = (primerDiaSemana + 6) % 7;

        for (let i = 0; i < primerDiaSemana; i++) {
          const celdaVacia = document.createElement("div");
          celdaVacia.className = "celda-dia vacia";
          listaDias.appendChild(celdaVacia);
        }
      }

      for (let dia = 1; dia <= diasEnMes; dia++) {
        const celdaDia = document.createElement("div");
        celdaDia.className = "celda-dia";

        const fechaActual = new Date(anioActual, mesActual, dia);
        if (
          fechaActual.getDate() === hoy.getDate() &&
          fechaActual.getMonth() === hoy.getMonth() &&
          fechaActual.getFullYear() === hoy.getFullYear()
        ) {
          celdaDia.classList.add("dia-seleccionado");
        }

        if (esFechaPasada(fechaActual)) {
          celdaDia.classList.add("dia-pasado");
        }

        const numeroDia = document.createElement("div");
        numeroDia.className = "numero-dia";
        numeroDia.textContent = String(dia);
        celdaDia.appendChild(numeroDia);

        const fechaFormateada =
          `${anioActual}-${String(mesActual + 1).padStart(2, "0")}-${String(dia).padStart(2, "0")}`;

        if (eventos[fechaFormateada] && eventos[fechaFormateada].length > 0) {
          const indicador = document.createElement("div");
          indicador.className = "indicador-evento";
          celdaDia.appendChild(indicador);

          const popupEventos = document.createElement("div");
          popupEventos.className = "popup-eventos";

          const fechaEvento = document.createElement("div");
          fechaEvento.className = "evento-fecha";
          fechaEvento.textContent =
            `${nombresDiasCompletos[fechaActual.getDay()]} ${dia} de ${capitalizarPrimeraLetra(nombresMeses[fechaActual.getMonth()])}`;
          popupEventos.appendChild(fechaEvento);

          eventos[fechaFormateada].forEach(function (evento) {
            const eventoItem = document.createElement("div");
            eventoItem.className = "evento-item";

            const eventoContenido = document.createElement("div");
            eventoContenido.className = "evento-contenido";

            if (evento.imagen) {
              const eventoImagen = document.createElement("div");
              eventoImagen.className = "evento-imagen";

              const img = document.createElement("img");
              img.src = evento.imagen;
              img.alt = evento.titulo;
              img.onerror = function () {
                eventoImagen.style.display = "none";
                eventoContenido.classList.add("sin-imagen");
              };

              eventoImagen.appendChild(img);
              eventoContenido.appendChild(eventoImagen);
            }

            const eventoInfo = document.createElement("div");
            eventoInfo.className = "evento-info";

            const eventoLugar = document.createElement("div");
            eventoLugar.className = "evento-lugar";
            eventoLugar.textContent = evento.titulo;
            eventoInfo.appendChild(eventoLugar);

            const eventoMeta = document.createElement("div");
            eventoMeta.className = "evento-meta";

            const eventoHora = document.createElement("div");
            eventoHora.className = "evento-titulo";
            eventoHora.textContent = evento.hora;
            eventoMeta.appendChild(eventoHora);

            eventoMeta.appendChild(createVenueRow(evento.venue));
            eventoInfo.appendChild(eventoMeta);

            const eventoAccion = document.createElement("div");
            eventoAccion.className = "evento-accion";

            const botonTickets = document.createElement("button");
            botonTickets.className = "btn-tickets";

            const btnLabel = evento.isManual && evento.ctaLabel ? evento.ctaLabel : "Tickets";

            botonTickets.innerHTML =
              '<svg fill="currentColor" height="20px" width="20px" viewBox="0 0 512.005 512.005" xmlns="http://www.w3.org/2000/svg">' +
              "<g><g><g>" +
              "<path d=\"M511.513,223.904L452.508,42.326c-1.708-5.251-7.348-8.125-12.602-6.42L6.912,176.612 c-5.252,1.707-8.126,7.349-6.42,12.602l27.93,85.949c-0.008,0.168-0.025,0.333-0.025,0.503v190.925c0,5.522,4.478,10,10,10 H493.68c5.522,0,10-4.478,10-10V275.666c0-5.522-4.478-10-10-10h-78.32l89.734-29.16 C510.345,234.799,513.219,229.157,511.513,223.904z M483.679,285.666v170.925H48.396V285.666h55.392v111.408 c0,5.522,4.478,10,10,10c5.522,0,10-4.478,10-10V285.666h228.441H483.679z M350.645,265.666H46.365l-23.762-73.123l52.711-17.129 l20.162,61.276c1.385,4.208,5.296,6.877,9.497,6.877c1.036,0,2.09-0.162,3.128-0.504c5.246-1.727,8.1-7.378,6.373-12.625 l-20.139-61.206L436.577,58.017l52.825,162.558L350.645,265.666z\"></path>" +
              "<path d=\"M421.405,101.849c-1.708-5.251-7.349-8.124-12.602-6.42l-260.728,84.727c-5.252,1.707-8.126,7.349-6.42,12.602 c1.374,4.226,5.293,6.912,9.509,6.912c1.024,0,2.066-0.159,3.093-0.492l260.728-84.727 C420.237,112.744,423.112,107.102,421.405,101.849z\"></path>" +
              "</g></g></g>" +
              "</svg>" +
              btnLabel;

            botonTickets.addEventListener("click", function (event) {
              event.stopPropagation();

              const redirectUrl = typeof evento.url === "string" ? evento.url.trim() : "";
              if (!redirectUrl) {
                console.warn("No hay URL disponible para el evento", evento.id);
                return;
              }

              window.open(redirectUrl, "_blank", "noopener,noreferrer");
            });

            eventoAccion.appendChild(botonTickets);
            eventoInfo.appendChild(eventoAccion);

            eventoContenido.appendChild(eventoInfo);
            eventoItem.appendChild(eventoContenido);
            popupEventos.appendChild(eventoItem);
          });

          celdaDia.appendChild(popupEventos);

          celdaDia.addEventListener("mouseenter", function () {
            root.querySelectorAll(".popup-active").forEach(function (popup) {
              if (popup !== popupEventos) {
                popup.classList.remove("popup-active");
              }
            });

            popupEventos.classList.add("popup-active");
            posicionarPopup(popupEventos);
          });

          celdaDia.addEventListener("mouseleave", function () {
            popupEventos.timeout = window.setTimeout(function () {
              if (!popupEventos.matches(":hover")) {
                popupEventos.classList.remove("popup-active");
              }
            }, 200);
          });

          popupEventos.addEventListener("mouseenter", function () {
            if (popupEventos.timeout) {
              clearTimeout(popupEventos.timeout);
            }
          });

          popupEventos.addEventListener("mouseleave", function () {
            popupEventos.timeout = window.setTimeout(function () {
              popupEventos.classList.remove("popup-active");
            }, 200);
          });
        }

        listaDias.appendChild(celdaDia);
      }

      calendarHost.appendChild(listaDias);
    }

    function cargarEventosSilencioso(mes, anio) {
      const cacheKey = `${anio}-${mes}`;
      if (eventosPrecargados[cacheKey]) {
        return;
      }

      const rango = obtenerRangoFechas(mes, anio);
      const url = buildAjaxUrl(env, rango.inicio, rango.fin);

      fetch(url, { method: "GET", credentials: "same-origin" })
        .then(function (response) {
          return response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`));
        })
        .then(function (data) {
          eventosPrecargados[cacheKey] = data || [];
        })
        .catch(function (error) {
          console.error("Error al precargar calendario con venues:", error);
        });
    }

    function precargarDatosAdyacentes(mes, anio) {
      let mesSiguiente = mes + 1;
      let anioSiguiente = anio;

      if (mesSiguiente > 11) {
        mesSiguiente = 0;
        anioSiguiente++;
      }

      cargarEventosSilencioso(mesSiguiente, anioSiguiente);
    }

    function cargarEventos(mes, anio) {
      if (esMesPasado(mes, anio)) {
        return;
      }

      if (mes === mesCargando && anio === anioCargando) {
        return;
      }

      mesCargando = mes;
      anioCargando = anio;

      const cacheKey = `${anio}-${mes}`;
      const rango = obtenerRangoFechas(mes, anio);
      const url = buildAjaxUrl(env, rango.inicio, rango.fin);

      if (eventosPrecargados[cacheKey]) {
        sesiones = eventosPrecargados[cacheKey];
        renderizarCalendario();
        precargarDatosAdyacentes(mes, anio);
        return;
      }

      renderizarCalendario();

      fetch(url, { method: "GET", credentials: "same-origin" })
        .then(function (response) {
          return response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`));
        })
        .then(function (data) {
          sesiones = data || [];
          eventosPrecargados[cacheKey] = sesiones;
          renderizarCalendario();
          precargarDatosAdyacentes(mes, anio);
        })
        .catch(function (error) {
          console.error("Error al obtener sesiones del calendario con venues:", error);
        });
    }

    function handleResize() {
      renderizarCalendario();
      root.querySelectorAll(".popup-eventos.popup-active").forEach(posicionarPopup);
    }

    inicializarEventosPrecargados();
    window.addEventListener("resize", handleResize);

    (function bindTouchSupport() {
      const isTouch = window.matchMedia("(pointer: coarse)").matches;
      if (!isTouch) {
        return;
      }

      root.addEventListener("click", function (event) {
        if (
          event.target.closest('[data-role="prev-month"]') ||
          event.target.closest('[data-role="next-month"]') ||
          event.target.closest(".nav-buttons") ||
          event.target.closest(".btn-tickets") ||
          event.target.closest("a, button")
        ) {
          return;
        }

        if (event.target.closest(".popup-eventos")) {
          return;
        }

        const celda = event.target.closest(".celda-dia");
        if (!celda || !root.contains(celda)) {
          closeAllPopups();
          return;
        }

        const popup = celda.querySelector(".popup-eventos");
        if (!popup) {
          return;
        }

        const abierto = popup.classList.contains("popup-active");
        closeAllPopups();

        if (!abierto) {
          popup.classList.add("popup-active");
          posicionarPopup(popup);
        }
      });

      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
          closeAllPopups();
        }
      });

      root.addEventListener("click", function (event) {
        if (
          event.target.closest('[data-role="prev-month"]') ||
          event.target.closest('[data-role="next-month"]')
        ) {
          closeAllPopups();
        }
      });
    })();

    renderizarCalendario();

    prevMonthButton.addEventListener("click", function () {
      let mesAnterior = mesActual - 1;
      let anioAnterior = anioActual;

      if (mesAnterior < 0) {
        mesAnterior = 11;
        anioAnterior--;
      }

      if (esMesPasado(mesAnterior, anioAnterior)) {
        return;
      }

      mesActual = mesAnterior;
      anioActual = anioAnterior;
      cargarEventos(mesActual, anioActual);
    });

    nextMonthButton.addEventListener("click", function () {
      mesActual++;

      if (mesActual > 11) {
        mesActual = 0;
        anioActual++;
      }

      cargarEventos(mesActual, anioActual);
    });
  }

  function boot() {
    const roots = document.querySelectorAll("[data-cloudari-calendar-venues]");
    if (!roots.length) {
      return;
    }

    const env = getEnv();
    if (!env.sesiones) {
      console.error("No se encontraron datos de sesiones para cloudari_calendar_venues");
      return;
    }

    roots.forEach(function (root) {
      initCalendar(root, env);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
})();
