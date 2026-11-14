# WineTripMap

Sito winetripmap.com: mappa di cantine e percorsi del vino, con la stessa logica di SkateboardMap. Rispondi in italiano.
Progetto separato da SkateboardMap: lavora solo in questa cartella e nel core comune (`../MAPCORE`), mai in `../SKATEBOARD MAP`.

## Struttura
- `site/` — quello che va online (web root del sito su Infomaniak).
- `site/core` — link simbolico a `../MAPCORE`, il core condiviso con SkateboardMap (leggi `../MAPCORE/CLAUDE.md` prima di toccarlo).

## Hosting
- Infomaniak, stesso hosting di skateboardmap.com ("Hosting Web 1"), sito PHP creato il 2026-11-13 con certificato SSL gratuito.
- La home è ancora la pagina "in arrivo" (`site/index.html`); la mappa è online in prova su `/mappa/` (noindex, non linkata).
- Utente FTP dedicato `5r1oeu_winetrip`, limitato a `/sites/winetripmap.com` (vede solo questo sito; server `5r1oeu.ftp.infomaniak.com`). La password la conosce solo l'utente: va nei GitHub Secrets, mai in chat o nei file.
- Repository GitHub privati: `weberone/winetripmap` (questa cartella) e `weberone/mapcore` (il core).
- Deploy automatico a ogni push su main (`.github/workflows/deploy.yml`, come SkateboardMap): scarica il core con una deploy key in sola lettura, lo copia in `site/core/`, comprime il JS, toglie i commenti e carica solo `site/` via FTPS.
- Secrets del repository winetripmap: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `MAPCORE_DEPLOY_KEY`.
- Una modifica al solo core non va online da sola: serve un push su winetripmap (o rilanciare il workflow a mano).

## Mappa (`site/mappa/`, `site/js/app.js`, `site/css/app.css`)
- Stessa esperienza di SkateboardMap (posizione, raggio, radar che scopre i pin, Cerca in questa zona, più vicine, elenco, scheda, pannello del telefono), con i moduli mappa del core.
- Mappe come SkateboardMap: prima Google finché la quota gratuita del mese regge, poi Mapbox, poi OpenFreeMap (MapLibre, colori passati con `palette`). Sceglie e conta `site/api/counter.php` (tetti Google 9.500 e Mapbox 47.500 al mese, file in `site/counter/`, escluso dal deploy); `createMap` in `app.js` carica il modulo e passa alla successiva se una non parte. Chiavi pubbliche in `MAPS` in cima ad `app.js`: vuote = mappa spenta.
- Solo cantine (`craft=winery`), in tutto il mondo. Enoteche e degustazioni tolte per scelta dell'utente: eventualmente dopo.
- Enoteche, wine bar, negozi e ristoranti segnati in OSM come cantine vengono tolti dal nome (`tools/non-cantine.mjs`, con liste TOGLI/TIENI corrette a mano), a ogni nuovo scarico.
- Dati: `node tools/osm-cantine.mjs` scarica da Overpass (server pubblici spesso pieni: riprova da solo) e scrive `site/data/cantine.json` (tutti i punti, leggeri) e `site/data/det5/<lat10>_<lng10>.json` (dettagli, caricati con la scheda).
- Vini: `node tools/vini-dai-siti.mjs crawl` legge i siti delle cantine (robots.txt rispettato, WineTripMapBot, pochi pagine per sito; riprende da dove era, cache in `tools/.cache/vini/`, non nel repo) e `node tools/vini-dai-siti.mjs build` scrive `tools/data/vini.json` e lo applica ai dati del sito: sesto campo in `cantine.json` = colore del vino principale (r rosso, b bianco, o rosato, s bollicine) per il colore del pin e il filtro; `v`/`vu` in `det5/` = lista dei vini nella scheda. Regole di riconoscimento e scarti in `tools/vini-colori.mjs`. `osm-cantine.mjs` riapplica i vini dopo ogni nuovo scarico. Pin: goccia piena (colore del vino principale, bordeaux se non noto) con il logo del pin-calice, nero se la cantina ha la lista dei vini (settimo campo di `cantine.json` = 1), bianco se no. Serve `npm install` dentro `tools/` (cheerio).
- Vini, fonti in ordine: negozio Shopify, WooCommerce Store API, JSON-LD Product, pagine dei vini (link alle schede, titoli h2–h4 con la foto vicino come elenco; titolo + sottotitolo nella stessa scheda = un vino; su Wix titoli senza foto solo se il nome dice già "vino"). Foto delle bottiglie: terzo campo di `v` in `det5/`, mostrate dal sito della cantina (hotlink con `referrerpolicy=no-referrer`, mai copiate; se non si caricano compare la sagoma). `node tools/vini-dai-siti.mjs page <url>` = cosa trovano le regole in una pagina. `build` non ripete il controllo "sembra un vino" (manca il testo intorno), solo pulizia e scarti.
- Ricerca luoghi: `site/api/geocode.php` (Nominatim con memoria, prima in Italia poi nel mondo; dall'Italia vale subito solo un luogo vero, rank ≤ 25, non un locale con lo stesso nome); in locale senza PHP l'app chiama Nominatim direttamente.
- Prova in locale: `python3 -m http.server 8642` dentro `site/` → http://127.0.0.1:8642/mappa/

## Account e admin (come SkateboardMap, rifatti per WTM)
- PHP + SQLite. Base in `site/api/lib.php`: cartella privata fuori dal sito (`wtm-private/` nella radice dello spazio web, creata da PHP; di riserva `site/private/`, esclusa dal deploy) con `config.json` (sale casuale), `wtm.sqlite`, `admins.json` (id degli amministratori), `settings.json` (Google Client ID, posta SMTP, email per gli avvisi: dalla pagina Impostazioni dell'admin, mai nel repository).
- API: `api/auth.php` (me, register, login, google, logout, verify, resend, forgot, reset, update, export, delete), `api/fav.php` (cantine preferite), `api/trip.php` (trip: itinerari con nome e tappe in ordine, tabella `trips`), `api/feedback.php`.
- Trip nella mappa: "Aggiungi al trip" nella scheda, pulsante trip in alto (pannello con tappe, ordine più corto, vedi sulla mappa, percorso su Google Maps, i miei trip). Bozza in `wtm.trip` nel browser anche senza account; salvare richiede l'accesso. `?trip=<id>` apre un trip salvato, `?trip=draft` la bozza (ritorno dall'accesso). Sulla mappa: `setRoute` (linea) e `setExtras` (numeri) del core.
- Accesso con Google: pronto nel codice, si attiva mettendo il Client ID OAuth (progetto Google Cloud di WTM, origini https://winetripmap.com e https://www.winetripmap.com) in Admin → Impostazioni. Pagina `/account/` (account.js). Nella mappa: pulsante account in alto, "Salva" nella scheda; `a=me&map=1` conta l'apertura per la dashboard (non per robot e amministratori).
- Admin `/admin/`: si entra con l'account del sito se si è in `admins.json`; il primo amministratore si crea dalla pagina admin finché non ce n'è nessuno. Pagine: dashboard, utenti, scheda utente (rendi/togli admin, cancella), aperture, feedback, impostazioni.
- In locale non c'è PHP: account e admin si provano online (senza creare account sul sito pubblico senza chiedere).

## Regole (come SkateboardMap)
- Zero spesa: niente servizi a pagamento per mappe, immagini o geocoding.
- Mai "free / gratis / forever" nei testi pubblici.
- Mai scrivere in chat, nei file o nei commit password, token o chiavi segrete.
