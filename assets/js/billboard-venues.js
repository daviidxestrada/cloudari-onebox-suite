(() => {
  "use strict";

  const CONFIG = Object.freeze({
    ENDPOINT: "/wp-json/cloudari/v1/billboard-venues",
    IMG_PLACEHOLDER:
      "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==",
    DEFAULT_CATEGORY_KEY: "teatro",
    CATEGORY_MAP: {
      teatro: { label: "Teatro", className: "obx-cat--teatro" },
      musica: { label: "Musica", className: "obx-cat--musica" },
      musical: { label: "Musical", className: "obx-cat--musical" },
      humor: { label: "Humor", className: "obx-cat--humor" },
      talk: { label: "Talk", className: "obx-cat--talk" },
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
  });

  let venuesPromise = null;
  let instanceCounter = 0;

  const esc = (value) =>
    String(value ?? "").replace(/[&<>\"']/g, (match) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;",
    })[match]);

  const inBrowser = () =>
    typeof window !== "undefined" && typeof document !== "undefined";

  const getEnv = () => {
    const raw = window.cloudariBillboardVenues || {};
    return raw && typeof raw === "object" ? raw : {};
  };

  const getEndpoint = () => {
    const value = String(getEnv().endpoint || "").trim();
    return value || CONFIG.ENDPOINT;
  };

  const getSpecialRedirects = () => {
    const map = getEnv().specialRedirects || {};
    return map && typeof map === "object" ? map : {};
  };

  const getCategoryOverrides = () => {
    const map = getEnv().categoryOverrides || {};
    return map && typeof map === "object" ? map : {};
  };

  const normalizeText = (value) =>
    String(value || "")
      .toLowerCase()
      .normalize("NFD")
      .replace(/\p{Diacritic}/gu, "")
      .trim();

  const normalizeCode = (value) => String(value || "").toUpperCase().trim();

  const sameDay = (left, right) =>
    left &&
    right &&
    left.getFullYear() === right.getFullYear() &&
    left.getMonth() === right.getMonth() &&
    left.getDate() === right.getDate();

  const formatDateFull = (value) => {
    if (!value) {
      return "";
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return "";
    }

    return date.toLocaleDateString("es-ES", {
      day: "2-digit",
      month: "long",
      year: "numeric",
    });
  };

  const formatRange = (startValue, endValue) => {
    if (!startValue && !endValue) {
      return "";
    }

    if (startValue && !endValue) {
      return formatDateFull(startValue);
    }

    if (!startValue && endValue) {
      return formatDateFull(endValue);
    }

    const start = new Date(startValue);
    const end = new Date(endValue);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
      return formatDateFull(startValue || endValue || "");
    }

    return sameDay(start, end)
      ? formatDateFull(start)
      : `Del ${formatDateFull(start)} al ${formatDateFull(end)}`;
  };

  const getEventLookupId = (eventItem) =>
    String(eventItem?.event_id ?? eventItem?.id ?? "").trim();

  const getEventUrl = (eventItem) => {
    const eventId = getEventLookupId(eventItem);
    const overrides = getSpecialRedirects();

    if (eventId && overrides[eventId]) {
      return String(overrides[eventId]).trim();
    }

    return String(eventItem?.url || "").trim();
  };

  const pickTextColor = (hexColor) => {
    const color = String(hexColor || "").trim();
    if (!/^#[0-9A-Fa-f]{6}$/.test(color)) {
      return "#FFFFFF";
    }

    const red = parseInt(color.slice(1, 3), 16);
    const green = parseInt(color.slice(3, 5), 16);
    const blue = parseInt(color.slice(5, 7), 16);
    const yiq = (red * 299 + green * 587 + blue * 114) / 1000;

    return yiq >= 160 ? "#000000" : "#FFFFFF";
  };

  const detectCanonicalKey = (eventItem) => {
    const overrideKey = String(
      getCategoryOverrides()[getEventLookupId(eventItem)] || ""
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
      ) || CONFIG.DEFAULT_CATEGORY_KEY
    );
  };

  const getCategoryDescriptor = (eventItem) => {
    const category = eventItem?.category || {};
    const canonicalKey = detectCanonicalKey(eventItem);
    const canonical = canonicalKey ? CONFIG.CATEGORY_MAP[canonicalKey] : null;

    const label = String(
      canonical?.label ||
        category?.name ||
        category?.description ||
        category?.custom?.description ||
        category?.custom?.code ||
        category?.code ||
        ""
    ).trim();

    const rawColor = String(
      eventItem?.cloudari?.category_color ||
        category?.custom?.color ||
        category?.color ||
        ""
    ).trim();

    const color = /^#[0-9A-Fa-f]{6}$/.test(rawColor)
      ? rawColor
      : /^#[0-9A-Fa-f]{6}$/.test(`#${rawColor}`)
        ? `#${rawColor}`
        : "";

    return {
      label,
      className: canonical?.className || "",
      style: color
        ? `style="background:${esc(color)};border-color:${esc(color)};color:${esc(
            pickTextColor(color)
          )};"`
        : "",
    };
  };

  const getVenueName = (eventItem, venue) =>
    String(eventItem?.venue?.name || venue?.name || "Espacio").trim();

  const getVenueKey = (venue) =>
    String(venue?.slug || venue?.id || venue?.name || "").trim();

  const getCtaLabel = (eventItem) => {
    const value = String(eventItem?.cloudari?.cta_label || "").trim();
    return value || "Entradas";
  };

  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

  const getMaxScrollLeft = ($scroller) =>
    Math.max(0, ($scroller?.scrollWidth || 0) - ($scroller?.clientWidth || 0));

  const renderSkeleton = ($tabs, $list) => {
    if ($tabs) {
      $tabs.innerHTML = `
        <span class="obxv-tab obxv-tab--skeleton obx-skel" aria-hidden="true"></span>
        <span class="obxv-tab obxv-tab--skeleton obx-skel" aria-hidden="true"></span>
        <span class="obxv-tab obxv-tab--skeleton obx-skel" aria-hidden="true"></span>
      `;
    }

    $list.innerHTML = `
      <section class="obxv-panel" aria-busy="true">
        <div class="obx-grid obxv-grid obxv-skeleton">
          <article class="obx-card">
            <div class="obx-media obx-skel" style="aspect-ratio:16/9"></div>
            <div class="obx-body">
              <div class="obx-skel" style="height:20px;width:72%;border-radius:6px"></div>
              <div class="obx-skel" style="height:14px;width:58%;border-radius:6px"></div>
              <div class="obx-skel" style="height:14px;width:52%;border-radius:6px"></div>
              <div class="obx-skel" style="height:32px;width:140px;border-radius:9999px"></div>
            </div>
          </article>
          <article class="obx-card">
            <div class="obx-media obx-skel" style="aspect-ratio:16/9"></div>
            <div class="obx-body">
              <div class="obx-skel" style="height:20px;width:66%;border-radius:6px"></div>
              <div class="obx-skel" style="height:14px;width:62%;border-radius:6px"></div>
              <div class="obx-skel" style="height:14px;width:48%;border-radius:6px"></div>
              <div class="obx-skel" style="height:32px;width:140px;border-radius:9999px"></div>
            </div>
          </article>
        </div>
      </section>
    `;
  };

  const renderEmpty = ($tabs, $list, message) => {
    if ($tabs) {
      $tabs.innerHTML = "";
    }

    $list.innerHTML = `<p class="obx-empty">${esc(message)}</p>`;
  };

  const stageIcon = `
    <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
      <path d="M4 5h16a1 1 0 0 1 1 1v9a3 3 0 0 1-3 3h-3.5l1.2 2.4a1 1 0 1 1-1.8.9L12.9 18h-1.8l-1.1 2.3a1 1 0 0 1-1.8-.9L9.5 18H6a3 3 0 0 1-3-3V6a1 1 0 0 1 1-1Zm1 2v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V7H5Zm2 1.5a1 1 0 0 1 1 1V13a1 1 0 1 1-2 0V9.5a1 1 0 0 1 1-1Zm10 0a1 1 0 0 1 1 1V13a1 1 0 1 1-2 0V9.5a1 1 0 0 1 1-1Z" fill="currentColor"/>
    </svg>
  `;

  const buildEventCard = (eventItem, venue, index) => {
    const category = getCategoryDescriptor(eventItem);
    const url = getEventUrl(eventItem);
    const title = String(eventItem?.title || "Evento").trim() || "Evento";
    const dateLabel =
      formatRange(eventItem?.start || "", eventItem?.end || "") ||
      "Fecha pendiente";
    const venueName = getVenueName(eventItem, venue);
    const eager = index < 4;
    const attrs = eager
      ? 'loading="eager" fetchpriority="high" decoding="async"'
      : 'loading="lazy" fetchpriority="low" decoding="async"';

    return `
      <article class="obx-card">
        <div class="obx-media">
          <img ${attrs} src="${esc(
            eventItem?.image || CONFIG.IMG_PLACEHOLDER
          )}" alt="${esc(title)} - cartel" referrerpolicy="no-referrer">
        </div>
        <div class="obx-topbar"></div>
        <div class="obx-body">
          <h3 class="obx-h3">${esc(title)}</h3>
          <div class="obx-datebar" aria-label="Fecha del evento">
            <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
              <path d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 16H5V9h14v11z" fill="currentColor"/>
            </svg>
            <span class="obx-datebar__text">${esc(dateLabel)}</span>
          </div>
          <div class="obx-locationbar" aria-label="Espacio">
            ${stageIcon}
            <span class="obx-locationbar__text">${esc(venueName)}</span>
          </div>
          <div class="obx-metaRow">
            ${
              category.label
                ? `<span class="obx-pill obx-pill--cat ${esc(category.className)}" ${category.style}>${esc(
                    category.label
                  )}</span>`
                : ""
            }
            ${
              url
                ? `<a class="obx-pill obx-pill--cta" href="${esc(
                    url
                  )}" target="_blank" rel="noopener noreferrer" aria-label="${esc(
                    `${getCtaLabel(eventItem)} para ${title}`
                  )}"><span>${esc(getCtaLabel(eventItem))}</span></a>`
                : `<span class="obx-pill obx-pill--cta obx-pill--disabled" aria-disabled="true">Info</span>`
            }
          </div>
        </div>
      </article>
    `;
  };

  const buildVenuePanel = (venue, panelId, tabId) => {
    const events = Array.isArray(venue?.events) ? venue.events : [];
    const venueName = String(venue?.name || "Espacio").trim() || "Espacio";

    if (!events.length) {
      return `
        <section class="obxv-panel" id="${esc(panelId)}" role="tabpanel" aria-labelledby="${esc(
          tabId
        )}">
          <h3 class="sr-only">${esc(venueName)}</h3>
          <p class="obx-empty">No hay eventos proximos para este espacio.</p>
        </section>
      `;
    }

    return `
      <section class="obxv-panel" id="${esc(panelId)}" role="tabpanel" aria-labelledby="${esc(
        tabId
      )}">
        <h3 class="sr-only">${esc(venueName)}</h3>
        <div class="obx-grid obxv-grid">
          ${events
            .map((eventItem, index) => buildEventCard(eventItem, venue, index))
            .join("")}
        </div>
      </section>
    `;
  };

  const buildTabs = (venues, activeKey, panelId, widgetId) =>
    venues
      .map((venue, index) => {
        const venueKey = getVenueKey(venue) || `venue-${index}`;
        const tabId = `${widgetId}-tab-${index}`;
        const isActive = venueKey === activeKey;

        return `
          <button
            type="button"
            id="${esc(tabId)}"
            class="obxv-tab"
            role="tab"
            aria-selected="${isActive ? "true" : "false"}"
            aria-controls="${esc(panelId)}"
            tabindex="${isActive ? "0" : "-1"}"
            data-venue-key="${esc(venueKey)}"
          >
            <span class="obxv-tab__label">${esc(venue?.name || "Espacio")}</span>
          </button>
        `;
      })
      .join("");

  const updateScrollerState = (state) => {
    if (!state.$tabsScroller || !state.$tabsWrap) {
      return;
    }

    const maxScrollLeft = getMaxScrollLeft(state.$tabsScroller);
    const scrollLeft = state.$tabsScroller.scrollLeft;

    state.$tabsWrap.classList.toggle("is-scrollable", maxScrollLeft > 2);
    state.$tabsWrap.classList.toggle("is-at-start", scrollLeft <= 2);
    state.$tabsWrap.classList.toggle(
      "is-at-end",
      scrollLeft >= maxScrollLeft - 2
    );
  };

  const queueScrollerStateUpdate = (state) => {
    if (!state || state.scrollStateFrame) {
      return;
    }

    state.scrollStateFrame = window.requestAnimationFrame(() => {
      state.scrollStateFrame = 0;
      updateScrollerState(state);
    });
  };

  const bindScrollerState = (state) => {
    if (!state.$tabsScroller || !state.$tabsWrap) {
      return;
    }

    const refresh = () => queueScrollerStateUpdate(state);

    state.$tabsScroller.addEventListener("scroll", refresh, { passive: true });
    window.addEventListener("resize", refresh, { passive: true });

    if (typeof ResizeObserver !== "undefined") {
      const resizeObserver = new ResizeObserver(refresh);
      resizeObserver.observe(state.$tabsScroller);
      resizeObserver.observe(state.$tabs);
      state.resizeObserver = resizeObserver;
    }

    refresh();
  };

  const bindScrollerDrag = (state) => {
    if (!state.$tabsScroller) {
      return;
    }

    let activePointerId = null;
    let startX = 0;
    let startScrollLeft = 0;
    let movedEnoughToDrag = false;

    const stopDragging = (event) => {
      if (activePointerId === null) {
        return;
      }

      if (
        event &&
        typeof event.pointerId === "number" &&
        event.pointerId !== activePointerId
      ) {
        return;
      }

      if (state.$tabsScroller.hasPointerCapture?.(activePointerId)) {
        state.$tabsScroller.releasePointerCapture(activePointerId);
      }

      activePointerId = null;
      state.$tabsScroller.classList.remove("is-dragging");

      window.setTimeout(() => {
        state.didDragScroller = false;
      }, 0);

      queueScrollerStateUpdate(state);
    };

    state.$tabsScroller.addEventListener("pointerdown", (event) => {
      if (event.pointerType !== "mouse" || event.button !== 0) {
        return;
      }

      if (getMaxScrollLeft(state.$tabsScroller) <= 0) {
        return;
      }

      activePointerId = event.pointerId;
      startX = event.clientX;
      startScrollLeft = state.$tabsScroller.scrollLeft;
      movedEnoughToDrag = false;
      state.didDragScroller = false;

      state.$tabsScroller.classList.add("is-dragging");
      state.$tabsScroller.setPointerCapture?.(activePointerId);
    });

    state.$tabsScroller.addEventListener("pointermove", (event) => {
      if (activePointerId === null || event.pointerId !== activePointerId) {
        return;
      }

      const delta = event.clientX - startX;

      if (!movedEnoughToDrag && Math.abs(delta) > 4) {
        movedEnoughToDrag = true;
        state.didDragScroller = true;
      }

      if (!movedEnoughToDrag) {
        return;
      }

      state.$tabsScroller.scrollLeft = startScrollLeft - delta;
      event.preventDefault();
      queueScrollerStateUpdate(state);
    });

    state.$tabsScroller.addEventListener("pointerup", stopDragging);
    state.$tabsScroller.addEventListener("pointercancel", stopDragging);
    state.$tabsScroller.addEventListener("lostpointercapture", stopDragging);

    state.$tabs.addEventListener(
      "click",
      (event) => {
        if (!state.didDragScroller) {
          return;
        }

        event.preventDefault();
        event.stopPropagation();
        state.didDragScroller = false;
      },
      true
    );
  };

  const scrollActiveTabIntoView = (state) => {
    if (
      typeof window === "undefined" ||
      !window.matchMedia("(max-width: 1023.98px)").matches ||
      !state.$tabsScroller
    ) {
      return;
    }

    const activeButton = state.$tabs.querySelector(
      'button[role="tab"][aria-selected="true"]'
    );
    if (!activeButton) {
      return;
    }

    const scrollerRect = state.$tabsScroller.getBoundingClientRect();
    const buttonRect = activeButton.getBoundingClientRect();
    const maxScrollLeft = getMaxScrollLeft(state.$tabsScroller);
    const centeredOffset = (scrollerRect.width - buttonRect.width) / 2;
    const nextLeft = clamp(
      state.$tabsScroller.scrollLeft +
        (buttonRect.left - scrollerRect.left) -
        centeredOffset,
      0,
      maxScrollLeft
    );

    state.$tabsScroller.scrollTo({
      left: nextLeft,
      behavior: "smooth",
    });

    queueScrollerStateUpdate(state);
  };

  const renderWidget = (state, nextActiveKey, focusActive = false) => {
    const venues = state.venues;
    const fallbackKey = getVenueKey(venues[0]);
    const activeKey = nextActiveKey || fallbackKey;
    const venue =
      venues.find((item) => getVenueKey(item) === activeKey) || venues[0] || null;

    if (!venue) {
      renderEmpty(state.$tabs, state.$list, "No hay espacios con eventos proximos.");
      return;
    }

    const resolvedKey = getVenueKey(venue);
    const activeIndex = Math.max(
      venues.findIndex((item) => getVenueKey(item) === resolvedKey),
      0
    );
    const tabId = `${state.widgetId}-tab-${activeIndex}`;

    state.activeKey = resolvedKey;
    state.$tabs.innerHTML = buildTabs(
      venues,
      resolvedKey,
      state.panelId,
      state.widgetId
    );
    state.$list.innerHTML = buildVenuePanel(venue, state.panelId, tabId);
    queueScrollerStateUpdate(state);

    if (focusActive) {
      const activeButton = state.$tabs.querySelector(
        'button[role="tab"][aria-selected="true"]'
      );
      activeButton?.focus();
    }

    scrollActiveTabIntoView(state);
  };

  const focusSiblingTab = (state, currentButton, direction) => {
    const buttons = Array.from(
      state.$tabs.querySelectorAll('button[role="tab"]')
    );
    if (!buttons.length) {
      return;
    }

    const currentIndex = Math.max(buttons.indexOf(currentButton), 0);
    const nextIndex = (currentIndex + direction + buttons.length) % buttons.length;
    const nextButton = buttons[nextIndex];

    if (!nextButton) {
      return;
    }

    renderWidget(state, nextButton.dataset.venueKey || "", true);
  };

  const bindWidgetEvents = (state) => {
    state.$tabs.addEventListener("click", (event) => {
      const button = event.target.closest('button[role="tab"]');
      if (!button) {
        return;
      }

      renderWidget(state, button.dataset.venueKey || "", true);
    });

    state.$tabs.addEventListener("keydown", (event) => {
      const button = event.target.closest('button[role="tab"]');
      if (!button) {
        return;
      }

      if (event.key === "ArrowRight") {
        event.preventDefault();
        focusSiblingTab(state, button, 1);
        return;
      }

      if (event.key === "ArrowLeft") {
        event.preventDefault();
        focusSiblingTab(state, button, -1);
        return;
      }

      if (event.key === "Home") {
        event.preventDefault();
        const first = state.$tabs.querySelector('button[role="tab"]');
        if (first) {
          renderWidget(state, first.dataset.venueKey || "", true);
        }
        return;
      }

      if (event.key === "End") {
        event.preventDefault();
        const buttons = state.$tabs.querySelectorAll('button[role="tab"]');
        const last = buttons[buttons.length - 1];
        if (last) {
          renderWidget(state, last.dataset.venueKey || "", true);
        }
      }
    });
  };

  const fetchVenues = async () => {
    if (!venuesPromise) {
      venuesPromise = fetch(getEndpoint(), {
        method: "GET",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
        },
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
          }

          return response.json();
        })
        .then((payload) => (Array.isArray(payload?.data) ? payload.data : []))
        .catch((error) => {
          venuesPromise = null;
          throw error;
        });
    }

    return venuesPromise;
  };

  const initWidget = (root, venues) => {
    if (!root || root.dataset.cloudariBillboardVenuesReady === "1") {
      return;
    }

    const $tabs = root.querySelector('[data-role="tabs"]');
    const $tabsWrap = root.querySelector(".obxv-tabs-wrap");
    const $tabsScroller = root.querySelector('[data-role="tabs-scroller"]');
    const $list = root.querySelector('[data-role="list"]');
    if (!$tabs || !$tabsWrap || !$tabsScroller || !$list) {
      return;
    }

    root.dataset.cloudariBillboardVenuesReady = "1";

    if (!Array.isArray(venues) || !venues.length) {
      renderEmpty($tabs, $list, "No hay espacios con eventos proximos.");
      return;
    }

    instanceCounter += 1;
    const state = {
      root,
      venues,
      $tabs,
      $tabsWrap,
      $tabsScroller,
      $list,
      activeKey: getVenueKey(venues[0]),
      didDragScroller: false,
      widgetId: `cloudari-billboard-venues-${instanceCounter}`,
      panelId: `cloudari-billboard-venues-panel-${instanceCounter}`,
    };

    bindWidgetEvents(state);
    bindScrollerState(state);
    bindScrollerDrag(state);
    renderWidget(state, state.activeKey);
  };

  const boot = async () => {
    if (!inBrowser()) {
      return;
    }

    const roots = Array.from(
      document.querySelectorAll("[data-cloudari-billboard-venues]")
    );
    if (!roots.length) {
      return;
    }

    roots.forEach((root) => {
      const $tabs = root.querySelector('[data-role="tabs"]');
      const $list = root.querySelector('[data-role="list"]');

      if ($list) {
        renderSkeleton($tabs, $list);
      }
    });

    try {
      const venues = await fetchVenues();

      roots.forEach((root) => {
        initWidget(root, venues);
      });
    } catch (error) {

      roots.forEach((root) => {
        const $tabs = root.querySelector('[data-role="tabs"]');
        const $list = root.querySelector('[data-role="list"]');

        if ($list) {
          renderEmpty($tabs, $list, "No se pudo cargar la cartelera por espacios.");
        }
      });
    }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
})();
