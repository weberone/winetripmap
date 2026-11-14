/* winetripmap.com — web app, stessa esperienza di SkateboardMap: posizione, raggio, radar che scopre i pin, elenco delle più vicine, scheda.
   - Mappa come SkateboardMap: prima Google finché la quota gratuita del mese regge, poi Mapbox, poi OpenFreeMap (gratuita);
     la scelta la fa /api/counter.php, i moduli sono nel core (core/js/map/map-google.js, map-gl.js), interfaccia window.SkateMap.
   - Cantine di tutto il mondo da OpenStreetMap (tools/osm-cantine.mjs):
     /data/cantine.json = tutti i punti (id, lat, lng, nome, comune), nel browser: raggio, ricerca e "più vicine" senza server;
     /data/det5/<riquadro di 2,5°>.json = indirizzo, telefono, sito e orari, caricati solo all'apertura di una scheda.
   - Ricerca di un luogo: /api/geocode.php (Nominatim di OpenStreetMap, gratuito, con memoria), altrimenti Nominatim direttamente. */
(() => {
"use strict";
// nome storico dell'interfaccia del core; il modulo (Google o GL) si carica all'avvio, quindi si legge sempre quello attuale
const M = new Proxy({}, { get: (_, k) => window.SkateMap[k] });

// ---------- mappe: chiavi PUBBLICHE, limitate in Google Cloud e in Mapbox al dominio winetripmap.com ----------
// Vuote = quella mappa è spenta. Mai chiavi segrete qui (Mapbox "sk.").
const MAPS = {
  googleKey: "",        // Google Cloud → Credenziali: chiave limitata a winetripmap.com e alla sola "Maps JavaScript API"
  googleMapId: "",      // Google Cloud → Gestione mappe → JavaScript, vettoriale (serve per i pin HTML)
  googleMapIdDark: "",  // Map ID con stile scuro (vuoto = tema scuro standard di Google)
  mapboxToken: "",      // account.mapbox.com → Tokens: token pubblico "pk." limitato a winetripmap.com
};
// traduzioni (site/js/i18n.js, caricato prima): t("chiave", { valori }), nW(n) = "12 cantine" nella lingua della pagina
const I18N = window.WTM_I18N, t = I18N.t, LOC = I18N.locale;
const nW = (n) => I18N.n("w", n, fmtN(n));
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const store = {
  get(k) { try { return JSON.parse(localStorage.getItem("wtm." + k)); } catch { return null; } },
  set(k, v) { try { localStorage.setItem("wtm." + k, JSON.stringify(v)); } catch {} }
};
const WORLD = { west: -150, south: -50, east: 165, north: 65 };
const RADII = [5, 10, 20, 30, 50];

const state = {
  me: null,                       // { lat, lng, name, gps }: da qui si misurano le distanze
  center: null,                   // centro dell'ultima ricerca (cerchio del raggio)
  radiusKm: RADII.includes(store.get("radius")) ? store.get("radius") : 20,
  showPreview: store.get("preview") !== false, // radar: cerca da solo quando sposti la mappa
  wc: "", // filtro per colore del vino principale ("" = tutte)
  all: [], byId: new Map(), sel: null, mapsReady: false, areaOk: false, loading: false, userMoved: false,
  user: null, favs: new Set()      // account (api/auth.php) e cantine preferite (api/fav.php)
};

// ---------- utilità ----------
function distKm(a, b) {
  const t = (x) => x * Math.PI / 180, R = 6371;
  const h = Math.sin(t(b.lat - a.lat) / 2) ** 2 + Math.cos(t(a.lat)) * Math.cos(t(b.lat)) * Math.sin(t(b.lng - a.lng) / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}
const fmtKm = (k) => k < 1 ? Math.round(k * 1000) + " m" : k >= 100 ? Math.round(k).toLocaleString(LOC) + " km"
  : k.toLocaleString(LOC, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + " km";
function fmtN(n) { return Number(n || 0).toLocaleString(LOC); }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const norm = (s) => (s || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
function offsetLatLng(c, m, deg) {
  const a = deg * Math.PI / 180;
  return { lat: c.lat + (m * Math.cos(a)) / 111320, lng: c.lng + (m * Math.sin(a)) / (111320 * Math.cos(c.lat * Math.PI / 180)) };
}
function boundsAround(c, m) {
  const n = offsetLatLng(c, m, 0), e = offsetLatLng(c, m, 90), s = offsetLatLng(c, m, 180), w = offsetLatLng(c, m, 270);
  return { north: n.lat, south: s.lat, east: e.lng, west: w.lng };
}
function setStatus(msg) { $("status").textContent = msg; }

// ---------- segnaposto ----------
const dark = () => document.documentElement.dataset.scheme === "dark";
const pinColor = () => dark() ? "#c23a55" : "#8e1b33";
// colore del vino principale della cantina (letto dal suo sito: tools/vini-dai-siti.mjs): r rosso, b bianco, o rosato, s bollicine
const WINE = {
  r: { name: t("red"), fill: () => pinColor() },
  b: { name: t("white"), fill: () => "#d6a51f" },
  o: { name: t("rose"), fill: () => "#e27591" },
  s: { name: t("spark"), fill: () => "#b38b2e" },
};
// goccia piena (bordeaux; col colore del vino principale quando è noto) con dentro il logo del sito, nero se la cantina
// ha la lista dei vini (dal suo sito), bianco se no:
// il pin a forma di calice del puntino della i (site/mappa/index.html). La punta (ax, in basso) è il punto esatto.
const LOGO_GLASS = "M1 5.5A5 5 0 0 1 11 5.5C11 8.9 8.4 11.3 6.6 12V14.3H8.6C9.1 14.3 9.4 14.6 9.4 14.95S9.1 15.6 8.6 15.6H3.4C2.9 15.6 2.6 15.3 2.6 14.95S2.9 14.3 3.4 14.3H5.4V12C3.6 11.3 1 8.9 1 5.5Z";
function pinSvg(w = 28, wc = "", hv = false) {
  const h = Math.round(w * 38 / 28), fill = (WINE[wc] || WINE.r).fill(), ink = hv ? "#1a1414" : "#fff";
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 28 38"><path d="M14 37C14 37 2 23.5 2 14a12 12 0 0 1 24 0c0 9.5-12 23-12 23z" fill="${fill}" stroke="#fff" stroke-width="1.6"/>`
    + `<g transform="translate(8.3 6.4) scale(.95)"><path d="${LOGO_GLASS}" fill="${ink}"/><circle cx="6" cy="5.5" r="1.75" fill="${fill}"/></g></svg>`;
}
function markerIcon(sel, wc, hv) { const w = sel ? 36 : 28; return { svg: pinSvg(w, wc, hv), w, h: Math.round(w * 38 / 28), ax: w / 2 }; }
// gruppo di cantine vicine: cerchio bordeaux con il numero
function clusterIcon(n) {
  const d = n < 10 ? 36 : n < 50 ? 42 : n < 500 ? 48 : 54, r = d / 2;
  const label = n < 1000 ? n : (Math.round(n / 100) / 10).toLocaleString(LOC) + "k";
  return { w: d, h: d, svg: `<svg xmlns="http://www.w3.org/2000/svg" width="${d}" height="${d}" viewBox="0 0 ${d} ${d}"><circle cx="${r}" cy="${r}" r="${r - 1.5}" fill="${pinColor()}" stroke="#fff" stroke-width="2"/><text x="${r}" y="${r + 4.5}" text-anchor="middle" font-family="Arial,sans-serif" font-weight="700" font-size="${n < 100 ? 14 : 12}" fill="#fff">${label}</text></svg>` };
}
const title = (x) => x.n || (x.c ? t("winery_in", { c: x.c }) : t("winery"));

// ---------- dati ----------
let dataP = null;
function loadData() {
  if (!dataP) dataP = fetch("/data/cantine.json").then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); }).then((d) => {
    state.all = d.items.map(([id, lat, lng, n, c, wc, hv]) => ({ id, lat, lng, n, c, wc: wc || "", hv: !!hv }));
    state.byId = new Map(state.all.map((x) => [x.id, x]));
    return d;
  });
  return dataP;
}
// dettagli (indirizzo, telefono, sito, orari) del riquadro di 10° della cantina: stesso calcolo di tools/osm-cantine.mjs
const cell = (x) => `${Math.floor(x.lat / 2.5)}_${Math.floor(x.lng / 2.5)}`;
const detCache = new Map();
function loadDetails(x) {
  const k = cell(x);
  if (!detCache.has(k)) detCache.set(k, fetch(`/data/det5/${k}.json`).then((r) => r.ok ? r.json() : {}).catch(() => ({})));
  return detCache.get(k).then((d) => d[x.id] || {});
}
// cantine nel raggio della ricerca, dalla più vicina a te
function inRadius() {
  if (!state.center) return [];
  const from = state.me || state.center, b = boundsAround(state.center, state.radiusKm * 1000 * 1.01);
  return state.all.filter((x) => x.lat <= b.north && x.lat >= b.south && x.lng <= b.east && x.lng >= b.west)
    .map((x) => Object.assign(x, { kmC: distKm(state.center, x), km: distKm(from, x) }))
    .filter((x) => x.kmC <= state.radiusKm).sort((a, b) => a.km - b.km);
}
const visible = () => { const v = inRadius(); return state.wc ? v.filter((x) => x.wc === state.wc) : v; };
// filtro per colore: Tutte, Rossi, Bianchi, Rosati, Bollicine, col numero di cantine nel raggio
function renderChips(all) {
  const n = { r: 0, b: 0, o: 0, s: 0 }; for (const x of all) if (x.wc) n[x.wc]++;
  const chip = (k, label) => `<button type="button" class="chip" data-wc="${k}" aria-pressed="${state.wc === k}">${k ? `<span class="wdot w${k}"></span>` : ""}${label}${k ? ` <span class="cn">${fmtN(n[k])}</span>` : ""}</button>`;
  const box = $("wineChips"); if (!box) return; // pagina vecchia ancora in memoria del browser: niente filtri
  box.innerHTML = chip("", t("all")) + chip("r", t("reds")) + chip("b", t("whites")) + chip("o", t("roses")) + chip("s", t("sparkling"));
}

// ---------- elenco, pin, cerchio ----------
function renderList() {
  const v = visible();
  renderChips(inRadius());
  $("count").textContent = fmtN(v.length);
  $("countLbl").textContent = I18N.n("found", v.length, "").trim();
  $("countMob").innerHTML = state.areaOk ? esc(t("within", { n: nW(v.length), km: state.radiusKm })).replace(fmtN(v.length), `<span class="cm-n">${fmtN(v.length)}</span>`) : "";
  if (state.areaOk && !state.loading) setStatus(t("within", { n: nW(v.length), km: state.radiusKm }));
  radarLabel();
  if (!v.length) {
    $("list").innerHTML = state.areaOk && state.wc && inRadius().length ? `<div class="empty">${esc(t("empty_color", { c: WINE[state.wc].name.toLowerCase(), all: t("all") }))}</div>`
      : state.areaOk ? `<div class="empty">${esc(t("empty_radius"))}</div>`
      : `<div class="empty">${esc(t("empty_start"))}</div>`;
  } else {
    $("list").innerHTML = v.slice(0, 300).map((x) => `<button class="item" data-id="${esc(x.id)}"><span class="mk">${pinSvg(20, x.wc, x.hv)}</span>
        <span style="min-width:0"><div class="name">${esc(title(x))}</div>${x.c && x.n ? `<div class="meta">${esc(x.c)}</div>` : ""}</span>
        <span class="km">${fmtKm(x.km)}</span></button>`).join("");
  }
  drawMarkers();
}
function drawMarkers() {
  if (!state.mapsReady) return;
  const v = visible(), sel = state.sel && state.byId.get(state.sel);
  if (sel && !v.includes(sel)) v.push(sel); // la cantina aperta resta sulla mappa anche fuori dal raggio
  M.setSpots(v.map((x) => ({ id: x.id, lat: x.lat, lng: x.lng, selected: x.id === state.sel, icon: markerIcon(x.id === state.sel, x.wc, x.hv), label: x.c || "" })), clusterIcon);
  drawMe();
}
function drawMe() {
  if (!state.mapsReady) return;
  if (state.me) M.setUser({ lat: state.me.lat, lng: state.me.lng });
  M.setCircle("radius", state.center, state.radiusKm);
}

// ---------- telefono: mappa intera o pannello aperto ----------
const MOB_MQ = "(max-width:760px), (max-height:500px) and (orientation:landscape)";
const isMobile = () => matchMedia(MOB_MQ).matches;
const app = document.querySelector(".app");
const mapOnly = () => app.classList.contains("map-only");
// tasto Indietro: chiude l'ultima cosa aperta (pannello, scheda, ricerca) invece di uscire
const layers = []; let ignorePop = 0, fromHistory = false;
function pushLayer(n) { layers.push(n); history.pushState({ wtm: layers.length }, ""); }
function popLayer(n) {
  if (fromHistory) return;
  const i = layers.lastIndexOf(n); if (i < 0) return;
  const top = i === layers.length - 1; layers.splice(i, 1);
  if (top) { ignorePop++; history.back(); }
}
addEventListener("popstate", () => {
  if (ignorePop > 0) { ignorePop--; return; }
  const n = layers.pop(); if (!n) return;
  fromHistory = true;
  try { if (n === "sheet") setSheet(false); else if (n === "detail") closeDetail(); else if (n === "search") closeSearch(); }
  finally { fromHistory = false; }
});
function setSheet(open) {
  const wasOpen = !mapOnly();
  if (isMobile() && open && !wasOpen) pushLayer("sheet");
  if (isMobile() && !open && wasOpen && !$("detail").hidden) closeDetail(true);
  if (isMobile() && !open && wasOpen) popLayer("sheet");
  app.classList.toggle("map-only", !open && isMobile());
  app.classList.toggle("mob", isMobile());
  $("menuBtn").setAttribute("aria-expanded", String(open));
  document.querySelector("aside.panel").inert = !open && isMobile();
  if (!state.mapsReady) return;
  // il pannello sale in 0,3 s: a movimento finito la mappa prende la nuova misura
  setTimeout(() => { M.resize(); M.setBottomPadding(mapPad(), 0); }, 320);
}
// telefono a mappa intera: il centro sale un poco, sopra l'onda e i pulsanti in basso (puntino e cerchio restano al centro del visibile)
function mapPad() {
  const p = isMobile() && mapOnly() ? 60 : 0;
  document.documentElement.style.setProperty("--mobpad", p + "px");
  return p;
}
function fitToRadius(duration = 0) {
  if (!state.mapsReady || !state.center) return;
  leaveGlobe();
  M.fitBounds(boundsAround(state.center, state.radiusKm * 1000), isMobile() ? 12 : 24, null, duration);
}

// ---------- radar (come SkateboardMap) ----------
// Lancetta che fa perno al centro e gira fino al bordo del raggio, con la scia che sfuma (un giro ogni 3 s).
let radarRaf = 0, radarAngle = 0, revealing = false, radarHold = false, radarWait = null, autoTimer = 0, tipFly = 0;
const noMotion = () => matchMedia("(prefers-reduced-motion: reduce)").matches;
function startRadar() {
  if (radarRaf || revealing || noMotion()) return;
  const period = 3000, t0 = performance.now() - radarAngle / 360 * period, start = performance.now();
  const tick = (now) => {
    radarAngle = ((now - t0) % period) / period * 360;
    M.setSweep(M.getCenter(), state.radiusKm, radarAngle, Math.min(1, (now - start) / 250));
    radarRaf = requestAnimationFrame(tick);
  };
  radarRaf = requestAnimationFrame(tick);
}
// risultati pronti: un ultimo giro veloce (1,5 s) e ogni pin compare quando la lancetta ci passa sopra
function radarReveal() {
  if (!radarRaf) return null;
  cancelAnimationFrame(radarRaf); radarRaf = 0; revealing = true;
  const c = M.getCenter(), a0 = radarAngle, km = state.radiusKm, ms = 1500;
  M.revealStart(c, a0);
  return () => new Promise((done) => {
    const t0 = performance.now();
    const tick = (now) => {
      const k = Math.min(1, (now - t0) / ms), swept = 360 * (1 - Math.pow(1 - k, 1.4));
      radarAngle = (a0 + swept) % 360;
      M.setSweep(c, km, radarAngle); M.revealAt(swept);
      if (k < 1) return requestAnimationFrame(tick);
      M.revealEnd(); revealing = false; done();
    };
    requestAnimationFrame(tick);
  });
}
// ricerche fatte dall'app (posizione, luogo cercato, "Cerca in questa zona"): radarBegin prima, radarFinish(disegna) dopo
function radarBegin(delay = 0) {
  if (!state.mapsReady || noMotion()) return false;
  radarHold = true;
  radarWait = delay ? new Promise((ok) => setTimeout(() => { startRadar(); ok(); }, delay)) : (startRadar(), null);
  M.setRadiusAccent(true);
  M.revealStart(M.getCenter(), radarAngle);
  radarLabel();
  return true;
}
async function radarFinish(draw, on) {
  if (on && radarWait) { await radarWait; radarWait = null; }
  const sweep = on ? radarReveal() : null;
  draw();
  if (sweep) await sweep(); else if (on) M.revealEnd();
  if (!on) return;
  M.setRadiusAccent(false);
  radarHold = false; updatePreview(); radarLabel();
}
function stopRadar() {
  if (revealing || radarHold) return;
  cancelAnimationFrame(radarRaf); radarRaf = 0;
  if (state.mapsReady) M.setSweep(null);
}
// con "Cerca in questa zona" visibile e il radar acceso: velo colorato e lancetta che gira sulla nuova zona
function updatePreview() {
  if (!state.mapsReady) return;
  const show = !$("searchHere").hidden && state.showPreview;
  M.setCircle("preview", show ? M.getCenter() : null, state.radiusKm);
  if (show) startRadar(); else stopRadar();
}
// dopo ogni spostamento: zona nuova? Allora "Cerca in questa zona" e, col radar acceso, ricerca da sola dopo 3 s di mappa ferma
function checkArea() {
  if (!state.center) return;
  if (tipFly && Date.now() - tipFly < 1500) { tipFly = 0; return; } // spostamento fatto dall'app su una cantina
  tipFly = 0;
  const moved = distKm(state.center, M.getCenter());
  $("searchHere").hidden = radarHold || !$("detail").hidden || moved < Math.max(1.5, state.radiusKm * 0.08);
  updatePreview();
  $("previewBtn").classList.toggle("idle", $("searchHere").hidden || !state.showPreview);
  radarLabel();
  clearTimeout(autoTimer);
  if (state.showPreview && !$("searchHere").hidden) autoTimer = setTimeout(searchHere, 3000);
}
// scritta del radar sulla mappa: Cerco… / Nessuna cantina qui / Radar attivo / Radar spento
function radarLabel() {
  const el = $("noRes");
  const searching = radarHold || (!$("searchHere").hidden && state.showPreview);
  const empty = state.areaOk && !state.loading && !visible().length;
  const k = searching ? "radarSearching" : empty ? "noResHere" : state.showPreview ? "radarOn" : "radarOff";
  el.textContent = { radarSearching: t("searching"), noResHere: t("no_res_here"), radarOn: t("radar_on"), radarOff: t("radar_off") }[k];
  el.dataset.k = k;
  el.hidden = !state.center;
  const nb = $("nearBtn");
  if (!nb.classList.contains("busy")) nb.hidden = k !== "noResHere" || !$("searchHere").hidden;
}

// ---------- ricerche ----------
let areaSeq = 0;
async function loadArea(minMs = 0) {
  const seq = ++areaSeq, t0 = performance.now();
  state.loading = true;
  if (state.mapsReady) M.setLink(null);
  setStatus(t("searching"));
  try {
    await loadData();
    const rest = minMs - (performance.now() - t0); if (rest > 0) await sleep(rest); // i dati sono già qui: il radar fa almeno un mezzo giro
    if (seq !== areaSeq) return;
    state.areaOk = true;
  } catch (e) {
    console.error(e);
    state.areaOk = false;
    setStatus(t("load_fail"));
  } finally { if (seq === areaSeq) state.loading = false; }
}
// nuova posizione (GPS o luogo cercato): le distanze si misurano da qui
async function setMe(lat, lng, name, gps, msg) {
  const prev = state.center;
  state.me = { lat, lng, name, gps };
  state.center = { lat, lng };
  $("worldAsk").hidden = true;
  $("locmsg").textContent = msg || "";
  $("searchHere").hidden = true; updatePreview();
  closeDetail(true);
  fitToRadius();
  drawMe();
  const rv = (!prev || distKm(prev, state.center) > Math.max(1.5, state.radiusKm * 0.08)) && radarBegin(prev ? 0 : 300);
  try { await loadArea(rv ? 900 : 0); }
  finally { await radarFinish(() => renderList(), rv); }
}
// "Cerca in questa zona" (o raggio cambiato): il cerchio va in c, le distanze restano da te
async function searchAt(c, minMs) {
  clearTimeout(autoTimer);
  if (!state.mapsReady) return;
  state.center = c;
  $("searchHere").hidden = true;
  M.setCircle("preview", null);
  const rv = radarBegin();
  drawMe();
  try { await loadArea(rv ? minMs : 0); }
  finally { await radarFinish(() => renderList(), rv); }
}
const searchHere = () => searchAt(M.getCenter(), 700);
// radar vuoto: la cantina più vicina al punto cercato, poi tutte quelle del raggio intorno a lei
async function findNearest() {
  const nb = $("nearBtn");
  if (nb.classList.contains("busy")) return;
  clearTimeout(autoTimer);
  await loadData();
  const from = { lat: state.center.lat, lng: state.center.lng };
  let best = null, bk = Infinity;
  for (const x of state.all) { const k = distKm(from, x); if (k < bk) { bk = k; best = x; } }
  if (!best) return;
  nb.hidden = true;
  state.center = { lat: best.lat, lng: best.lng };
  tipFly = Date.now() + 600;
  const vb = boundsAround(state.center, state.radiusKm * 1000);
  vb.north = Math.max(vb.north, from.lat); vb.south = Math.min(vb.south, from.lat);
  vb.east = Math.max(vb.east, from.lng); vb.west = Math.min(vb.west, from.lng);
  M.fitBounds(vb, isMobile() ? 28 : 48, null, noMotion() ? 0 : 1200);
  drawMe();
  await loadArea(); renderList();
  M.setLink(from, best, fmtKm(bk));
  setStatus(t("nearest_at", { d: fmtKm(bk) }));
}

// ---------- posizione ----------
function getPosition(fresh = false) {
  if (!navigator.geolocation) return Promise.resolve({ err: { code: 2 } });
  return new Promise((res) => navigator.geolocation.getCurrentPosition((p) => res({ p }), (err) => res({ err }),
    fresh ? { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 } : { enableHighAccuracy: false, timeout: 8000, maximumAge: 300000 }));
}
async function applyPosition(r, silent) {
  if (r.p) {
    store.set("lastgps", { lat: r.p.coords.latitude, lng: r.p.coords.longitude });
    await setMe(r.p.coords.latitude, r.p.coords.longitude, t("your_pos"), true, "");
    $("meBtn").setAttribute("aria-pressed", "true");
    return true;
  }
  if (silent) return false;
  const why = t(!navigator.geolocation ? "why_browser" : r.err.code === 1 ? "why_denied" : r.err.code === 3 ? "why_gps" : "why_na");
  $("locmsg").textContent = t("no_pos", { why });
  setStatus(t("pos_na"));
  return false;
}
async function restartGeo() {
  state.userMoved = false;
  $("meBtn").classList.add("busy"); setStatus(t("pos_seek"));
  let r = await getPosition(true);
  if (!r.p && r.err && r.err.code !== 1) r = await getPosition(false); // al chiuso il GPS è lento: la posizione più recente
  $("meBtn").classList.remove("busy");
  return applyPosition(r, false);
}
// pulsante acceso quando la mappa è centrata sulla tua posizione GPS
function syncMeBtn() {
  if (!state.mapsReady || !state.me || !state.me.gps) return $("meBtn").setAttribute("aria-pressed", "false");
  const b = M.getBounds(), c = M.getCenter();
  const width = distKm({ lat: c.lat, lng: b.west }, { lat: c.lat, lng: b.east });
  $("meBtn").setAttribute("aria-pressed", String(distKm(c, state.me) <= Math.max(0.05, width * 0.03)));
}

// ---------- fumetto e scheda ----------
function pinTip(id) {
  const x = state.byId.get(id); if (!x) return;
  const from = state.me || state.center;
  state.sel = id; drawMarkers();
  M.showPopup({ lat: x.lat, lng: x.lng }, `<button class="pintip" type="button"><b>${esc(title(x))}</b>${x.c && x.n ? `<small>${esc(x.c)}</small>` : ""}${from ? `<small>${esc(t("away", { d: fmtKm(distKm(from, x)) }))}</small>` : ""}<em>${esc(t("open_card"))}</em></button>`,
    () => openDetail(id, false));
}
const directionsUrl = (x) => `https://www.google.com/maps/dir/?api=1&destination=${x.lat},${x.lng}`;
const osmUrl = (x) => `https://www.openstreetmap.org/${{ n: "node", w: "way", r: "relation" }[x.id[0]]}/${x.id.slice(1)}`;
const placeUrl = (x) => `${location.origin}${location.pathname}?c=${encodeURIComponent(x.id)}`;
async function openDetail(id, fly = true) {
  const x = state.byId.get(id); if (!x) return;
  const from = state.me || state.center;
  const wasOpen = !$("detail").hidden;
  state.sel = id;
  M.showPopup(null);
  const head = `<div class="dhead"><h2>${esc(title(x))}</h2><button class="x" id="dClose" aria-label="${esc(t("close"))}">✕</button></div>
    <div class="dkind">${esc(t("winery"))}${from ? ` · ${esc(t("away", { d: fmtKm(distKm(from, x)) }))}` : ""}</div>`;
  $("detail").innerHTML = head + `<p class="dline"><small>${esc(t("loading"))}</small></p>`;
  $("detail").hidden = false; $("list").hidden = true;
  app.classList.add("has-detail");
  $("dClose").onclick = () => closeDetail();
  $("detail").scrollTop = 0; document.querySelector("aside.panel").scrollTop = 0;
  if (isMobile() && mapOnly()) setSheet(true);
  if (!wasOpen) pushLayer("detail");
  drawMarkers();
  if (fly) { tipFly = Date.now(); M.flyTo({ lat: x.lat, lng: x.lng }, 14); }
  // telefono: sopra la scheda resta una striscia di mappa; a pannello salito (0,3 s) la cantina va al centro, da vicino
  else if (isMobile()) setTimeout(() => { if (state.sel === id && state.mapsReady) { tipFly = Date.now(); M.flyTo({ lat: x.lat, lng: x.lng }, 13); } }, 360);
  $("searchHere").hidden = true; updatePreview();
  const d = await loadDetails(x);
  if (state.sel !== id) return; // nel frattempo ne hai aperta un'altra
  const tel = d.p ? d.p.split(/[;,]/)[0].trim() : "";
  const host = d.w ? d.w.replace(/^https?:\/\/(www\.)?/i, "").replace(/\/$/, "") : "";
  $("detail").innerHTML = head + `
    ${d.a || x.c ? `<p class="dline"><small>${esc(t("address"))}</small>${esc([d.a, x.c].filter(Boolean).join(", "))}</p>` : ""}
    ${d.o ? `<p class="dline"><small>${esc(t("hours"))}</small>${esc(d.o)}</p>` : ""}
    ${tel ? `<p class="dline"><small>${esc(t("phone"))}</small><a href="tel:${esc(tel.replace(/[^\d+]/g, ""))}">${esc(tel)}</a></p>` : ""}
    ${host ? `<p class="dline"><small>${esc(t("site"))}</small><a href="${esc(d.w)}" target="_blank" rel="noopener nofollow">${esc(host)}</a></p>` : ""}
    ${wineList(d)}
    <div class="dbtns">
      <a class="btn primary" href="${directionsUrl(x)}" target="_blank" rel="noopener">${esc(t("directions"))}</a>
      ${tel ? `<a class="btn" href="tel:${esc(tel.replace(/[^\d+]/g, ""))}">${esc(t("call"))}</a>` : ""}
      ${d.w ? `<a class="btn" href="${esc(d.w)}" target="_blank" rel="noopener nofollow">${esc(t("site_btn"))}</a>` : ""}
      <button class="btn fav" id="dFav" type="button" aria-pressed="false"><svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.6a4.3 4.3 0 0 1 7.5 2.7c0 5.6-7.5 10.2-7.5 10.2z"/></svg><span>${esc(t("save"))}</span></button>
      <button class="btn" id="dShare" type="button">${esc(t("share"))}</button>
    </div>
    <p class="dsrc">${esc(t("osm"))} · <a href="${osmUrl(x)}" target="_blank" rel="noopener">${esc(t("fix"))}</a></p>`;
  $("dClose").onclick = () => closeDetail();
  $("dShare").onclick = () => share(title(x), placeUrl(x));
  $("dFav").onclick = () => toggleFav(x); syncFav(x.id);
  const more = $("wMore"); if (more) more.onclick = () => { $("detail").querySelectorAll(".wines li[hidden]").forEach((li) => { li.hidden = false; }); more.remove(); };
}
// i vini letti dal sito della cantina; i primi 12, poi "Tutti i vini".
// Con le foto delle bottiglie (prese dal sito della cantina, caricate da lì: non le copiamo) una griglia di bottiglie,
// dove manca la foto una sagoma nel colore del vino; senza nessuna foto l'elenco con il pallino del colore.
const BOTTLE = (c) => `<svg class="wsil" viewBox="0 0 20 56" aria-hidden="true"><path d="M8 2h4v12c0 2.5 4 4 4 9v29a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V23c0-5 4-6.5 4-9z" fill="${c ? WINE[c].fill() : "none"}" stroke="${c ? WINE[c].fill() : "currentColor"}" stroke-width="1.4"/></svg>`;
function wineList(d) {
  if (!d.v || !d.v.length) return "";
  const src = d.vu ? d.vu.replace(/^https?:\/\/(www\.)?/i, "").replace(/\/$/, "") : "";
  const pics = d.v.some((w) => w[2]);
  const item = ([n, c, img], i) => pics
    ? `<li${i >= 12 ? " hidden" : ""}><span class="wpic">${img ? `<img src="${esc(img)}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.replaceWith(document.createRange().createContextualFragment(this.dataset.f))" data-f="${esc(BOTTLE(c))}">` : BOTTLE(c)}</span>
        <span class="wname"><span class="wdot${c ? " w" + c : ""}" title="${c ? WINE[c].name : ""}"></span>${esc(n)}</span></li>`
    : `<li${i >= 12 ? " hidden" : ""}><span class="wdot${c ? " w" + c : ""}" title="${c ? WINE[c].name : ""}"></span>${esc(n)}</li>`;
  return `<div class="dline"><small>${esc(t("wines"))} (${d.v.length})</small><ul class="wines${pics ? " wgrid" : ""}">${d.v.map(item).join("")}</ul>
    ${d.v.length > 12 ? `<button type="button" class="linkbtn" id="wMore">${esc(t("all_wines"))}</button>` : ""}
    <p class="wsrc">${esc(t("from_site"))}${src ? `: <a href="${esc(d.vu)}" target="_blank" rel="noopener nofollow">${esc(src.length > 40 ? src.slice(0, 40) + "…" : src)}</a>` : ""}${pics ? esc(t("photos")) : ""}${esc(t("vintages"))}</p></div>`;
}
function closeDetail(silent) {
  if ($("detail").hidden && !state.sel) return;
  const wasOpen = !$("detail").hidden;
  $("detail").hidden = true; $("list").hidden = false;
  app.classList.remove("has-detail");
  state.sel = null;
  if (state.mapsReady) M.showPopup(null);
  if (wasOpen) popLayer("detail");
  if (!silent) drawMarkers();
}
async function share(text, url) {
  try { if (navigator.share) return await navigator.share({ title: "WineTripMap", text, url }); } catch (e) { return; }
  try { await navigator.clipboard.writeText(url); setStatus(t("link_copied")); } catch (e) { prompt(t("copy_link"), url); }
}

// ---------- ricerca: cantine per nome, comuni, luoghi (Nominatim) ----------
let hits = [], hi = -1;
function openSearch() { $("searchModal").hidden = false; pushLayer("search"); $("searchMsg").textContent = ""; loadData().then(suggest, () => {}); setTimeout(() => $("q").focus(), 30); suggest(); }
function closeSearch() { if ($("searchModal").hidden) return; $("searchModal").hidden = true; popLayer("search"); }
function suggest() {
  const s = norm($("q").value.trim());
  hits = []; hi = -1;
  if (s.length < 2) { $("results").innerHTML = ""; return; }
  const towns = new Map();
  state.all.forEach((x) => { if (x.c && norm(x.c).startsWith(s)) { const t = towns.get(x.c) || { name: x.c, n: 0, lat: 0, lng: 0 }; t.n++; t.lat += x.lat; t.lng += x.lng; towns.set(x.c, t); } });
  const tw = [...towns.values()].sort((a, b) => b.n - a.n).slice(0, 4).map((t) => ({ kind: "town", name: t.name, n: t.n, lat: t.lat / t.n, lng: t.lng / t.n }));
  const ws = state.all.filter((x) => x.n && norm(x.n).includes(s)).sort((a, b) => (norm(a.n).startsWith(s) ? 0 : 1) - (norm(b.n).startsWith(s) ? 0 : 1)).slice(0, 8).map((x) => ({ kind: "place", x }));
  hits = [{ kind: "geo", q: $("q").value.trim() }, ...tw, ...ws];
  let html = `<li data-i="0">🔎 ${esc(t("find_place", { q: hits[0].q }))}</li>`;
  if (tw.length) html += `<li class="rhead">${esc(t("towns"))}</li>` + tw.map((t, i) => `<li data-i="${i + 1}">${esc(t.name)}<small>${fmtN(t.n)}</small></li>`).join("");
  if (ws.length) html += `<li class="rhead">${esc(t("wineries"))}</li>` + ws.map((w, i) => `<li data-i="${i + 1 + tw.length}">${esc(w.x.n)}<small>${esc(w.x.c || "")}</small></li>`).join("");
  $("results").innerHTML = html;
}
function pick(i) {
  const h = hits[i]; if (!h) return;
  if (h.kind === "geo") return geocode(h.q);
  closeSearch(); state.userMoved = true;
  if (h.kind === "town") return setMe(h.lat, h.lng, h.name, false, t("place", { p: h.name }));
  // una cantina: il raggio intorno a lei (le distanze restano da te) e la sua scheda
  const x = h.x;
  state.center = { lat: x.lat, lng: x.lng };
  fitToRadius(); drawMe();
  loadArea().then(() => { renderList(); openDetail(x.id, false); });
}
async function geocode(q) {
  if (q.length < 2) return;
  const msg = (m) => { $("searchMsg").textContent = m; };
  const go = (lat, lng, name, full) => { closeSearch(); state.userMoved = true; setMe(lat, lng, name || q, false, t("place", { p: full || name || q })); };
  msg(t("searching_q", { q }));
  // prima in Italia ("Alba" è quella delle Langhe), poi nel resto del mondo. Dall'Italia vale subito solo un luogo
  // (città, paese, frazione: rank fino a 25), non un locale col nome uguale ("New York" è un bar a Roma, non la città)
  const isPlace = (p) => (p.rank ?? 30) <= 25;
  const it = await lookup(q, "it");
  if (it === "down") return msg(t("search_down"));
  if (it && isPlace(it)) return go(it.lat, it.lng, it.name, it.full);
  const w = await lookup(q, "");
  if (w && w !== "down" && (isPlace(w) || !it)) return go(w.lat, w.lng, w.name, w.full);
  if (it) return go(it.lat, it.lng, it.name, it.full);
  msg(w === "down" ? t("search_down") : t("not_found", { q }));
}
// un luogo dal nostro server (con memoria); se non risponde (o in prova locale senza PHP) da Nominatim direttamente.
// Restituisce il luogo, null se non esiste, "down" se nessuno risponde.
async function lookup(q, cc) {
  try {
    const r = await fetch("/api/geocode.php?" + new URLSearchParams({ q, lang: I18N.lang, cc }));
    if (r.status === 404) return null;
    const d = r.ok ? await r.json() : null;
    if (d && !d.error) return d;
  } catch (e) {}
  try {
    const r = await fetch("https://nominatim.openstreetmap.org/search?" + new URLSearchParams(Object.assign({ q, format: "jsonv2", limit: "1", "accept-language": I18N.lang }, cc ? { countrycodes: cc } : {})));
    if (!r.ok) return "down";
    const x = (await r.json())[0];
    return x ? { lat: +x.lat, lng: +x.lon, name: x.name, full: x.display_name, rank: +x.place_rank } : null;
  } catch (e) { return "down"; }
}

// ---------- raggio, tema ----------
function setRadius(km) {
  state.radiusKm = km; store.set("radius", km);
  $("radius").value = String(km); $("kmVal").textContent = km;
  document.querySelectorAll("[data-km]").forEach((b) => b.setAttribute("aria-pressed", String(+b.dataset.km === km)));
  if (!state.center) return;
  closeDetail(true);
  fitToRadius(noMotion() ? 0 : 400);
  searchAt(state.center, 600);
}
function toggleKm(open) {
  const p = $("kmPick"), o = open ?? !p.classList.contains("open");
  p.classList.toggle("open", o); $("kmBtn").setAttribute("aria-expanded", String(o));
}
function applyScheme() {
  const d = document.documentElement, t = d.dataset.theme;
  d.dataset.scheme = t === "light" || (t === "auto" && !matchMedia("(prefers-color-scheme: dark)").matches) ? "light" : "dark";
  d.dataset.mapscheme = d.dataset.scheme;
  if (state.mapsReady) { M.setDark(d.dataset.scheme === "dark"); M.redrawPins(); drawMarkers(); }
}

// ---------- pulsanti ----------
function bind() {
  $("menuBtn").onclick = () => setSheet(mapOnly());
  $("searchBtn").onclick = openSearch; $("placeBtn").onclick = openSearch; $("worldAskBtn").onclick = openSearch;
  $("locBtn").onclick = restartGeo; $("worldAskGps").onclick = restartGeo;
  $("meBtn").onclick = () => { state.me && state.me.gps ? setMe(state.me.lat, state.me.lng, state.me.name, true, "") : restartGeo(); };
  $("searchHere").onclick = searchHere;
  $("nearBtn").onclick = findNearest;
  $("previewBtn").setAttribute("aria-pressed", String(state.showPreview));
  $("previewBtn").onclick = () => {
    state.showPreview = !state.showPreview; store.set("preview", state.showPreview);
    $("previewBtn").setAttribute("aria-pressed", String(state.showPreview));
    checkArea();
  };
  $("kmBtn").onclick = () => toggleKm();
  if ($("wineChips")) $("wineChips").onclick = (e) => {
    const b = e.target.closest("[data-wc]"); if (!b) return;
    state.wc = b.dataset.wc; renderList();
  };
  document.querySelectorAll("[data-km]").forEach((b) => b.onclick = () => { toggleKm(false); setRadius(+b.dataset.km); });
  $("radius").onchange = () => setRadius(+$("radius").value);
  $("list").onclick = (e) => { const b = e.target.closest(".item"); if (b) openDetail(b.dataset.id); };
  $("zoomIn").onclick = () => M.zoomBy(1); $("zoomOut").onclick = () => M.zoomBy(-1);
  $("mapThemeBtn").onclick = () => {
    const d = document.documentElement; d.dataset.theme = d.dataset.scheme === "dark" ? "light" : "dark";
    store.set("theme", d.dataset.theme); applyScheme();
  };
  matchMedia("(prefers-color-scheme: dark)").addEventListener("change", applyScheme);
  $("shareHdr").onclick = () => share(t("share_text"), location.origin + location.pathname);
  // Invio: il comune o la cantina con esattamente quel nome, altrimenti la ricerca del luogo
  $("searchForm").onsubmit = (e) => {
    e.preventDefault(); suggest();
    const exact = hits.findIndex((h) => h.kind !== "geo" && norm(h.name || (h.x && h.x.n)) === norm($("q").value.trim()));
    pick(hi >= 0 ? hi : exact > 0 ? exact : 0);
  };
  $("q").oninput = suggest;
  $("q").onkeydown = (e) => {
    if (e.key !== "ArrowDown" && e.key !== "ArrowUp") return;
    e.preventDefault();
    const idx = [...$("results").querySelectorAll("li[data-i]")].map((li) => +li.dataset.i);
    const pos = Math.max(0, Math.min(idx.length - 1, idx.indexOf(hi) + (e.key === "ArrowDown" ? 1 : -1)));
    hi = idx[pos];
    $("results").querySelectorAll("li[data-i]").forEach((li) => li.classList.toggle("sel", +li.dataset.i === hi));
  };
  $("results").onclick = (e) => { const li = e.target.closest("li[data-i]"); if (li) pick(+li.dataset.i); };
  document.querySelectorAll("[data-close]").forEach((b) => b.onclick = closeSearch);
  $("searchModal").onclick = (e) => { if (e.target === $("searchModal")) closeSearch(); };
  addEventListener("keydown", (e) => { if (e.key === "Escape") { closeSearch(); toggleKm(false); } });
  matchMedia(MOB_MQ).addEventListener("change", () => setSheet(!mapOnly() && isMobile()));
  $("footYear").textContent = new Date().getFullYear();
  // lingua: le 12 di SkateboardMap; la scelta si ricorda e la pagina si ricarica (anche ?lang= nell'indirizzo)
  $("langSel").innerHTML = I18N.langs.map((l) => `<option value="${l}"${l === I18N.lang ? " selected" : ""}>${I18N.names[l]}</option>`).join("");
  $("langSel").onchange = () => { I18N.set($("langSel").value); const u = new URL(location.href); u.searchParams.delete("lang"); location.replace(u); };
}

// ---------- account (come SkateboardMap): chi sei, cantine preferite ----------
// a=me&map=1: l'apertura della mappa conta nella dashboard dell'admin (da dove arriva, dispositivo, mappa usata)
async function loadMe(mapKind) {
  try {
    const q = new URLSearchParams({ a: "me", map: "1", m: mapKind || "", ref: document.referrer.slice(0, 300),
      utm: (new URLSearchParams(location.search).get("utm_source") || "").slice(0, 40) });
    try { q.set("tz", Intl.DateTimeFormat().resolvedOptions().timeZone || ""); } catch (e) {}
    const r = await fetch("/api/auth.php?" + q, { credentials: "same-origin" });
    if (!r.ok) return;
    const d = await r.json(); // in locale senza PHP non è JSON: si resta senza account
    state.user = d.user || null;
    const b = $("acctBtn");
    b.classList.toggle("on", !!state.user);
    b.title = b.ariaLabel = t(state.user ? "account" : "login");
    b.querySelector(".ini").textContent = state.user ? (state.user.name || state.user.email || "?").trim().charAt(0).toUpperCase() : "";
    if (state.user) {
      const f = await fetch("/api/fav.php", { credentials: "same-origin" }).then((x) => x.json());
      state.favs = new Set((f.items || []).map((x) => x.id));
      if (state.sel) syncFav(state.sel);
    }
  } catch (e) {}
}
function syncFav(id) {
  const b = $("dFav"); if (!b) return;
  const on = state.favs.has(id);
  b.setAttribute("aria-pressed", String(on)); b.querySelector("span").textContent = t(on ? "saved" : "save");
}
async function toggleFav(x) {
  if (!state.user) { location.href = "/account/?next=" + encodeURIComponent("/mappa/?c=" + x.id); return; }
  const on = !state.favs.has(x.id);
  on ? state.favs.add(x.id) : state.favs.delete(x.id); syncFav(x.id);
  try {
    const r = await fetch("/api/fav.php", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: x.id, on, name: x.n || "", town: x.c || "", lat: x.lat, lng: x.lng }) });
    if (!r.ok) throw new Error(r.status);
    setStatus(t(on ? "fav_saved" : "fav_removed"));
  } catch (e) { on ? state.favs.delete(x.id) : state.favs.add(x.id); syncFav(x.id); setStatus(t("failed")); }
}

