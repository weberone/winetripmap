// WineTripMap: mappa delle cantine (dati OpenStreetMap in /data/cantine.json, creati da tools/osm-cantine.mjs).
// La mappa è il modulo comune core/js/map/map-gl.js (MapLibre + OpenFreeMap, gratuita): qui solo dati, colori e interfaccia.
(() => {
"use strict";
const M = window.SkateMap; // nome storico dell'interfaccia del core
const $ = (s) => document.querySelector(s);
const status = $("#status");
const darkMq = matchMedia("(prefers-color-scheme: dark)");

// ---------- colori della mappa (stesse chiavi della palette del core) ----------
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
const STYLE = "https://tiles.openfreemap.org/styles/liberty"; // stessa base per chiaro e scuro: il core la ricolora con PALETTE
const ITALY = { west: 6.6, south: 36.6, east: 18.6, north: 47.1 };

// ---------- tipi di luogo ----------
const TYPES = {
  c: { name: "Cantina", light: "#8e1b33", dark: "#c23a55" },
  d: { name: "Cantina con degustazione", light: "#d0506a", dark: "#e8798e" },
  e: { name: "Enoteca", light: "#b8860b", dark: "#d9a93a" }
};
const color = (t) => TYPES[t][darkMq.matches ? "dark" : "light"];
// pin a goccia con un calice; la punta (ax, in basso) è il punto esatto
const pinIcon = (t) => {
  const c = color(t);
  return { w: 28, h: 38, ax: 14, svg: `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="38" viewBox="0 0 28 38">
<path d="M14 37C14 37 2 23.5 2 14a12 12 0 0 1 24 0c0 9.5-12 23-12 23z" fill="${c}" stroke="#fff" stroke-width="2"/>
<path d="M9.5 8h9c0 3.6-1.7 6.3-4.5 6.8V19h2.6v1.6H11.4V19H14v-4.2C11.2 14.3 9.5 11.6 9.5 8z" fill="#fff"/></svg>` };
};
// gruppo di luoghi vicini: cerchio con il numero, più grande se sono tanti
const clusterIcon = (n) => {
  const s = n < 10 ? 34 : n < 100 ? 40 : n < 1000 ? 48 : 56, r = s / 2, c = color("c");
  const label = n < 1000 ? n : Math.round(n / 100) / 10 + "k";
  return { w: s, h: s, svg: `<svg xmlns="http://www.w3.org/2000/svg" width="${s}" height="${s}" viewBox="0 0 ${s} ${s}">
<circle cx="${r}" cy="${r}" r="${r - 1}" fill="${c}" fill-opacity=".28"/><circle cx="${r}" cy="${r}" r="${r - 6}" fill="${c}" stroke="#fff" stroke-width="2"/>
<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="${s < 40 ? 12 : 13}" font-weight="700" fill="#fff">${label}</text></svg>` };
};

// ---------- dati ----------
let all = [], byId = new Map(), selected = null;
const shown = { c: true, d: true, e: true };
const norm = (s) => (s || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const title = (x) => x.n || TYPES[x.t].name + (x.c ? " a " + x.c : "");

function draw() {
  const icons = { c: pinIcon("c"), d: pinIcon("d"), e: pinIcon("e") };
  M.setSpots(all.filter((x) => shown[x.t] || x.id === selected).map((x) => ({ id: x.id, lat: x.lat, lng: x.lng, icon: icons[x.t], selected: x.id === selected })), clusterIcon);
}

// ---------- fumetto ----------
function open(id, fly) {
  const x = byId.get(id); if (!x) return;
  selected = id; draw();
  if (fly) M.flyTo({ lat: x.lat, lng: x.lng }, 15, 80);
  const where = [x.a, x.c].filter(Boolean).join(", ");
  const links = [
    x.w && `<a href="${esc(x.w)}" target="_blank" rel="noopener nofollow">Sito</a>`,
    x.p && `<a href="tel:${esc(x.p.split(/[;,]/)[0].replace(/[^\d+]/g, ""))}">Chiama</a>`,
    `<a href="https://www.google.com/maps/dir/?api=1&destination=${x.lat},${x.lng}" target="_blank" rel="noopener">Indicazioni</a>`,
    `<a href="https://www.openstreetmap.org/${{ n: "node", w: "way", r: "relation" }[x.id[0]]}/${x.id.slice(1)}" target="_blank" rel="noopener" title="Correggi o completa i dati su OpenStreetMap">OSM</a>`
  ].filter(Boolean).join("");
  M.showPopup({ lat: x.lat, lng: x.lng }, `<div class="pop">
<div class="kind"><i class="dot t-${x.t}"></i>${TYPES[x.t].name}</div>
<h3>${esc(title(x))}</h3>${where ? `<p>${esc(where)}</p>` : ""}${x.o ? `<p>Orari: ${esc(x.o)}</p>` : ""}
<div class="links">${links}</div></div>`);
}
function closeSel() { if (!selected) return; selected = null; M.showPopup(null); draw(); }

// ---------- ricerca (nei dati già scaricati: nessun servizio esterno) ----------
const q = $("#q"), results = $("#results");
let hits = [], hi = -1;
function search() {
  const s = norm(q.value.trim());
  if (s.length < 2) { results.hidden = true; return; }
  hits = all.filter((x) => norm(x.n).includes(s) || norm(x.c).includes(s))
    .sort((a, b) => (norm(a.n).startsWith(s) ? 0 : 1) - (norm(b.n).startsWith(s) ? 0 : 1)).slice(0, 12);
  hi = -1;
  results.innerHTML = hits.length ? hits.map((x, i) => `<li data-i="${i}"><i class="dot t-${x.t}"></i>${esc(title(x))}<small>${esc(x.c || "")}</small></li>`).join("")
    : `<li class="none">Nessun risultato</li>`;
  results.hidden = false;
}
function pick(i) { const x = hits[i]; if (!x) return; results.hidden = true; q.value = title(x); q.blur(); open(x.id, true); }
q.addEventListener("input", search);
q.addEventListener("focus", search);
q.addEventListener("keydown", (e) => {
  if (results.hidden) return;
  if (e.key === "ArrowDown" || e.key === "ArrowUp") {
    e.preventDefault(); hi = Math.max(0, Math.min(hits.length - 1, hi + (e.key === "ArrowDown" ? 1 : -1)));
    results.querySelectorAll("li").forEach((li, i) => li.classList.toggle("sel", i === hi));
  } else if (e.key === "Enter") { e.preventDefault(); pick(hi < 0 ? 0 : hi); }
  else if (e.key === "Escape") results.hidden = true;
});
results.addEventListener("click", (e) => { const li = e.target.closest("li[data-i]"); if (li) pick(+li.dataset.i); });
document.addEventListener("click", (e) => { if (!e.target.closest(".search")) results.hidden = true; });

// ---------- filtri ----------
document.querySelectorAll(".chip").forEach((b) => b.addEventListener("click", () => {
  const t = b.dataset.t; shown[t] = !shown[t];
  b.classList.toggle("on", shown[t]); b.setAttribute("aria-pressed", shown[t]);
  draw();
}));
function counts() {
  const n = { c: 0, d: 0, e: 0 }; all.forEach((x) => n[x.t]++);
  document.querySelectorAll(".chip").forEach((b) => { b.querySelector("span").textContent = n[b.dataset.t].toLocaleString("it-IT"); });
}

// ---------- pulsanti ----------
$("#zin").addEventListener("click", () => M.zoomBy(1));
$("#zout").addEventListener("click", () => M.zoomBy(-1));
$("#me").addEventListener("click", () => {
  if (!navigator.geolocation) return;
  status.textContent = "Cerco la tua posizione…";
  navigator.geolocation.getCurrentPosition((p) => {
    status.textContent = "";
    const pos = { lat: p.coords.latitude, lng: p.coords.longitude };
    M.setUser(pos); M.flyTo(pos, 11);
  }, () => { status.textContent = "Posizione non disponibile"; setTimeout(() => { status.textContent = ""; }, 2500); }, { enableHighAccuracy: false, timeout: 10000 });
});

// ---------- avvio ----------
async function start() {
  const dataP = fetch("/data/cantine.json").then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); });
  await M.create($("#map"), { lib: "maplibre", style: STYLE, styleLight: STYLE, dark: darkMq.matches, palette: PALETTE, center: { lat: 42.5, lng: 12.5 }, bounds: ITALY, padding: 20 });
  M.on("pinclick", (id) => open(id, false));
  M.on("click", closeSel);
  darkMq.addEventListener("change", () => { M.setDark(darkMq.matches); draw(); });
  try {
    const d = await dataP;
    all = d.items; byId = new Map(all.map((x) => [x.id, x]));
    counts(); draw();
    status.textContent = "";
  } catch (e) {
    status.textContent = "Non riesco a caricare le cantine, riprova tra poco";
  }
}
start().catch(() => { status.textContent = "La mappa non si è caricata, ricarica la pagina"; });
})();
