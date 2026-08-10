(() => {
  "use strict";

  const config = window.cloudariFeaturedPopup;
  const dialog = document.querySelector("[data-cloudari-obx56934-popup]");
  if (!config?.endpoint || !(dialog instanceof HTMLDialogElement)) return;

  const path = window.location.pathname.replace(/\/+$/, "") || "/";
  if (path !== "/") {
    dialog.remove();
    return;
  }

  const roles = (name) => dialog.querySelector(`[data-obx56934-role="${name}"]`);
  const fields = {
    days: roles("days"),
    hours: roles("hours"),
    minutes: roles("minutes"),
    seconds: roles("seconds"),
  };

  let nextStart = null;
  let timer = null;
  let loading = false;
  let dismissed = false;

  const hide = () => {
    if (timer) window.clearInterval(timer);
    timer = null;
    if (dialog.open) dialog.close();
    dialog.hidden = true;
  };

  const close = () => {
    dismissed = true;
    if (dialog.open) dialog.close();
  };

  const tick = () => {
    if (!(nextStart instanceof Date)) return;
    const remaining = nextStart.getTime() - Date.now();

    if (remaining <= 0) {
      if (timer) window.clearInterval(timer);
      timer = null;
      refresh();
      return;
    }

    const total = Math.floor(remaining / 1000);
    const values = {
      days: Math.floor(total / 86400),
      hours: Math.floor((total % 86400) / 3600),
      minutes: Math.floor((total % 3600) / 60),
      seconds: total % 60,
    };

    Object.entries(values).forEach(([unit, value]) => {
      if (fields[unit]) fields[unit].textContent = String(value).padStart(2, "0");
    });
  };

  const applyPayload = (payload) => {
    const parsedStart = new Date(payload?.next_start || "");
    const remainingDays = Number(payload?.remaining_days);

    if (
      !payload?.active ||
      Number.isNaN(parsedStart.getTime()) ||
      parsedStart.getTime() <= Date.now() ||
      !Number.isInteger(remainingDays) ||
      remainingDays < 1
    ) {
      hide();
      return;
    }

    nextStart = parsedStart;
    roles("days-label").textContent =
      remainingDays === 1 ? "1 día restante" : `Últimos ${remainingDays} días`;

    const poster = roles("poster");
    if (poster instanceof HTMLImageElement && payload.poster_url) {
      poster.src = payload.poster_url;
      poster.fetchPriority = "high";
    }

    const purchase = roles("purchase");
    if (purchase instanceof HTMLAnchorElement && payload.purchase_url) {
      purchase.href = payload.purchase_url;
      purchase.hidden = false;
    } else if (purchase) {
      purchase.hidden = true;
    }

    tick();
    if (timer) window.clearInterval(timer);
    timer = window.setInterval(tick, 1000);

    if (!dismissed) {
      dialog.hidden = false;
      if (!dialog.open) dialog.showModal();
    }
  };

  async function refresh() {
    if (loading) return;
    loading = true;

    try {
      const response = await fetch(config.endpoint, {
        headers: { Accept: "application/json" },
        cache: "no-store",
        credentials: "same-origin",
      });
      if (!response.ok) throw new Error(`Cloudari popup: ${response.status}`);
      applyPayload(await response.json());
    } catch (_) {
      hide();
    } finally {
      loading = false;
    }
  }

  roles("close")?.addEventListener("click", close);
  dialog.addEventListener("cancel", close);
  dialog.addEventListener("click", (event) => {
    if (event.target === dialog) close();
  });

  refresh();
})();
