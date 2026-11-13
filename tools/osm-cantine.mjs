// Scarica da OpenStreetMap (Overpass, gratuito) tutte le cantine del mondo (craft=winery) e le salva per la mappa:
// - site/data/cantine.json: tutti i punti, leggeri → { updated, source, items: [[id, lat, lng, nome, comune], …] }
//   (mappa, radar, ricerca e "Trova le più vicine" lavorano solo su questo file)
// - site/data/det/<lat10>_<lng10>.json: indirizzo, telefono, sito e orari, divisi in riquadri di 10° × 10°,
//   caricati solo quando si apre la scheda di una cantina → { id: { a, p, w, o } }
// usage: node tools/osm-cantine.mjs
// Dati © OpenStreetMap contributors, licenza ODbL: l'attribuzione resta visibile sulla mappa.
import { writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const dataDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'site', 'data');
const SERVERS = [
  'https://overpass-api.de/api/interpreter',
  'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
  'https://overpass.private.coffee/api/interpreter',
  'https://overpass.kumi.systems/api/interpreter',
];
const query = `[out:json][timeout:600];nwr["craft"="winery"];out center tags;`;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function download() {
  // i server pubblici sono spesso pieni: ogni tentativo dura al massimo 10 minuti, poi pause sempre più lunghe (fino a 10 minuti)
  let wait = 60000;
  for (let round = 0; round < 8; round++) {
    for (const url of SERVERS) {
      try {
        const r = await fetch(url, { method: 'POST', body: new URLSearchParams({ data: query }), headers: { 'User-Agent': 'winetripmap-build/1.0' }, signal: AbortSignal.timeout(600000) });
        const txt = await r.text();
        if (r.ok && txt.startsWith('{')) { console.log('dati da', url); return JSON.parse(txt).elements; }
        console.log('occupato:', url, r.status);
      } catch (e) { console.log('errore:', url, e.message); }
    }
    console.log(`nuovo giro tra ${wait / 1000} s`);
    await sleep(wait); wait = Math.min(wait * 2, 600000);
  }
  throw new Error('nessun server Overpass disponibile, riprova più tardi');
}

const web = (t) => { const w = t.website || t['contact:website'] || t.url; if (!w) return undefined; return /^https?:\/\//i.test(w) ? w : 'https://' + w; };
const round = (x) => Math.round(x * 1e5) / 1e5; // ~1 m, file più leggero
export const cell = (lat, lng) => `${Math.floor(lat / 10)}_${Math.floor(lng / 10)}`; // stesso calcolo in site/js/app.js

const els = await download();
const seen = new Set(), items = [], det = {};
for (const e of els) {
  const t = e.tags || {};
  const lat = e.lat ?? e.center?.lat, lng = e.lon ?? e.center?.lon;
  if (lat == null || lng == null) continue;
  const id = e.type[0] + e.id;
  if (seen.has(id)) continue; seen.add(id);
  const name = t.name || t['name:it'] || t['name:en'] || t.brand || '';
  const town = t['addr:city'] || t['addr:town'] || t['addr:village'] || t['addr:place'] || '';
  items.push([id, round(lat), round(lng), name, town]);
  const d = {
    a: [t['addr:street'], t['addr:housenumber']].filter(Boolean).join(' ') || undefined,
    p: t.phone || t['contact:phone'] || undefined,
    w: web(t),
    o: t.opening_hours || undefined,
  };
  if (d.a || d.p || d.w || d.o) (det[cell(lat, lng)] ||= {})[id] = d;
}
items.sort((a, b) => a[0].localeCompare(b[0])); // ordine stabile: diff piccoli quando si aggiornano i dati
mkdirSync(dataDir, { recursive: true });
writeFileSync(join(dataDir, 'cantine.json'), JSON.stringify({ updated: new Date().toISOString().slice(0, 10), source: '© OpenStreetMap contributors (ODbL)', items }));
rmSync(join(dataDir, 'det'), { recursive: true, force: true });
mkdirSync(join(dataDir, 'det'));
for (const [k, v] of Object.entries(det)) writeFileSync(join(dataDir, 'det', k + '.json'), JSON.stringify(v));
console.log(`${items.length} cantine nel mondo, dettagli in ${Object.keys(det).length} riquadri → ${dataDir}`);
