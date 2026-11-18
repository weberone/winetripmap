// In che comune, regione e stato è ogni cantina, per le pagine SEO (stati → regioni → comuni → cantine).
// Dati GeoNames (CC-BY 4.0, download.geonames.org/export/dump: cities500.zip, admin1CodesASCII.txt, countryInfo.txt),
// scaricati in tools/.cache/geonames/ (non nel repository). Tutto sul Mac, nessuna richiesta a servizi esterni.
//
// Come: il luogo abitato più vicino alla cantina (entro 25 km), poi il capoluogo del suo comune (stesso codice amministrativo
// più fine: admin4 in Francia e Germania, admin3 in Italia…), così una frazione finisce sotto il suo comune.
// Il nome del comune: quello di GeoNames, ma se le cantine in OpenStreetMap usano un nome più corto contenuto in quello
// ("Neive" per "Neive-Borgonovo") si usa quello.
//
// usage: node tools/luoghi.mjs   → tools/data/luoghi.json
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const gn = join(here, '.cache', 'geonames');
const out = join(here, 'data', 'luoghi.json');
// apostrofo: d'Iseo, Sant'Antonio, L'Aquila → trattino (provaglio-d-iseo); in inglese Mary's → marys. Stessa regola in site/seo/common.php (slugify)
export const slug = (s) => String(s).normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/(^|[^a-z])(d|l|dell|dall|nell|sull|all|coll|sant|quell|bell|un|j|qu|c|n|m|t|s)['’`](?=[a-z])/g, '$1$2-').replace(/['’`]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'x';

// ---------- GeoNames ----------
const countries = {};
for (const l of readFileSync(join(gn, 'countryInfo.txt'), 'utf8').split('\n')) {
  if (!l || l[0] === '#') continue; const f = l.split('\t'); countries[f[0]] = f[4];
}
const admin1 = {}, admin1g = {};
for (const l of readFileSync(join(gn, 'admin1CodesASCII.txt'), 'utf8').split('\n')) { const f = l.split('\t'); if (f[1]) { admin1[f[0]] = f[1]; admin1g[f[0]] = +f[3]; } }
const places = []; // [id, name, lat, lng, fcode, cc, a1, a2, a3, a4, pop]
for (const l of readFileSync(join(gn, 'cities500.txt'), 'utf8').split('\n')) {
  const f = l.split('\t'); if (f.length < 15) continue;
  places.push({ id: +f[0], n: f[1], lat: +f[4], lng: +f[5], fc: f[7], cc: f[8], a1: f[10], a2: f[11], a3: f[12], a4: f[13], pop: +f[14] });
}
// griglia di 0,25° per il più vicino
const G = 0.25, grid = new Map();
for (const p of places) { const k = Math.floor(p.lat / G) + ':' + Math.floor(p.lng / G); (grid.get(k) || grid.set(k, []).get(k)).push(p); }
const km = (a, b, c, d) => { const r = Math.PI / 180, x = (d - b) * r * Math.cos((a + c) / 2 * r), y = (c - a) * r; return Math.sqrt(x * x + y * y) * 6371; };
function nearest(lat, lng) {
  let best = null, bd = 25;
  const gy = Math.floor(lat / G), gx = Math.floor(lng / G);
  for (let i = -1; i <= 1; i++) for (let j = -1; j <= 1; j++) for (const p of grid.get((gy + i) + ':' + (gx + j)) || []) {
    const d = km(lat, lng, p.lat, p.lng); if (d < bd) { bd = d; best = p; }
  }
  return best;
}
// per nome: il comune scritto in OpenStreetMap (addr:city) vale più del paese più vicino, se esiste lì vicino
const norm = (s) => slug(s).replace(/-/g, '');
const byName = new Map();
for (const p of places) for (const nm of [p.n]) { const k = norm(nm); (byName.get(k) || byName.set(k, []).get(k)).push(p); }
function named(town, lat, lng) {
  let best = null, bd = 12;
  for (const p of byName.get(norm(town)) || []) { const d = km(lat, lng, p.lat, p.lng); if (d < bd) { bd = d; best = p; } }
  return best;
}
// capoluoghi dei comuni: per ogni codice amministrativo (stato.a1.a2.a3.a4 fino al più fine presente), il luogo PPLA*/PPLC più popoloso
const seatKey = (p) => { const c = [p.cc, p.a1, p.a2, p.a3, p.a4]; while (c.length > 2 && !c[c.length - 1]) c.pop(); return c.join('.'); };
const seats = new Map();
for (const p of places) {
  if (!/^PPL(A\d?|C)$/.test(p.fc)) continue;
  const k = seatKey(p), o = seats.get(k);
  // il capoluogo del comune ha il codice amministrativo del suo livello: un PPLA3 per un codice a3, ecc.
  if (!o || p.pop > o.pop) seats.set(k, p);
}

// ---------- cantine ----------
const cantine = JSON.parse(readFileSync(join(here, '..', 'site', 'data', 'cantine.json'), 'utf8')).items;
const res = { countries: {}, regions: {}, cities: {}, w: {} };
const osmNames = new Map(); // comune → nomi usati in OSM dalle sue cantine
let none = 0;
for (const [id, lat, lng, , town] of cantine) {
  const p = (town && named(town, lat, lng)) || nearest(lat, lng);
  if (!p) { none++; continue; }
  // il capoluogo vale solo se GeoNames ha il livello del comune (a3/a4: Italia, Francia, Germania, Spagna…) ed è vicino;
  // dove il livello più fine è la contea (Stati Uniti, Ungheria…) resta il paese più vicino
  const s0 = (p.a3 || p.a4) ? seats.get(seatKey(p)) : null;
  const seat = s0 && km(lat, lng, s0.lat, s0.lng) < 15 ? s0 : p;
  const ck = seat.id;
  if (!res.cities[ck]) {
    const rk = seat.cc + '.' + seat.a1;
    res.cities[ck] = { n: seat.n, cc: seat.cc, r: rk, lat: +seat.lat.toFixed(4), lng: +seat.lng.toFixed(4) };
    res.regions[rk] ||= { n: admin1[rk] || seat.a1 || seat.cc, cc: seat.cc, g: admin1g[rk] || 0 };
    res.countries[seat.cc] ||= { n: countries[seat.cc] || seat.cc };
  }
  res.w[id] = ck;
  if (town) { const m = osmNames.get(ck) || new Map(); m.set(town, (m.get(town) || 0) + 1); osmNames.set(ck, m); }
}
// nome più corto da OpenStreetMap quando è contenuto in quello di GeoNames ("Neive" in "Neive-Borgonovo")
for (const [ck, m] of osmNames) {
  const c = res.cities[ck], gnN = c.n.toLowerCase();
  const best = [...m].filter(([t]) => t.length >= 3 && t.length < c.n.length && gnN.startsWith(t.toLowerCase())).sort((a, b) => b[1] - a[1])[0];
  if (best) c.n = best[0];
}
// indirizzi (slug) unici dentro il genitore: comuni con lo stesso nome nella stessa regione prendono un numero
const used = new Set();
for (const [k, c] of Object.entries(res.countries)) { c.s = slug(c.n); }
for (const [k, r] of Object.entries(res.regions)) { let s = slug(r.n), i = 2; while (used.has(r.cc + '/' + s)) s = slug(r.n) + '-' + i++; used.add(r.cc + '/' + s); r.s = s; }
for (const [k, c] of Object.entries(res.cities)) { let s = slug(c.n), i = 2; while (used.has(c.r + '/' + s)) s = slug(c.n) + '-' + i++; used.add(c.r + '/' + s); c.s = s; }
mkdirSync(dirname(out), { recursive: true });
writeFileSync(out, JSON.stringify(res));
console.log(`${Object.keys(res.w).length} cantine in ${Object.keys(res.cities).length} comuni, ${Object.keys(res.regions).length} regioni, ${Object.keys(res.countries).length} stati (${none} senza luogo entro 25 km) → ${out}`);