// ---------- avvio ----------
async function start() {
  applyScheme();
  bind();
  setSheet(false);
  setRadius(state.radiusKm);
  loadData().catch(() => {});
  // si parte dalla tua posizione (se il permesso c'è già), altrimenti dal mondo: mai dall'ultimo luogo cercato
  store.set("me", null); // le versioni di prova lo ricordavano
  let perm = "prompt";
  try { perm = (await navigator.permissions.query({ name: "geolocation" })).state; } catch (e) {}
  const last = store.get("lastgps"), startAt = perm === "granted" ? last : null;
  const mapKind = await createMap({ dark: dark(), center: startAt ? { lat: startAt.lat, lng: startAt.lng } : { lat: 30, lng: 10 },
    bounds: startAt ? boundsAround(startAt, state.radiusKm * 1000) : WORLD, padding: 12, language: I18N.lang });
  state.mapsReady = true; app.classList.add("mapready");
  loadMe(mapKind);
  M.setBottomPadding(mapPad(), 0);
  M.on("moveend", () => { syncMeBtn(); checkArea(); });
  M.on("movestart", () => clearTimeout(autoTimer));
  M.on("dragstart", () => { state.userMoved = true; toggleKm(false); $("meBtn").setAttribute("aria-pressed", "false"); });
  M.on("pinclick", (id) => pinTip(id));
  M.on("click", () => {
    toggleKm(false);
    if (isMobile() && !mapOnly()) { setSheet(false); return; }
    if (state.sel && $("detail").hidden) { state.sel = null; drawMarkers(); }
  });
  // link di una cantina (?c=n123): si apre lei, con il raggio intorno
  const shared = new URLSearchParams(location.search).get("c");
  if (shared) {
    await loadData().catch(() => {});
    const x = state.byId.get(shared);
    if (x) { state.center = { lat: x.lat, lng: x.lng }; fitToRadius(); drawMe(); await loadArea(); renderList(); openDetail(x.id, false); return; }
  }
  // posizione: se il permesso c'è già si usa subito, altrimenti l'invito "Dove vuoi andare?" (cerca un luogo o usa la posizione)
  if (perm === "granted") { if (!(await applyPosition(await getPosition(false), true))) askWhere(); }
  else askWhere();
}
function askWhere() { $("worldAsk").hidden = false; setStatus(t("where_go")); renderList(); showGlobe(); }
// all'inizio, prima di posizione o ricerca: la Terra in 3D che gira piano (si ferma appena la tocchi); poi la mappa torna piana
let globe = false;
function showGlobe() {
  if (globe || state.center) return;
  globe = true;
  M.setProjection("globe");
  // la Terra intera, non tagliata: diametro del globo ≈ 512·2^zoom/π px, qui l'80% del lato corto della mappa
  const r = $("map").getBoundingClientRect(), side = Math.min(r.width, r.height - (isMobile() ? 140 : 0));
  if (M.setView && side > 0) M.setView({ lat: 25, lng: 12 }, Math.log2(side * 0.8 * Math.PI / 512));
  if (!noMotion()) M.spin(true, 3.6); // un giro in 100 secondi
  const stop = () => M.spin(false);
  ["pointerdown", "wheel", "touchstart"].forEach((ev) => $("map").addEventListener(ev, stop, { passive: true, once: true }));
}
function leaveGlobe() {
  if (!globe) return;
  globe = false; M.spin(false); M.setProjection("mercator");
}

