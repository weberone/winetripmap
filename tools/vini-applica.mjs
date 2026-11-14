// Mette i vini letti dai siti (tools/data/vini.json) nei dati del sito:
// - site/data/cantine.json: sesto campo di ogni cantina = colore principale (r rosso, b bianco, o rosato, s bollicine), per il colore del pin
// - site/data/det5/<riquadro>.json: v = [[nome, colore], …] e vu = pagina da cui vengono (lista dei vini nella scheda)
// Lo usano vini-dai-siti.mjs (build) e osm-cantine.mjs (quando riscarica le cantine, i vini restano).
import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs';
import { join } from 'node:path';

export function applyVini(siteData, vini) {
  if (!vini) { const f = join(siteData, '..', '..', 'tools', 'data', 'vini.json'); vini = existsSync(f) ? JSON.parse(readFileSync(f, 'utf8')) : {}; }
  const cf = join(siteData, 'cantine.json');
  const all = JSON.parse(readFileSync(cf, 'utf8'));
  let colored = 0;
  all.items = all.items.map((it) => { const x = it.slice(0, 5); const c = vini[x[0]]?.c; if (c) { x.push(c); colored++; } return x; });
  writeFileSync(cf, JSON.stringify(all));
  let listed = 0;
  for (const f of readdirSync(join(siteData, 'det5'))) {
    const p = join(siteData, 'det5', f); const d = JSON.parse(readFileSync(p, 'utf8'));
    for (const [id, v] of Object.entries(d)) {
      delete v.v; delete v.vu;
      const w = vini[id]; if (w?.v?.length) { v.v = w.v; v.vu = w.u; listed++; }
    }
    writeFileSync(p, JSON.stringify(d));
  }
  console.log(`vini nei dati del sito: ${colored} pin colorati, ${listed} schede con la lista dei vini`);
}
