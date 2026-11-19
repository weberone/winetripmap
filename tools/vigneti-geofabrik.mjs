// Vigneti di OpenStreetMap (landuse=vineyard, solo il centro) dai file per paese di Geofabrik (download.geofabrik.de),
// solo per i paesi con cantine (negli Stati Uniti per stato). Ogni file viene scaricato, filtrato con osmium e cancellato,
// così il disco non si riempie. Scrive tools/.cache/osm/vigneti.json (usato da produttori.mjs).
// Più veloce e sicuro dei server Overpass pubblici (vigneti-osm.mjs, sempre pieni). Gira nel workflow mensile
// (.github/workflows/dati.yml); in locale serve osmium-tool (brew install osmium-tool) e una decina di GB di download.
// Paesi senza file Geofabrik: niente vigneti, e per produttori.mjs "nessun vigneto noto" non toglie punti.
//
// Ogni file finisce in tools/.cache/vigneti/<file>.json (con il paese, o lo stato USA "US.CA"): un file già fatto non si
// rifà, e il lavoro si può dividere in parti parallele (nel workflow 8 macchine, --part 3/8), poi --merge unisce tutto.
// vigneti.json dice anche quali paesi sono coperti (zone): dove mancano i vigneti, produttori.mjs non toglie nessuno per questo.
// Dallo stesso file anche i posti che possono essere cantine non segnate come tali (agriturismi, fattorie…: tools/agri.mjs)
// → tools/.cache/osm/agri.json.
//
// usage: node tools/vigneti-geofabrik.mjs [--only it,fr] [--part k/n] [--merge]
import { readFileSync, writeFileSync, mkdirSync, rmSync, createReadStream, existsSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { createInterface } from 'node:readline';
import { AGRI_FILTER, agriCandidate } from './agri.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const tmp = join(here, '.cache', 'geofabrik');
mkdirSync(tmp, { recursive: true });
const UA = 'winetripmap-build/1.0';
const run = (cmd, args) => { const r = spawnSync(cmd, args, { stdio: ['ignore', 'inherit', 'inherit'] }); return r.status === 0; };

// paesi (e stati USA) con cantine, da tools/data/luoghi.json
const L = JSON.parse(readFileSync(join(here, 'data', 'luoghi.json'), 'utf8'));
const cc = new Map(), us = new Map();
for (const k of Object.values(L.w)) { const c = L.cities[k]; cc.set(c.cc, (cc.get(c.cc) || 0) + 1); if (c.cc === 'US') us.set(c.r, (us.get(c.r) || 0) + 1); }
const arg = (k) => process.argv.find((a, i) => process.argv[i - 1] === '--' + k) || '';
const outDir = join(here, '.cache', 'vigneti');
mkdirSync(outDir, { recursive: true });
const only = (process.argv.find((a, i) => process.argv[i - 1] === '--only') || '').toUpperCase().split(',').filter(Boolean);

const idx = join(tmp, 'index.json');
if (!run('curl', ['-sfL', '-A', UA, '-o', idx, 'https://download.geofabrik.de/index-v1-nogeom.json'])) throw new Error('indice Geofabrik non scaricato');
const G = JSON.parse(readFileSync(idx, 'utf8')).features.map((f) => f.properties);
const files = new Map(); // id → [url, zone] (un file può coprire più paesi: israel-and-palestine…)
const addFile = (p, z) => { const f = files.get(p.id); if (f) f[1].push(z); else files.set(p.id, [p.urls.pbf, [z]]); };
for (const [c] of [...cc].sort((a, b) => b[1] - a[1])) {
  if (only.length && !only.includes(c)) continue;
  if (c === 'US') { // il file degli Stati Uniti intero è enorme: solo gli stati con cantine
    for (const r of us.keys()) { const p = G.find((g) => (g['iso3166-2'] || []).includes('US-' + r.split('.')[1])); if (p) addFile(p, r); }
    continue;
  }
  const p = G.filter((g) => (g['iso3166-1:alpha2'] || []).includes(c)).sort((a, b) => (a['iso3166-1:alpha2'].length - b['iso3166-1:alpha2'].length))[0];
  if (p) addFile(p, c); else console.log('nessun file Geofabrik per', c);
}
const [pk, pn] = (arg('part') || '1/1').split('/').map(Number);
const mine = process.argv.includes('--merge') ? [] : [...files].filter((_, i) => i % pn === pk - 1);
console.log(files.size, 'file da Geofabrik,', mine.length, 'in questa parte');

let i = 0;
for (const [id, [url, zona]] of mine) { // zona: paesi (o stati USA) coperti da questo file
  i++;
  const done = join(outDir, id.replace(/\//g, '_') + '.json');
  if (existsSync(done)) { console.log(`${i}/${mine.length} ${id}: già fatto`); continue; }
  const pbf = join(tmp, 'x.osm.pbf'), vin = join(tmp, 'v.osm.pbf'), gj = join(tmp, 'v.geojsonseq');
  const t0 = Date.now();
  let ok = false;
  for (let t = 0; t < 3 && !ok; t++) ok = run('curl', ['-sfL', '--retry', '3', '-A', UA, '-o', pbf, url]);
  if (!ok) { console.log(id, 'download non riuscito'); continue; }
  ok = run('osmium', ['tags-filter', pbf, 'wr/landuse=vineyard', '-o', vin, '--overwrite', '--no-progress'])
    && run('osmium', ['export', vin, '-f', 'geojsonseq', '-x', 'print_record_separator=false', '--geometry-types=polygon', '-o', gj, '--overwrite', '--no-progress']);
  // dallo stesso file anche agriturismi, ristoranti, fattorie e punti vendita che possono essere cantine (tools/agri.mjs)
  const agri = [], ag = join(tmp, 'a.osm.pbf'), agj = join(tmp, 'a.geojsonseq');
  if (ok && run('osmium', ['tags-filter', pbf, ...AGRI_FILTER, '-o', ag, '--overwrite', '--no-progress'])
    && run('osmium', ['export', ag, '-f', 'geojsonseq', '-x', 'print_record_separator=false', '--geometry-types=point,polygon', '--attributes=type,id', '-o', agj, '--overwrite', '--no-progress'])) {
    const KEEP = /^(name|operator|brand|description(:..)?|produce|product|amenity|tourism|shop|place|landuse|craft|addr:.*|phone|contact:(phone|website)|website|url|opening_hours)$/;
    for await (const line of createInterface({ input: createReadStream(agj) })) {
      if (!line) continue;
      let f; try { f = JSON.parse(line); } catch { continue; }
      const p = f.properties || {}; if (!agriCandidate(p)) continue;
      let s = 90, w = 180, nn = -90, e = -180;
      const walk = (a) => { if (typeof a[0] === 'number') { if (a[1] < s) s = a[1]; if (a[1] > nn) nn = a[1]; if (a[0] < w) w = a[0]; if (a[0] > e) e = a[0]; } else for (const b of a) walk(b); };
      try { walk(f.geometry.coordinates); } catch { continue; }
      if (s > nn) continue;
      const t = {}; for (const [k, v] of Object.entries(p)) if (KEEP.test(k)) t[k] = v;
      const tp = String(p['@type'] || 'node')[0]; // n, w o r: lo stesso id di OpenStreetMap delle cantine
      agri.push([tp + p['@id'], +((s + nn) / 2).toFixed(5), +((w + e) / 2).toFixed(5), t]);
    }
  }
  rmSync(ag, { force: true }); rmSync(agj, { force: true });
  rmSync(pbf, { force: true });
  if (!ok) { console.log(id, 'osmium non riuscito'); continue; }
  const pts = [];
  for await (const line of createInterface({ input: createReadStream(gj) })) {
    if (!line) continue;
    let s = 90, w = 180, nn = -90, e = -180; // centro del rettangolo che contiene il vigneto
    const walk = (a) => { if (typeof a[0] === 'number') { if (a[1] < s) s = a[1]; if (a[1] > nn) nn = a[1]; if (a[0] < w) w = a[0]; if (a[0] > e) e = a[0]; } else for (const b of a) walk(b); };
    try { walk(JSON.parse(line).geometry.coordinates); } catch { continue; }
    if (s > nn) continue;
    pts.push([+((s + nn) / 2).toFixed(5), +((w + e) / 2).toFixed(5)]);
  }
  rmSync(vin, { force: true }); rmSync(gj, { force: true });
  writeFileSync(done, JSON.stringify({ zona, pts, agri }));
  console.log(`${i}/${mine.length} ${id}: ${pts.length} vigneti, ${agri.length} possibili cantine non segnate come tali (${Math.round((Date.now() - t0) / 1000)} s)`);
}
if (pn > 1 && !process.argv.includes('--merge')) process.exit(0); // le parti si uniscono dopo con --merge

// unisce i file fatti; stesso vigneto in due file vicini (paesi confinanti): una volta sola
const seen = new Set(), out = [], zone = new Set(), agri = new Map();
for (const f of readdirSync(outDir)) {
  if (!f.endsWith('.json')) continue;
  const { zona, pts, agri: ag = [] } = JSON.parse(readFileSync(join(outDir, f), 'utf8')); for (const z of zona) zone.add(z);
  for (const a of ag) agri.set(a[0], a);
  for (const [lat, lon] of pts) { const k = lat + ',' + lon; if (!seen.has(k)) { seen.add(k); out.push({ lat, lon }); } }
}
// gli Stati Uniti sono coperti stato per stato (zone "US.CA"): produttori.mjs guarda lo stato della cantina
mkdirSync(join(here, '.cache', 'osm'), { recursive: true });
writeFileSync(join(here, '.cache', 'osm', 'vigneti.json'), JSON.stringify({ zone: [...zone].sort(), elements: out }));
writeFileSync(join(here, '.cache', 'osm', 'agri.json'), JSON.stringify([...agri.values()])); // per tools/agri.mjs apply
const manca = [...files.values()].flatMap(([, z]) => z).filter((z) => !zone.has(z));
console.log('vigneti in tutto:', out.length, 'in', zone.size, 'zone' + (manca.length ? '; mancano: ' + manca.join(' ') : ''));
