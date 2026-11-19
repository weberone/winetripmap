// Cantine che OpenStreetMap non segna come cantina (craft=winery) ma come agriturismo, ristorante, fattoria o punto vendita:
// es. "Tenuta Valcurone" e "Agriturismo Terrazze di Montevecchia" (amenity=restaurant). Richiesta dell'utente 2026-11-19.
// Entra solo chi ha un nome o una descrizione da produttore di vino E un vigneto OSM vicino (entro 1 km; gli agriturismi
// senza parole del vino entro 400 m): così non rientrano enoteche e wine bar di città.
//
// - agriCandidate(tags): il punto può essere una cantina? → 'vino' (parole del vino), 'agri' (agriturismo/fattoria) o null.
//   Lo usa vigneti-geofabrik.mjs mentre legge i file Geofabrik (filtro osmium AGRI_FILTER), salvando i candidati nei suoi file.
// - node tools/agri.mjs apply → aggiunge a site/data/cantine.json e det5/ i candidati vicini a un vigneto e non già presenti
//   (tools/.cache/osm/agri.json, scritto da vigneti-geofabrik.mjs --merge), poi rifà le pagine SEO solo se chiamato da solo.
//   Nel workflow mensile gira dopo i vigneti e prima della lettura dei siti (così anche i loro vini e descrizioni arrivano).
// - node tools/agri.mjs test <overpass.json> → cosa entrerebbe da un file Overpass (out center tags), per provare le regole.
import { readFileSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
// filtro per osmium tags-filter (vigneti-geofabrik.mjs): tutti i posti con un nome che potrebbero essere una cantina
export const AGRI_FILTER = ['nwr/amenity=restaurant,cafe,bar,pub,biergarten', 'nwr/tourism=guest_house,chalet,hotel,apartment,attraction,camp_site',
  'nwr/shop=farm,wine,alcohol,beverages,deli', 'nwr/place=farm', 'nwr/landuse=farmyard', 'nwr/produce', 'nwr/product'];
// nomi da produttore di vino (nel nome o nell'operatore)
// aziende agricole in genere (non solo vino): entrano solo vicinissime a un vigneto
const FARM = /(azienda agricola|az\.? ?agr|societ[àa] agricola|soc\.? ?agr)/i;
const STRONG = /(azienda (vinicola|vitivinicola)|vitivinicol|cantin[ae]\b|tenut[ae]\b|weingut|weinbau|winzer|heurig|buschenschank|kellerei|domaine|ch[aâ]teau|vignobles?|vigneron|bodegas?\b|quinta\b|winery|wines\b|vinícola|vinhos\b|wijngaard|vinařství|pince(szet)?\b|winnica)/i;
// la descrizione dice che lì si fa o si vende il proprio vino ("vendita diretta vino", "produzione vini", "eigener Wein")
const DESC = /(vendita (diretta )?(di |del )?vin|produzion[ei] (di |del )?vin|produttor[ei] (di )?vin|nostr[io] vin|own wines?|eigene[nr]? wein|weinbau|vins? de (la )?propriété|vente (directe )?de vin|vigneron)/i;
// agriturismi: entrano solo vicinissimi a un vigneto (Agriturismo Terrazze di Montevecchia)
const AGRI = /(agriturismo|agritur\b|buschenschank|heuriger)/i;
// enoteche, wine bar, negozi e locali: mai (anche con "vigne" nel nome: Osteria delle Vigne, Trattoria nelle Vigne)
const NO = /(enotec|vinotec|wine ?bar|bar à vins?|bar a vin|weinbar|weinhandel|weinstube|caviste|cave à vins?|cave a vin|wine (shop|store)|liquor|osteria|trattoria|ristorante|restaurant|pizz|hotel|albergo|pub\b|bistr|sushi|kebab|burger|gelat|pasticc|panific|bäckerei|caf[eé]\b|bar\b|lounge|club\b|camping|bed (and|&) breakfast|b&b|parco|park\b|affittacamere|alloggi|appartament|apartment|rooms\b|zimmer|ferienwohnung|residence|^(la |alla |)cantina$)/i;

export function agriCandidate(t) {
  if (!t || !t.name || t.craft === 'winery') return null; // le cantine vere arrivano già da osm-cantine.mjs
  if (NO.test(t.name)) return null;
  const who = [t.name, t.operator, t.brand].filter(Boolean).join(' · ');
  const desc = [t.description, t['description:it'], t['description:en'], t['description:de'], t['description:fr']].filter(Boolean).join(' · ');
  const prod = /wine|grape|vino|wein|\bvin\b|uva/i.test([t.produce, t.product].filter(Boolean).join(' '));
  if (prod || DESC.test(desc)) return 'vino';
  if (/^(bar|pub|cafe|biergarten)$/.test(t.amenity || '')) return null; // un bar chiamato "La Cantina" non è una cantina
  if (STRONG.test(who)) return 'vino';
  if (FARM.test(who) || (AGRI.test(t.name) && (t.amenity || t.tourism))) return 'agri';
  return null;
}
// il candidato entra se è abbastanza vicino a un vigneto: 1 km con parole del vino, 400 m per agriturismi e aziende agricole
export const agriOk = (kind, km) => km != null && (kind === 'vino' ? km <= 1 : km <= 0.4);

// indice dei vigneti (tools/.cache/osm/vigneti.json) → distanza in km dal più vicino
export function vineNear() {
  const f = join(here, '.cache', 'osm', 'vigneti.json'); if (!existsSync(f)) return null;
  const grid = new Map();
  for (const e of JSON.parse(readFileSync(f, 'utf8')).elements) { const k = Math.floor(e.lat * 20) + ':' + Math.floor(e.lon * 20); (grid.get(k) || grid.set(k, []).get(k)).push([e.lat, e.lon]); }
  return (lat, lng) => {
    let best = Infinity; const cl = Math.cos(lat * Math.PI / 180), a = Math.floor(lat * 20), b = Math.floor(lng * 20);
    for (let i = a - 1; i <= a + 1; i++) for (let j = b - 2; j <= b + 2; j++) for (const [y, x] of grid.get(i + ':' + j) || []) {
      const d = Math.hypot((y - lat) * 111.2, (x - lng) * 111.2 * cl); if (d < best) best = d;
    }
    return best;
  };
}

const web = (t) => { const w = t.website || t['contact:website'] || t.url; if (!w) return undefined; return /^https?:\/\//i.test(w) ? w : 'https://' + w; };
const round = (x) => Math.round(x * 1e5) / 1e5;
const cell = (lat, lng) => `${Math.floor(lat / 2.5)}_${Math.floor(lng / 2.5)}`; // come osm-cantine.mjs e app.js

// candidati [id, lat, lng, tags] → quelli che entrano (vicini a un vigneto, non già sul sito entro 150 m)
export function pickAgri(cands, items, near) {
  const g = new Map(); const key = (la, ln) => Math.floor(la * 100) + ':' + Math.floor(ln * 100);
  for (const x of items) (g.get(key(x[1], x[2])) || g.set(key(x[1], x[2]), []).get(key(x[1], x[2]))).push(x);
  const have = new Set(items.map((x) => x[0])), out = [];
  for (const [id, lat, lng, t] of cands) {
    if (have.has(id)) continue;
    const kind = agriCandidate(t); if (!kind) continue;
    const km = near(lat, lng); if (!agriOk(kind, km)) continue;
    let dup = false; const a = Math.floor(lat * 100), b = Math.floor(lng * 100);
    for (let i = a - 1; i <= a + 1 && !dup; i++) for (let j = b - 1; j <= b + 1 && !dup; j++) for (const x of g.get(i + ':' + j) || [])
      if (Math.hypot((x[1] - lat) * 111.2, (x[2] - lng) * 111.2 * Math.cos(lat * Math.PI / 180)) < 0.15) { dup = true; break; }
    if (dup) continue;
    have.add(id); out.push({ id, lat, lng, t, kind, km });
  }
  return out;
}

async function apply() {
  const f = join(here, '.cache', 'osm', 'agri.json'); if (!existsSync(f)) return console.log('agri: manca tools/.cache/osm/agri.json (vigneti-geofabrik.mjs --merge)');
  const near = vineNear(); if (!near) return console.log('agri: mancano i vigneti');
  const { nonProduttore } = await import('./produttori.mjs');
  const dataDir = join(here, '..', 'site', 'data'), cf = join(dataDir, 'cantine.json'), C = JSON.parse(readFileSync(cf, 'utf8'));
  const add = pickAgri(JSON.parse(readFileSync(f, 'utf8')), C.items, near).filter((x) => !nonProduttore(x.id)); // "Togli" dall'admin vale anche qui
  const det = {};
  for (const { id, lat, lng, t } of add) {
    C.items.push([id, round(lat), round(lng), t.name, t['addr:city'] || t['addr:town'] || t['addr:village'] || t['addr:place'] || '']);
    const d = { a: [t['addr:street'], t['addr:housenumber']].filter(Boolean).join(' ') || undefined, p: t.phone || t['contact:phone'] || undefined, w: web(t), o: t.opening_hours || undefined };
    if (d.a || d.p || d.w || d.o) (det[cell(lat, lng)] ||= {})[id] = d;
  }
  C.items.sort((a, b) => a[0].localeCompare(b[0]));
  writeFileSync(cf, JSON.stringify(C));
  for (const [k, v] of Object.entries(det)) { const p = join(dataDir, 'det5', k + '.json'); const o = existsSync(p) ? JSON.parse(readFileSync(p, 'utf8')) : {}; writeFileSync(p, JSON.stringify(Object.assign(o, v))); }
  writeFileSync(join(here, '.cache', 'agri-aggiunte.json'), JSON.stringify(add.map(({ id, t, kind, km }) => [id, t.name, kind, +km.toFixed(2)])));
  console.log(`agri: ${add.length} cantine da agriturismi, ristoranti e fattorie vicini ai vigneti (${add.filter((x) => x.kind === 'vino').length} con parole del vino)`);
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const [cmd, file] = process.argv.slice(2);
  if (cmd === 'apply') await apply();
  else if (cmd === 'test') {
    const near = vineNear(); const els = JSON.parse(readFileSync(file, 'utf8')).elements;
    const cands = els.map((e) => [e.type[0] + e.id, e.lat ?? e.center?.lat, e.lon ?? e.center?.lon, e.tags || {}]).filter((x) => x[1] != null);
    const items = JSON.parse(readFileSync(join(here, '..', 'site', 'data', 'cantine.json'), 'utf8')).items;
    const add = pickAgri(cands, items, near);
    console.log(cands.length, 'posti con nome,', add.length, 'entrano');
    for (const x of add) console.log(x.kind, x.km.toFixed(2), '|', x.t.name, '|', x.t.amenity || x.t.tourism || x.t.shop || x.t.place || x.t.landuse || '', '|', x.t['addr:city'] || '');
  }
}
