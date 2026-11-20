// Cantine di Overture Maps (tools/overture-cantine.py → tools/.cache/overture/places.json.gz) unite a quelle di OpenStreetMap
// e Wikidata. Richiesta dell'utente 2026-11-20: mancavano produttori importanti (Anselmi, Pieropan, Inama a Soave: OSM ne aveva
// 36 nella zona, Overture 228).
// - ovMatch(base): per ogni cantina già nostra [id, lat, lng, nome] il luogo Overture che è lei (stesso nome entro 1,5 km, stesso
//   sito entro 3 km, o qualunque nome entro 60 m) e le cantine Overture ("winery") che non abbiamo, senza doppioni tra loro.
//   I luoghi Overture che sono enoteche, wine bar, negozi di alcolici o ingrossi dicono a produttori.mjs che la nostra "cantina"
//   è probabilmente un negozio.
// - node tools/overture.mjs apply → aggiunge le cantine mancanti a site/data/cantine.json e det5/ (id "o" + 12 cifre esadecimali
//   dell'id Overture) e completa sito, telefono e indirizzo di quelle che già ci sono. Nel workflow mensile gira dopo
//   osm-cantine.mjs; chi non produce vino esce poi con produttori.mjs (build + apply), come le altre.
// Dati: Overture Maps Foundation; licenze CDLA Permissive 2.0, Apache 2.0 (Foursquare), CC0: attribuzione nella scheda e nella privacy.
import { readFileSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { gunzipSync } from 'node:zlib';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const file = join(here, '.cache', 'overture', 'places.json.gz');
const cell = (lat, lng) => `${Math.floor(lat / 2.5)}_${Math.floor(lng / 2.5)}`; // come osm-cantine.mjs e app.js

export function overture() {
  if (overture.d !== undefined) return overture.d;
  return overture.d = existsSync(file) ? JSON.parse(gunzipSync(readFileSync(file)).toString('utf8')) : null;
}
export const ovId = (gers) => 'o' + gers.slice(0, 12).toLowerCase();

// nome ridotto alle parole che distinguono: senza forme societarie e parole generiche del vino
const GENERIC = new Set(('azienda agricola agricole agr az societa soc srl srls snc sas spa ss sa gmbh kg ltd llc inc co sarl scea earl gaec ' +
  'vinicola vitivinicola cantina cantine cantinas winery wineries wines wine vini vino vin vins winzer weingut weinbau weine domaine domaines ' +
  'chateau bodega bodegas tenuta tenute estate estates vineyard vineyards vigneti cellars cellar quinta cave caves the di del della dei degli ' +
  'da la il lo le i gli e and et und & de des du y el los las of von vom zum zur am au aux').split(' '));
export function words(name) {
  return new Set((name || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ')
    .split(' ').filter((w) => w.length > 1 && !GENERIC.has(w)));
}
export function sameName(a, b) {
  const A = words(a), B = words(b);
  if (!A.size || !B.size) return false;
  let k = 0; for (const w of A) if (B.has(w)) k++;
  return k / Math.min(A.size, B.size) >= 0.99 || k / Math.max(A.size, B.size) >= 0.5;
}
export const host = (u) => { try { return new URL(/^https?:/i.test(u) ? u : 'https://' + u).hostname.replace(/^www\./, '').toLowerCase(); } catch { return ''; } };
const km = (a, b, c, d) => Math.hypot((a - c) * 111.2, (b - d) * 111.2 * Math.cos(a * Math.PI / 180));

function grid(list, f) { // indice a celle di ~2 km
  const g = new Map();
  for (const x of list) { const [lat, lng] = f(x); const k = Math.floor(lat * 50) + ':' + Math.floor(lng * 50); (g.get(k) || g.set(k, []).get(k)).push(x); }
  return (lat, lng, r = 1) => { const a = Math.floor(lat * 50), b = Math.floor(lng * 50), out = []; for (let i = a - r; i <= a + r; i++) for (let j = b - r * 2; j <= b + r * 2; j++) out.push(...(g.get(i + ':' + j) || [])); return out; };
}

// base: [[id, lat, lng, nome, sito?], …] → { match: Map(id → luogo Overture), extra: [luoghi "winery" che mancano] }
// luogo Overture: [gers, lat, lng, nome, comune, cc, sito, tel, indirizzo, conf, cat, fonti]
export function ovMatch(base) {
  const O = overture(); if (!O) return { match: new Map(), extra: [], cand: [] };
  const near = grid(O.items, (o) => [o[1], o[2]]);
  const match = new Map(), used = new Set();
  for (const [id, lat, lng, name, web] of base) {
    if (lat == null) continue;
    let best = null, bs = 0;
    for (const o of near(lat, lng)) {
      const d = km(lat, lng, o[1], o[2]), sn = sameName(name, o[3]), sw = web && o[6] && host(web) === host(o[6]);
      const s = sn && d <= 1.5 ? 3 - d : sw && d <= 3 ? 2.5 - d / 3 : d <= 0.06 ? 1 : 0;
      if (s > bs) { bs = s; best = o; }
    }
    if (best) { match.set(id, best); if ('wva'.includes(best[10])) used.add(best[0]); }
  }
  // le cantine Overture che restano, senza doppioni tra loro (stesso nome o stesso sito a meno di 3 km: si tiene la più sicura)
  const rest = O.items.filter((o) => o[10] === 'w' && !used.has(o[0]) && o[9] >= 0.5).sort((a, b) => b[9] - a[9]);
  const kept = [], g = new Map();
  for (const o of rest) {
    const a = Math.floor(o[1] * 50), b = Math.floor(o[2] * 50); let dup = false;
    for (let i = a - 2; i <= a + 2 && !dup; i++) for (let j = b - 4; j <= b + 4 && !dup; j++) for (const k of g.get(i + ':' + j) || []) {
      const d = km(o[1], o[2], k[1], k[2]);
      if ((d <= 3 && sameName(o[3], k[3])) || (d <= 3 && o[6] && k[6] && host(o[6]) === host(k[6])) || d <= 0.02) { dup = true; break; }
    }
    if (dup) continue;
    kept.push(o); (g.get(a + ':' + b) || g.set(a + ':' + b, []).get(a + ':' + b)).push(o);
  }
  // alloggi, agriturismi e ristoranti delle zone del vino (cat "a") che non sono già nostri: candidati, entrano solo se il loro sito
  // dice che producono vino (produttori.mjs); senza doppioni con le cantine Overture già prese
  const cand = [];
  for (const o of O.items) {
    if (o[10] !== 'a' || used.has(o[0])) continue;
    const a = Math.floor(o[1] * 50), b = Math.floor(o[2] * 50); let dup = false;
    for (let i = a - 2; i <= a + 2 && !dup; i++) for (let j = b - 4; j <= b + 4 && !dup; j++) for (const k of g.get(i + ':' + j) || [])
      if (km(o[1], o[2], k[1], k[2]) <= 1.5 && (sameName(o[3], k[3]) || (o[6] && k[6] && host(o[6]) === host(k[6])))) { dup = true; break; }
    if (!dup) cand.push(o);
  }
  return { match, extra: kept, cand };
}

// "MONTEFORTE D'ALPONE" → "Monteforte D'Alpone" (Overture a volte ha i comuni in maiuscolo; "Soave" e "SOAVE" erano due comuni)
const townCase = (t) => t && t === t.toUpperCase() && /\p{Lu}/u.test(t) ? t.toLowerCase().replace(/(^|[\s\-'’(])(\p{L})/gu, (m, a, c) => a + c.toUpperCase()) : t;
const web = (u) => !u ? undefined : /^https?:\/\//i.test(u) ? u : 'https://' + u;

async function apply() {
  const O = overture(); if (!O) return console.log('overture: manca tools/.cache/overture/places.json.gz (python3 tools/overture-cantine.py)');
  // come nonProduttore() di produttori.mjs (che importa questo file: niente import circolare)
  const rd = (f) => existsSync(join(here, 'data', f)) ? JSON.parse(readFileSync(join(here, 'data', f), 'utf8')) : {};
  const P = rd('produttori.json'), R = rd('revisioni.json');
  const nonProduttore = (id) => R[id] === 't' || (R[id] !== 'k' && id in P && P[id] <= -2);
  const dataDir = join(here, '..', 'site', 'data'), cf = join(dataDir, 'cantine.json'), C = JSON.parse(readFileSync(cf, 'utf8'));
  C.items = C.items.filter(([id]) => id[0] !== 'o'); // si rifanno da capo
  const dets = new Map(); const det = (k) => dets.get(k) || dets.set(k, existsSync(join(dataDir, 'det5', k + '.json')) ? JSON.parse(readFileSync(join(dataDir, 'det5', k + '.json'), 'utf8')) : {}).get(k);
  for (const f of readdirSync(join(dataDir, 'det5'))) { const v = det(f.replace(/\.json$/, '')); for (const id of Object.keys(v)) if (id[0] === 'o') delete v[id]; }
  const base = C.items.map(([id, lat, lng, name]) => [id, lat, lng, name, det(cell(lat, lng))[id]?.w]);
  const { match, extra, cand } = ovMatch(base);
  // completa i dettagli delle cantine che già abbiamo (solo dove mancano), se Overture le conosce come cantine
  let compl = 0;
  for (const [id, lat, lng] of C.items) {
    const o = match.get(id); if (!o || o[10] !== 'w') continue;
    const D = det(cell(lat, lng)), d = D[id] || {}, n = { ...d };
    if (!n.w && o[6]) n.w = web(o[6]); if (!n.p && o[7]) n.p = o[7]; if (!n.a && o[8]) n.a = o[8];
    if (JSON.stringify(n) !== JSON.stringify(d)) { D[id] = n; compl++; }
  }
  let add = 0; const per = {};
  for (const o of extra) {
    const id = ovId(o[0]); if (nonProduttore(id)) continue;
    C.items.push([id, o[1], o[2], o[3], townCase(o[4])]); add++; per[o[5]] = (per[o[5]] || 0) + 1;
    const d = { a: o[8] || undefined, p: o[7] || undefined, w: web(o[6]) };
    if (d.a || d.p || d.w) det(cell(o[1], o[2]))[id] = d;
  }
  // relais, agriturismi, ristoranti che producono vino: solo quelli che produttori.mjs ha confermato dal loro sito (punteggio > 0)
  let conf = 0;
  for (const o of cand) {
    const id = ovId(o[0]); if (!(P[id] > 0) || R[id] === 't') continue;
    C.items.push([id, o[1], o[2], o[3], townCase(o[4])]); conf++;
    const d = { a: o[8] || undefined, p: o[7] || undefined, w: web(o[6]) };
    if (d.a || d.p || d.w) det(cell(o[1], o[2]))[id] = d;
  }
  if (cand.length) console.log(`overture: ${conf} relais/agriturismi/ristoranti che producono vino (su ${cand.length} candidati nelle zone del vino)`);
  C.items.sort((a, b) => a[0].localeCompare(b[0]));
  C.source = '© OpenStreetMap contributors (ODbL); Wikidata (CC0); Overture Maps Foundation (CDLA Permissive 2.0, Apache 2.0, CC0)';
  writeFileSync(cf, JSON.stringify(C));
  for (const [k, v] of dets) writeFileSync(join(dataDir, 'det5', k + '.json'), JSON.stringify(v));
  // le cantine Overture rifatte da capo: si rimettono vini e descrizioni letti dai siti
  const { applyVini } = await import('./vini-applica.mjs'); applyVini(dataDir);
  const { applyDesc } = await import('./descrizioni.mjs'); applyDesc();
  console.log(`overture (${O.release}): ${add} cantine nuove, ${compl} completate (sito, telefono, indirizzo); ${C.items.length} in tutto`);
  console.log('nuove per paese:', Object.entries(per).sort((a, b) => b[1] - a[1]).slice(0, 20).map((x) => x.join(' ')).join(', '));
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const cmd = process.argv[2];
  if (cmd === 'apply') await apply();
  else console.log('usage: node tools/overture.mjs apply');
}
