// Scarica da OpenStreetMap (Overpass, gratuito) cantine, cantine con degustazione ed enoteche di un paese
// e le salva compatte in site/data/cantine.json, il file che legge la mappa.
// usage: node tools/osm-cantine.mjs [codice paese ISO, default IT]
// Dati © OpenStreetMap contributors, licenza ODbL: l'attribuzione resta visibile sulla mappa.
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const country = (process.argv[2] || 'IT').toUpperCase();
const out = join(dirname(fileURLToPath(import.meta.url)), '..', 'site', 'data', 'cantine.json');
const SERVERS = [
  'https://overpass-api.de/api/interpreter',
  'https://overpass.private.coffee/api/interpreter',
  'https://overpass.kumi.systems/api/interpreter',
  'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
];
const query = `[out:json][timeout:300];
area["ISO3166-1"="${country}"][admin_level=2]->.a;
(nwr["craft"="winery"](area.a);nwr["tourism"="wine_cellar"](area.a);nwr["shop"="wine"](area.a););
out center tags;`;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function download() {
  // i server pubblici sono spesso pieni: ogni tentativo dura al massimo 4 minuti, poi pause sempre più lunghe (fino a 10 minuti, circa un'ora in tutto)
  let wait = 60000;
  for (let round = 0; round < 8; round++) {
    for (const url of SERVERS) {
      try {
        const r = await fetch(url, { method: 'POST', body: new URLSearchParams({ data: query }), headers: { 'User-Agent': 'winetripmap-build/1.0' }, signal: AbortSignal.timeout(240000) });
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

// tipo: c = cantina (craft=winery), d = cantina con degustazione/vendita (tourism=wine_cellar), e = enoteca (shop=wine)
const type = (t) => t.craft === 'winery' ? 'c' : t.tourism === 'wine_cellar' ? 'd' : 'e';
const web = (t) => { const w = t.website || t['contact:website'] || t.url; if (!w) return undefined; return /^https?:\/\//i.test(w) ? w : 'https://' + w; };
const round = (x) => Math.round(x * 1e5) / 1e5; // ~1 m, file più leggero

const els = await download();
const seen = new Set();
const list = [];
for (const e of els) {
  const t = e.tags || {};
  const lat = e.lat ?? e.center?.lat, lng = e.lon ?? e.center?.lon;
  if (lat == null || lng == null) continue;
  const id = e.type[0] + e.id;
  if (seen.has(id)) continue; seen.add(id);
  const row = {
    id, t: type(t), lat: round(lat), lng: round(lng),
    n: t.name || t.brand || undefined,
    w: web(t),
    p: t.phone || t['contact:phone'] || undefined,
    o: t.opening_hours || undefined,
    c: t['addr:city'] || t['addr:town'] || t['addr:village'] || undefined,
    a: [t['addr:street'], t['addr:housenumber']].filter(Boolean).join(' ') || undefined,
  };
  list.push(row);
}
list.sort((a, b) => a.id.localeCompare(b.id)); // ordine stabile: diff piccoli quando si aggiornano i dati
mkdirSync(dirname(out), { recursive: true });
writeFileSync(out, JSON.stringify({ country, updated: new Date().toISOString().slice(0, 10), source: '© OpenStreetMap contributors (ODbL)', items: list }));
const n = (k) => list.filter((x) => x.t === k).length;
console.log(`${list.length} luoghi: ${n('c')} cantine, ${n('d')} cantine con degustazione, ${n('e')} enoteche → ${out}`);
