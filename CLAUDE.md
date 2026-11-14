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
- Dati: `node tools/osm-cantine.mjs` scarica da Overpass (server pubblici spesso pieni: riprova da solo) e scrive `site/data/cantine.json` (tutti i punti, leggeri) e `site/data/det/<lat10>_<lng10>.json` (dettagli, caricati con la scheda).
- Vini: `node tools/vini-dai-siti.mjs crawl` legge i siti delle cantine (robots.txt rispettato, WineTripMapBot, pochi pagine per sito; riprende da dove era, cache in `tools/.cache/vini/`, non nel repo) e `node tools/vini-dai-siti.mjs build` scrive `tools/data/vini.json` e lo applica ai dati del sito: sesto campo in `cantine.json` = colore del vino principale (r rosso, b bianco, o rosato, s bollicine) per il colore del pin e il filtro; `v`/`vu` in `det/` = lista dei vini nella scheda. Regole di riconoscimento e scarti in `tools/vini-colori.mjs`. `osm-cantine.mjs` riapplica i vini dopo ogni nuovo scarico. Pin senza vini noti: chiaro col bordo bordeaux. Serve `npm install` dentro `tools/` (cheerio).
- Ricerca luoghi: `site/api/geocode.php` (Nominatim con memoria, prima in Italia poi nel mondo; dall'Italia vale subito solo un luogo vero, rank ≤ 25, non un locale con lo stesso nome); in locale senza PHP l'app chiama Nominatim direttamente.
- Prova in locale: `python3 -m http.server 8642` dentro `site/` → http://127.0.0.1:8642/mappa/

## Regole (come SkateboardMap)
- Zero spesa: niente servizi a pagamento per mappe, immagini o geocoding.
- Mai "free / gratis / forever" nei testi pubblici.
- Mai scrivere in chat, nei file o nei commit password, token o chiavi segrete.
