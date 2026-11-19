// Consenso ai cookie (GDPR / ePrivacy), come SkateboardMap: Google Analytics parte SOLO dopo "Accetta".
// Senza scelta compare un banner in basso, con "Accetta" e "Rifiuta" sullo stesso piano. La scelta resta 12 mesi
// (localStorage wtm.consent) e si cambia con window.wtmConsent.open() (link "Cookie" in fondo alla mappa, pagina privacy).
// Il browser di chi è entrato nell'admin (cookie wtm_ns=1, messo da admin/layout.php) non viene contato.
// Usato da index.html, dalle pagine SEO, account e admin. Eventi: window.wtmGa("nome", { … }) (non fa niente senza consenso).
(() => {
  "use strict";
  const GA_ID = "G-027ZVB7QZ1", KEY = "wtm.consent", DAYS = 365;
  if (/(?:^|; )wtm_ns=1(?:;|$)/.test(document.cookie)) window["ga-disable-" + GA_ID] = true;
  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function () { dataLayer.push(arguments); };
  const T = {
    en: ["We use analytics cookies to understand how the site is used.", "Accept", "Reject", "Privacy"],
    it: ["Usiamo cookie di statistica per capire come viene usato il sito.", "Accetta", "Rifiuta", "Privacy"],
    es: ["Usamos cookies de estadística para entender cómo se usa el sitio.", "Aceptar", "Rechazar", "Privacidad"],
    pt: ["Usamos cookies de estatística para perceber como o site é usado.", "Aceitar", "Recusar", "Privacidade"],
    fr: ["Nous utilisons des cookies de mesure d'audience pour comprendre l'usage du site.", "Accepter", "Refuser", "Confidentialité"],
    de: ["Wir verwenden Statistik-Cookies, um zu verstehen, wie die Website genutzt wird.", "Akzeptieren", "Ablehnen", "Datenschutz"],
    ru: ["Мы используем аналитические cookie, чтобы понимать, как используется сайт.", "Принять", "Отклонить", "Конфиденциальность"],
    ja: ["サイトの利用状況を把握するため、分析用 Cookie を使用します。", "同意する", "拒否する", "プライバシー"],
    zh: ["我们使用统计 Cookie 来了解网站的使用情况。", "接受", "拒绝", "隐私"],
    pl: ["Używamy analitycznych plików cookie, aby zrozumieć, jak korzystasz ze strony.", "Akceptuj", "Odrzuć", "Prywatność"],
    ko: ["사이트 사용 방식을 이해하기 위해 분석용 쿠키를 사용해요.", "동의", "거부", "개인정보"],
    id: ["Kami memakai cookie analitik untuk memahami cara situs digunakan.", "Terima", "Tolak", "Privasi"],
  };
  const read = () => { try { const v = JSON.parse(localStorage.getItem(KEY)); return v && v.at > Date.now() - DAYS * 864e5 ? v.ok : null; } catch (e) { return null; } };
  const save = (ok) => { try { localStorage.setItem(KEY, JSON.stringify({ ok, at: Date.now() })); } catch (e) {} };
  let loaded = false;
  function loadGA() {
    if (loaded) return; loaded = true;
    gtag("js", new Date()); gtag("config", GA_ID, { anonymize_ip: true });
    const s = document.createElement("script"); s.async = true; s.src = "https://www.googletagmanager.com/gtag/js?id=" + GA_ID; document.head.appendChild(s);
  }
  function banner() {
    if (document.getElementById("wtmConsent")) return;
    const lang = (document.documentElement.lang || "en").slice(0, 2), t = T[lang] || T.en;
    const sc = document.documentElement.dataset.scheme || document.documentElement.dataset.theme;
    const dark = sc === "dark" || (sc !== "light" && matchMedia("(prefers-color-scheme: dark)").matches);
    const css = document.createElement("style");
    css.textContent = `#wtmConsent{--cb:${dark ? "#241b1a" : "#ffffff"};--ci:${dark ? "#f3ebe6" : "#2a1a1d"};--cl:${dark ? "#3a2c2b" : "#e2d6cf"};--ca:${dark ? "#c23a55" : "#8e1b33"};
      position:fixed;left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom,0px));z-index:400;max-width:560px;margin:0 auto;
      background:var(--cb);color:var(--ci);border:1px solid var(--cl);border-radius:14px;padding:12px 14px;box-shadow:0 8px 30px rgb(0 0 0 / ${dark ? ".45" : ".18"});
      font:14px/1.45 "Work Sans",system-ui,sans-serif;display:flex;gap:14px;align-items:center}
      #wtmConsent p{margin:0;flex:1 1 auto;min-width:0} #wtmConsent a{color:var(--ca);white-space:nowrap}
      #wtmConsent .b{display:flex;gap:8px;flex:0 0 auto}
      #wtmConsent button{font:700 14px "Work Sans",system-ui,sans-serif;padding:9px 16px;border-radius:99px;cursor:pointer;border:1.5px solid var(--ca)}
      #wtmConsent .y{background:var(--ca);color:#fff} #wtmConsent .n{background:transparent;color:var(--ci);border-color:var(--ci)}
      @media (max-width:520px){#wtmConsent{font-size:13px;gap:10px} #wtmConsent .b{flex-direction:column;gap:6px} #wtmConsent .b button{padding:7px 12px;font-size:13px}}`;
    const el = document.createElement("div"); el.id = "wtmConsent"; el.setAttribute("role", "dialog"); el.setAttribute("aria-label", "Cookie");
    el.innerHTML = `<p>${t[0]} <a href="/privacy/?lang=${lang}#cookie">${t[3]}</a></p><div class="b"><button type="button" class="n">${t[2]}</button><button type="button" class="y">${t[1]}</button></div>`;
    const close = (ok) => {
      save(ok); el.remove(); if (ok) loadGA();
      beacon(ok ? "consent:yes" : "consent:no"); // totali per l'admin (api/track.php), anche senza accettare: nessun identificativo
      try { dispatchEvent(new CustomEvent("wtm-consent", { detail: { ok } })); } catch (e) {} // la mappa lo segna sull'apertura
    };
    el.querySelector(".y").onclick = () => close(true);
    el.querySelector(".n").onclick = () => close(false);
    document.head.appendChild(css); document.body.appendChild(el);
  }
  // conteggi per la dashboard dell'admin (api/track.php): solo il nome dell'evento, per giorno
  function beacon(ev) {
    try { const b = JSON.stringify({ e: [ev] }); navigator.sendBeacon ? navigator.sendBeacon("/api/track.php", new Blob([b], { type: "application/json" }))
      : fetch("/api/track.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: b, keepalive: true }); } catch (e) {}
  }
  // pagine diverse dalla mappa (la mappa conta le sue aperture da sola): pagine SEO delle cantine, account, privacy
  const pg = location.pathname;
  if (pg !== "/" && !/^\/(admin|api)\//.test(pg)) beacon("page:" + (/^\/account\//.test(pg) ? "account" : /^\/privacy\//.test(pg) ? "privacy" : "seo"));
  // per cambiare idea: riapre il banner (rifiutando dopo aver accettato, GA si ferma alla prossima pagina)
  window.wtmConsent = { open: () => { try { localStorage.removeItem(KEY); } catch (e) {} banner(); }, given: () => read() === true };
  window.wtmGa = (name, params = {}) => { try { if (loaded) gtag("event", name, params); } catch (e) {} };
  const c = read();
  if (c === true) loadGA();
  else if (c === null) (document.body ? banner() : document.addEventListener("DOMContentLoaded", banner));
})();
