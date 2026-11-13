# WineTripMap

Sito winetripmap.com: mappa di cantine e percorsi del vino, con la stessa logica di SkateboardMap. Rispondi in italiano.
Progetto separato da SkateboardMap: lavora solo in questa cartella e nel core comune (`../MAPCORE`), mai in `../SKATEBOARD MAP`.

## Struttura
- `site/` — quello che va online (web root del sito su Infomaniak).
- `site/core` — link simbolico a `../MAPCORE`, il core condiviso con SkateboardMap (leggi `../MAPCORE/CLAUDE.md` prima di toccarlo).

## Hosting
- Infomaniak, stesso hosting di skateboardmap.com ("Hosting Web 1"), sito PHP creato il 2026-11-13 con certificato SSL gratuito.
- Per ora online c'è solo la pagina "in arrivo" (`site/index.html`).
- Utente FTP dedicato `5r1oeu_winetrip`, limitato a `/sites/winetripmap.com` (vede solo questo sito; server `5r1oeu.ftp.infomaniak.com`). La password la conosce solo l'utente: va nei GitHub Secrets, mai in chat o nei file.
- Repository GitHub privati: `weberone/winetripmap` (questa cartella) e `weberone/mapcore` (il core).
- Deploy automatico a ogni push su main (`.github/workflows/deploy.yml`, come SkateboardMap): scarica il core con una deploy key in sola lettura, lo copia in `site/core/`, comprime il JS, toglie i commenti e carica solo `site/` via FTPS.
- Secrets del repository winetripmap: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `MAPCORE_DEPLOY_KEY`.
- Una modifica al solo core non va online da sola: serve un push su winetripmap (o rilanciare il workflow a mano).

## Regole (come SkateboardMap)
- Zero spesa: niente servizi a pagamento per mappe, immagini o geocoding.
- Mai "free / gratis / forever" nei testi pubblici.
- Mai scrivere in chat, nei file o nei commit password, token o chiavi segrete.
