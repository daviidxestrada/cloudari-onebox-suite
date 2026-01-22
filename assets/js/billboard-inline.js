(() => {
  "use strict";

  /** =========================
   *  CONFIG
   *  ========================= */
  const CONFIG = Object.freeze({
    // Bump de versión para limpiar caché vieja de categorías
    CACHE_KEY: "obx_events_v8_category_colors",
    CACHE_TTL_MS: 24 * 60 * 60 * 1000,
    CHUNK_SIZE: 24,

    // Manuales (REST normalizado)
    REST_MANUALES: "/wp-json/cloudari/v1/manual-events",

    DEFAULT_CATEGORY_SLUG: "teatro",
    API_TIMEOUT_MS: 15000,

    CATEGORY_CANONICALS: [
      { key: "musica",  label: "Música",  cls: "obx-cat--musica"  },
      { key: "humor",   label: "Humor",   cls: "obx-cat--humor"   },
      { key: "musical", label: "Musical", cls: "obx-cat--musical" },
      { key: "teatro",  label: "Teatro",  cls: "obx-cat--teatro"  },
      { key: "talk",    label: "Talk",    cls: "obx-cat--talk"    },
    ],

    CATEGORY_ORDER: ["teatro", "musica", "musical", "humor", "talk"],

    KW: {
      musica:  ["musica","música","music","concierto","conciertos","live","tributo","banda","gira","recital","piano","guitarra","sinfónica","sinfonica","dj","orquesta","coro"],
      humor:   ["humor","comedia","cómico","comico","monologo","monólogo","standup","stand up","stand-up","impro","improvisación","improvisacion","sketch"],
      musical: ["musical","teatro musical","espectaculo musical","espectáculo musical","show musical","jukebox"],
      teatro:  ["teatro","drama","obra","funcion","función","tragicomedia","performance","clown","circo","danza","ballet","mimo"],
      talk:    ["talk","charla","conferencia","coloquio","ponencia","debate","mesa redonda","q&a","q and a","entrevista","encuentro","speaker","fireside","presentación","presentacion"],
    },

    IMG_PLACEHOLDER:
      "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==",
  });

  /** =========================
   *  ENV HELPERS (leen SIEMPRE de window.*)
   *  ========================= */

  function getEnv() {
    const raw =
      window.oneboxCards ||
      window.cloudariOneboxCards ||
      window.oneboxData ||
      window.oneboxCalendar ||
      {};
    return (raw && typeof raw === "object") ? raw : {};
  }

  function getBillboardEndpoint() {
    const env = getEnv();
    const v =
      env.billboardEndpoint ||
      env.billboard_endpoint ||
      env.restBillboard ||
      "";
    const url = String(v || "").trim();

    // fallback por si alguien usa el JS sin el Enqueue nuevo
    return url || "/wp-json/cloudari/v1/billboard-events";
  }

  function getPurchaseBase() {
    const env = getEnv();
    const raw =
      env.purchaseBase ??
      env.purchase_base ??
      env.purchasebase ??
      "";
    const v = String(raw || "").trim();
    if (!v) return null;
    return v.replace(/\/?$/, "/");
  }

  function getSpecialRedirectUrl(eventId, url) {
    const env = getEnv();
    const map = env.specialRedirects || {};
    return map[eventId] ? map[eventId] : url;
  }

  function getCategoryOverrides() {
    const env = getEnv();
    const map =
      env.categoryOverrides ??
      env.category_overrides ??
      {};
    return (map && typeof map === "object") ? map : {};
  }

  /** =========================
   *  DEBUG ENV UNA SOLA VEZ (FIABLE)
   *  ========================= */
  (function debugEnvOnce() {
    if (typeof window === "undefined") return;

    window.addEventListener(
      "load",
      () => {
        const env  = getEnv();
        const base = getPurchaseBase();

        // Lo dejamos accesible por si quieres mirarlo en consola
        window.__CLOUDARI_ONEBOX_ENV__ = env;

        console.log("[Cloudari Billboard] ENV final:", env);
        console.log("[Cloudari Billboard] billboardEndpoint:", getBillboardEndpoint());
        console.log(
          "[Cloudari Billboard] purchaseBase inyectado:",
          (env.purchaseBase || env.purchase_base || env.purchasebase || "(vacío)"),
          "| BASE normalizada usada:",
          base || "(ninguna: falta configurarla en el perfil)"
        );
        if (env.categoryOverrides || env.category_overrides) {
          console.log(
            "[Cloudari Billboard] categoryOverrides detectados:",
            env.categoryOverrides || env.category_overrides
          );
        }
      },
      { once: true }
    );
  })();

  /** =========================
   *  UTILS
   *  ========================= */
  const esc = (s) =>
    String(s ?? "").replace(/[&<>\"']/g, (m) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;",
    })[m]);
  const clamp = (n, min, max) => Math.min(Math.max(n, min), max);
  const eTime = (d) =>
    d instanceof Date && !isNaN(d) ? d.getTime() : Number.POSITIVE_INFINITY;
  const sameDay = (a, b) =>
    a &&
    b &&
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate();
  const fmtDateFull = (d) =>
    d
      ? new Date(d).toLocaleDateString("es-ES", {
          day: "2-digit",
          month: "long",
          year: "numeric",
        })
      : "";
  const fmtRange = (d1, d2) => {
    if (!d1 && !d2) return "";
    if (d1 && !d2) return fmtDateFull(d1);
    if (!d1 && d2) return fmtDateFull(d2);
    const a = new Date(d1),
      b = new Date(d2);
    return sameDay(a, b)
      ? fmtDateFull(a)
      : `Del ${fmtDateFull(a)} al ${fmtDateFull(b)}`;
  };
  const inBrowser = () =>
    typeof window !== "undefined" && typeof document !== "undefined";
  const normText = (s) =>
    String(s || "")
      .toLowerCase()
      .normalize("NFD")
      .replace(/\p{Diacritic}/gu, "")
      .replace(/[^a-z0-9]+/g, " ")
      .trim();
  const normCode = (s) => String(s || "").toUpperCase().trim();

  const byFirstDateAsc = (a, b) => eTime(a.firstDate) - eTime(b.firstDate);

  const IMAGE_PRIORITY = ["landscape", "secondary", "main", "portrait"];
  const getLocale = (o) =>
    (o?.["es-ES"] || o?.es_ES || o?.es || o?.en || "");
  const getFromArr = (a) => (Array.isArray(a) ? getLocale(a[0] || {}) : "");
  const pickImageClassic = (imgs) => {
    imgs ||= {};
    for (const k of IMAGE_PRIORITY) {
      const v = imgs[k];
      if (!v) continue;
      if (typeof v === "object" && !Array.isArray(v)) {
        const u = getLocale(v);
        if (u) return u;
      }
      const u = getFromArr(v);
      if (u) return u;
    }
    return "";
  };
  const pickImageMedia = (mediaImgs) => {
    const lang =
      mediaImgs?.es_ES ||
      mediaImgs?.["es-ES"] ||
      mediaImgs?.es ||
      mediaImgs?.en ||
      null;
    if (!lang) return "";
    const order = ["LANDSCAPE", "SECONDARY", "MAIN", "PORTRAIT", "BANNER_HEADER"];
    for (const k of order) {
      const v = lang[k];
      if (!v) continue;
      if (Array.isArray(v)) {
        const first =
          v.find((it) => it?.value)?.value ||
          v[0]?.value ||
          (typeof v[0] === "string" ? v[0] : "");
        if (first) return first;
      } else if (typeof v === "object" && v.value) {
        return v.value;
      } else if (typeof v === "string") {
        return v;
      }
    }
    return "";
  };

  /** URL de compra: base (perfil) + idEvento */
  const buildPurchaseUrl = (eventId, sessionUrl) => {
    // Eventos manuales pueden traer su propia URL → la respetamos
    if (sessionUrl) return sessionUrl;

    const base = getPurchaseBase();
    if (!base) {
      console.warn(
        "[Cloudari Billboard] No hay purchaseBase configurada en el perfil. No se puede construir URL de compra para",
        eventId
      );
      return "#";
    }
    return base + String(eventId);
  };

  /** =========================
   *  CATEGORY COLOR HELPERS
   *  ========================= */
  const isHexColor = (s) => /^#[0-9A-Fa-f]{6}$/.test(String(s || "").trim());

  function pickCategoryColor(ev) {
    const c1 = ev?.category?.custom?.color;
    const c2 = ev?.category?.color;
    const c3 = ev?.cloudari?.category_color;

    const raw = (c1 || c2 || c3 || "").trim();
    if (!raw) return "";

    const v = raw[0] === "#" ? raw : ("#" + raw);
    return isHexColor(v) ? v : "";
  }

  // Contraste simple (YIQ)
  function pickTextColor(bgHex) {
    if (!isHexColor(bgHex)) return "";
    const r = parseInt(bgHex.slice(1, 3), 16);
    const g = parseInt(bgHex.slice(3, 5), 16);
    const b = parseInt(bgHex.slice(5, 7), 16);
    const yiq = (r * 299 + g * 587 + b * 114) / 1000;
    return yiq >= 160 ? "#000000" : "#FFFFFF";
  }

  /** =========================
   *  MAPEOS DE CÓDIGOS → CANÓNICAS
   *  ========================= */
  const CANONICALS = new Map(
    CONFIG.CATEGORY_CANONICALS.map((c) => [c.key, c])
  );
  const FALLBACK_KEY = CONFIG.DEFAULT_CATEGORY_SLUG;

  const CODE_TO_CANON = (() => {
    const m = new Map();
    const add = (canon, arr) => arr.forEach((k) => m.set(normCode(k), canon));

    add("teatro", [
      "ARTET","ART","ARTE","ESCENICAS","ESCÉNICAS",
      "ARTES ESCENICAS","ARTES ESCÉNICAS",
      "THEATRE","THEATER","DRAMA","PLAY",
      "TEATRO","CIRCO","DANZA","DANCE"
    ]);

    add("musica", [
      "ARTCLA","ARTMU","MUS","MUSICA","MÚSICA","MUSIC",
      "CONCIERTO","CONCIERTOS","BANDA","GIRA","RECITAL",
      "LIVE","FESTMUS","FESTIVAL MUSICAL"
    ]);

    add("humor", [
      "ARTHU","HUM","HUMOR","COMEDIA","COMEDY",
      "COMICO","CÓMICO","MONOLOGO","MONÓLOGO",
      "STANDUP","STAND UP","STAND-UP","IMPRO","IMPROV"
    ]);

    add("musical", [
      "ARTMS","ARTMUS","MUSICAL","TEATRO MUSICAL",
      "SHOW MUSICAL","ESPECTACULO MUSICAL","ESPECTÁCULO MUSICAL"
    ]);

    add("talk", [
      "TALK","CONF","CULCON","CONFERENCIA","CONFERENCIAS",
      "CHARLA","COLOQUIO","PONENCIA","DEBATE",
      "MESA REDONDA","Q&A","Q AND A",
      "ENTREVISTA","ENCUENTRO","SPEAKER",
      "FIRESIDE","PRESENTACION","PRESENTACIÓN"
    ]);

    return m;
  })();

  /** =========================
   *  CLASIFICACIÓN
   *  ========================= */

  function buildAuxText(ev) {
    return [
      ev?.texts?.title?.["es-ES"],
      ev?.texts?.title?.es,
      ev?.name,
      ev?.texts?.subtitle?.["es-ES"],
      ev?.texts?.subtitle?.es,
      ev?.texts?.description_long?.["es-ES"],
      ev?.texts?.description_long?.es,
      ev?.media?.texts?.["es-ES"]?.TITLE?.value,
      ev?.media?.texts?.["es-ES"]?.DESCRIPTION_LONG?.value,
    ]
      .filter(Boolean)
      .join(" ");
  }

  function codeToCanon(ev) {
    const cat = ev?.category || {};
    const candidates = [cat?.custom?.code, cat?.code, cat?.parent?.code]
      .filter(Boolean)
      .map(normCode);

    for (const c of candidates) {
      if (CODE_TO_CANON.has(c)) return CODE_TO_CANON.get(c);
      for (const [k, v] of CODE_TO_CANON.entries()) {
        if (c.includes(k)) return v;
      }
    }
    return "";
  }

  function textToCanon(ev) {
    const s = normText(buildAuxText(ev));
    const hasAny = (arr) => arr.some((k) => s.includes(normText(k)));

    if (hasAny(CONFIG.KW.musical)) return "musical";
    if (hasAny(CONFIG.KW.humor))   return "humor";
    if (hasAny(CONFIG.KW.talk))    return "talk";
    if (hasAny(CONFIG.KW.musica))  return "musica";
    if (hasAny(CONFIG.KW.teatro))  return "teatro";

    return "";
  }

  function canonicalKey(ev) {
    const overrides = getCategoryOverrides();
    const rawId     = ev?.event?.id ?? ev?.id;
    const ovKey     = rawId ? overrides[String(rawId)] : undefined;

    // 1) Override manual del panel (si existe y es canónica conocida)
    if (ovKey && CANONICALS.has(ovKey)) {
      return ovKey;
    }

    // 2) Lógica automática por código
    const byCode = codeToCanon(ev);
    if (byCode) return byCode;

    // 3) Lógica automática por texto
    const byText = textToCanon(ev);
    if (byText) return byText;

    // 4) Fallback
    return FALLBACK_KEY;
  }

  /** =========================
   *  MANUAL CATEGORY HELPERS (NUEVO)
   *  ========================= */
  function titleCaseLabelFromKey(key) {
    const s = String(key || "").trim();
    if (!s) return "";
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  function getManualCategory(ev) {
    const slug =
      (ev?.category?.slug) ||
      (ev?.category?.custom?.code ? String(ev.category.custom.code).toLowerCase() : "") ||
      "";

    const key = String(slug || "").trim().toLowerCase();
    const label =
      (ev?.category?.name && String(ev.category.name).trim()) ||
      (key ? titleCaseLabelFromKey(key) : "");

    return { key, label };
  }

  /** =========================
   *  ADAPTADORES
   *  ========================= */

  const OneboxAdapter = {
    async fetchAll() {
      const endpoint = getBillboardEndpoint();

      const controller =
        "AbortController" in window ? new AbortController() : null;
      const t = setTimeout(() => controller?.abort(), CONFIG.API_TIMEOUT_MS);

      try {
        const r = await fetch(endpoint, {
          method: "GET",
          headers: { Accept: "application/json" },
          credentials: "same-origin",
          signal: controller?.signal,
        });

        if (!r.ok) {
          if (r.status === 401 || r.status === 403) {
            const err = new Error("auth");
            err.status = r.status;
            throw err;
          }
          const err = new Error("http");
          err.status = r.status;
          throw err;
        }

        const j = await r.json();
        return Array.isArray(j?.data) ? j.data : [];
      } catch (err) {
        if (err && err.name === "AbortError") {
          const e = new Error("timeout");
          e.status = 408;
          console.error("Timeout cargando eventos OneBox (REST Cloudari):", endpoint);
          throw e;
        }

        console.error("Error cargando eventos OneBox (REST Cloudari):", err);
        throw err;
      } finally {
        clearTimeout(t);
      }
    },
  };

  const ManualesAdapter = {
    async fetchAll() {
      try {
        const r = await fetch(CONFIG.REST_MANUALES, {
          method: "GET",
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        });
        if (!r.ok) return [];
        const data = await r.json();
        return Array.isArray(data) ? data : [];
      } catch (err) {
        console.error("Error cargando eventos manuales:", err);
        return [];
      }
    },
  };

  /** =========================
   *  NORMALIZACIÓN A TARJETAS
   *  ========================= */
  function toCard(ev) {
    try {
      const rawId = ev?.id;
      if (!rawId) return null;

      const evId     = String(rawId);
      const isManual = evId.startsWith("manual-");

      const title =
        ev?.texts?.title?.["es-ES"] ||
        ev?.texts?.title?.es ||
        ev?.name ||
        "Sin título";

      const image =
        pickImageClassic(ev?.images) ||
        pickImageMedia(ev?.media?.images) ||
        CONFIG.IMG_PLACEHOLDER;

      const firstDate = ev?.date?.start ? new Date(ev.date.start) : null;
      const lastDate  = ev?.date?.end   ? new Date(ev.date.end)   : firstDate;

      const baseId = ev?.event?.id ?? ev?.id;

      let targetUrl;
      const evUrl =
        typeof ev.url === "string" && ev.url.trim() !== "" ? ev.url.trim() : "";

      if (isManual) {
        // Eventos manuales: usan siempre su propia URL (_url_evento)
        targetUrl = evUrl || "#";
      } else {
        // Eventos OneBox: preferir URL precalculada por integracion si existe
        targetUrl = evUrl || buildPurchaseUrl(baseId, null);
      }

      const finalUrl = getSpecialRedirectUrl(baseId, targetUrl);

      // CTA label configurable para eventos manuales
      const ctaLabel =
        isManual && typeof ev?.cloudari?.cta_label === "string" && ev.cloudari.cta_label.trim() !== ""
          ? ev.cloudari.cta_label.trim()
          : "Entradas";

      /** =========================
       *  CATEGORY (FIX)
       *  - OneBox: canónicas (como siempre)
       *  - Manual: respeta slug/name REAL de taxonomía, salvo override canónico
       *  ========================= */
      let categoryKey, categoryLabel, categoryClass;

      if (isManual) {
        // 1) override (solo si es canónica conocida)
        const ov = getCategoryOverrides();
        const rawId2 = ev?.event?.id ?? ev?.id;
        const ovKey = rawId2 ? ov[String(rawId2)] : undefined;

        if (ovKey && CANONICALS.has(ovKey)) {
          const canon = CANONICALS.get(ovKey);
          categoryKey = canon.key;
          categoryLabel = canon.label;
          categoryClass = canon.cls;
        } else {
          // 2) manual real (slug/name de taxonomía)
          const mc = getManualCategory(ev);
          if (mc.key) {
            categoryKey = mc.key;                 // ej: "mercado"
            categoryLabel = mc.label || "Teatro"; // ej: "Mercado"
            categoryClass = "";                   // no hay clase canónica
          } else {
            // 3) fallback
            const canon = CANONICALS.get(CONFIG.DEFAULT_CATEGORY_SLUG);
            categoryKey = canon.key;
            categoryLabel = canon.label;
            categoryClass = canon.cls;
          }
        }
      } else {
        const key = canonicalKey(ev);
        const canon =
          CANONICALS.get(key) ||
          CANONICALS.get(CONFIG.DEFAULT_CATEGORY_SLUG);

        categoryKey = canon.key;
        categoryLabel = canon.label;
        categoryClass = canon.cls;
      }

      // Color de categoría (si viene del backend)
      const categoryColor = pickCategoryColor(ev);
      const categoryTextColor = categoryColor ? pickTextColor(categoryColor) : "";

      return {
        id: evId,
        title,
        titleLower: title.toLowerCase(),
        category: categoryLabel,
        categoryKey,
        categoryClass: categoryClass || "",
        categoryColor,
        categoryTextColor,
        image,
        firstDate,
        lastDate,
        url: finalUrl,
        ctaLabel,
      };
    } catch (e) {
      console.error("Error normalizando evento a tarjeta:", e, ev);
      return null;
    }
  }

  function normalizeAndSort(rawList) {
    const cards = [];
    for (const ev of (rawList || [])) {
      const c = toCard(ev);
      if (c) cards.push(c);
    }
    return cards.sort(byFirstDateAsc);
  }

  /** =========================
   *  RENDER
   *  ========================= */
  function buildCardNode(e, index) {
    const eager = index < 6;
    const attrs = eager
      ? 'loading="eager" fetchpriority="high" decoding="async"'
      : 'loading="lazy"  fetchpriority="low"  decoding="async"';

    // estilo inline de la pill si hay color
    const catStyle = e.categoryColor
      ? `style="background:${esc(e.categoryColor)};border-color:${esc(e.categoryColor)};color:${esc(e.categoryTextColor || "#FFFFFF")};"`
      : "";

    const card = document.createElement("article");
    card.className = "obx-card";
    card.innerHTML = `
      <div class="obx-media">
        <img ${attrs} src="${esc(e.image)}" alt="${esc(
          e.title
        )} – cartel" referrerpolicy="no-referrer">
      </div>
      <div class="obx-topbar"></div>
      <div class="obx-body">
        <h3 class="obx-h3">${esc(e.title)}</h3>
        <div class="obx-datebar" aria-label="Rango de fechas">
          <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
            <path d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 16H5V9h14v11z" fill="currentColor"/>
          </svg>
          <span class="obx-datebar__text">${fmtRange(
            e.firstDate,
            e.lastDate
          )}</span>
        </div>
        <div class="obx-metaRow">
          <div class="obx-pill obx-pill--cat ${e.categoryClass}" ${catStyle}>${esc(
            e.category
          )}</div>
          <a
            class="obx-pill obx-pill--cta"
            href="${esc(e.url)}"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="${esc(e.ctaLabel)} para ${esc(e.title)}"
          >
            <span>${esc(e.ctaLabel || "Entradas")}</span>
          </a>
        </div>
      </div>`;
    return card;
  }

  function renderSkeleton($grid, n = 8) {
    $grid.innerHTML = "";
    for (let i = 0; i < n; i++) {
      const c = document.createElement("article");
      c.className = "obx-card";
      c.setAttribute("aria-busy", "true");
      c.innerHTML = `<div class="obx-media obx-skel" style="aspect-ratio:16/9"></div>
        <div class="obx-body">
          <div class="obx-skel" style="height:20px;width:70%;border-radius:6px"></div>
          <div class="obx-skel" style="height:14px;width:55%;border-radius:6px"></div>
          <div class="obx-skel" style="height:32px;width:140px;border-radius:9999px"></div>
        </div>`;
      $grid.appendChild(c);
    }
  }

  function renderEmpty($grid, msg) {
    $grid.innerHTML = `<p class="obx-empty">${esc(
      msg || "No hay eventos disponibles."
    )}</p>`;
  }

  function renderAuthError($grid) {
    $grid.innerHTML = `
      <div class="obx-msg" role="alert">
        ⚠️ No autorizado: revisa las credenciales de OneBox en la página de ajustes del plugin.
      </div>`;
  }

  function renderChunked($grid, list, chunkSize) {
    $grid.innerHTML = "";
    if (!list.length) {
      renderEmpty($grid);
      return;
    }
    let i = 0;
    const chunk = clamp(chunkSize | 0, 8, 100);
    const step = () => {
      const frag = document.createDocumentFragment();
      for (let c = 0; c < chunk && i < list.length; c++, i++) {
        frag.appendChild(buildCardNode(list[i], i));
      }
      $grid.appendChild(frag);
      if (i < list.length) {
        if ("requestIdleCallback" in window) {
          requestIdleCallback(step, { timeout: 120 });
        } else {
          setTimeout(step, 16);
        }
      }
    };
    step();
  }

  /** =========================
   *  UI
   *  ========================= */

  // Actualizado: ahora incluye también categorías custom/manual (ej: "mercado")
  function populateCategoryFilter($select, list) {
    if (!$select) return;

    const present = new Map(); // key -> label
    list.forEach((e) => {
      if (e?.categoryKey) {
        if (!present.has(e.categoryKey)) {
          present.set(e.categoryKey, e.category || e.categoryKey);
        }
      }
    });

    $select.innerHTML = `<option value="all">Todas las categorías</option>`;

    // 1) Canónicas en orden
    CONFIG.CATEGORY_ORDER.forEach((key) => {
      if (present.has(key)) {
        const c = CANONICALS.get(key);
        const opt = document.createElement("option");
        opt.value = key;
        opt.textContent = c?.label || present.get(key) || key;
        $select.appendChild(opt);
        present.delete(key);
      }
    });

    // 2) Resto (manuales/custom) al final, alfabético
    const rest = Array.from(present.entries())
      .map(([key, label]) => ({ key, label: String(label || key) }))
      .sort((a, b) => a.label.localeCompare(b.label, "es", { sensitivity: "base" }));

    rest.forEach(({ key, label }) => {
      const opt = document.createElement("option");
      opt.value = key;
      opt.textContent = label;
      $select.appendChild(opt);
    });
  }

  function applyFilters($grid, events, $q, $cat) {
    const q   = ($q?.value || "").toLowerCase().trim();
    const cat = $cat?.value || "all";
    const filtered = (events || []).filter((e) => {
      const okTxt = !q || e.titleLower.includes(q);
      const okCat = cat === "all" || e.categoryKey === cat;
      return okTxt && okCat;
    });
    renderChunked($grid, filtered, CONFIG.CHUNK_SIZE);
  }

  /** =========================
   *  CACHE
   *  ========================= */
  const Cache = {
    read(key, ttl) {
      try {
        const raw = localStorage.getItem(key);
        if (!raw) return null;
        const { ts, data } = JSON.parse(raw);
        if (!Array.isArray(data)) return null;
        if (Date.now() - ts > ttl) return null;
        return data;
      } catch {
        return null;
      }
    },
    write(key, list) {
      try {
        localStorage.setItem(
          key,
          JSON.stringify({ ts: Date.now(), data: list || [] })
        );
      } catch {
        // ignore
      }
    },
  };

  const debounce = (fn, ms = 160) => {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  };

  /** =========================
   *  BOOT
   *  ========================= */
  const boot = () => startApp();
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }

  async function startApp() {
    if (!inBrowser()) return;

    const $grid = document.getElementById("obx-grid");
    const $q    = document.getElementById("obx-q");
    const $cat  = document.getElementById("obx-cat");
    if (!$grid) return;

    const cachedRaw = Cache.read(CONFIG.CACHE_KEY, CONFIG.CACHE_TTL_MS);
    if (cachedRaw?.length) {
      const cards = normalizeAndSort(cachedRaw);
      window.__OBX_CARDS__ = cards;
      populateCategoryFilter($cat, cards);
      applyFilters($grid, cards, $q, $cat);
    } else {
      renderSkeleton($grid);
    }

    const onFilter = () =>
      applyFilters($grid, window.__OBX_CARDS__ || [], $q, $cat);
    const debouncedFilter = debounce(onFilter, 160);
    $q?.addEventListener("input", debouncedFilter, { passive: true });
    $cat?.addEventListener("change", onFilter, { passive: true });

    try {
      const [oneboxRes, manualesRes] = await Promise.allSettled([
        OneboxAdapter.fetchAll(),
        ManualesAdapter.fetchAll(),
      ]);

      let oneboxRaw    = [];
      let manualesRaw  = [];
      let hadAuthError = false;
      let hadHttpError = false;

      if (oneboxRes.status === "fulfilled") {
        oneboxRaw = oneboxRes.value || [];
      } else {
        const err = oneboxRes.reason;
        if (err && err.message === "auth") {
          hadAuthError = true;
        } else {
          hadHttpError = true;
        }
      }

      if (manualesRes.status === "fulfilled") {
        manualesRaw = manualesRes.value || [];
      }

      const combined = [].concat(oneboxRaw || [], manualesRaw || []);
      Cache.write(CONFIG.CACHE_KEY, combined);

      const cards = normalizeAndSort(combined);
      window.__OBX_CARDS__ = cards;

      if (cards.length === 0) {
        if (!cachedRaw?.length) {
          if (hadAuthError) {
            renderAuthError($grid);
          } else if (hadHttpError) {
            renderEmpty(
              $grid,
              "No se pudieron cargar eventos (error al consultar OneBox)."
            );
          } else {
            renderEmpty(
              $grid,
              "No hay eventos disponibles en este momento."
            );
          }
        } else {
          console.warn(
            "Cloudari OneBox: error de recarga, usando solo eventos en caché."
          );
        }
        return;
      }

      populateCategoryFilter($cat, cards);
      applyFilters($grid, cards, $q, $cat);

      if (hadAuthError && manualesRaw.length) {
        console.warn(
          "Cloudari OneBox: solo se han podido cargar eventos manuales (error de autenticación en OneBox)."
        );
      }
    } catch (err) {
      console.error("Error cargando cartelera:", err);
      if (!cachedRaw?.length) {
        if (err?.message === "auth") {
          renderAuthError($grid);
        } else {
          renderEmpty($grid, "No se pudieron cargar eventos.");
        }
      }
    }
  }
})();
