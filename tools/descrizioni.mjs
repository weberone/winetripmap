// Descrizione breve di ogni cantina, presa dalla pagina iniziale del suo sito: la descrizione che il sito stesso
// dà ai motori di ricerca (meta description, og:description o la "description" dei dati strutturati JSON-LD).
// Una sola pagina per sito (più robots.txt), rispettando robots.txt, come WineTripMapBot.
// La scheda la mostra con la fonte ("dal sito della cantina").
//
// usage:
//   node tools/descrizioni.mjs crawl [--limit N] [--only id,id] [--refresh] [--conc 40]  → tools/.cache/desc/<id>.json (riprende da dove era)
//   node tools/descrizioni.mjs build                                                   → tools/data/descrizioni.json + ds nei dettagli (site/data/det5/)
//   node tools/descrizioni.mjs page <url>                                              → cosa trova in una pagina
import { readFileSync, writeFileSync, mkdirSync, readdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as cheerio from 'cheerio';
import { get, robotsRules, allowed } from './rete.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const siteData = join(here, '..', 'site', 'data');
const cacheDir = join(here, '.cache', 'desc');
const outFile = join(here, 'data', 'descrizioni.json');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const args = process.argv.slice(2);
const opt = (k, d) => { const i = args.indexOf('--' + k); return i < 0 ? d : (args[i + 1] && !args[i + 1].startsWith('--') ? args[i + 1] : true); };

// testi che non dicono nulla della cantina: segnaposto dei CMS, cookie, negozi generici, parole chiave in fila
const JUNK = /just another|wordpress|lorem ipsum|coming soon|under construction|in costruzione|cookie|javascript|this site|questo sito utilizza|page not found|404|default description|shopify|powered by|wix\.com|squarespace|jimdo|webnode|home page|homepage|benvenut[oi] (nel|sul) (nostro )?sito|welcome to our (web)?site|sito ufficiale$|official (web)?site$/i;
function clean(s, title) {
  s = String(s || '').replace(/\s+/g, ' ').replace(/^[\s\-–—|:·]+|[\s\-–—|:·]+$/g, '').trim();
  if (s.length < 50 || JUNK.test(s)) return '';
  if ((s.match(/,/g) || []).length > 10 && !/[.!?]/.test(s.slice(0, -1))) return ''; // elenco di parole chiave
  if (/^https?:\/\//.test(s) || /[{}<>]/.test(s)) return '';
  if (title && s.toLowerCase() === title.toLowerCase()) return '';
  if (s === s.toUpperCase() && /[A-Z]/.test(s)) s = s.charAt(0) + s.slice(1).toLowerCase(); // TUTTO MAIUSCOLO
  // al massimo ~300 caratteri, tagliando alla fine di una frase o di una parola
  if (s.length > 320) {
    const cut = s.slice(0, 320), dot = Math.max(cut.lastIndexOf('. '), cut.lastIndexOf('! '), cut.lastIndexOf('? '));
    s = dot > 120 ? cut.slice(0, dot + 1) : cut.replace(/\s+\S*$/, '') + '…';
  }
  return s;
}
// controllo in più al momento di applicarle: testi di menu ("Nuestra historia Nuestra historia"), eventi, segni di spunta
function keep(s) {
  if (/✓|✔|☑|^(event|termin|news|aktuell|eventtermine|novità|news)/i.test(s)) return false;
  if (/\b(\p{L}+ \p{L}+) \1\b/iu.test(s)) return false; // due parole ripetute di seguito: voci di menu
  return true;
}
export function descOf(html) {
  const $ = cheerio.load(html);
  const title = $('title').first().text().trim();
  const meta = (sel) => $(sel).attr('content') || '';
  const cand = [meta('meta[name="description"]'), meta('meta[property="og:description"]'), meta('meta[name="twitter:description"]')];
  $('script[type="application/ld+json"]').each((_, el) => {
    try {
      const walk = (x) => { if (!x || typeof x !== 'object') return; if (Array.isArray(x)) return x.forEach(walk);
        const t = [].concat(x['@type'] || []).join(' ');
        if (/Organization|Winery|LocalBusiness|Brewery|Store|WebSite/i.test(t) && typeof x.description === 'string') cand.push(x.description);
        if (x['@graph']) walk(x['@graph']); };
      walk(JSON.parse($(el).text()));
    } catch {}
  });
  for (const c of cand) { const d = clean(c, title); if (d && keep(d)) return d; }
  return '';
}

async function readSite(id, site) {
  const res = { id, at: new Date().toISOString(), ds: '', note: '' };
  let start; try { start = new URL(site); } catch { res.note = 'url'; return res; }
  const robots = await get(start.origin + '/robots.txt', { max: 5e5 });
  const rules = robots.status === 200 && !/<html/i.test(robots.text.slice(0, 300)) ? robotsRules(robots.text) : [];
  if (!allowed(rules, start.pathname || '/')) { res.note = 'robots'; return res; }
  await sleep(400);
  let home = await get(start.href, { max: 1.5e6 });
  if (home.status !== 200 && start.pathname.length > 1 && typeof home.status === 'number') { await sleep(400); home = await get(start.origin + '/', { max: 1.5e6 }); }
  if (home.status !== 200) { res.note = 'home ' + (home.err || home.status); return res; }
  res.ds = descOf(home.text);
  return res;
}

function sites() {
  const list = [];
  for (const f of readdirSync(join(siteData, 'det5'))) {
    const d = JSON.parse(readFileSync(join(siteData, 'det5', f), 'utf8'));
    for (const [id, v] of Object.entries(d)) if (v.w) list.push([id, v.w]);
  }
  return list;
}

// mette ds nei dettagli delle cantine (site/data/det5/); lo rifà anche osm-cantine.mjs dopo un nuovo scarico
export function applyDesc() {
  if (!existsSync(outFile)) return;
  const ds = JSON.parse(readFileSync(outFile, 'utf8'));
  let n = 0;
  for (const f of readdirSync(join(siteData, 'det5'))) {
    const p = join(siteData, 'det5', f); const d = JSON.parse(readFileSync(p, 'utf8'));
    for (const [id, v] of Object.entries(d)) { delete v.ds; if (ds[id]) { v.ds = ds[id]; n++; } }
    writeFileSync(p, JSON.stringify(d));
  }
  console.log(`descrizioni nei dati del sito: ${n} cantine`);
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const cmd = args[0];
  if (cmd === 'crawl') {
    mkdirSync(cacheDir, { recursive: true });
    let list = sites();
    const only = opt('only'); if (only) list = list.filter(([id]) => String(only).split(',').includes(id));
    if (!opt('refresh')) list = list.filter(([id]) => !existsSync(join(cacheDir, id + '.json')));
    const lim = +opt('limit', 0); if (lim) list = list.slice(0, lim);
    const conc = +opt('conc', 40);
    console.log(`${list.length} siti da leggere, ${conc} alla volta`);
    let done = 0, found = 0;
    const worker = async () => {
      for (;;) {
        const it = list.shift(); if (!it) return;
        let r;
        try { r = await Promise.race([readSite(...it), sleep(60000).then(() => ({ id: it[0], at: new Date().toISOString(), ds: '', note: 'timeout' }))]); }
        catch (e) { r = { id: it[0], at: new Date().toISOString(), ds: '', note: 'errore' }; }
        writeFileSync(join(cacheDir, it[0] + '.json'), JSON.stringify(r));
        done++; if (r.ds) found++;
        if (done % 200 === 0) console.log(`${done} fatti, ${found} con la descrizione`);
      }
    };
    await Promise.all(Array.from({ length: conc }, worker));
    console.log(`fine: ${done} siti, ${found} con la descrizione`);
  } else if (cmd === 'build') {
    const ds = {};
    for (const f of readdirSync(cacheDir)) { const r = JSON.parse(readFileSync(join(cacheDir, f), 'utf8')); if (r.ds && keep(r.ds)) ds[r.id] = r.ds; }
    mkdirSync(dirname(outFile), { recursive: true }); writeFileSync(outFile, JSON.stringify(ds));
    applyDesc();
  }
  else if (cmd === 'page') { const p = await get(args[1]); console.log(JSON.stringify(descOf(p.text || ''))); }
  else console.log('usage: node tools/descrizioni.mjs crawl|build|page <url>');
}
