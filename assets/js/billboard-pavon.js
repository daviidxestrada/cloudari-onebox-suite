/**
 * Cartelera - Gran Teatro Pavon (widget de Elementor).
 *
 * Lee los mismos datos que la cartelera por espacios (REST
 * `cloudari/v1/billboard-venues`, OneBox + eventos manuales) y los pinta con el
 * diseño propio del Pavon: selector segmentado de espacios sobre una misma
 * rejilla de tarjetas para todos ellos.
 *
 * Toda la configuracion llega por instancia en `data-config`, asi que puede
 * haber varios widgets en la misma pagina con textos distintos.
 */
(() => {
  "use strict";

  const SELECTOR = "[data-cloudari-billboard-pavon]";
  const ELEMENTOR_WIDGET = "cloudari_billboard_pavon";

  const CONFIG = Object.freeze({
    ENDPOINT: "/wp-json/cloudari/v1/billboard-venues",
    IMG_PLACEHOLDER:
      "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==",
    DEFAULT_CATEGORY_KEY: "teatro",
    CATEGORY_ORDER: ["teatro", "musica", "musical", "humor", "talk"],
    CATEGORY_MAP: {
      teatro: { label: "Teatro", className: "cbp-cat--teatro" },
      musica: { label: "Musica", className: "cbp-cat--musica" },
      musical: { label: "Musical", className: "cbp-cat--musical" },
      humor: { label: "Humor", className: "cbp-cat--humor" },
      talk: { label: "Talk", className: "cbp-cat--talk" },
    },
    CATEGORY_KEYWORDS: {
      teatro: ["teatro", "drama", "obra", "circo", "danza"],
      musica: ["musica", "music", "concierto", "banda", "recital"],
      musical: ["musical"],
      humor: ["humor", "comedia", "monologo", "standup", "stand up", "impro"],
      talk: ["talk", "charla", "conferencia", "coloquio", "debate", "ponencia"],
    },
    CATEGORY_CODE_MAP: {
      ARTET: "teatro",
      ART: "teatro",
      ARTE: "teatro",
      TEATRO: "teatro",
      CIRCO: "teatro",
      DANZA: "teatro",
      ARTCLA: "musica",
      ARTMU: "musica",
      MUS: "musica",
      MUSICA: "musica",
      MUSIC: "musica",
      CONCIERTO: "musica",
      ARTHU: "humor",
      HUMOR: "humor",
      HUM: "humor",
      COMEDIA: "humor",
      ARTMS: "musical",
      ARTMUS: "musical",
      MUSICAL: "musical",
      TALK: "talk",
      CONF: "talk",
      CONFERENCIA: "talk",
      CHARLA: "talk",
    },
    TEXTS: {
      allCategories: "Todas las categorias",
      cta: "Entradas",
      showSingular: "espectáculo",
      showPlural: "espectáculos",
      emptyFiltered: "No hay espectaculos que coincidan con los filtros.",
      emptyVenue: "No hay eventos proximos para este espacio.",
      emptyAll: "No hay espacios con eventos proximos.",
      error: "No se pudo cargar la cartelera por espacios.",
    },
  });

  // Una peticion por endpoint, compartida entre instancias.
  const venuesPromises = new Map();
  let instanceCounter = 0;

  const esc = (value) =>
    String(value ?? "").replace(/[&<>\"']/g, (match) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;",
    })[match]);

  const normalizeText = (value) =>
    String(value || "")
      .toLowerCase()
      .normalize("NFD")
      .replace(/\p{Diacritic}/gu, "")
      .trim();

  const normalizeCode = (value) => String(value || "").toUpperCase().trim();

  const toTitleCaseLabel = (value) => {
    const text = String(value || "").trim();
    return text ? text.charAt(0).toUpperCase() + text.slice(1) : "";
  };

  const asMap = (value) =>
    value && typeof value === "object" && !Array.isArray(value) ? value : {};

  /**
   * ==============================
   *  CONFIGURACION POR INSTANCIA
   * ==============================
   */
  const readConfig = (root) => {
    let raw = {};

    try {
      raw = JSON.parse(root.getAttribute("data-config") || "{}") || {};
    } catch (error) {
      raw = {};
    }

    const texts = { ...CONFIG.TEXTS };
    Object.entries(asMap(raw.texts)).forEach(([key, value]) => {
      const text = String(value ?? "").trim();
      if (text && key in texts) {
        texts[key] = text;
      }
    });

    return {
      endpoint: String(raw.endpoint || "").trim() || CONFIG.ENDPOINT,
      specialRedirects: asMap(raw.specialRedirects),
      categoryOverrides: asMap(raw.categoryOverrides),
      venueLabels: Object.fromEntries(
        Object.entries(asMap(raw.venueLabels))
          .map(([key, label]) => [normalizeText(key), String(label ?? "").trim()])
          .filter(([key, label]) => key && label)
      ),
      showCount: raw.showCount === true,
      texts,
    };
  };

  /**
   * ==============================
   *  FECHAS (siempre en horario de Madrid)
   * ==============================
   */
  const CLOUDARI_TZ = "Europe/Madrid";

  const madridYmd = (() => {
    let fmt = null;
    return (date) => {
      if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
        return "";
      }

      if (!fmt) {
        fmt = new Intl.DateTimeFormat("en-GB", {
          timeZone: CLOUDARI_TZ,
          year: "numeric",
          month: "2-digit",
          day: "2-digit",
        });
      }

      const out = {};
      fmt.formatToParts(date).forEach((part) => {
        out[part.type] = part.value;
      });

      return `${out.year}-${out.month}-${out.day}`;
    };
  })();

  const formatDateFull = (value) => {
    if (!value) {
      return "";
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return "";
    }

    return date.toLocaleDateString("es-ES", {
      timeZone: CLOUDARI_TZ,
      day: "2-digit",
      month: "long",
      year: "numeric",
    });
  };

  const formatRange = (startValue, endValue) => {
    if (!startValue || !endValue) {
      return formatDateFull(startValue || endValue || "");
    }

    const start = new Date(startValue);
    const end = new Date(endValue);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
      return formatDateFull(startValue || endValue);
    }

    const sameDay = madridYmd(start) !== "" && madridYmd(start) === madridYmd(end);

    return sameDay
      ? formatDateFull(start)
      : `Del ${formatDateFull(start)} al ${formatDateFull(end)}`;
  };

  const formatEventDateLabel = (eventItem) => {
    if (eventItem?.cloudari?.permanent) {
      return "Información permanente";
    }

    const label =
      formatRange(eventItem?.start || "", eventItem?.end || "") ||
      "Fecha pendiente";

    return eventItem?.cloudari?.time_tba
      ? `${label} · Horario pendiente`
      : label;
  };

  /**
   * ==============================
   *  EVENTOS, ENLACES Y CATEGORIAS
   * ==============================
   */
  const getEventLookupId = (eventItem) =>
    String(eventItem?.event_id ?? eventItem?.id ?? "").trim();

  const getEventUrl = (config, eventItem) => {
    const eventId = getEventLookupId(eventItem);

    if (eventId && config.specialRedirects[eventId]) {
      return String(config.specialRedirects[eventId]).trim();
    }

    return String(eventItem?.url || "").trim();
  };

  const getCtaLabel = (config, eventItem) => {
    const value = String(eventItem?.cloudari?.cta_label || "").trim();
    return value || config.texts.cta;
  };

  const pickTextColor = (hexColor) => {
    const red = parseInt(hexColor.slice(1, 3), 16);
    const green = parseInt(hexColor.slice(3, 5), 16);
    const blue = parseInt(hexColor.slice(5, 7), 16);
    const yiq = (red * 299 + green * 587 + blue * 114) / 1000;

    return yiq >= 160 ? "#000000" : "#FFFFFF";
  };

  const detectCanonicalKey = (config, eventItem) => {
    const overrideKey = String(
      config.categoryOverrides[getEventLookupId(eventItem)] || ""
    )
      .trim()
      .toLowerCase();

    if (overrideKey && CONFIG.CATEGORY_MAP[overrideKey]) {
      return overrideKey;
    }

    const category = eventItem?.category || {};
    const codeCandidates = [
      category?.custom?.code,
      category?.code,
      category?.parent?.code,
      category?.slug,
      category?.custom?.slug,
    ]
      .filter(Boolean)
      .map(normalizeCode);

    for (const code of codeCandidates) {
      if (CONFIG.CATEGORY_CODE_MAP[code]) {
        return CONFIG.CATEGORY_CODE_MAP[code];
      }

      const partial = Object.keys(CONFIG.CATEGORY_CODE_MAP).find((candidate) =>
        code.includes(candidate)
      );
      if (partial) {
        return CONFIG.CATEGORY_CODE_MAP[partial];
      }
    }

    const haystack = normalizeText(
      [
        eventItem?.title,
        category?.name,
        category?.description,
        category?.code,
        category?.slug,
        category?.custom?.description,
        category?.custom?.code,
        category?.custom?.slug,
      ]
        .filter(Boolean)
        .join(" ")
    );

    return (
      Object.keys(CONFIG.CATEGORY_KEYWORDS).find((key) =>
        CONFIG.CATEGORY_KEYWORDS[key].some((keyword) =>
          haystack.includes(normalizeText(keyword))
        )
      ) || ""
    );
  };

  const getCategoryDescriptor = (config, eventItem) => {
    const category = eventItem?.category || {};
    const fallback = CONFIG.CATEGORY_MAP[CONFIG.DEFAULT_CATEGORY_KEY];
    const canonicalKey = detectCanonicalKey(config, eventItem);
    const isManual =
      Boolean(eventItem?.cloudari?.manual) ||
      String(eventItem?.source || "").trim().toLowerCase() === "manual";

    let key = "";
    let label = "";
    let className = "";

    if (canonicalKey) {
      key = canonicalKey;
      label = CONFIG.CATEGORY_MAP[canonicalKey].label;
      className = CONFIG.CATEGORY_MAP[canonicalKey].className;
    } else if (isManual) {
      // Los eventos manuales pueden traer una categoria propia con su color.
      key = String(category?.slug || category?.custom?.code || "")
        .trim()
        .toLowerCase();
      label = String(
        category?.name ||
          category?.description ||
          category?.custom?.description ||
          category?.custom?.code ||
          category?.code ||
          ""
      ).trim() || toTitleCaseLabel(key);
    }

    if (!key) {
      key = CONFIG.DEFAULT_CATEGORY_KEY;
      label = fallback.label;
      className = fallback.className;
    }

    const rawColor = className
      ? ""
      : String(
          eventItem?.cloudari?.category_color ||
            category?.custom?.color ||
            category?.color ||
            ""
        )
          .trim()
          .replace(/^#?/, "#");

    const color = /^#[0-9A-Fa-f]{6}$/.test(rawColor) ? rawColor : "";

    return {
      key,
      label: label || fallback.label,
      className,
      style: color
        ? `style="background:${esc(color)};color:${esc(pickTextColor(color))} !important;"`
        : "",
    };
  };

  /**
   * ==============================
   *  ESPACIOS
   * ==============================
   */
  const getVenueKey = (venue) =>
    String(venue?.slug || venue?.id || venue?.name || "").trim();

  // Nombre que se pinta en el selector: el del widget si hay uno para ese
  // espacio (por nombre o por slug); si no, el que llega de la API.
  const getVenueLabel = (config, venue) =>
    config.venueLabels[normalizeText(venue?.name)] ||
    config.venueLabels[normalizeText(venue?.slug)] ||
    String(venue?.name || "Espacio");

  const getVenueEvents = (venue) =>
    Array.isArray(venue?.events) ? venue.events : [];

  const formatShowCount = (config, count) =>
    `${count} ${count === 1 ? config.texts.showSingular : config.texts.showPlural}`;

  /**
   * ==============================
   *  RENDER
   * ==============================
   */
  const CALENDAR_ICON = `
    <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
      <path d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 16H5V9h14v11z" fill="currentColor"/>
    </svg>
  `;

  const STAGE_ICON = `
    <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
      <path d="M4 5h16a1 1 0 0 1 1 1v9a3 3 0 0 1-3 3h-3.5l1.2 2.4a1 1 0 1 1-1.8.9L12.9 18h-1.8l-1.1 2.3a1 1 0 0 1-1.8-.9L9.5 18H6a3 3 0 0 1-3-3V6a1 1 0 0 1 1-1Zm1 2v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V7H5Zm2 1.5a1 1 0 0 1 1 1V13a1 1 0 1 1-2 0V9.5a1 1 0 0 1 1-1Zm10 0a1 1 0 0 1 1 1V13a1 1 0 1 1-2 0V9.5a1 1 0 0 1 1-1Z" fill="currentColor"/>
    </svg>
  `;

  const renderSkeleton = (state) => {
    state.$tabs.innerHTML = `
      <span class="cbp-tab--skeleton cbp-skel" aria-hidden="true"></span>
      <span class="cbp-tab--skeleton cbp-skel" aria-hidden="true"></span>
    `;

    const card = `
      <article class="cbp-card">
        <div class="cbp-media cbp-skel"></div>
        <div class="cbp-body">
          <div class="cbp-skel" style="height:20px;width:72%;border-radius:6px"></div>
          <div class="cbp-skel" style="height:14px;width:58%;border-radius:6px"></div>
          <div class="cbp-skel" style="height:14px;width:52%;border-radius:6px"></div>
          <div class="cbp-skel" style="height:32px;width:140px;border-radius:9999px"></div>
        </div>
      </article>
    `;

    state.$list.innerHTML = `
      <section class="cbp-panel" aria-busy="true">
        <div class="cbp-grid">${card}${card}</div>
      </section>
    `;
  };

  const renderEmpty = (state, message) => {
    state.$tabs.innerHTML = "";
    state.$list.innerHTML = `<p class="cbp-empty">${esc(message)}</p>`;
  };

  const buildEventCard = (config, eventItem, venue, index) => {
    const category = getCategoryDescriptor(config, eventItem);
    const url = getEventUrl(config, eventItem);
    const title = String(eventItem?.title || "Evento").trim() || "Evento";
    const venueName = String(
      eventItem?.venue?.name || venue?.name || "Espacio"
    ).trim();
    const ctaLabel = getCtaLabel(config, eventItem);
    const ctaAria = esc(`${ctaLabel} para ${title}`);
    const attrs =
      index < 4
        ? 'loading="eager" fetchpriority="high" decoding="async"'
        : 'loading="lazy" fetchpriority="low" decoding="async"';

    return `
      <article class="cbp-card">
        <div class="cbp-media"${
          url
            ? ` data-url="${esc(url)}" role="link" tabindex="0" aria-label="${ctaAria}"`
            : ""
        }>
          <img ${attrs} src="${esc(
            eventItem?.image || CONFIG.IMG_PLACEHOLDER
          )}" alt="${esc(title)} - cartel" referrerpolicy="no-referrer">
        </div>
        <div class="cbp-topbar"></div>
        <div class="cbp-body">
          <h3 class="cbp-title">${
            url
              ? `<a class="cbp-title-link" href="${esc(url)}" target="_blank" rel="noopener noreferrer" aria-label="${ctaAria}">${esc(title)}</a>`
              : esc(title)
          }</h3>
          <div class="cbp-meta cbp-meta--date" aria-label="Fecha del evento">
            ${CALENDAR_ICON}
            <span class="cbp-meta__text">${esc(formatEventDateLabel(eventItem))}</span>
          </div>
          <div class="cbp-meta" aria-label="Espacio">
            ${STAGE_ICON}
            <span class="cbp-meta__text">${esc(venueName)}</span>
          </div>
          <div class="cbp-meta-row">
            <span class="cbp-pill cbp-pill--cat ${esc(category.className)}" ${category.style}>${esc(
              category.label
            )}</span>
            ${
              url
                ? `<a class="cbp-pill cbp-pill--cta" href="${esc(url)}" target="_blank" rel="noopener noreferrer" aria-label="${ctaAria}"><span>${esc(ctaLabel)}</span></a>`
                : `<span class="cbp-pill cbp-pill--cta cbp-pill--disabled" aria-disabled="true">Info</span>`
            }
          </div>
        </div>
      </article>
    `;
  };

  const getFilteredEvents = (state, venue) => {
    const { config } = state;
    const query = normalizeText(state.$search?.value || "");
    const selectedCategory = String(state.$category?.value || "all").trim();

    return getVenueEvents(venue).filter((eventItem) => {
      const matchesQuery =
        !query || normalizeText(eventItem?.title || "").includes(query);
      const matchesCategory =
        selectedCategory === "all" ||
        getCategoryDescriptor(config, eventItem).key === selectedCategory;

      return matchesQuery && matchesCategory;
    });
  };

  const populateCategoryFilter = (state, venue) => {
    if (!state.$category) {
      return;
    }

    const { config } = state;
    const selectedValue = String(state.$category.value || "all").trim();
    const present = new Map();

    getVenueEvents(venue).forEach((eventItem) => {
      const category = getCategoryDescriptor(config, eventItem);
      if (category.key && !present.has(category.key)) {
        present.set(category.key, category.label || category.key);
      }
    });

    const ordered = [
      ...CONFIG.CATEGORY_ORDER.filter((key) => present.has(key)).map((key) => [
        key,
        CONFIG.CATEGORY_MAP[key].label,
      ]),
      ...Array.from(present.entries())
        .filter(([key]) => !CONFIG.CATEGORY_MAP[key])
        .sort((left, right) =>
          String(left[1]).localeCompare(String(right[1]), "es", {
            sensitivity: "base",
          })
        ),
    ];

    state.$category.innerHTML = "";

    [["all", config.texts.allCategories], ...ordered].forEach(([key, label]) => {
      const option = document.createElement("option");
      option.value = key;
      option.textContent = label;
      state.$category.appendChild(option);
    });

    state.$category.value = present.has(selectedValue) ? selectedValue : "all";
  };

  const buildVenuePanel = (state, venue, tabId) => {
    const { config } = state;
    const sourceEvents = getVenueEvents(venue);
    const events = getFilteredEvents(state, venue);
    const venueName = String(venue?.name || "Espacio").trim() || "Espacio";

    const body = events.length
      ? `<div class="cbp-grid">${events
          .map((eventItem, index) => buildEventCard(config, eventItem, venue, index))
          .join("")}</div>`
      : `<p class="cbp-empty">${esc(
          sourceEvents.length ? config.texts.emptyFiltered : config.texts.emptyVenue
        )}</p>`;

    return `
      <section class="cbp-panel" id="${esc(state.panelId)}" role="tabpanel" aria-labelledby="${esc(tabId)}">
        <h3 class="cbp-sr-only">${esc(venueName)}</h3>
        ${body}
      </section>
    `;
  };

  const buildTabs = (state, activeKey) =>
    state.venues
      .map((venue, index) => {
        const venueKey = getVenueKey(venue) || `venue-${index}`;
        const isActive = venueKey === activeKey;
        const count = state.config.showCount
          ? `<span class="cbp-tab__count">${esc(
              formatShowCount(state.config, getVenueEvents(venue).length)
            )}</span>`
          : "";

        return `
          <button
            type="button"
            id="${esc(`${state.widgetId}-tab-${index}`)}"
            class="cbp-tab"
            role="tab"
            aria-selected="${isActive ? "true" : "false"}"
            aria-controls="${esc(state.panelId)}"
            tabindex="${isActive ? "0" : "-1"}"
            data-venue-key="${esc(venueKey)}"
          >
            <span class="cbp-tab__label">${esc(getVenueLabel(state.config, venue))}</span>
            ${count}
          </button>
        `;
      })
      .join("");

  // Coloca la pastilla que marca el espacio activo bajo su pestana. Mide los
  // cuatro lados porque en movil los espacios se apilan en vertical.
  const syncTabThumb = (state) => {
    const activeButton = state.$tabs.querySelector(
      'button[role="tab"][aria-selected="true"]'
    );
    if (!activeButton) {
      return;
    }

    const left = activeButton.offsetLeft;
    const right = Math.max(
      0,
      state.$tabs.clientWidth - left - activeButton.offsetWidth
    );

    const top = activeButton.offsetTop;
    const bottom = Math.max(
      0,
      state.$tabs.clientHeight - top - activeButton.offsetHeight
    );

    state.$tabs.style.setProperty("--cbp-thumb-l", `${left}px`);
    state.$tabs.style.setProperty("--cbp-thumb-r", `${right}px`);
    state.$tabs.style.setProperty("--cbp-thumb-t", `${top}px`);
    state.$tabs.style.setProperty("--cbp-thumb-b", `${bottom}px`);

    if (!state.thumbReady) {
      state.thumbReady = true;
      // La primera colocacion no se anima; las siguientes si.
      window.requestAnimationFrame(() => {
        window.requestAnimationFrame(() => {
          state.$tabs.classList.add("is-thumb-ready");
        });
      });
    }
  };

  const scrollActiveTabIntoView = (state) => {
    const scroller = state.$tabsScroller;
    const activeButton = state.$tabs.querySelector(
      'button[role="tab"][aria-selected="true"]'
    );
    if (!scroller || !activeButton) {
      return;
    }

    const maxScrollLeft = Math.max(0, scroller.scrollWidth - scroller.clientWidth);
    if (maxScrollLeft <= 0) {
      return;
    }

    const centered =
      activeButton.offsetLeft - (scroller.clientWidth - activeButton.offsetWidth) / 2;

    scroller.scrollTo({
      left: Math.min(maxScrollLeft, Math.max(0, centered)),
      behavior: "smooth",
    });
  };

  const renderWidget = (state, nextActiveKey, focusActive = false) => {
    const { venues } = state;
    const venue =
      venues.find((item) => getVenueKey(item) === nextActiveKey) || venues[0];

    if (!venue) {
      renderEmpty(state, state.config.texts.emptyAll);
      return;
    }

    const activeKey = getVenueKey(venue);
    const activeIndex = Math.max(venues.indexOf(venue), 0);

    state.activeKey = activeKey;
    state.$tabs.innerHTML = buildTabs(state, activeKey);
    populateCategoryFilter(state, venue);
    state.$list.innerHTML = buildVenuePanel(
      state,
      venue,
      `${state.widgetId}-tab-${activeIndex}`
    );
    syncTabThumb(state);

    if (focusActive) {
      state.$tabs
        .querySelector('button[role="tab"][aria-selected="true"]')
        ?.focus();
    }

    scrollActiveTabIntoView(state);
  };

  /**
   * ==============================
   *  EVENTOS DE UI
   * ==============================
   */
  const debounce = (fn, waitMs = 160) => {
    let timeoutId = 0;

    return (...args) => {
      window.clearTimeout(timeoutId);
      timeoutId = window.setTimeout(() => fn(...args), waitMs);
    };
  };

  const openMediaUrl = (mediaEl) => {
    const url = String(mediaEl?.dataset?.url || "").trim();
    if (url) {
      window.open(url, "_blank", "noopener,noreferrer");
    }
  };

  const bindWidgetEvents = (state) => {
    state.$tabs.addEventListener("click", (event) => {
      const button = event.target.closest('button[role="tab"]');
      if (button) {
        renderWidget(state, button.dataset.venueKey || "", true);
      }
    });

    state.$tabs.addEventListener("keydown", (event) => {
      const button = event.target.closest('button[role="tab"]');
      if (!button) {
        return;
      }

      const buttons = Array.from(
        state.$tabs.querySelectorAll('button[role="tab"]')
      );
      const currentIndex = Math.max(buttons.indexOf(button), 0);
      const targets = {
        ArrowRight: buttons[(currentIndex + 1) % buttons.length],
        ArrowLeft: buttons[(currentIndex - 1 + buttons.length) % buttons.length],
        Home: buttons[0],
        End: buttons[buttons.length - 1],
      };
      const target = targets[event.key];

      if (target) {
        event.preventDefault();
        renderWidget(state, target.dataset.venueKey || "", true);
      }
    });

    const applyFilters = () => renderWidget(state, state.activeKey);

    state.$search?.addEventListener("input", debounce(applyFilters, 160), {
      passive: true,
    });
    state.$category?.addEventListener("change", applyFilters, {
      passive: true,
    });

    state.$list.addEventListener("click", (event) => {
      const mediaEl = event.target.closest(".cbp-media[data-url]");
      if (mediaEl && state.$list.contains(mediaEl)) {
        event.preventDefault();
        openMediaUrl(mediaEl);
      }
    });

    state.$list.addEventListener("keydown", (event) => {
      const mediaEl = event.target.closest(".cbp-media[data-url]");
      if (
        mediaEl &&
        state.$list.contains(mediaEl) &&
        (event.key === "Enter" || event.key === " ")
      ) {
        event.preventDefault();
        openMediaUrl(mediaEl);
      }
    });

    // La pastilla depende de medidas reales: se recoloca al cambiar el ancho
    // o al terminar de cargar las fuentes.
    const refreshThumb = () => syncTabThumb(state);

    window.addEventListener("resize", refreshThumb, { passive: true });

    if (typeof ResizeObserver !== "undefined") {
      new ResizeObserver(refreshThumb).observe(state.$tabs);
    }

    document.fonts?.ready?.then(refreshThumb).catch(() => {});
  };

  /**
   * ==============================
   *  DATOS
   * ==============================
   */
  const fetchVenues = (endpoint) => {
    if (!venuesPromises.has(endpoint)) {
      venuesPromises.set(
        endpoint,
        fetch(endpoint, {
          method: "GET",
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        })
          .then((response) => {
            if (!response.ok) {
              throw new Error(`HTTP ${response.status}`);
            }

            return response.json();
          })
          .then((payload) => (Array.isArray(payload?.data) ? payload.data : []))
          .catch((error) => {
            venuesPromises.delete(endpoint);
            throw error;
          })
      );
    }

    return venuesPromises.get(endpoint);
  };

  /**
   * ==============================
   *  ARRANQUE
   * ==============================
   */
  const init = async (root) => {
    if (!root || root.dataset.cloudariBillboardPavonReady === "1") {
      return;
    }

    const $tabs = root.querySelector('[data-role="tabs"]');
    const $list = root.querySelector('[data-role="list"]');
    if (!$tabs || !$list) {
      return;
    }

    root.dataset.cloudariBillboardPavonReady = "1";
    instanceCounter += 1;

    const state = {
      root,
      config: readConfig(root),
      venues: [],
      $tabs,
      $tabsScroller: root.querySelector('[data-role="tabs-scroller"]'),
      $search: root.querySelector('[data-role="search"]'),
      $category: root.querySelector('[data-role="category"]'),
      $list,
      activeKey: "",
      thumbReady: false,
      widgetId: `cloudari-billboard-pavon-${instanceCounter}`,
      panelId: `cloudari-billboard-pavon-panel-${instanceCounter}`,
    };

    renderSkeleton(state);

    try {
      state.venues = await fetchVenues(state.config.endpoint);
    } catch (error) {
      renderEmpty(state, state.config.texts.error);
      return;
    }

    if (!state.venues.length) {
      renderEmpty(state, state.config.texts.emptyAll);
      return;
    }

    bindWidgetEvents(state);
    renderWidget(state, getVenueKey(state.venues[0]));
  };

  const initAll = (scope) => {
    (scope || document).querySelectorAll(SELECTOR).forEach((root) => {
      init(root);
    });
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => initAll(document), {
      once: true,
    });
  } else {
    initAll(document);
  }

  // Editor y previsualizacion de Elementor: el widget se reinyecta en cada cambio.
  // `elementor/frontend/init` se dispara con jQuery.trigger, que no despacha un
  // evento DOM nativo, asi que addEventListener no serviria aqui.
  const registerElementorHook = () => {
    if (!window.elementorFrontend || !window.elementorFrontend.hooks) {
      return false;
    }

    window.elementorFrontend.hooks.addAction(
      `frontend/element_ready/${ELEMENTOR_WIDGET}.default`,
      ($scope) => {
        init($scope && $scope[0] ? $scope[0].querySelector(SELECTOR) : null);
      }
    );

    return true;
  };

  if (!registerElementorHook() && window.jQuery) {
    window.jQuery(window).on("elementor/frontend/init", registerElementorHook);
  }
})();
