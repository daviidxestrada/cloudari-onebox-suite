(() => {
  "use strict";

  const CONFIG = Object.freeze({
    ENDPOINT: "/wp-json/cloudari/v1/billboard-venues",
    IMG_PLACEHOLDER:
      "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==",
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
  });

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

  const toTimestamp = (value) => {
    const timestamp = Date.parse(value || "");
    return Number.isNaN(timestamp) ? Number.POSITIVE_INFINITY : timestamp;
  };

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
    const haystack = normalizeText(
      [
        category?.name,
        category?.description,
        category?.code,
        category?.custom?.description,
        category?.custom?.code,
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

  const getCtaLabel = (eventItem) => {
    const value = String(eventItem?.cloudari?.cta_label || "").trim();
    return value || "Entradas";
  };

  const matchesEventQuery = (eventItem, query, venue) => {
    if (!query) {
      return true;
    }

    const haystack = normalizeText(
      [
        eventItem?.title,
        eventItem?.category?.name,
        eventItem?.category?.description,
        getVenueName(eventItem, venue),
      ]
        .filter(Boolean)
        .join(" ")
    );

    return haystack.includes(query);
  };

  const renderSkeleton = ($list) => {
    $list.innerHTML = `
      <section class="obxv-venue" aria-busy="true">
        <div class="obxv-venue-head">
          <div class="obx-skel" style="height:20px;width:220px;border-radius:6px"></div>
          <div class="obx-skel" style="height:14px;width:96px;border-radius:6px"></div>
        </div>
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

  const renderEmpty = ($list, message) => {
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
    const eager = index < 6;
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

  const buildVenueBlock = (venue) => {
    const events = Array.isArray(venue?.events) ? venue.events : [];
    const countLabel = events.length === 1 ? "1 evento" : `${events.length} eventos`;

    return `
      <section class="obxv-venue" data-venue="${esc(venue?.slug || venue?.id || "")}">
        <header class="obxv-venue-head">
          <div class="obxv-venue-label">
            ${stageIcon}
            <h3 class="obxv-venue-title">${esc(venue?.name || "Espacio")}</h3>
          </div>
          <p class="obxv-venue-meta">${esc(countLabel)}</p>
        </header>
        <div class="obx-grid obxv-grid">
          ${events.map((eventItem, index) => buildEventCard(eventItem, venue, index)).join("")}
        </div>
      </section>
    `;
  };

  const renderVenues = ($list, venues, query) => {
    const normalizedQuery = normalizeText(query);
    const visible = venues
      .map((venue) => {
        const venueName = normalizeText(venue?.name);
        const venueMatches = !normalizedQuery || venueName.includes(normalizedQuery);

        if (venueMatches) {
          return venue;
        }

        const events = Array.isArray(venue?.events)
          ? venue.events.filter((eventItem) =>
              matchesEventQuery(eventItem, normalizedQuery, venue)
            )
          : [];

        if (!events.length) {
          return null;
        }

        return {
          ...venue,
          events,
          event_count: events.length,
          next_start: events[0]?.start || venue?.next_start || "",
        };
      })
      .filter(Boolean)
      .sort((left, right) => toTimestamp(left?.next_start) - toTimestamp(right?.next_start));

    if (!visible.length) {
      renderEmpty(
        $list,
        normalizedQuery
          ? "No hay coincidencias para esa busqueda."
          : "No hay espacios con eventos proximos."
      );
      return;
    }

    $list.innerHTML = visible.map(buildVenueBlock).join("");
  };

  const fetchVenues = async () => {
    const response = await fetch(getEndpoint(), {
      method: "GET",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
      },
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const payload = await response.json();
    return Array.isArray(payload?.data) ? payload.data : [];
  };

  const boot = async () => {
    if (!inBrowser()) {
      return;
    }

    const $list = document.getElementById("obxv-list");
    const $query = document.getElementById("obxv-q");
    if (!$list) {
      return;
    }

    renderSkeleton($list);

    try {
      const venues = await fetchVenues();
      window.__CLOUDARI_VENUE_BILLBOARD__ = venues;
      renderVenues($list, venues, $query?.value || "");

      $query?.addEventListener("input", () => {
        renderVenues($list, venues, $query.value || "");
      });
    } catch (error) {
      console.error("Cloudari venue billboard error:", error);
      renderEmpty($list, "No se pudo cargar la cartelera por espacios.");
    }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
})();
