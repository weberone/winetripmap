// Vigneti di OpenStreetMap (landuse=vineyard, solo il centro) dai file per paese di Geofabrik (download.geofabrik.de),
// solo per i paesi con cantine (negli Stati Uniti per stato). Ogni file viene scaricato, filtrato con osmium e cancellato,
// così il disco non si riempie. Scrive tools/.cache/osm/vigneti.json (usato da produttori.mjs).
// Più veloce e sicuro dei server Overpass pubblici (vigneti-osm.mjs, sempre pieni). Gira nel workflow mensile
// (.github/workflows/dati.yml); in locale serve osmium-tool (brew install osmium-tool) e una decina di GB di download.
// Paesi senza file Geofabrik: niente vigneti, e per produttori.mjs "nessun vigneto noto" non toglie punti.
//
// usage: node tools/vigneti-geofabrik.mjs [--only it,fr]
import { readFileSync, writeFileSync, mkdirSync, rmSync, createReadStream, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { createInterface } from 'node:readline';

const here = dirname(fileURLToPath(import.meta.url));
const tmp = join(here, '.cache', 'geofabrik');
mkdirSync(tmp, { recursive: true });
const UA = 'winetripmap-build/1.0';
const run = (cmd, args) => { const r = spawnSync(cmd, args, { stdio: ['ignore', 'inherit', 'inherit'] }); return r.status === 0; };

// paesi (e stati USA) con cantine, da tools/data/luoghi.json
const L = JSON.parse(readFileSync(join(here, 'data', 'luoghi.json'), 'utf8'));
const cc = new Map(), us = new Map();
for (const k of Object.values(L.w)) { const c = L.cities[k]; cc.set(c.cc, (cc.get(c.cc) || 0) + 1); if (c.cc === 'US') us.set(c.r, (us.get(c.r) || 0) + 1); }
const only = (process.argv.find((a, i) => process.argv[i - 1] === '--only') || '').toUpperCase().split(',').filter(Boolean);

const idx = join(tmp, 'index.json');
if (!run('curl', ['-sfL', '-A', UA, '-o', idx, 'https://download.geofabrik.de/index-v1-nogeom.json'])) throw new Error('indice Geofabrik non scaricato');
const G = JSON.parse(readFileSync(idx, 'utf8')).features.map((f) => f.properties);
const files = new Map(); // id → url
for (const [c] of [...cc].sort((a, b) => b[1] - a[1])) {
  if (only.length && !only.includes(c)) continue;
  if (c === 'US') { // il file degli Stati Uniti intero è enorme: solo gli stati con cantine
    for (const r of us.keys()) { const p = G.find((g) => (g['iso3166-2'] || []).includes('US-' + r.split('.')[1])); if (p) files.set(p.id, p.urls.pbf); }
    continue;
  }
  const p = G.filter((g) => (g['iso3166-1:alpha2'] || []).includes(c)).sort((a, b) => (a['iso3166-1:alpha2'].length - b['iso3166-1:alpha2'].length))[0];
  if (p) files.set(p.id, p.urls.pbf); else console.log('nessun file Geofabrik per', c);
}
console.log(files.size, 'file da Geofabrik');

const all = [];
let i = 0;
for (const [id, url] of files) {
  i++;
  const pbf = join(tmp, 'x.osm.pbf'), vin = join(tmp, 'v.osm.pbf'), gj = join(tmp, 'v.geojsonseq');
  const t0 = Date.now();
  let ok = false;
  for (let t = 0; t < 3 && !ok; t++) ok = run('curl', ['-sfL', '--retry', '3', '-A', UA, '-o', pbf, url]);
  if (!ok) { console.log(id, 'download non riuscito'); continue; }
  ok = run('osmium', ['tags-filter', pbf, 'wr/landuse=vineyard', '-o', vin, '--overwrite', '--no-progress'])
    && run('osmium', ['export', vin, '-f', 'geojsonseq', '-x', 'print_record_separator=false', '--geometry-types=polygon', '-o', gj, '--overwrite', '--no-progress']);
  rmSync(pbf, { force: true });
  if (!ok) { console.log(id, 'osmium non riuscito'); continue; }
  let n = 0;
  for await (const line of createInterface({ input: createReadStream(gj) })) {
    if (!line) continue;
    let s = 90, w = 180, nn = -90, e = -180; // centro del rettangolo che contiene il vigneto
    const walk = (a) => { if (typeof a[0] === 'number') { if (a[1] < s) s = a[1]; if (a[1] > nn) nn = a[1]; if (a[0] < w) w = a[0]; if (a[0] > e) e = a[0]; } else for (const b of a) walk(b); };
    try { walk(JSON.parse(line).geometry.coordinates); } catch { continue; }
    if (s > nn) continue;
    all.push([+((s + nn) / 2).toFixed(5), +((w + e) / 2).toFixed(5)]); n++;
  }
  rmSync(vin, { force: true }); rmSync(gj, { force: true });
  console.log(`${i}/${files.size} ${id}: ${n} vigneti (${Math.round((Date.now() - t0) / 1000)} s)`);
}
// stesso vigneto in due file vicini (paesi confinanti): una volta sola
const seen = new Set(), out = [];
for (const [lat, lon] of all) { const k = lat + ',' + lon; if (!seen.has(k)) { seen.add(k); out.push({ lat, lon }); } }
mkdirSync(join(here, '.cache', 'osm'), { recursive: true });
writeFileSync(join(here, '.cache', 'osm', 'vigneti.json'), JSON.stringify({ elements: out }));
console.log('vigneti in tutto:', out.length);