// ---------- quale mappa: Google → Mapbox → OpenFreeMap ----------
// Il contatore del sito (api/counter.php) sceglie la prima con quota gratuita nel mese e la conta.
// Se una non parte (chiave rifiutata, quota finita) si passa alla successiva; se si rompe dopo ("fatal"), si ricarica la pagina senza di lei.
const loaded = {};
function load(url, css) {
  return loaded[url] ||= new Promise((res, rej) => {
    const e = document.createElement(css ? "link" : "script");
    if (css) { e.rel = "stylesheet"; e.href = url; document.head.prepend(e); return res(); }
    e.src = url; e.onload = res; e.onerror = () => { delete loaded[url]; rej(new Error("load " + url)); }; document.head.append(e);
  });
}
const MODULE = { google: "/core/js/map/map-google.js?v=3", gl: "/core/js/map/map-gl.js?v=5" };
async function useModule(m) { if (window.SkateMapKind !== m) { delete loaded[MODULE[m]]; await load(MODULE[m]); window.SkateMapKind = m; } }
async function counter(have, skip) {
  try { const r = await fetch(`/api/counter.php?have=${have.join(",")}&skip=${skip.join(",")}`, { cache: "no-store" }); if (r.ok) return (await r.json()).use; } catch (e) {}
  return "free"; // senza contatore (anche in locale) non si rischia: mappa gratuita
}
async function createMap(o) {
  const have = [MAPS.googleKey && MAPS.googleMapId && "google", MAPS.mapboxToken && "mapbox"].filter(Boolean);
  let skip = []; try { skip = JSON.parse(sessionStorage.getItem("wtm.mapskip")) || []; } catch (e) {}
  for (;;) {
    const use = have.some((p) => !skip.includes(p)) ? await counter(have, skip) : "free";
    $("map").innerHTML = ""; $("map").className = "";
    try {
      if (use === "google") {
        await useModule("google");
        await M.create($("map"), { ...o, key: MAPS.googleKey, mapId: MAPS.googleMapId, mapIdDark: MAPS.googleMapIdDark });
      } else if (use === "mapbox") {
        await Promise.all([load("https://api.mapbox.com/mapbox-gl-js/v3.9.4/mapbox-gl.js"), load("https://api.mapbox.com/mapbox-gl-js/v3.9.4/mapbox-gl.css", true)]);
        await useModule("gl");
        await M.create($("map"), { ...o, lib: "mapbox", token: MAPS.mapboxToken });
      } else {
        await Promise.all([load("https://cdn.jsdelivr.net/npm/maplibre-gl@5.6.0/dist/maplibre-gl.js"), load("https://cdn.jsdelivr.net/npm/maplibre-gl@5.6.0/dist/maplibre-gl.css", true)]);
        await useModule("gl");
        await M.create($("map"), { ...o, lib: "maplibre", style: STYLE, styleLight: STYLE, palette: PALETTE });
      }
    } catch (e) {
      if (use === "free") throw e;
      skip.push(use); continue; // la prossima della lista
    }
    document.documentElement.dataset.map = use;
    M.on("fatal", () => { // chiave revocata o quota finita a mappa già aperta: si riparte senza questa mappa
      try { sessionStorage.setItem("wtm.mapskip", JSON.stringify([...skip, use])); } catch (e) {}
      location.reload();
    });
    return use;
  }
}

