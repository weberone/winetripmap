// Dati delle pagine per i motori di ricerca (site/seo/*.php): stati → regioni → comuni → cantine.
// Da site/data/cantine.json e tools/data/luoghi.json (tools/luoghi.mjs, GeoNames). Scrive site/data/seo/:
//   index.json  → { CC: { n, s, k, r: { "CC.a1": { n, s, k } } } }   (stati e regioni con il numero di cantine)
//   <cc>.json   → { r: { rk: { n, s, k } }, ci: { id: { n, s, r, k, lat, lng } }, w: [[id, nome, colore, comune, lat, lng], …] }
//   w.json      → { id: "cc" }   (per trovare la pagina di una cantina)
// usage: node tools/seo-dati.mjs   (lo rifanno anche osm-cantine.mjs e vini-dai-siti.mjs build, dopo i loro dati)
import { readFileSync, writeFileSync, mkdirSync, rmSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
export function seoDati() {
  const L = JSON.parse(readFileSync(join(here, 'data', 'luoghi.json'), 'utf8'));
  // nomi nelle lingue delle pagine (tools/nomi.mjs, Wikidata): t = { lingua: nome } solo dove è diverso da quello di GeoNames
  const N = existsSync(join(here, 'data', 'nomi.json')) ? JSON.parse(readFileSync(join(here, 'data', 'nomi.json'), 'utf8')) : {};
  const tr = (gid, n) => { const t = {}; for (const [l, m] of Object.entries(N)) if (l !== 'en' && m[gid] && m[gid] !== n) t[l] = m[gid]; return Object.keys(t).length ? { t } : {}; };
  const items = JSON.parse(readFileSync(join(here, '..', 'site', 'data', 'cantine.json'), 'utf8')).items;
  const dir = join(here, '..', 'site', 'data', 'seo');
  rmSync(dir, { recursive: true, force: true }); mkdirSync(dir, { recursive: true });
  const index = {}, per = {}, wmap = {};
  for (const [id, lat, lng, n, , wc] of items) {
    const ck = L.w[id]; if (!ck) continue;
    const city = L.cities[ck], cc = city.cc, rk = city.r;
    const C = index[cc] ||= { n: L.countries[cc].n, s: L.countries[cc].s, k: 0, r: {} };
    C.k++; (C.r[rk] ||= { n: L.regions[rk].n, s: L.regions[rk].s, k: 0 }).k++;
    const P = per[cc] ||= { r: {}, ci: {}, w: [] };
    (P.r[rk] ||= { n: L.regions[rk].n, s: L.regions[rk].s, k: 0, ...tr(L.regions[rk].g, L.regions[rk].n) }).k++;
    (P.ci[ck] ||= { n: city.n, s: city.s, r: rk, k: 0, lat: city.lat, lng: city.lng, ...tr(ck, city.n) }).k++;
    P.w.push([id, n || '', wc || '', +ck, lat, lng]);
    wmap[id] = cc.toLowerCase();
  }
  writeFileSync(join(dir, 'index.json'), JSON.stringify(index));
  for (const [cc, P] of Object.entries(per)) writeFileSync(join(dir, cc.toLowerCase() + '.json'), JSON.stringify(P));
  writeFileSync(join(dir, 'w.json'), JSON.stringify(wmap));
  console.log(`pagine SEO: ${Object.keys(index).length} stati, ${Object.values(per).reduce((a, p) => a + Object.keys(p.r).length, 0)} regioni, ${Object.values(per).reduce((a, p) => a + Object.keys(p.ci).length, 0)} comuni, ${Object.keys(wmap).length} cantine → ${dir}`);
}
if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  if (!existsSync(join(here, 'data', 'luoghi.json'))) { console.log('prima: node tools/luoghi.mjs'); process.exit(1); }
  seoDati();
}
