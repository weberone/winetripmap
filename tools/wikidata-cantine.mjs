// Cantine di Wikidata (licenza CC0): prova in più per tools/produttori.mjs e cantine che mancano in OpenStreetMap.
//   node tools/wikidata-cantine.mjs      → scarica (SPARQL, gratuito) e scrive tools/data/wikidata.json [[Q, lat, lng, nome, sito, paese], …]
// Abbinamento con OSM (wdMatch): etichetta wikidata= della cantina OSM, oppure entro 300 m, oppure nome simile entro 2 km
// (le coordinate di Wikidata a volte sono quelle del paese). Quelle senza abbinamento vanno sulla mappa con id "q<numero>" (osm-cantine.mjs).
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const file = join(here, 'data', 'wikidata.json');

// cantine (winery e sottoclassi) o aziende del settore vino, con coordinate, non chiuse
const QUERY = `SELECT ?i ?c ?w ?cc ?l WHERE {
  { ?i wdt:P31/wdt:P279* wd:Q156362 } UNION { ?i wdt:P452 wd:Q282 } UNION { ?i wdt:P1056 wd:Q282 }
  ?i wdt:P625 ?c .
  FILTER NOT EXISTS { ?i wdt:P576 [] }
  OPTIONAL { ?i wdt:P856 ?w } OPTIONAL { ?i wdt:P17/wdt:P297 ?cc }
  OPTIONAL { ?i rdfs:label ?l . FILTER(LANG(?l) IN ("mul", "en")) }
}`;

async function download() {
  let r;
  for (let k = 0; k < 5; k++) { // il servizio pubblico a volte risponde 502/504: riprova
    r = await fetch('https://query.wikidata.org/sparql', { method: 'POST', body: new URLSearchParams({ query: QUERY }),
      headers: { Accept: 'application/sparql-results+json', 'User-Agent': 'winetripmap-build/1.0' }, signal: AbortSignal.timeout(300000) }).catch((e) => ({ ok: false, status: e.message }));
    if (r.ok) break; console.log('Wikidata', r.status, 'riprovo'); await new Promise((f) => setTimeout(f, 30000 * (k + 1)));
  }
  if (!r.ok) throw new Error('Wikidata ' + r.status);
  const rows = (await r.json()).results.bindings, out = new Map();
  for (const b of rows) {
    const q = b.i.value.split('/').pop(), m = b.c.value.match(/Point\(([-\d.eE]+) ([-\d.eE]+)\)/); if (!m) continue;
    const name = b.l?.value || ''; if (!name || /^Q\d+$/.test(name)) continue; // senza un nome vero non serve
    if (out.has(q)) { const o = out.get(q); if (!o[4] && b.w) o[4] = b.w.value; if (b.l?.['xml:lang'] === 'mul') o[3] = name; continue; } // più siti o coordinate: il primo
    out.set(q, [q, Math.round(+m[2] * 1e5) / 1e5, Math.round(+m[1] * 1e5) / 1e5, name, b.w?.value || '', b.cc?.value || '']);
  }
  return [...out.values()].sort((a, b) => a[0].localeCompare(b[0]));
}

export const wikidata = () => existsSync(file) ? JSON.parse(readFileSync(file, 'utf8')) : [];

// parole del nome che contano (senza "château", "winery", "weingut"…)
const GEN = /^(ch[aâ]teau|domaine|winery|wines?|weingut|weinbau|bodegas?|vineyards?|estates?|cellars?|cantina|cantine|tenuta|azienda|agricola|vinicola|quinta|clos|vignobles?|the|and|des|del|della|di|de|du|la|le|les|los|las|el|von|zu|und|et|y|e|winzer|kellerei|family|vinos?|vini|vins?|wein)$/i;
const words = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().split(/[^\p{L}\p{N}]+/u).filter((w) => w.length >= 4 && !GEN.test(w));
// els = cantine OSM con etichette (tools/.cache/osm/cantine-tag.json) → { osm: Map(idOSM → Q), extra: [righe di Wikidata senza abbinamento] }
export function wdMatch(els, W = wikidata()) {
  const grid = new Map(), byTag = new Map(), osm = new Map();
  for (const e of els) {
    const lat = e.lat ?? e.center?.lat, lng = e.lon ?? e.center?.lon; if (lat == null) continue;
    const id = e.type[0] + e.id, t = e.tags || {};
    if (/^Q\d+$/.test(t.wikidata || '')) byTag.set(t.wikidata, id);
    const k = Math.floor(lat * 20) + ':' + Math.floor(lng * 20);
    (grid.get(k) || grid.set(k, []).get(k)).push([id, lat, lng, words(t.name || t.brand || '')]);
  }
  const extra = [];
  for (const row of W) {
    const [q, lat, lng, name] = row;
    let id = byTag.get(q);
    if (!id) {
      const a = Math.floor(lat * 20), b = Math.floor(lng * 20), cl = Math.cos(lat * Math.PI / 180), wq = new Set(words(name));
      let best = null, bd = Infinity;
      for (let i = a - 1; i <= a + 1; i++) for (let j = b - 1; j <= b + 1; j++) for (const [oid, y, x, ow] of grid.get(i + ':' + j) || []) {
        const d = Math.hypot((y - lat) * 111.2, (x - lng) * 111.2 * cl);
        const ok = d <= 0.3 || (d <= 2 && ow.some((w) => wq.has(w)));
        if (ok && d < bd) { bd = d; best = oid; }
      }
      id = best;
    }
    if (id) osm.set(id, q); else extra.push(row);
  }
  return { osm, extra };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const W = await download();
  writeFileSync(file, JSON.stringify(W));
  const tf = join(here, '.cache', 'osm', 'cantine-tag.json');
  if (existsSync(tf)) { const { osm, extra } = wdMatch(JSON.parse(readFileSync(tf, 'utf8')).elements, W); console.log(`${W.length} cantine di Wikidata: ${osm.size} già in OSM, ${extra.length} da aggiungere`); }
  else console.log(`${W.length} cantine di Wikidata`);
}
