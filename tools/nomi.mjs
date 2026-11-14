// Nomi di regioni e comuni nelle 12 lingue delle pagine (Piedmont → Piemonte, ピエモンテ州…), da Wikidata:
// ogni luogo di GeoNames ha il suo codice (proprietà P1566 di Wikidata), che lega il luogo alla sua voce e ai nomi nelle lingue.
// Una richiesta ogni 300 luoghi al servizio pubblico di Wikidata (query.wikidata.org), con una pausa tra una e l'altra.
// Dove manca una traduzione resta il nome di GeoNames.
// usage: node tools/nomi.mjs   → tools/data/nomi.json { lingua: { geonameid: nome } }   (poi node tools/seo-dati.mjs)
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const L = JSON.parse(readFileSync(join(here, 'data', 'luoghi.json'), 'utf8'));
const LANGS = ['en', 'it', 'es', 'pt', 'fr', 'de', 'ru', 'ja', 'zh', 'pl', 'ko', 'id'];
const ids = [...new Set([...Object.values(L.regions).map((r) => r.g).filter(Boolean), ...Object.keys(L.cities).map(Number)])];
const out = Object.fromEntries(LANGS.map((l) => [l, {}]));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
for (let i = 0; i < ids.length; i += 300) {
  const chunk = ids.slice(i, i + 300);
  const q = `SELECT ?g ?l ?lang WHERE { VALUES ?g { ${chunk.map((x) => `"${x}"`).join(' ')} } ?it wdt:P1566 ?g; rdfs:label ?l. BIND(LANG(?l) AS ?lang) FILTER(?lang IN (${LANGS.map((l) => `"${l}"`).join(',')})) }`;
  for (let t = 0; t < 4; t++) {
    try {
      const r = await fetch('https://query.wikidata.org/sparql', { method: 'POST', body: new URLSearchParams({ query: q }),
        headers: { Accept: 'application/sparql-results+json', 'User-Agent': 'WineTripMapBot/1.0 (+https://winetripmap.com)' }, signal: AbortSignal.timeout(90000) });
      if (!r.ok) throw new Error(r.status);
      for (const b of (await r.json()).results.bindings) out[b.lang.value][b.g.value] ??= b.l.value;
      break;
    } catch (e) { console.log('riprovo', i, e.message); await sleep(5000 * (t + 1)); }
  }
  console.log(`${Math.min(i + 300, ids.length)}/${ids.length}`);
  await sleep(1500);
}
writeFileSync(join(here, 'data', 'nomi.json'), JSON.stringify(out));
console.log('nomi:', Object.fromEntries(LANGS.map((l) => [l, Object.keys(out[l]).length])));
