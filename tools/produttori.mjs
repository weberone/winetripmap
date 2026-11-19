// Chi produce davvero vino? OpenStreetMap segna come cantina (craft=winery) anche locali, enoteche, negozi e distributori.
// Ogni punto riceve un punteggio da più segnali, tutti gratuiti:
//  - vigneti di OSM (landuse=vineyard) vicini al punto (il segnale più forte)
//  - parole da produttore / da locale nella pagina iniziale del sito della cantina (robots.txt rispettato)
//  - lista dei vini già trovata sul sito (tools/data/vini.json), etichette OSM (amenity=bar/restaurant, shop, cuisine…)
//   node tools/produttori.mjs crawl [--conc 40] [--limit N] [--refresh]  → tools/.cache/prod/<id>.json
//   node tools/produttori.mjs confronta <vecchio produttori.json> [file.txt]  → chi esce e chi rientra, con i motivi
//   vigneti: node tools/vigneti-geofabrik.mjs (o vigneti-osm.mjs, Overpass, lento) → tools/.cache/osm/vigneti.json; cantine con tutte le etichette: tools/.cache/osm/cantine-tag.json
//   node tools/produttori.mjs build     → tools/data/produttori.json { id: punteggio } + riepilogo; osm-cantine.mjs toglie chi è sotto soglia
//   node tools/produttori.mjs apply     → toglie subito dai dati del sito chi è sotto soglia (e rifà aree e pagine SEO)
//   node tools/produttori.mjs page <url> → parole trovate in una pagina
import { readFileSync, writeFileSync, mkdirSync, readdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as cheerio from 'cheerio';
import { get, robotsRules, allowed } from './rete.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const siteData = join(here, '..', 'site', 'data');
const cacheDir = join(here, '.cache', 'prod');
const osmDir = join(here, '.cache', 'osm');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const args = process.argv.slice(2);
const opt = (k, d) => { const i = args.indexOf('--' + k); return i < 0 ? d : (args[i + 1] && !args[i + 1].startsWith('--') ? args[i + 1] : true); };

// parole di chi coltiva e fa il vino (it, en, fr, de, es, pt, hu, ro, gr, hr…)
const PROD = /(?<![\p{L}\d])(vigne(t[oi]|ron|ronne|rons)?|vign[ae]|viticolt\w*|vitivinicol\w*|ettar[io]|hectares?|hektar\w*|vendemmi\w*|harvest\w*|vinifica\w*|vinification|vinery|winemak\w*|vineyards?|grapes?|grape[- ]?grow\w*|estate[- ]grown|cellar door|weinberg\w*|weinbau\w*|winzer\w*|rebfl[äa]che|reben|weinlese|kellerei|vi[ñn]edos?|vendimia|viticultor\w*|cosecha|elaboramos|vignobles?|vendanges?|r[ée]colte|cuverie|chai|propri[ée]taire[- ]r[ée]coltant|vinhas?|vindima|viticultura|uvas?|uve|szőlő\w*|pinc[ée]\w*|vie de vie|podgorij\w*|vinograd\w*|αμπελ\w*|οινοποι\w*|produciamo|produzione propria|nostra produzione|our wines|nos vins|unsere weine|nuestros vinos|i nostri vini|family[- ]owned winery|azienda agricola|tenuta|terroir)(?![\p{L}\d])/giu;
// parole di un locale, un negozio o un distributore
const SHOP = /(?<![\p{L}\d])(menu|men[uù] del giorno|prenota (un )?tavolo|book a table|reserve a table|pizz\w*|aperitiv\w*|happy hour|cocktails?|burgers?|brunch|colazion\w*|piatti|antipasti|primi piatti|ristorante|restaurant|trattoria|osteria|tapas|carta dei vini|wine list|consegna a domicilio|home delivery|delivery|takeaway|asporto|birr[ae]|beers?|craft beer|spirits|liquor\w*|whisk(e)?y|gin|enoteca|wine bar|wine shop|bottle shop|caviste|weinhandlung|vinoteca|importers?|importatore|distribu\w*|ingrosso|wholesale|grossista|catering|hotel|b&b|bed and breakfast|camere|rooms)(?![\p{L}\d])/giu;

export function wordsOf(html) {
  const $ = cheerio.load(html);
  $('script,style,noscript,svg').remove();
  const text = ($('title').text() + ' ' + $('meta[name=description]').attr('content') + ' ' + $('body').text()).replace(/\s+/g, ' ').slice(0, 200000);
  const count = (re) => { const m = {}; for (const x of text.matchAll(re)) { const k = x[0].toLowerCase(); m[k] = (m[k] || 0) + 1; } return m; };
  return { prod: count(PROD), shop: count(SHOP), len: text.length };
}

async function readSite(id, site) {
  const res = { id, at: new Date().toISOString(), note: '' };
  let start; try { start = new URL(site); } catch { res.note = 'url'; return res; }
  const robots = await get(start.origin + '/robots.txt', { max: 5e5 });
  const rules = robots.status === 200 && !/<html/i.test(robots.text.slice(0, 300)) ? robotsRules(robots.text) : [];
  if (!allowed(rules, start.pathname || '/')) { res.note = 'robots'; return res; }
  await sleep(400);
  let home = await get(start.href, { max: 1.5e6 });
  if (home.status !== 200 && start.pathname.length > 1 && typeof home.status === 'number') { await sleep(400); home = await get(start.origin + '/', { max: 1.5e6 }); }
  if (home.status !== 200) { res.note = 'home ' + (home.err || home.status); return res; }
  Object.assign(res, wordsOf(home.text), { host: new URL(home.url || start.href).hostname });
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

// ---- punteggio ----
const outFile = join(here, 'data', 'produttori.json');
const NAME_PROD = /(weingut|weinhof|winzerhof|weinbau|heurig|buschenschank|stra[uß]+wirtschaft|h[äa]cker|sekt|winzer|domaine|ch[aâ]teau|tenuta|bodegas?\b|winery|vineyards?|estates?\b|cellars\b|azienda (agricola|vinicola|vitivinicola)|az\.? ?agr|quinta\b|vignobles?|vigneron|wijngaard|wijndomein|kellerei|cantina sociale|cave (coop|des vignerons)|cooperativa|vinařství|winnica|pince(szet)?\b|vinarija|vinárstvo|oinopoieio|οινοποιείο|κτήμα|vin[ií]cola|vinhos\b|ving[åa]a?rd|viinitila|\bwines\b|wine co\b|\bceller\b|kelter\b|ワイナリー)/i;
const NAME_SHOP = /((?<!\p{L})bar(?!\p{L})|caff[eè]|(?<!\p{L})caf[eé](?!\p{L})|bibit|beverage|drinks?\b|distribu|import|ingross|wholesale|liquor|liquori|alimentari|market|\bshop\b|store\b|negozio|deli\b|gastronom|bistr|restaurant|ristorant|trattoria|osteria|pizzeri|pizza|hotel|lounge|club\b|birr|beer|brew|spirits|tabacch|coffee|kaffee|vinothek|weinhandl|caviste|cave [àa] vins?|wine ?shop|bottle ?shop|wine ?bar|weinbar|vinoteca|enoteca|merchants?\b|negoce|négoce)/iu;
const BAD_AMENITY = /^(bar|pub|restaurant|cafe|fast_food|biergarten|nightclub|food_court|ice_cream)$/;
const BAD_SHOP = /^(wine|alcohol|beverages|deli|supermarket|convenience|general|wholesale|kiosk|department_store|gift|variety_store)$/;

function vineIndex() {
  const f = join(osmDir, 'vigneti.json'); if (!existsSync(f)) return null;
  const grid = new Map(); let n = 0;
  const V = JSON.parse(readFileSync(f, 'utf8'));
  // paesi (e stati USA "US.CA") di cui si hanno i vigneti; senza elenco (vecchio vigneti-osm.mjs) tutti
  const zone = V.zone ? new Set(V.zone) : null;
  const L = existsSync(join(here, 'data', 'luoghi.json')) ? JSON.parse(readFileSync(join(here, 'data', 'luoghi.json'), 'utf8')) : { w: {}, cities: {} };
  const cities = Object.values(L.cities);
  const covered = (id, lat, lng) => {
    if (!zone) return true;
    let c = L.cities[L.w[id]];
    if (!c) { let bd = Infinity; for (const x of cities) { const d = (x.lat - lat) ** 2 + ((x.lng - lng) * Math.cos(lat * Math.PI / 180)) ** 2; if (d < bd) { bd = d; c = x; } } } // cantina non nel sito: il comune più vicino
    return !!c && (zone.has(c.cc) || zone.has(c.r));
  };
  for (const e of V.elements) {
    const lat = e.lat ?? e.center?.lat, lng = e.lon ?? e.center?.lon; if (lat == null) continue;
    const k = Math.floor(lat * 10) + ':' + Math.floor(lng * 10); (grid.get(k) || grid.set(k, []).get(k)).push([lat, lng]); n++;
  }
  console.log(`${n} vigneti di OSM` + (zone ? ` in ${zone.size} zone` : ''));
  const dist = (lat, lng) => { // distanza dal vigneto più vicino (km) e quanti vigneti entro 25 km
    let best = Infinity, near = 0; const cl = Math.cos(lat * Math.PI / 180);
    const a = Math.floor(lat * 10), b = Math.floor(lng * 10), rl = Math.ceil(3 / cl);
    for (let i = a - 3; i <= a + 3; i++) for (let j = b - rl; j <= b + rl; j++) for (const [y, x] of grid.get(i + ':' + j) || []) {
      const d = Math.hypot((y - lat) * 111.2, (x - lng) * 111.2 * cl); if (d < best) best = d; if (d < 25) near++;
    }
    return [best, near];
  };
  // null dove i vigneti del paese non ci sono (file Geofabrik mancante o non riuscito): lì non contano né a favore né contro
  return (id, lat, lng) => covered(id, lat, lng) ? dist(lat, lng) : null;
}

function build() {
  const vine = vineIndex();
  const tagged = existsSync(join(osmDir, 'cantine-tag.json')) ? JSON.parse(readFileSync(join(osmDir, 'cantine-tag.json'), 'utf8')).elements : [];
  const tags = new Map(tagged.map((e) => [e.type[0] + e.id, e.tags || {}]));
  const vini = existsSync(join(here, 'data', 'vini.json')) ? JSON.parse(readFileSync(join(here, 'data', 'vini.json'), 'utf8')) : {};
  const crawl = new Map(); if (existsSync(cacheDir)) for (const f of readdirSync(cacheDir)) { const r = JSON.parse(readFileSync(join(cacheDir, f), 'utf8')); crawl.set(r.id, r); }
  // tutte le cantine di OSM (anche quelle già tolte dal sito), altrimenti quelle del sito
  const items = tagged.length ? tagged.map((e) => [e.type[0] + e.id, e.lat ?? e.center?.lat, e.lon ?? e.center?.lon, (e.tags || {}).name || '']).filter((x) => x[1] != null)
    : JSON.parse(readFileSync(join(siteData, 'cantine.json'), 'utf8')).items;
  const out = {};
  for (const [id, lat, lng, name] of items) {
    let pos = 0, neg = 0, vneg = 0; const why = [];
    const add = (p, w, v) => { if (p > 0) pos += p; else if (v) vneg += p; else neg += p; why.push((p > 0 ? '+' : '') + p + ' ' + w); };
    const vz = vine && vine(id, lat, lng);
    if (vz) {
      const [d, near] = vz;
      if (d <= 0.6) add(3, 'vigneto a ' + d.toFixed(1) + ' km'); else if (d <= 1.5) add(2, 'vigneto a ' + d.toFixed(1) + ' km'); else if (d <= 3) add(1, 'vigneto a ' + d.toFixed(1) + ' km');
      else if (near >= 5) add(-2, 'nessun vigneto entro 3 km (' + near + ' entro 25 km)', 1); else add(-1, near ? 'nessun vigneto entro 3 km' : 'nessun vigneto entro 25 km', 1);
    }
    const t = tags.get(id) || {};
    if (BAD_AMENITY.test(t.amenity || '')) add(-3, 'amenity=' + t.amenity);
    if (t.cuisine) add(-1, 'cuisine');
    if (BAD_SHOP.test(t.shop || '')) add(-1, 'shop=' + t.shop);
    const soloVendita = /^(wine|alcohol)$/.test(t.shop || '') ? 1 : 0; // spesso è la vendita diretta della cantina: con i soli vigneti lontani non basta
    if (/office|company/.test(t.office || '')) add(-1, 'office=' + t.office);
    if (t.landuse === 'vineyard' || /grape|wine/.test(t.produce || '')) add(3, 'vigneto nel punto');
    if (NAME_PROD.test(name)) add(2, 'nome da produttore');
    if (NAME_SHOP.test(name)) add(-2, 'nome da locale/negozio');
    if (vini[id]) add(1, 'lista dei vini');
    const c = crawl.get(id);
    if (c && c.prod) {
      const P = Object.keys(c.prod).length, Sh = Object.keys(c.shop).length;
      if (P) add(Math.min(P, 3), 'sito: ' + Object.keys(c.prod).slice(0, 4).join(', '));
      if (Sh >= 3 && P <= 1) add(-2, 'sito da locale: ' + Object.keys(c.shop).slice(0, 4).join(', '));
    }
    // fuori: segni da locale e al massimo una prova debole da produttore (chi ne ha di più resta: cantine con ristorante, Heuriger…)
    // solo produttori (richiesta dell'utente 2026-11-18): serve almeno una prova di produzione (vigneto vicino, nome, sito,
    // lista dei vini); chi ha segni da negozio/locale e al massimo una prova debole (+1) esce. Ristorante o Heuriger con prove
    // da produttore restano
    // i vigneti di OSM sono incompleti (tante cantine vere li hanno lontano o non segnati): la sola mancanza di vigneti non basta
    // per togliere, serve anche un altro segno da locale/negozio (2026-11-19, dopo il primo rapporto: 1.610 fuori, tanti produttori veri)
    const s = (pos <= 1 && neg <= -2) || (pos === 0 && neg + soloVendita <= -1 && vneg < 0) ? -2 : pos === 0 && neg === -1 ? -1 : pos + neg + vneg < 0 ? 0 : pos + neg + vneg;
    out[id] = [s, name, why.join('; ')];
  }
  mkdirSync(dirname(outFile), { recursive: true });
  writeFileSync(outFile, JSON.stringify(Object.fromEntries(Object.entries(out).map(([k, v]) => [k, v[0]]))));
  writeFileSync(join(here, '.cache', 'produttori-motivi.json'), JSON.stringify(out));
  const h = {}; for (const [s] of Object.values(out)) h[s] = (h[s] || 0) + 1;
  console.log('punteggi:', Object.entries(h).sort((a, b) => a[0] - b[0]).map(([k, v]) => k + ':' + v).join(' '));
}

// toglie dai dati del sito (cantine.json, det5/) chi non produce, poi rifà aree e pagine SEO
async function apply() {
  const cf = join(siteData, 'cantine.json'); const C = JSON.parse(readFileSync(cf, 'utf8'));
  const prima = C.items.length; C.items = C.items.filter(([id]) => !nonProduttore(id));
  writeFileSync(cf, JSON.stringify(C));
  for (const f of readdirSync(join(siteData, 'det5'))) {
    const p = join(siteData, 'det5', f); const d = JSON.parse(readFileSync(p, 'utf8')); let ch = false;
    for (const id of Object.keys(d)) if (nonProduttore(id)) { delete d[id]; ch = true; }
    if (ch) writeFileSync(p, JSON.stringify(d));
  }
  console.log(`${prima - C.items.length} tolte, ${C.items.length} cantine sul sito`);
  const { seoDati } = await import('./seo-dati.mjs'); seoDati();
}

// chi esce (o rientra) rispetto a un produttori.json precedente: numeri per stato ed elenco con i motivi → file di testo
function confronta(vecchio, uscita) {
  const V = JSON.parse(readFileSync(vecchio, 'utf8')), N = JSON.parse(readFileSync(outFile, 'utf8'));
  const M = JSON.parse(readFileSync(join(here, '.cache', 'produttori-motivi.json'), 'utf8'));
  const L = existsSync(join(here, 'data', 'luoghi.json')) ? JSON.parse(readFileSync(join(here, 'data', 'luoghi.json'), 'utf8')) : { w: {}, cities: {} };
  const dove = (id) => { const c = L.cities[L.w[id]]; return c ? c.cc + ' ' + c.n : '?'; };
  const sito = new Set(JSON.parse(readFileSync(join(siteData, 'cantine.json'), 'utf8')).items.map((x) => x[0]));
  const esce = Object.keys(N).filter((id) => N[id] <= -2 && !(V[id] <= -2) && sito.has(id)), rientra = Object.keys(V).filter((id) => V[id] <= -2 && N[id] > -2);
  const per = {}; for (const id of esce) { const k = dove(id).split(' ')[0]; per[k] = (per[k] || 0) + 1; }
  const riga = (id) => [id, N[id], M[id]?.[1], dove(id), M[id]?.[2]].join(' | ');
  const txt = [`escono ${esce.length}, rientrano ${rientra.length}`, 'per stato: ' + Object.entries(per).sort((a, b) => b[1] - a[1]).map((x) => x.join(' ')).join(', '),
    '', '== escono ==', ...esce.sort((a, b) => dove(a).localeCompare(dove(b))).map(riga), '', '== rientrano ==', ...rientra.map(riga)].join('\n');
  writeFileSync(uscita || join(here, '.cache', 'produttori-confronto.txt'), txt);
  console.log(txt.split('\n').slice(0, 2).join('\n'));
}

export function nonProduttore(id, soglia = -2) {
  if (!existsSync(outFile)) return false;
  nonProduttore.m ||= JSON.parse(readFileSync(outFile, 'utf8'));
  return id in nonProduttore.m && nonProduttore.m[id] <= soglia;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const cmd = args[0];
  if (cmd === 'crawl') {
    mkdirSync(cacheDir, { recursive: true });
    let list = sites();
    if (!opt('refresh')) list = list.filter(([id]) => !existsSync(join(cacheDir, id + '.json')));
    const lim = +opt('limit', 0); if (lim) list = list.slice(0, lim);
    const conc = +opt('conc', 40);
    console.log(`${list.length} siti da leggere, ${conc} alla volta`);
    let done = 0;
    const worker = async () => {
      for (;;) {
        const it = list.shift(); if (!it) return;
        let r;
        try { r = await Promise.race([readSite(...it), sleep(60000).then(() => ({ id: it[0], at: new Date().toISOString(), note: 'timeout' }))]); }
        catch { r = { id: it[0], at: new Date().toISOString(), note: 'errore' }; }
        writeFileSync(join(cacheDir, it[0] + '.json'), JSON.stringify(r));
        if (++done % 200 === 0) console.log(`${done} fatti`);
      }
    };
    await Promise.all(Array.from({ length: conc }, worker));
    console.log(`fine: ${done} siti`);
  } else if (cmd === 'build') build();
  else if (cmd === 'confronta') confronta(args[1], args[2]);
  else if (cmd === 'apply') await apply();
  else if (cmd === 'page') { const p = await get(args[1]); console.log(JSON.stringify(wordsOf(p.text || ''))); }
  else if (cmd !== 'build' && cmd !== 'apply') console.log('usage: node tools/produttori.mjs crawl|build|apply|page <url>');
}
