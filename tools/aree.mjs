// Aree per la ricerca della mappa: continenti, stati, regioni, zone del vino. Scrive site/data/aree.json:
//   { n,                                   numero di cantine in cantine.json (gli indici sotto valgono solo per quel file)
//     ct: { EU: { c: ["IT", …], k, b } },  continenti → stati
//     c:  { IT: { r: ["IT.09", …], k, b } },  stati → regioni (nomi degli stati: Intl.DisplayNames nel browser)
//     r:  { "IT.09": { n, t, i: [indici], k, b } },  regioni (n = nome GeoNames, t = altre lingue da tools/data/nomi.json)
//     z:  [{ n, t, i, k, b, d }] }         zone: regioni del vino di Wikidata (le cantine entro il raggio della zona)
//                                          e denominazioni (d: 1; le cantine che hanno quel nome nella lista dei vini)
// b = [ovest, sud, est, nord] delle cantine dell'area (senza le più lontane: isole e territori d'oltremare non allargano lo stato).
// Da tools/data/luoghi.json (GeoNames), nomi.json, zone.json (tools/zone.mjs), vini.json. Lo rifà seo-dati.mjs.
// usage: node tools/aree.mjs
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const J = (f, d = null) => existsSync(f) ? JSON.parse(readFileSync(f, 'utf8')) : d;
const norm = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[’'`]/g, "'").replace(/[^a-z0-9']+/g, ' ').trim();

function bbox(pts) {
  if (!pts.length) return null;
  const q = (arr, p) => arr[Math.min(arr.length - 1, Math.max(0, Math.round(p * (arr.length - 1))))];
  const la = pts.map((p) => p[0]).sort((a, b) => a - b), ln = pts.map((p) => p[1]).sort((a, b) => a - b);
  const cut = pts.length >= 30 ? 0.02 : 0;
  const r = (x) => Math.round(x * 1000) / 1000;
  return [r(q(ln, cut)), r(q(la, cut)), r(q(ln, 1 - cut)), r(q(la, 1 - cut))];
}
function distKm(a, b) {
  const t = (x) => x * Math.PI / 180, h = Math.sin(t(b[0] - a[0]) / 2) ** 2 + Math.cos(t(a[0])) * Math.cos(t(b[0])) * Math.sin(t(b[1] - a[1]) / 2) ** 2;
  return 12742 * Math.asin(Math.sqrt(h));
}

export function aree() {
  const L = J(join(here, 'data', 'luoghi.json')); if (!L) return console.log('aree: manca tools/data/luoghi.json');
  const N = J(join(here, 'data', 'nomi.json'), {});
  const Z = J(join(here, 'data', 'zone.json'), { z: [], d: [] });
  const V = J(join(here, 'data', 'vini.json'), {});
  const items = J(join(here, '..', 'site', 'data', 'cantine.json')).items;
  // continente di ogni stato (countryInfo.txt di GeoNames, colonna 9)
  const cont = {};
  const ci = join(here, '.cache', 'geonames', 'countryInfo.txt');
  if (existsSync(ci)) for (const l of readFileSync(ci, 'utf8').split('\n')) { if (!l || l[0] === '#') continue; const f = l.split('\t'); cont[f[0]] = f[8]; }
  const tr = (gid, n) => { const t = {}; for (const [l, m] of Object.entries(N)) if (l !== 'en' && m[gid] && m[gid] !== n) t[l] = m[gid]; return Object.keys(t).length ? t : undefined; };

  const r = {}, c = {}, ct = {}, pts = { r: {}, c: {}, ct: {} };
  items.forEach(([id, lat, lng], i) => {
    const ck = L.w[id]; if (!ck) return;
    const city = L.cities[ck], cc = city.cc, rk = city.r, k = cont[cc] || 'XX', p = [lat, lng];
    const R = r[rk] ||= { n: L.regions[rk].n, t: tr(L.regions[rk].g, L.regions[rk].n), i: [], k: 0 };
    R.i.push(i); R.k++; (pts.r[rk] ||= []).push(p);
    const C = c[cc] ||= { r: [], k: 0 }; if (!C.r.includes(rk)) C.r.push(rk); C.k++; (pts.c[cc] ||= []).push(p);
    const T = ct[k] ||= { c: [], k: 0 }; if (!T.c.includes(cc)) T.c.push(cc); T.k++; (pts.ct[k] ||= []).push(p);
  });
  for (const [k, v] of Object.entries(r)) v.b = bbox(pts.r[k]);
  for (const [k, v] of Object.entries(c)) { v.b = bbox(pts.c[k]); v.r.sort((a, b) => r[b].k - r[a].k); }
  for (const [k, v] of Object.entries(ct)) { v.b = bbox(pts.ct[k]); v.c.sort((a, b) => c[b].k - c[a].k); }
  delete ct.XX;

  const z = [];
  const seen0 = (n) => z.some((x) => norm(x.n) === norm(n));
  // regioni del vino di Wikidata: le cantine entro il raggio della zona (dalla superficie, se c’è; altrimenti 15 km)
  for (const w of Z.z) {
    const km = w.km2 ? Math.max(6, Math.min(60, Math.sqrt(w.km2 / Math.PI) * 1.1)) : 15, i = [], p = [];
    items.forEach(([, lat, lng], j) => { if (Math.abs(lat - w.lat) < km / 100 && distKm([w.lat, w.lng], [lat, lng]) <= km) { i.push(j); p.push([lat, lng]); } });
    if (!i.length) continue;
    const n = w.n.en || Object.values(w.n)[0], t = {}; for (const [l, v] of Object.entries(w.n)) if (l !== 'en' && v !== n) t[l] = v;
    z.push({ n, t: Object.keys(t).length ? t : undefined, i, k: i.length, b: bbox(p) });
  }
  // grandi zone del vino che Wikidata non ha con le coordinate (Bordeaux, Mosella…): punti e raggio scritti a mano, qui le cantine
  // entro r km da uno dei punti (la Mosella segue il fiume). Se Wikidata le ha già (stesso nome) non si ripetono
  const EXTRA = [
    { n: { en: 'Bordeaux', it: 'Bordeaux', fr: 'Vignoble de Bordeaux', de: 'Bordeaux', es: 'Burdeos', pt: 'Bordéus', ru: 'Бордо', ja: 'ボルドー', zh: '波尔多', pl: 'Bordeaux', ko: '보르도', id: 'Bordeaux' }, pts: [[44.85, -0.45], [45.15, -0.8], [44.6, -0.3]], r: 35 },
    { n: { en: 'Médoc', it: 'Médoc', fr: 'Médoc', ja: 'メドック', zh: '梅多克', ru: 'Медок', ko: '메독' }, pts: [[45.2, -0.8], [45.0, -0.68]], r: 18 },
    { n: { en: 'Mosel', it: 'Mosella', fr: 'Moselle', de: 'Mosel', es: 'Mosela', pt: 'Mosela', ru: 'Мозель', ja: 'モーゼル', zh: '摩泽尔', pl: 'Mozela', ko: '모젤', id: 'Mosel' },
      pts: [[49.6, 6.4], [49.75, 6.64], [49.82, 6.8], [49.88, 6.95], [49.93, 7.05], [50.0, 7.1], [50.07, 7.13], [50.15, 7.2], [50.22, 7.35], [50.3, 7.5], [50.35, 7.58]], r: 7 },
    { n: { en: 'Rheingau', it: 'Rheingau', de: 'Rheingau', fr: 'Rheingau', ja: 'ラインガウ', zh: '莱茵高', ru: 'Рейнгау', ko: '라인가우' }, pts: [[50.0, 7.9], [50.03, 8.05], [50.02, 8.2]], r: 8 },
    { n: { en: 'Alsace', it: 'Alsazia', fr: 'Alsace', de: 'Elsass', es: 'Alsacia', pt: 'Alsácia', ru: 'Эльзас', ja: 'アルザス', zh: '阿尔萨斯', pl: 'Alzacja', ko: '알자스', id: 'Alsace' }, pts: [[48.75, 7.45], [48.5, 7.42], [48.25, 7.35], [48.05, 7.3], [47.85, 7.25]], r: 12 },
    { n: { en: 'Loire Valley', it: 'Valle della Loira', fr: 'Vallée de la Loire', de: 'Loiretal', es: 'Valle del Loira', pt: 'Vale do Loire', ru: 'Долина Луары', ja: 'ロワール渓谷', zh: '卢瓦尔河谷', pl: 'Dolina Loary', ko: '루아르 밸리', id: 'Lembah Loire' }, pts: [[47.3, 2.85], [47.4, 1.0], [47.3, 0.4], [47.25, -0.4], [47.2, -1.4]], r: 22 },
    { n: { en: 'Rhône Valley', it: 'Valle del Rodano', fr: 'Vallée du Rhône', de: 'Rhônetal', es: 'Valle del Ródano', pt: 'Vale do Ródano', ru: 'Долина Роны', ja: 'ローヌ渓谷', zh: '罗讷河谷', pl: 'Dolina Rodanu', ko: '론 밸리', id: 'Lembah Rhône' }, pts: [[45.4, 4.8], [45.05, 4.85], [44.6, 4.75], [44.2, 4.8], [44.05, 4.9]], r: 18 },
    { n: { en: 'Beaujolais', it: 'Beaujolais', fr: 'Beaujolais', ja: 'ボージョレ', zh: '博若莱', ru: 'Божоле', ko: '보졸레' }, pts: [[46.15, 4.65], [45.95, 4.6]], r: 14 },
    { n: { en: 'Chablis', it: 'Chablis', fr: 'Chablis', ja: 'シャブリ', zh: '夏布利', ru: 'Шабли', ko: '샤블리' }, pts: [[47.81, 3.8]], r: 12 },
    { n: { en: 'Provence', it: 'Provenza', fr: 'Provence', de: 'Provence', es: 'Provenza', pt: 'Provença', ru: 'Прованс', ja: 'プロヴァンス', zh: '普罗旺斯', pl: 'Prowansja', ko: '프로방스', id: 'Provence' }, pts: [[43.5, 5.6], [43.4, 6.2], [43.6, 6.6]], r: 35 },
    { n: { en: 'Langhe', it: 'Langhe', fr: 'Langhe', de: 'Langhe' }, pts: [[44.6, 8.0], [44.5, 8.1]], r: 15 },
  ];
  for (const w of EXTRA) {
    const n = w.n.en; if (seen0(n)) continue;
    const i = [], p = [];
    items.forEach(([, lat, lng], j) => { if (w.pts.some(([a, b]) => Math.abs(lat - a) < w.r / 90 && distKm([a, b], [lat, lng]) <= w.r)) { i.push(j); p.push([lat, lng]); } });
    if (!i.length) continue;
    const t = {}; for (const [l, v] of Object.entries(w.n)) if (l !== 'en' && v !== n) t[l] = v;
    z.push({ n, t: Object.keys(t).length ? t : undefined, i, k: i.length, b: bbox(p) });
  }
  // una denominazione è un luogo: delle cantine che la nominano restano solo quelle raccolte nella sua zona (intorno al punto
  // mediano, entro il doppio della distanza tipica, tra 25 e 120 km); fuori i negozi e le enoteche lontane che la vendono
  const med = (a) => { const b = a.slice().sort((x, y) => x - y); return b[b.length >> 1]; };
  const core = (js) => {
    let keep = js;
    for (let k = 0; k < 2 && keep.length; k++) {
      const c = [med(keep.map((j) => items[j][1])), med(keep.map((j) => items[j][2]))];
      const ds = js.map((j) => distKm(c, [items[j][1], items[j][2]]));
      const r = Math.max(25, Math.min(120, 2 * med(keep.map((j) => distKm(c, [items[j][1], items[j][2]])))));
      keep = js.filter((j, n) => ds[n] <= r);
    }
    return keep;
  };
  // denominazioni: il nome senza la sigla ("Barolo" da "Barolo DOCG") nella lista dei vini della cantina
  const idx = new Map(items.map((it, i) => [it[0], i]));
  const wines = Object.entries(V).filter(([id]) => idx.has(id)).map(([id, w]) => [idx.get(id), ' ' + w.v.map((x) => norm(x[0])).join(' | ') + ' ']);
  const seen = new Set(z.map((x) => norm(x.n)));
  for (const d of Z.d) {
    const base = norm(d.replace(/\s+(DOCG|DOC|AOC|AOP|DOP|DOCa|DOQ|DO|AVA|DAC)$/i, ''));
    if (base.length < 4 || seen.has(norm(d))) continue;
    const re = new RegExp(`[ |]${base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}( |$)`);
    const k0 = core(wines.filter(([, s]) => re.test(s)).map(([j]) => j));
    if (k0.length < 2) continue;
    // e tutte le cantine dentro l'area dove stanno quelle che la nominano (intorno al punto mediano, entro 1,3 volte la distanza
    // tipica, tra 4 e 40 km): Anselmi a Monteforte d'Alpone è nel Soave anche senza la lista dei vini
    const c = [med(k0.map((j) => items[j][1])), med(k0.map((j) => items[j][2]))];
    const r = Math.max(4, Math.min(40, 1.3 * med(k0.map((j) => distKm(c, [items[j][1], items[j][2]])))));
    const inK = new Set(k0);
    items.forEach(([, lat, lng], j) => { if (!inK.has(j) && Math.abs(lat - c[0]) < r / 100 && distKm(c, [lat, lng]) <= r) inK.add(j); });
    const i = [...inK].sort((a, b) => a - b);
    seen.add(norm(d));
    z.push({ n: d, i, k: i.length, b: bbox(i.map((j) => [items[j][1], items[j][2]])), d: 1 });
  }
  z.sort((a, b) => b.k - a.k);
  writeFileSync(join(here, '..', 'site', 'data', 'aree.json'), JSON.stringify({ n: items.length, ct, c, r, z }));
  console.log(`aree: ${Object.keys(ct).length} continenti, ${Object.keys(c).length} stati, ${Object.keys(r).length} regioni, ${z.filter((x) => !x.d).length} zone del vino, ${z.filter((x) => x.d).length} denominazioni`);
}
if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) aree();