// ---------- colori della mappa (stesse chiavi della palette del core) ----------
const STYLE = "https://tiles.openfreemap.org/styles/liberty"; // stessa base per chiaro e scuro: il core la ricolora
const PALETTE = {
  light: { paper: "#fbf7f2", text: "#8f8580", halo: "#fbf7f2", residential: "rgba(214,184,174,0.28)", park: "#eef0e2", parkLine: "#e2e5d2",
    wood: "rgba(206,214,186,0.45)", pitch: "#efe8d8", soft: "#f2ece2", school: "#f4ece6", hospital: "#f5e9e6", aero: "#f1ebe6",
    water: "#c9dde6", river: "#b3cfdc", building: "#eadbd2", border: "#d9b9b0", border3: "#e8d3cb", rail: "#dcc6bc", relief: 0.3,
    motorway: ["#e7b9a6", "#d49a86"], major: ["#f0cdbd", "#dcae9c"], medium: ["#f6e0d5", "#e8c8b8"], minor: ["#ffffff", "#efdcd2"] },
  dark: { paper: "#16110f", text: "#6b5f5a", halo: "#16110f", residential: "rgba(48,36,36,0.8)", park: "#1a1715", parkLine: "#231f1c",
    wood: "rgba(28,24,22,0.9)", pitch: "#211c1a", soft: "#1a1715", school: "#1f1a18", hospital: "#1f1a18", aero: "#1d1816",
    water: "#14222b", river: "#1d3340", building: "#3a2e2c", border: "#5a4643", border3: "#352a28", rail: "#463936", relief: 0,
    motorway: ["#4a2630", "#2b1a1e"], major: ["#3d232a", "#241a1c"], medium: ["#30212a", "#1e1719"], minor: ["#241c1c", "#181213"] }
};

start().catch((e) => { console.error(e); setStatus(t("map_fail")); });
})();
