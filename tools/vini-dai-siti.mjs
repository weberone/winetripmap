// Legge i vini dai siti delle cantine (il sito viene da OpenStreetMap, in site/data/det) e ne ricava il colore principale.
// Educato: rispetta robots.txt, si presenta come WineTripMapBot, una richiesta alla volta per sito con una pausa, poche pagine per sito.
// Fonti, in ordine: negozio Shopify (/products.json), negozio WooCommerce (Store API), dati strutturati (JSON-LD Product),
// pagine dei vini ("vini", "wines", "weine", "vins", "shop"…) con i link alle schede dei singoli vini o i vini come titoli (h2–h4) con la foto.
// Di ogni vino si tiene anche l'immagine della bottiglia, quando c'è (la scheda la mostra dal sito della cantina, non la copiamo).
//
// usage:
//   node tools/vini-dai-siti.mjs crawl [--limit N] [--only id,id] [--refresh] [--conc 32]   → tools/.cache/vini/<id>.json (riprende da dove era)
//   node tools/vini-dai-siti.mjs build                                                    → tools/data/vini.json + applica ai dati del sito
//   node tools/vini-dai-siti.mjs show <id>                                                → cosa ha trovato per una cantina
import { readFileSync, writeFileSync, mkdirSync, readdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as cheerio from 'cheerio';
import { classify, mainColor, cleanName, looksLikeWine, isNotWine } from './vini-colori.mjs';
import { applyVini } from './vini-applica.mjs';
import { seoDati } from './seo-dati.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const siteData = join(here, '..', 'site', 'data');
const cacheDir = join(here, '.cache', 'vini');
const outFile = join(here, 'data', 'vini.json');
const UA = 'WineTripMapBot/1.0 (+https://winetripmap.com)';
const PAGE_MAX = 8;          // pagine HTML per sito (oltre a robots.txt e alle API dei negozi)
const PAUSE = 400;           // ms tra due richieste allo stesso sito
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const args = process.argv.slice(2);
const opt = (k, d) => { const i = args.indexOf('--' + k); return i < 0 ? d : (args[i + 1] && !args[i + 1].startsWith('--') ? args[i + 1] : true); };

// ---------- rete ----------
async function get(url, { json = false, max = 3e6 } = {}) {
  try {
    const r = await fetch(url, { headers: { 'User-Agent': UA, 'Accept-Language': 'it,en;q=0.8,*;q=0.5', Accept: json ? 'application/json' : 'text/html,application/xhtml+xml' }, redirect: 'follow', signal: AbortSignal.timeout(15000) });
    const ct = r.headers.get('content-type') || '';
    if (!r.ok) return { status: r.status, url: r.url };
    if (json ? !/json/.test(ct) : !/html|xml/.test(ct)) return { status: 'type', url: r.url };
    const reader = r.body.getReader(); const chunks = []; let n = 0;
    for (;;) { const { done, value } = await reader.read(); if (done) break; n += value.length; if (n > max) { reader.cancel(); break; } chunks.push(value); }
    const text = Buffer.concat(chunks).toString('utf8');
    return { status: 200, url: r.url, text };
  } catch (e) { return { status: 'err', err: String(e.cause?.code || e.name || e.message).slice(0, 60) }; }
}

// robots.txt: gruppi per "*" e per il nostro nome; Disallow/Allow con * e $ (la regola più lunga vince)
function robotsRules(txt) {
  const groups = []; let cur = null, lastUA = false;
  for (const raw of (txt || '').split(/\r?\n/)) {
    const line = raw.replace(/#.*/, '').trim(); if (!line) continue;
    const m = line.match(/^([a-z-]+)\s*:\s*(.*)$/i); if (!m) continue;
    const k = m[1].toLowerCase(), v = m[2].trim();
    if (k === 'user-agent') { if (!lastUA) { cur = { ua: [], rules: [] }; groups.push(cur); } cur.ua.push(v.toLowerCase()); lastUA = true; continue; }
    lastUA = false; if (!cur) continue;
    if ((k === 'disallow' || k === 'allow') && v) cur.rules.push({ allow: k === 'allow', path: v });
  }
  const mine = groups.filter((g) => g.ua.some((u) => u.includes('winetripmap')));
  const use = mine.length ? mine : groups.filter((g) => g.ua.includes('*'));
  return use.flatMap((g) => g.rules).map((r) => ({ ...r, re: new RegExp('^' + r.path.replace(/[.+?^{}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\\\$$/, '$')) }));
}
const allowed = (rules, path) => { let best = null; for (const r of rules) if (r.re.test(path) && (!best || r.path.length > best.path.length)) best = r; return !best || best.allow; };

// ---------- pagine dei vini ----------
const WINE_PAGE = /(^|[\/\-_ ])(i-?nostri-?vini|nostri vini|vini|wines?|our-?wines|weine?|weingut-weine|unsere-?weine|vins?|nos-?vins|vinos|nuestros-?vinos|vinhos|prodotti|products?|shop|negozio|e-?shop|store|boutique|tienda|loja|collections?|catalogo|catalog|range|gamma|sortiment|etichette|labels|selezione|rossi|bianchi|rosati|spumanti|bollicine|reds|whites|sparkling)([\/\-_ .]|$)/i;
const NOT_PAGE = /wine-?club|blog|news|notizie|tasting|degustazion|visit|tour|event|privacy|cookie|cart|carrello|checkout|account|login|contatt|contact|lavora|press|stampa|ricette|recipe|agriturismo|ospitalit|hospitality|wedding|matrimon|faq|terms|condizioni|spedizion|shipping|wp-content|\.pdf$|\.jpe?g$|\.png$|mailto:|tel:|#$/i;
const PRODUCT_PATH = /\/(prodotto|product|products|vino|vini|wine|wines|wein|weine|vin|vins|vinos?|shop|negozio|catalogo|etichetta|label|collections\/[^/]+\/products)\/[^/?#]+/i;
const sameSite = (a, b) => a.replace(/^www\./, '') === b.replace(/^www\./, '');

// testo di un elemento con uno spazio tra i figli (titolo e sottotitolo di una scheda non si incollano)
function textOf($, el) { const parts = []; const walk = (n) => { if (n.type === 'text') parts.push(n.data); else if (n.children && !/^(script|style|noscript|svg)$/i.test(n.name || '')) n.children.forEach(walk); }; walk(el); return parts.join(' ').replace(/\s+/g, ' ').trim(); }
function linksOf($, base) {
  const out = [];
  $('a[href]').each((_, a) => {
    let u; try { u = new URL($(a).attr('href'), base); } catch { return; }
    if (!/^https?:$/.test(u.protocol)) return;
    u.hash = '';
    const im = $(a).find('img').first();
    out.push({ u, text: textOf($, a) || (im.attr('alt') || '').trim(), title: ($(a).attr('title') || '').trim(), img: im.length ? imgOf($, im[0], base) : '' });
  });
  return out;
}

// ---------- immagini delle bottiglie ----------
// solo https (il sito è https: un'immagine http verrebbe bloccata), niente loghi, icone, bandiere, sfondi, segnaposto
const NOT_IMG = /logo|icon|favicon|flag|bandier|banner|sfondo|background|placeholder|spinner|loader|sprite|avatar|social|facebook|instagram|twitter|youtube|whatsapp|payment|paypal|visa|mastercard|cookie|\.svg(\?|$)|\.gif(\?|$)|^data:/i;
function imgUrl(src, base) {
  if (!src) return '';
  src = String(src).trim().split(/\s*,\s*/)[0].split(/\s+/)[0]; // srcset: la prima
  let u; try { u = new URL(src, base); } catch { return ''; }
  if (u.protocol === 'http:') u.protocol = 'https:'; // il sito è https: si prova la stessa immagine in https (se non c'è, la scheda la nasconde)
  if (u.protocol !== 'https:' || NOT_IMG.test(u.pathname) || u.href.length > 400) return '';
  return u.href;
}
// immagine di un elemento <img>: anche quelle caricate dopo, con l'indirizzo vero in un attributo data-… (data-src, data-lazy-src,
// data-nectar-img-src…) o in srcset; src per ultimo, perché spesso è un segnaposto
const IMG_EXT = /\.(jpe?g|png|webp|avif)(\?|$|\s)/i;
function imgOf($, el, base) {
  const at = el.attribs || {};
  for (const a of ['data-src', 'data-lazy-src', 'data-original', 'data-lazy', 'data-srcset', 'data-lazy-srcset']) { const u = imgUrl(at[a], base); if (u) return u; }
  for (const [a, v] of Object.entries(at)) if (a.startsWith('data-') && IMG_EXT.test(v || '')) { const u = imgUrl(v, base); if (u) return u; }
  for (const a of ['srcset', 'src']) { const v = imgUrl(at[a], base); if (v) return v; }
  return '';
}
// la foto della "scheda" di un elemento: si sale nei contenitori (fino a 6 livelli) finché uno ha un'immagine,
// ma solo finché il contenitore è ancora la scheda di un vino (al massimo 2 titoli: nome e denominazione) e non la pagina intera
function nearImg($, el, base, levels = 6) {
  let cur = $(el);
  for (let k = 0; k <= levels && cur.length && cur[0].type !== 'root'; k++) {
    if (k > 0 && cur.find('h1, h2, h3, h4').length > 2) return '';
    const imgs = cur.is('img') ? cur.toArray() : cur.find('img').toArray();
    if (imgs.length > 4) return '';
    for (const im of imgs) { const v = imgOf($, im, base); if (v) return v; }
    cur = cur.parent();
  }
  return '';
}
const ldImg = (x) => { const i = Array.isArray(x) ? x[0] : x; return typeof i === 'string' ? i : (i && (i.url || i.contentUrl)) || ''; };

// prodotti nei dati strutturati
function fromJsonLd($, base) {
  const names = [];
  const walk = (x) => {
    if (!x || typeof x !== 'object') return;
    if (Array.isArray(x)) return x.forEach(walk);
    const t = [].concat(x['@type'] || []).join(' ');
    if (/Product/i.test(t) && x.name) names.push({ n: String(x.name), d: [x.description, x.category].filter(Boolean).join(' ').slice(0, 400), i: imgUrl(ldImg(x.image), base) });
    if (x.itemListElement) walk(x.itemListElement);
    if (x.item) walk(x.item);
    if (x['@graph']) walk(x['@graph']);
  };
  $('script[type="application/ld+json"]').each((_, s) => { try { walk(JSON.parse($(s).contents().text())); } catch {} });
  return names;
}

// sulla pagina dei vini: titoli delle schede prodotto (WooCommerce e simili) e link alle schede dei singoli vini
function fromWinePage($, pageUrl) {
  const found = [];
  $('.woocommerce-loop-product__title, .product-title, .product_title, .product-name, .product-item-name, .wine-title, .wine-name, .card__heading, .product-card__title').each((_, el) => {
    const n = textOf($, el); if (n) found.push({ n, d: '', i: nearImg($, el, pageUrl) });
  });
  const base = new URL(pageUrl);
  const basePath = base.pathname.replace(/\/$/, '');
  for (const l of linksOf($, pageUrl)) {
    if (!sameSite(l.u.hostname, base.hostname) || NOT_PAGE.test(l.u.pathname)) continue;
    const p = l.u.pathname.replace(/\/$/, '');
    const child = basePath.length > 1 && p.startsWith(basePath + '/') && p.split('/').length === basePath.split('/').length + 1;
    if (!child && !PRODUCT_PATH.test(p)) continue;
    const n = l.text || l.title; if (!n) continue;
    found.push({ n, d: decodeURIComponent(p.split('/').pop() || '').replace(/[-_]/g, ' '), i: l.img });
  }
  return found.concat(fromHeadings($, pageUrl));
}
// vini scritti come titoli (h2–h4) nella pagina dei vini, ognuno con la foto della bottiglia vicino (Gaffino, Scuropasso, Mirù…).
// Vale solo se sono un elenco: almeno 2 titoli con un'immagine, così i titoli delle sezioni ("La nostra storia") restano fuori.
// Il testo sotto il titolo (sottotitolo, denominazione, uve) aiuta a capire il colore e se è davvero un vino.
const SECTION = /^(la |il |i |le |our |unsere |nos |nuestros )?(produzione|storia|history|territori\w*|vign\w*|vineyards?|azienda|cantina|winery|chi siamo|about( us)?|contatt\w*|contacts?|famiglia|family|filosofia|philosophy|ospitalit\w*|degustazion\w*|tasting\w*|nostri vini|vini|wines?|weine|vins|vinos|prodotti|products|shop|news|eventi|events|olio|oil|grappa|premi|awards|riconoscimenti|dove siamo|scheda tecnica|schede tecniche|download|newsletter|seguici|follow us)$/i;
function fromHeadings($, pageUrl) {
  if (!WINE_PAGE.test(new URL(pageUrl).pathname)) return [];
  const out = [], bare = [];
  $('h2, h3, h4').each((_, el) => {
    const n = textOf($, el);
    if (n.length < 3 || n.length > 70 || /[.!?:]$/.test(n) || n.split(' ').length > 9 || SECTION.test(n.toLowerCase().trim())) return;
    const img = nearImg($, el, pageUrl);
    // senza una foto abbinabile (pagine Wix e simili, tutto in un contenitore): vale solo se il nome da solo dice che è un vino
    if (!img) { if (classify(n) || looksLikeWine(n)) bare.push({ n, d: '', i: '' }); return; }
    const lvl = +el.tagName[1], last = out[out.length - 1];
    // titolo e sottotitolo nella stessa scheda (h2 "Cardinale" + h3 "I.G.P. Cesanese"): un vino solo, "Cardinale I.G.P. Cesanese"
    if (last && last.img === img && lvl > last.lvl) { last.n += ' ' + n; last.d = n + ' ' + last.d; return; }
    let d = '', sib = $(el).next();
    for (let k = 0; k < 3 && sib.length && d.length < 300; k++, sib = sib.next()) d += ' ' + textOf($, sib[0]);
    out.push({ n, d: d.slice(0, 400), i: img, img, lvl });
  });
  // elenco: se buona parte dei titoli con la foto sono chiaramente vini, valgono anche gli altri ("Il Taccio", "Cocciuto")
  const ok = out.filter((w) => classify(w.n + ' ' + w.d) || looksLikeWine(w.n + ' ' + w.d)).length;
  const withImg = out.length >= 2 && ok >= 2 ? out.map(({ n, d, i }) => ({ n, d, i, t: ok >= out.length * 0.4 })) : [];
  return withImg.concat(bare.length >= 3 ? bare : []);
}

// ---------- negozi ----------
const shopItem = (n, extra, i = '') => ({ n, d: extra.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 500), shop: true, i: imgUrl(i) });
async function shopify(origin) {
  const r = await get(origin + '/products.json?limit=250', { json: true, max: 6e6 });
  if (r.status !== 200) return [];
  try { return (JSON.parse(r.text).products || []).map((p) => shopItem(p.title, [p.product_type, [].concat(p.tags || []).join(' '), (p.body_html || '').slice(0, 600)].join(' '))); } catch { return []; }
}
async function woo(origin) {
  const r = await get(origin + '/wp-json/wc/store/v1/products?per_page=100', { json: true, max: 6e6 });
  if (r.status !== 200) return [];
  try { return (JSON.parse(r.text) || []).map((p) => shopItem(p.name, [(p.categories || []).map((c) => c.name).join(' '), p.short_description || '', (p.description || '').slice(0, 600)].join(' '), p.images?.[0]?.thumbnail || p.images?.[0]?.src || '')); } catch { return []; }
}

// ---------- una cantina ----------
async function crawlSite(id, site) {
  const res = { id, site, at: new Date().toISOString(), pages: [], wines: [], note: '' };
  let start; try { start = new URL(site); } catch { res.note = 'url'; return res; }
  const robots = await get(start.origin + '/robots.txt', { max: 5e5 });
  const rules = robots.status === 200 && !/<html/i.test(robots.text.slice(0, 300)) ? robotsRules(robots.text) : [];
  if (!allowed(rules, start.pathname || '/')) { res.note = 'robots'; return res; }
  await sleep(PAUSE);
  let home = await get(start.href);
  // indirizzo vecchio in OpenStreetMap (pagina sparita): riprova dalla radice del sito
  if (home.status !== 200 && start.pathname.length > 1 && typeof home.status === 'number') { await sleep(PAUSE); home = await get(start.origin + '/'); }
  if (home.status !== 200) { res.note = 'home ' + (home.err || home.status); return res; }
  const homeUrl = new URL(home.url);
  res.pages.push(home.url);
  const $h = cheerio.load(home.text);
  const raw = [];
  const origin = homeUrl.origin;
  const html = home.text;
  if (/cdn\.shopify\.com|Shopify\.theme/i.test(html) && allowed(rules, '/products.json')) { await sleep(PAUSE); const s = await shopify(origin); raw.push(...s); if (s.length) res.pages.push(origin + '/products.json'); }
  if (/woocommerce|wc-block|wp-content\/plugins\/woocommerce/i.test(html) && allowed(rules, '/wp-json/wc/store/v1/products')) { await sleep(PAUSE); const s = await woo(origin); raw.push(...s); if (s.length) res.pages.push(origin + '/wp-json/wc/store/v1/products'); }
  raw.push(...fromJsonLd($h, home.url), ...fromWinePage($h, home.url));
  // pagine dei vini dalla home, poi un livello sotto (es. /vini → /vini/rossi)
  const seen = new Set([home.url.replace(/\/$/, '')]);
  const score = (l) => (WINE_PAGE.test(l.u.pathname) ? 2 : 0) + (WINE_PAGE.test(l.text) ? 2 : 0) - l.u.pathname.split('/').length * 0.1;
  let queue = linksOf($h, home.url).filter((l) => sameSite(l.u.hostname, homeUrl.hostname) && !NOT_PAGE.test(l.u.href) && score(l) >= 2 && !PRODUCT_PATH.test(l.u.pathname))
    .sort((a, b) => score(b) - score(a));
  let pages = 0;
  while (queue.length && pages < PAGE_MAX) {
    const l = queue.shift(); const key = l.u.href.replace(/\/$/, '');
    if (seen.has(key)) continue; seen.add(key);
    if (!allowed(rules, l.u.pathname)) continue;
    await sleep(PAUSE);
    const p = await get(l.u.href); pages++;
    if (p.status !== 200) continue;
    res.pages.push(p.url);
    const $ = cheerio.load(p.text);
    raw.push(...fromJsonLd($, p.url), ...fromWinePage($, p.url));
    const more = linksOf($, p.url).filter((m) => sameSite(m.u.hostname, homeUrl.hostname) && !NOT_PAGE.test(m.u.href) && !PRODUCT_PATH.test(m.u.pathname) && score(m) >= 2 && !seen.has(m.u.href.replace(/\/$/, '')));
    queue.push(...more.slice(0, 6));
  }
  res.wines = pickWines(raw);
  return res;
}

// dai candidati ai vini: pulizia, solo quelli che sembrano vini, colore per ciascuno, niente doppioni.
// trust = candidati già accettati durante la lettura (build): si ripassano pulizia e scarti, ma non si chiede di nuovo
// "sembra un vino?", perché ora manca il testo intorno (descrizione del negozio, denominazione sotto il titolo)
function pickWines(raw, trust = false) {
  const out = new Map();
  for (const r of raw) {
    const n = cleanName(r.n); if (!n) continue;
    // anche il nome del file della foto aiuta: "chianti-classico-riserva-2021.jpg" dice che è un rosso
    const file = r.i ? decodeURIComponent(r.i.split('/').pop().split('?')[0]).replace(/\.\w+$/, '').replace(/[-_]+/g, ' ').replace(/\b\d+x\d+\b/g, '') : '';
    const text = n + ' ' + (r.d || '') + ' ' + file;
    if (isNotWine(n)) continue;
    const c = classify(n) || classify(text);
    if (!c && !trust && !r.t && !looksLikeWine(text)) continue;
    // un titolo di pagina (h2–h4) deve sembrare un vino da solo o con il testo sotto; i nomi di soli comuni o sezioni no
    const k = n.toLowerCase().normalize('NFD').replace(/[^a-z0-9]+/g, '');
    if (!out.has(k)) out.set(k, { n, c: c || '', i: r.i || '' });
    else { const o = out.get(k); if (!o.c && c) o.c = c; if (!o.i && r.i) o.i = r.i; }
  }
  const list = [...out.values()].slice(0, 60);
  const uses = {}; for (const w of list) if (w.i) uses[w.i] = (uses[w.i] || 0) + 1;
  for (const w of list) if (w.i && uses[w.i] > 1) w.i = '';
  return list;
}

// ---------- elenco delle cantine con un sito ----------
function sites() {
  const list = [];
  for (const f of readdirSync(join(siteData, 'det'))) {
    const d = JSON.parse(readFileSync(join(siteData, 'det', f), 'utf8'));
    for (const [id, v] of Object.entries(d)) if (v.w) list.push([id, v.w]);
  }
  return list;
}

// ---------- comandi ----------
const cmd = args[0];
if (cmd === 'crawl') {
  mkdirSync(cacheDir, { recursive: true });
  let list = sites();
  const only = opt('only'); if (only) list = list.filter(([id]) => String(only).split(',').includes(id));
  if (!opt('refresh')) list = list.filter(([id]) => !existsSync(join(cacheDir, id + '.json')));
  // a caso ma stabile: i siti dello stesso gruppo/hosting non vengono visitati tutti insieme
  list.sort((a, b) => (hash(a[0]) - hash(b[0])));
  const lim = +opt('limit', 0); if (lim) list = list.slice(0, lim);
  const conc = +opt('conc', 32);
  console.log(`${list.length} siti da leggere, ${conc} alla volta`);
  let done = 0, withWines = 0; const t0 = Date.now();
  const worker = async () => {
    for (;;) {
      const it = list.shift(); if (!it) return;
      let r;
      try { r = await Promise.race([crawlSite(...it), sleep(180000).then(() => ({ id: it[0], site: it[1], at: new Date().toISOString(), pages: [], wines: [], note: 'timeout' }))]); }
      catch (e) { r = { id: it[0], site: it[1], at: new Date().toISOString(), pages: [], wines: [], note: 'errore ' + e.message.slice(0, 80) }; }
      writeFileSync(join(cacheDir, it[0] + '.json'), JSON.stringify(r));
      done++; if (r.wines.length) withWines++;
      if (done % 50 === 0) console.log(`${done} fatti, ${withWines} con vini, ${Math.round((Date.now() - t0) / 60000)} min`);
    }
  };
  await Promise.all(Array.from({ length: conc }, worker));
  console.log(`fine: ${done} siti, ${withWines} con vini`);
} else if (cmd === 'build') {
  const vini = {};
  let n = 0;
  for (const f of readdirSync(cacheDir)) {
    const r = JSON.parse(readFileSync(join(cacheDir, f), 'utf8'));
    const wines = pickWines(r.wines.map((w) => ({ n: w.n, d: '', i: w.i || '' })), true); // ripassa le regole attuali
    // il colore di ogni vino lo ricalcola pickWines; se nel giro aveva un colore dal testo del negozio lo tiene
    const byName = new Map(r.wines.map((w) => [w.n.toLowerCase(), w.c]));
    for (const w of wines) if (!w.c && byName.get(w.n.toLowerCase())) w.c = byName.get(w.n.toLowerCase());
    if (!wines.length) continue;
    const c = mainColor(wines.map((w) => w.c));
    // pagina da mostrare: una pagina vera dei vini, mai l'indirizzo tecnico del negozio (products.json, wp-json)
    const human = r.pages.filter((p) => !/products\.json|wp-json/.test(p));
    vini[r.id] = { c, v: wines.map((w) => (w.i ? [w.n, w.c || '', w.i] : w.c ? [w.n, w.c] : [w.n])), u: human[1] || human[0] || r.site };
    n++;
  }
  mkdirSync(dirname(outFile), { recursive: true });
  writeFileSync(outFile, JSON.stringify(vini));
  const counts = {}; for (const v of Object.values(vini)) counts[v.c || '-'] = (counts[v.c || '-'] || 0) + 1;
  const all = Object.values(vini).flatMap((x) => x.v), withImg = all.filter((w) => w[2]).length;
  console.log(`${n} cantine con vini (${all.length} vini, ${withImg} con la foto) → ${outFile}`, counts);
  applyVini(siteData, vini);
  seoDati(); // le pagine per i motori di ricerca mostrano il colore del vino principale
} else if (cmd === 'page') {
  // prova di una pagina: cosa trovano le regole (candidati e vini scelti)
  const p = await get(args[1]); const $ = cheerio.load(p.text || '');
  const raw = [...fromJsonLd($, args[1]), ...fromWinePage($, args[1])];
  console.log('candidati:', raw.map((r) => r.n + (r.i ? ' [foto]' : '') + (r.t ? ' [elenco]' : '')).join(' | '));
  console.log('vini:', pickWines(raw).map((w) => (w.c || '-') + ' ' + w.n).join(' | '));
} else if (cmd === 'show') {
  console.log(JSON.stringify(JSON.parse(readFileSync(join(cacheDir, args[1] + '.json'), 'utf8')), null, 1));
} else {
  console.log('usage: node tools/vini-dai-siti.mjs crawl|build|show');
}
function hash(s) { let h = 0; for (const ch of s) h = (h * 31 + ch.charCodeAt(0)) | 0; return h; }
