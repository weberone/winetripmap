// Scarica da OpenStreetMap (Overpass, gratuito) tutte le cantine del mondo (craft=winery) e le salva per la mappa:
// - site/data/cantine.json: tutti i punti, leggeri → { updated, source, items: [[id, lat, lng, nome, comune], …] }
//   (mappa, radar, ricerca e "Trova le più vicine" lavorano solo su questo file)
// - site/data/det5/<lat÷2,5>_<lng÷2,5>.json: indirizzo, telefono, sito e orari, divisi in riquadri di 2,5° × 2,5° (cartella det5, nome storico),
//   caricati solo quando si apre la scheda di una cantina → { id: { a, p, w, o } }
// usage: node tools/osm-cantine.mjs (prima, se si vuole aggiornare anche Wikidata: node tools/wikidata-cantine.mjs)
// Dati © OpenStreetMap contributors, licenza ODbL: l'attribuzione resta visibile sulla mappa.
import { writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { applyVini } from './vini-applica.mjs';
import { nonCantina } from './non-cantine.mjs';
import { applyDesc } from './descrizioni.mjs';
import { nonProduttore } from './produttori.mjs';
import { wdMatch } from './wikidata-cantine.mjs';

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
export const cell = (lat, lng) => `${Math.floor(lat / 2.5)}_${Math.floor(lng / 2.5)}`; // stesso calcolo in site/js/app.js

const els = await download();
// tutte le cantine con le etichette, anche quelle che non vanno sul sito: servono a tools/produttori.mjs
const osmCache = join(dirname(fileURLToPath(import.meta.url)), '.cache', 'osm');
mkdirSync(osmCache, { recursive: true });
writeFileSync(join(osmCache, 'cantine-tag.json'), JSON.stringify({ elements: els }));
const seen = new Set(), items = [], det = {};
let tolte = 0;
for (const e of els) {
  const t = e.tags || {};
  const lat = e.lat ?? e.center?.lat, lng = e.lon ?? e.center?.lon;
  if (lat == null || lng == null) continue;
  const id = e.type[0] + e.id;
  if (seen.has(id)) continue; seen.add(id);
  const name = t.name || t['name:it'] || t['name:en'] || t.brand || '';
  if (nonCantina(id, name)) { tolte++; continue; } // enoteche, wine bar, negozi: vedi tools/non-cantine.mjs
  if (nonProduttore(id)) { tolte++; continue; } // locali, negozi, distributori senza prove da produttore: tools/produttori.mjs
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
// cantine di Wikidata che mancano in OpenStreetMap (tools/wikidata-cantine.mjs): id "q<numero>", solo nome e sito
let wd = 0; const wdSeen = new Set();
for (const [q, lat, lng, name, w] of wdMatch(els).extra) {
  const id = q.toLowerCase(), k = lat.toFixed(3) + ',' + lng.toFixed(3);
  if (wdSeen.has(k) || nonCantina(id, name) || nonProduttore(id)) continue; wdSeen.add(k);
  items.push([id, lat, lng, name, '']); wd++;
  if (w) (det[cell(lat, lng)] ||= {})[id] = { w };
}
console.log(`${wd} cantine da Wikidata che mancano in OpenStreetMap`);
items.sort((a, b) => a[0].localeCompare(b[0])); // ordine stabile: diff piccoli quando si aggiornano i dati
mkdirSync(dataDir, { recursive: true });
writeFileSync(join(dataDir, 'cantine.json'), JSON.stringify({ updated: new Date().toISOString().slice(0, 10), source: '© OpenStreetMap contributors (ODbL); Wikidata (CC0)', items }));
rmSync(join(dataDir, 'det5'), { recursive: true, force: true });
mkdirSync(join(dataDir, 'det5'));
for (const [k, v] of Object.entries(det)) writeFileSync(join(dataDir, 'det5', k + '.json'), JSON.stringify(v));
applyVini(dataDir); // i vini letti dai siti (tools/data/vini.json) restano dopo ogni nuovo scarico
applyDesc(); // e le descrizioni (tools/data/descrizioni.json)
console.log(`${items.length} cantine nel mondo (${tolte} enoteche e locali tolti), dettagli in ${Object.keys(det).length} riquadri → ${dataDir}`);
