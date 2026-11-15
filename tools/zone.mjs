// Zone vitivinicole per la ricerca della mappa, da Wikidata (CC0): regioni del vino, AVA americane, DOC/DOCG, DO/DOCa,
// AOC, DOC portoghesi, quando hanno le coordinate. Nomi nelle 12 lingue del sito, superficie (P2046) per il raggio.
// Scrive tools/data/zone.json: { z: [{ q, n: { lingua: nome }, lat, lng, km2 }], d: ["Barolo DOCG", …] } (d = denominazioni dei vini:
// vini di Wikidata con DOCG, DOC, AOC, DO… nel nome; le cantine ci entrano se il nome è nella loro lista dei vini). Lo usa tools/aree.mjs.
// usage: node tools/zone.mjs
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const UA = 'WineTripMapBot/1.0 (+https://winetripmap.com)';
const LANGS = ['en', 'it', 'es', 'pt', 'fr', 'de', 'ru', 'ja', 'zh', 'pl', 'ko', 'id'];
const CLASSES = ['Q2140699', 'Q166247', 'Q654824', 'Q2305591', 'Q3139487', 'Q95556874', 'Q1565828', 'Q451752'];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function sparql(q) {
  for (let i = 0; i < 4; i++) {
    try {
      const r = await fetch('https://query.wikidata.org/sparql?query=' + encodeURIComponent(q), { headers: { Accept: 'application/sparql-results+json', 'User-Agent': UA } });
      if (r.ok) return (await r.json()).results.bindings;
    } catch (e) {}
    await sleep(3000 * (i + 1));
  }
  throw new Error('sparql');
}
const items = new Map();
for (const c of CLASSES) {
  const rows = await sparql(`SELECT ?i ?co ?area WHERE { ?i wdt:P31/wdt:P279* wd:${c} . ?i wdt:P625 ?co . OPTIONAL { ?i wdt:P2046 ?area } }`);
  for (const b of rows) {
    const q = b.i.value.split('/').pop(), m = b.co.value.match(/Point\(([-\d.eE]+) ([-\d.eE]+)\)/); if (!m) continue;
    if (!items.has(q)) items.set(q, { q, lat: +(+m[2]).toFixed(4), lng: +(+m[1]).toFixed(4), km2: b.area ? +b.area.value : 0, n: {} });
  }
  console.log(c, rows.length, '→', items.size);
}
// nomi: wbgetentities, 50 per volta
const ids = [...items.keys()];
for (let i = 0; i < ids.length; i += 50) {
  const u = `https://www.wikidata.org/w/api.php?action=wbgetentities&format=json&props=labels&languages=${LANGS.join('|')}&ids=${ids.slice(i, i + 50).join('|')}`;
  const d = await (await fetch(u, { headers: { 'User-Agent': UA } })).json();
  for (const [q, e] of Object.entries(d.entities || {})) for (const [l, v] of Object.entries(e.labels || {})) items.get(q).n[l] = v.value;
  await sleep(200);
}
// denominazioni: i vini (Q282) con la sigla nel nome, in una delle lingue dei paesi del vino
const den = new Set();
for (const b of await sparql('SELECT DISTINCT ?l WHERE { ?i wdt:P31 wd:Q282 . ?i rdfs:label ?l . FILTER(LANG(?l) IN ("en","it","fr","es","pt","de")) FILTER(REGEX(?l, " (DOCG|DOC|AOC|AOP|DOP|DOCa|DOQ|DO|AVA|DAC)$")) }'))
  den.add(b.l.value.replace(/\s+/g, ' ').trim());
console.log('denominazioni', den.size);
const out = [...items.values()].filter((z) => Object.keys(z.n).length && !/^Q\d+$/.test(z.n.en || ''));
mkdirSync(join(here, 'data'), { recursive: true });
writeFileSync(join(here, 'data', 'zone.json'), JSON.stringify({ z: out, d: [...den].sort() }));
console.log(`${out.length} zone, ${den.size} denominazioni → tools/data/zone.json`);
