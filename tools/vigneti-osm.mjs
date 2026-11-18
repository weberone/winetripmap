// Scarica da OpenStreetMap i vigneti (landuse=vineyard, solo il centro) a riquadri di 5° × 5°, solo dove ci sono cantine,
// e li unisce in tools/.cache/osm/vigneti.json (usato da produttori.mjs). Riprende dai riquadri già scaricati.
// usage: node tools/vigneti-osm.mjs
import { readFileSync, writeFileSync, mkdirSync, existsSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
const here = dirname(fileURLToPath(import.meta.url));
const dir = join(here, '.cache', 'osm', 'vigneti');
mkdirSync(dir, { recursive: true });
const SERVERS = ['https://overpass-api.de/api/interpreter', 'https://maps.mail.ru/osm/tools/overpass/api/interpreter', 'https://overpass.private.coffee/api/interpreter', 'https://overpass.kumi.systems/api/interpreter'];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const { items } = JSON.parse(readFileSync(join(here, '..', 'site', 'data', 'cantine.json'), 'utf8'));
const cells = [...new Set(items.map(([, la, ln]) => Math.floor(la / 5) * 5 + '_' + Math.floor(ln / 5) * 5))];
console.log(cells.length, 'riquadri');
let si = 0;
const queue = cells.map((c) => { const [s, w] = c.split('_').map(Number); return [s, w, 5]; });
while (queue.length) {
  const [s, w, z] = queue.shift();
  const c = `${s}_${w}_${z}`, f = join(dir, (z === 5 ? `${s}_${w}` : c) + '.json');
  if (existsSync(f) || (z === 5 && existsSync(join(dir, `${s}_${w}_split`)))) {
    if (z === 5 && existsSync(join(dir, `${s}_${w}_split`))) for (const [a, b] of [[0, 0], [0, 2.5], [2.5, 0], [2.5, 2.5]]) queue.unshift([s + a, w + b, 2.5]);
    continue;
  }
  const q = `[out:json][timeout:180];(way["landuse"="vineyard"](${s},${w},${s + z},${w + z});relation["landuse"="vineyard"](${s},${w},${s + z},${w + z}););out center;`;
  let ok = false;
  for (let t = 0; t < (z > 0.7 ? 4 : 12); t++) {
    const url = SERVERS[si++ % SERVERS.length];
    try {
      const r = await fetch(url, { method: 'POST', body: new URLSearchParams({ data: q }), headers: { 'User-Agent': 'winetripmap-build/1.0' }, signal: AbortSignal.timeout(200000) });
      const txt = await r.text();
      if (r.ok && txt.startsWith('{')) { const el = JSON.parse(txt).elements.map((e) => [e.center?.lat ?? e.lat, e.center?.lon ?? e.lon]); writeFileSync(f, JSON.stringify(el)); console.log(c, el.length); ok = true; break; }
      console.log(c, 'occupato', url.split('/')[2], r.status);
    } catch (e) { console.log(c, 'errore', url.split('/')[2], e.message); }
    await sleep(Math.min(10000 * (t + 1), 60000));
  }
  if (!ok) { const h = z / 2; console.log(c, 'divido'); for (const [a, b] of [[0, 0], [0, h], [h, 0], [h, h]]) queue.unshift([s + a, w + b, h]); }
}
const all = []; for (const f of readdirSync(dir)) for (const [lat, lon] of JSON.parse(readFileSync(join(dir, f), 'utf8'))) if (lat != null) all.push({ lat, lon });
writeFileSync(join(here, '.cache', 'osm', 'vigneti.json'), JSON.stringify({ elements: all }));
console.log('vigneti in tutto:', all.length);
