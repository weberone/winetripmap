// Riconoscere un vino e il suo colore dal nome (e dal testo intorno): r rosso, b bianco, o rosato, s bollicine.
// Parole del colore (rosso, white, blanc…) contano più dei vitigni e delle denominazioni; le bollicine vincono su tutto
// (un Franciacorta Rosé è prima di tutto bollicine).

const norm = (s) => ' ' + String(s || '').toLowerCase().replace(/ß/g, 'ss').normalize('NFD').replace(/[̀-ͯ]/g, '')
  .replace(/[’'`´]/g, ' ').replace(/[^a-z0-9]+/g, ' ').trim() + ' ';
const words = (list) => new RegExp(' (' + list.split('|').map((w) => w.trim().replace(/ +/g, ' ')).join('|') + ') ');

// frasi che cambiano il senso delle parole sciolte: vanno tolte prima
const FIX = [
  [/ cabernet sauvignon /g, ' cabernet '], [/ cabernet franc /g, ' cabernet '], [/ sauvignon blanc /g, ' sauvignon '],
  [/ pinot (grigio|gris|bianco|blanc) /g, ' pinotbianco '], [/ pinot (nero|noir) /g, ' pinotnero '],
  [/ grenache blanc /g, ' grenacheblanc '], [/ blanc de noirs /g, ' blancdenoirs '], [/ blanc de blancs /g, ' blancdeblancs '],
  [/ vino bianco /g, ' bianco '], [/ vino rosso /g, ' rosso '], [/ red wine /g, ' red '], [/ white wine /g, ' white '],
  [/ moscato d asti /g, ' moscatodasti '], [/ terre di rosso /g, ' '], [/ rosso conero /g, ' rosso '],
];

const SPARK = words(`spumante|spumanti|prosecco|franciacorta|champagne|cremant|sekt|metodo classico|methode traditionnelle|methode champenoise|
  brut|extra dry|extra brut|pas dose|dosaggio zero|dosage zero|sparkling|pet nat|petnat|petillant|frizzante|trento doc|trentodoc|alta langa|
  cap classique|espumante|secco|prickelnd|perlwein|petillant naturel|col fondo|perlage|bollicine|blancdenoirs|blancdeblancs|moscatodasti|asti spumante|oltrepo pavese metodo classico|
  durello|lessini durello|cartizze|valdobbiadene|conegliano|spritz`);
const ROSE = words(`rosato|rosati|rose|rosado|cerasuolo|chiaretto|blush|ramato|vin gris|kretzer|weissherbst|schilcher|rotling|schillerwein`);
const RED_W = words(`rosso|rossi|red|reds|rouge|rot|rotwein|rotweine|tinto|tintos|rosu|crno|vermelho|nero|crianza|negru|neagra|feteasca neagra`);
const WHITE_W = words(`bianco|bianchi|white|whites|blanc|blancs|weiss|weisswein|weissweine|blanco|blancos|branco|bijelo|bily|alb`);
const RED_G = words(`nebbiolo|barolo|barbaresco|dolcetto|barbera|freisa|grignolino|ruche|pelaverga|sangiovese|chianti|brunello|vino nobile|
  morellino|bolgheri|montepulciano|aglianico|taurasi|primitivo|negroamaro|nero d avola|nerello|frappato|cannonau|sagrantino|lagrein|teroldego|
  schiava|marzemino|refosco|schioppettino|pignolo|valpolicella|amarone|ripasso|recioto|bardolino|corvina|raboso|cabernet|merlot|malbec|syrah|
  shiraz|pinotnero|spatburgunder|blauburgunder|zweigelt|blaufrankisch|lemberger|tempranillo|rioja|ribera|garnacha|grenache|mencia|monastrell|
  mourvedre|carignan|carignano|zinfandel|petite sirah|gamay|beaujolais|medoc|pomerol|saint emilion|chateauneuf|carmenere|pinotage|tannat|
  touriga|saperavi|kadarka|plavac|mavrud|xinomavro|agiorgitiko|cesanese|ciliegiolo|gaglioppo|ciro|susumaniello|bonarda|croatina|sforzato|
  sfursat|valtellina|ghemme|gattinara|lessona|carema|rossese|ormeasco|vespolina|lacrima|lambrusco|sangue di giuda|buttafuoco|nero di troia|
  tintilia|piedirosso|casavecchia|pallagrello nero|magliocco|perricone|nocera|carricante rosso|mammolo|canaiolo|alicante|cinsault|dornfelder|
  trollinger|portugieser|regent|cabernet dorsa|cabernet mitos|acolon|domina|blauer|st laurent|sankt laurent|nero buono|uva rara|ruche|quagliano|brachetto|grasparossa|sorbara|salamino|primitivo di manduria`);
const WHITE_G = words(`chardonnay|sauvignon|riesling|pinotbianco|weissburgunder|grauburgunder|muller thurgau|gewurztraminer|traminer|gruner veltliner|
  gruner|veltliner|silvaner|sylvaner|kerner|chenin|viognier|marsanne|roussanne|semillon|albarino|alvarinho|verdejo|godello|vermentino|verdicchio|
  soave|garganega|trebbiano|lugana|turbiana|fiano|greco|falanghina|coda di volpe|arneis|cortese|gavi|timorasso|favorita|erbaluce|nascetta|
  pigato|ribolla|friulano|malvasia|vitovska|pecorino|passerina|grechetto|orvieto|vernaccia|ansonica|inzolia|insolia|grillo|catarratto|carricante|
  zibibbo|moscato|muscat|moscatel|muskateller|torrontes|assyrtiko|furmint|tokaji|chablis|sancerre|pouilly|muscadet|vinho verde|txakoli|picpoul|
  nosiola|manzoni|bombino bianco|verdeca|minutolo|vespaiola|bellone|petite arvine|chasselas|fendant|grenacheblanc|welschriesling|scheurebe|
  bacchus|ortega|rivaner|gemischter satz|auxerois|gutedel|elbling|huxelrebe|ortega|seyval|vidal|solaris|johanniter|souvignier|roter veltliner|rotgipfler|zierfandler|neuburger|sauvignonasse|picolit|verduzzo|
  durella|biancolella|forastera|asprinio|pallagrello bianco|caprettone|nuragus|nasco|semidano|vernaccia di oristano|albana|pignoletto|bianchello|
  maceratino|cococciola|montonico|trebbiano d abruzzo|frascati|est est est|lacryma christi bianco|kerner|traminer aromatico`);
const WINE_HINT = words(`vino|vini|wine|wines|wein|weine|vin|vins|vinos|vinho|doc|docg|igt|igp|dop|aoc|aop|ava|qba|pradikat|kabinett|spatlese|auslese|
  riserva|reserva|reserve|gran reserva|crianza|cuvee|superiore|classico|millesimato|vendemmia|vintage|passito|vin santo|sauternes|porto|madeira|
  marsala|sherry|jerez|eiswein|late harvest`);
const NOT_WINE = words(`olio|oil|olive|grappa|gin|vodka|whisky|whiskey|rum|brandy|cognac|liquore|liquori|liqueur|amaro|birra|beer|cider|sidro|aceto|
  vinegar|miele|honey|marmellata|jam|confettura|pasta|cioccolato|chocolate|t shirt|tshirt|maglietta|felpa|hoodie|cappello|cap|grembiule|apron|
  bicchiere|bicchieri|glass|glasses|calice|calici|cavatappi|corkscrew|decanter|gift card|giftcard|buono regalo|voucher|gutschein|carte cadeau|
  abbonamento|subscription|wine club|club|membership|tour|visita|visit|degustazione|tasting|experience|esperienza|evento|event|corso|course|
  cassetta|cassette|confezione|confezioni|box|kit|pack|set|mix|assortimento|selection box|degustazione box|spedizione|shipping|carrello|cart|
  checkout|account|login|contatti|contact|chi siamo|about|home|news|blog|privacy|cookie|shop|negozio|store|catalogo|scopri|discover|leggi|read more|
  vedi|view|acquista|buy|add to cart|aggiungi|more|tutti|all|filtra|filter|ordina|sort|prodotti|products|nuovo|new|offerte|sale|saldi|sconto|
  agriturismo|camere|rooms|ristorante|restaurant|dettagli|details|scheda tecnica|technical sheet|download|pdf|magnum box|regalo|gift|newsletter|
  i nostri vini|our wines|unsere weine|nos vins|nuestros vinos|vini rossi|vini bianchi|red wines|white wines|rose wines|sparkling wines|
  linea|line|collezione|collection|gamma|range|annata|annate|vintages|etichette|labels|torna|back|indietro|next|prev|precedente|successivo`);

export function classify(text) {
  let s = norm(text); for (const [re, to] of FIX) s = s.replace(re, to);
  const sc = { s: 0, o: 0, r: 0, b: 0 };
  if (SPARK.test(s)) sc.s += 4;
  if (ROSE.test(s)) sc.o += 3;
  if (RED_W.test(s)) sc.r += 3;
  if (WHITE_W.test(s)) sc.b += 3;
  if (RED_G.test(s)) sc.r += 1;
  if (WHITE_G.test(s)) sc.b += 1;
  const best = Object.entries(sc).sort((a, b) => b[1] - a[1])[0];
  if (!best[1]) return null;
  const tie = Object.values(sc).filter((v) => v === best[1]).length > 1;
  return tie ? null : best[0];
}
export function looksLikeWine(text) { const s = norm(text); return WINE_HINT.test(s) || / (19[5-9]\d|20[0-3]\d) /.test(s); }
// nome intero che è una voce di menu o un prodotto che non è vino (controllo sul solo nome, non sulla descrizione)
const GENERIC = new Set(`vino vini wine wines weine wein vins vin vinos vinhos vinuri i il lo la le les gli die der das the our nos nuestros unsere
  nostri nostro i nostri e and und et y de di del della dei des of von rosso rossi bianco bianchi rosato rosati spumante spumanti bollicine red reds white whites
  rose roses sparkling rouge rouges blanc blancs rot weiss weissweine rotweine roseweine rotwein weisswein schaumwein schaumweine sussweine suss sekt
  tinto tintos blanco blancos rosado rosados espumosos dolci dolce dessert sweet linea line selezione selection classici classic premium riserve
  alle tutti all altri other 1 2 3 4 5 6`.split(/\s+/));
const JUNK_SUB = /(this link|scroll|section|mulled|spritz|dinner|cena |release party|custodia|secchiello|stopper|cofanetto|cosmetic|navigation|uberspringen|^ (eng|ita|deu|ger|fra|esp|en|it|de|fr|es) $|traubenbrand|edelbrand|branntwein|weinbrand|tresterbrand|likor|gelee|balsam|vinagre|aceto|jugo|curry|marmelad|cordial|rocky road|kuvano|mixdoos|scatola|paquete|enoturismo|unlabelled|gutsch| \d{2} ?°|°|% ?vol|bon cadeau|cheque cadeau|carte cadeau|accedi|registrati|iscriviti|anmelden|connexion|decouvrir|gluhwein|gluehwein|scegli|choose|auswahl|seleziona|paket|kiste|probier|gutschein|geschenk|degustationsbox|weinprobe|coffret|bundle|confezione|cassetta|selezione regalo)/;
const MONTH_DATE = / (\d{1,2} )?(gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre|january|february|march|april|june|july|august|september|october|november|december|janvier|fevrier|mars|avril|mai|juin|juillet|aout|septembre|octobre|novembre|decembre|januar|februar|marz|juni|juli|oktober|dezember)( \d{1,2})? /;
const JUNK_WORDS = words(`olio|oil|grappa|gin|birra|beer|biere|ale|lager|cidre|cider|sidro|calvados|pommeau|vermouth|vermut|quetsch|mirabelle|schnaps|likor|eau de vie|
    gluhwein|gluhwolle|weinschorle|schorle|jus de raisin|jus|verjus|alkoholfrei|alcohol free|sans alcool|analcolico|non wine|bestseller|highlights|
    unsere besten|wein des monats|en savoir plus|lire la suite|commander|ordina|read more|leggi tutto|mehr erfahren|weiterlesen|ver mas|saber mas|
    coupon|caja|dozen|bundle|abo|geschenk|geschenkset|actividades|rutas|escapada|colegios|medaille|medal|concours|award|selection|selezione|
    glas|universalglas|polo|shirt|shirts|sleeve|jacket|tote|hat|hats|beanie|case|cases|pack|packs|mixed dozen|half dozen|kirsch|poire williams|framboise|marc de|eau-de-vie|bulles|bubbles|kisten|weinkisten|sangria|traubensaft|saft|juice|succo|mosto|gift card|voucher|buono regalo|t shirt|cassetta|box|kit|abbonamento|degustazione|degustation|tasting|tour|visite|visita|open day|black friday|cyber monday|pachet|paket|pacchetto|package|auszeichnungen|awards|premi|newsletter|recensioni|reviews|weinprobe|cata|catas|cadou`);
const generic = (s) => s.trim().split(' ').every((w) => !w || GENERIC.has(w));
const PLURAL = / (champagner|sekte|weine|wines|vini|vins|vinos|vinhos|spumanti|passiti|rossi|bianchi|rotweine|weissweine) /;
export function isNotWine(name) {
  if (/°|%\s*vol|<\s*0[.,]\d\s*%/i.test(name)) return true; // gradi e "% vol": distillati e vini senza alcol
  const s = norm(name);
  if (generic(s)) return true;
  // categorie del menu al plurale ("Weißweine für Genießer", "Spumanti e passiti") se non c'è un vitigno o una denominazione
  if (PLURAL.test(s) && !(RED_G.test(s) || WHITE_G.test(s) || / (doc|docg|igt|aoc|igp|dop) /.test(s))) return true;
  if (NOT_WINE.test(s) && !(RED_G.test(s) || WHITE_G.test(s) || SPARK.test(s) || / (rosso|bianco|rosato|red|white|rose) /.test(s))) return true;
  if (JUNK_SUB.test(s) || MONTH_DATE.test(s)) return true;
  return JUNK_WORDS.test(s);
}
const ENT = { amp: '&', quot: '"', apos: "'", lt: '<', gt: '>', nbsp: ' ', rsquo: '’', lsquo: '‘', rdquo: '”', ldquo: '“', ndash: '–', mdash: '—', hellip: '…', laquo: '«', raquo: '»', bdquo: '„', eacute: 'é', egrave: 'è', agrave: 'à', ograve: 'ò', ugrave: 'ù', igrave: 'ì', uuml: 'ü', ouml: 'ö', auml: 'ä', szlig: 'ß', ccedil: 'ç' };
export const decode = (s) => String(s || '').replace(/&#x([0-9a-f]+);/gi, (_, h) => String.fromCodePoint(parseInt(h, 16))).replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(+d)).replace(/&([a-z]+);/gi, (m, k) => ENT[k.toLowerCase()] ?? m);
export function cleanName(n) {
  let s = decode(n).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  // testi di elementi diversi incollati senza spazio ("SaffrediIGT", "20232023Vino rosso") e prefissi dei link ("Lier vers : …")
  s = s.replace(/((?:19|20)\d\d)(?=(?:19|20)\d\d)/g, '$1 ').replace(/((?:19|20)\d\d)(?=[A-ZÀ-Ý])/g, '$1 ').replace(/([a-zà-ÿ])([A-ZÀ-Ý]{2,})/g, '$1 $2')
    .replace(/^(artikel-?nr\.?|art\.?-?nr\.?|sku|cod\.?|codice)\s*:?\s*[\w\-]+\s*/i, '').replace(/\s*(shop)?\s*=+\s*$/i, '').replace(/\s+(shop|kaufen|acquista|buy now|buy)$/i, '').replace(/(\D)0[.,]75\b/g, '$1 ')
    .replace(/(?<!\p{L})(scopri il vino|scopri di più|scopri|entdecken|vedi il prodotto|vedi prodotto|view product|voir le produit|voir le vin|zum produkt|mehr erfahren|chf|épuisé|epuise|ausverkauft|esaurito|sold out|out of stock)(?!\p{L})/giu, ' ')
    .replace(/^(Den|Die|Das)\s+(?=\p{Lu})/u, '')
    .replace(/^(lier vers|link to|go to|vai a|vai al|zum produkt|zum wein|voir|scopri|découvrir|decouvrir|discover|weiterlesen|mehr zu|ver)\s*[:\-–]?\s*/i, '')
    .replace(/\s*[|]\s*/g, ' ');
  s = s.replace(/\b(magnum|jeroboam|double magnum|bib|bag[- ]in[- ]box|en[- ]primeur|halbe flasche|mezza bottiglia|half bottle)\b/gi, ' ').replace(/\b\d+([.,]\d+)?\s*l\b/gi, ' ');
  s = s.replace(/\s*[-–—|·:]\s*(\d+([.,]\d+)?\s*(cl|ml|l|lt|litri|liter|litre)s?\b.*)$/i, '')
    .replace(/\b\d+([.,]\d+)?\s*(cl|ml|lt|litri|liter|litre)\b\.?/gi, '').replace(/\b0[.,]75\s*l\b/gi, '')
    .replace(/(€|eur|\$|£)\s*\d+([.,]\d+)?|\d+([.,]\d+)?\s*(€|eur)/gi, '').replace(/\b\d+[.,]\d{2}\b/g, '')
    .replace(/\b(19[5-9]\d|20[0-3]\d)(er)?\b/g, '') // l'annata: un vino solo, non uno per anno
    .replace(/\bartikel-?nr\.?\s*[\w\-]+/gi, '').replace(/\(\s*[=,;\s]*\)/g, '').replace(/,\s*\)/g, ')').replace(/\bNV\b/gi, '')
    .replace(/\(\s*\)|\[\s*\]/g, '').replace(/\s+/g, ' ').replace(/^[\s\-–—|·:,.>»›→<«‹←*]+|[\s\-–—|·:,>»›→<«‹←*]+$/g, '').trim();
  s = s.replace(/(\s+(de|di|del|della|du|des|of|von|vom|la|le|et|e|and|und|y|&|\.))+$/i, '').trim();
  if (s.length < 3 || s.length > 70) return '';
  if (!/[a-z]/i.test(s)) return '';
  if (s.split(' ').length > 9) return '';
  if (s === s.toUpperCase() && s.length > 40) return '';
  // parole lunghe in maiuscolo dentro un nome misto ("Solalto IGT TOSCANA BIANCO") → iniziali maiuscole
  if (s !== s.toUpperCase()) {
    s = s.replace(/\b(\p{Lu}{4,})\b/gu, (w) => /^(DOCG|DOCA)$/.test(w) ? w : w[0] + w.slice(1).toLowerCase())
      .replace(/(?<=\s)(\p{Lu}{2,3})\b/gu, (w) => /^(DOC|IGT|IGP|DOP|AOC|AOP|AVA|QBA|GG|VDP|BIO|IGP|VQA|DO|DOQ|DAC|XO|VSQ|AC)$/.test(w) ? w : /^(DI|DE|DEL|DES|DU|LA|LE|IL|LO|DA|EL|Y|ET|E|AND|OF|THE)$/.test(w) ? w.toLowerCase() : w[0] + w.slice(1).toLowerCase());
    // "Morellino di Scansano Riserva Morellino di Scansano Riserva DOCG": la seconda volta via
    const t = s.split(' '); for (let L = Math.floor(t.length / 2); L >= 2; L--) for (let i = 0; i + 2 * L <= t.length; i++)
      if (t.slice(i, i + L).join(' ').toLowerCase() === t.slice(i + L, i + 2 * L).join(' ').toLowerCase()) { t.splice(i + L, L); s = t.join(' '); L = 0; break; }
  }
  // TUTTO MAIUSCOLO → iniziali maiuscole (DOC, DOCG, IGT… restano)
  if (s === s.toUpperCase() && /[A-Z]{4}/.test(s)) s = s.toLowerCase().replace(/(^|[\s'’(\-])(\p{L})/gu, (m, a, b) => a + b.toUpperCase()).replace(/\b(Docg|Doc|Igt|Igp|Dop|Aoc|Aop|Ava|Qba)\b/g, (m) => m.toUpperCase());
  return s;
}
// il colore della cantina: quello con più vini; a pari merito l'ordine rosso, bianco, bollicine, rosato
export function mainColor(cs) {
  const n = { r: 0, b: 0, s: 0, o: 0 }; for (const c of cs) if (c in n) n[c]++;
  const best = Object.entries(n).sort((a, b) => b[1] - a[1])[0];
  return best[1] ? best[0] : '';
}
