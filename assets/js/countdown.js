(function () {
  "use strict";

  if (typeof window === "undefined" || typeof document === "undefined") return;
  if (!window.cloudariCountdown) return;

  const CONFIG = window.cloudariCountdown;

  const now = () => new Date();
  const isoDay = (d) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(
      d.getDate()
    ).padStart(2, "0")}`;

  const fmt = (dt) =>
    new Intl.DateTimeFormat("es-ES", {
      timeZone: "Europe/Madrid",
      weekday: "long",
      day: "2-digit",
      month: "long",
      hour: "2-digit",
      minute: "2-digit",
    })
      .format(dt)
      .replace(/^\w/, (c) => c.toUpperCase());

  function getCalendarImage(session) {
    const path = (o, keys) =>
      keys.reduce(
        (v, k) => (v && v[k] !== undefined ? v[k] : null),
        o
      );

    return (
      path(session, ["images", "landscape", 0, "es-ES"]) ||
      path(session, ["images", "main", "es-ES"]) ||
      path(session, ["event", "images", "landscape", 0, "es-ES"]) ||
      path(session, ["event", "images", "main", "es-ES"]) ||
      null
    );
  }

    function pickNextBundle(ses, eventId) {
    if (!ses || !Array.isArray(ses.data)) return null;
    const t = now();

    const s0 = ses.data
      .filter((s) => s?.event?.id == eventId && s?.date?.start)
      .sort((a, b) => new Date(a.date.start) - new Date(b.date.start))
      .find((x) => new Date(x.date.start) > t);

    if (!s0) return null;

    return {
      date: new Date(s0.date.start),
      image: getCalendarImage(s0),
      url: typeof s0?.url === "string" ? s0.url.trim() : "", // ✅ NUEVO
      title:
        (s0.event?.texts?.title?.["es-ES"]) ||
        s0.event?.name ||
        s0.name ||
        "Evento",
    };
  }


  function buildPurchaseUrl(eventId, sessionUrl) {
    // ✅ Manual events: si la sesión trae URL propia, la respetamos
    if (sessionUrl && String(sessionUrl).trim() !== "") {
      return String(sessionUrl).trim();
    }

    const id = String(eventId);
    const overrides = CONFIG.specialRedirects || {};
    if (overrides[id]) {
      return overrides[id];
    }

    const base = (CONFIG.purchaseBase || "").trim();
    if (!base) return "#";

    const normalized = base.replace(/\/?$/, "/");
    return normalized + id;
  }

  function applyOverride(eventId, url) {
    const id = String(eventId);
    const overrides = CONFIG.specialRedirects || {};
    if (overrides[id]) {
      return overrides[id];
    }
    return url;
  }


  async function getSessions(inicio, fin) {
    const ajaxBase = CONFIG.ajaxSesiones;
    if (!ajaxBase) return null;
    const url =
      ajaxBase +
      "&inicio=" +
      encodeURIComponent(inicio) +
      "&fin=" +
      encodeURIComponent(fin);

    const res = await fetch(url, { credentials: "same-origin" });
    if (!res.ok) return null;
    return res.json();
  }

  function startCountdown(root, target) {
    const cd = root.querySelector(".cloudari-ce-countdown");
    if (!cd) return;

    const dEl = cd.querySelector("[data-role='d']");
    const hEl = cd.querySelector("[data-role='h']");
    const mEl = cd.querySelector("[data-role='m']");
    const sEl = cd.querySelector("[data-role='s']");

    function tick() {
      let diff = Math.max(0, target - now());
      const d = Math.floor(diff / 86400000);
      diff -= d * 86400000;
      const h = Math.floor(diff / 3600000);
      diff -= h * 3600000;
      const m = Math.floor(diff / 60000);
      diff -= m * 60000;
      const s = Math.floor(diff / 1000);

      if (dEl) dEl.textContent = String(d).padStart(2, "0");
      if (hEl) hEl.textContent = String(h).padStart(2, "0");
      if (mEl) mEl.textContent = String(m).padStart(2, "0");
      if (sEl) sEl.textContent = String(s).padStart(2, "0");
    }

    tick();
    cd.classList.add("cloudari-ce-ready");
    setInterval(tick, 1000);
  }

  function paintPoster(root, bundle, eventId) {
    const img = root.querySelector("[data-role='poster-img']");
    const tit = root.querySelector("[data-role='poster-title']");
    const link = root.querySelector("[data-role='poster-link']");

    if (tit && bundle.title) tit.textContent = bundle.title;

    if (img) {
      if (bundle.image) {
        img.src = bundle.image;
        img.style.display = "block";
      } else {
        img.style.display = "none";
      }
    }

    if (link) {
      // ✅ Prioridad: URL de la sesión (manual / OneBox) -> override -> purchaseBase+id
      const sessionUrl = (bundle && typeof bundle.url === "string") ? bundle.url.trim() : "";
      const baseUrl = sessionUrl || buildPurchaseUrl(eventId) || "#";
      link.href = applyOverride(eventId, baseUrl);
    }
  }


  function setNext(root, dt) {
    const el = root.querySelector("[data-role='next-date']");
    if (!el) return;
    el.textContent = fmt(dt);
    el.setAttribute("datetime", dt.toISOString());
  }

  function hideCountdown(root) {
    const cd = root.querySelector(".cloudari-ce-countdown");
    if (!cd) return;
    cd.classList.remove("cloudari-ce-ready");
    cd.style.visibility = "hidden";
  }

   function readCache(eventId) {
    const key = `cloudari_ce_next_${eventId}`;
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return null;
      const obj = JSON.parse(raw);
      const dt = new Date(obj.iso);
      if (dt > now() && Date.now() - obj.savedAt < CONFIG.cacheTtlMs) {
        return { date: dt, image: obj.image, title: obj.title, url: obj.url || "" }; // ✅
      }
    } catch (e) {
      // ignore
    }
    return null;
  }

  function writeCache(eventId, bundle) {
    const key = `cloudari_ce_next_${eventId}`;
    try {
      localStorage.setItem(
        key,
        JSON.stringify({
          iso: bundle.date.toISOString(),
          image: bundle.image,
          title: bundle.title,
          url: bundle.url || "", // ✅
          savedAt: Date.now(),
        })
      );
    } catch (e) {
      // ignore
    }
  }


  async function initWidget(root) {
    const eventId = parseInt(root.dataset.eventId || "", 10);
    if (!eventId) return;

    const extraDays =
      parseInt(root.dataset.extraDays || "", 10) ||
      CONFIG.extraDaysDefault ||
      180;

    const cached = readCache(eventId);
    if (cached) {
      setNext(root, cached.date);
      startCountdown(root, cached.date);
      paintPoster(root, cached, eventId);
    }

    try {
      const i = isoDay(now());
      const f = isoDay(new Date(Date.now() + extraDays * 86400000));
      const data = await getSessions(i, f);

      const bundle = pickNextBundle(data, eventId);
      if (bundle) {
        paintPoster(root, bundle, eventId);
        if (!cached || +bundle.date !== +cached.date) {
          setNext(root, bundle.date);
          if (!cached) startCountdown(root, bundle.date);
          writeCache(eventId, bundle);
        }
      } else if (!cached) {
        const el = root.querySelector("[data-role='next-date']");
        if (el) el.textContent = "Próximamente";
        hideCountdown(root);
        const img = root.querySelector("[data-role='poster-img']");
        if (img) img.style.display = "none";
      }
    } catch (e) {
      console.error("Cloudari countdown error:", e);
      if (!cached) {
        const el = root.querySelector("[data-role='next-date']");
        if (el) el.textContent = "—";
        hideCountdown(root);
        const img = root.querySelector("[data-role='poster-img']");
        if (img) img.style.display = "none";
      }
    }
  }

  function boot() {
    const nodes = document.querySelectorAll("[data-cloudari-countdown]");
    nodes.forEach(initWidget);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
})();
