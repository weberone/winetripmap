# WineTripMap

Sito winetripmap.com: mappa di cantine e percorsi del vino, con la stessa logica di SkateboardMap. Rispondi in italiano.
Progetto separato da SkateboardMap: lavora solo in questa cartella e nel core comune (`../MAPCORE`), mai in `../SKATEBOARD MAP`.

## Struttura
- `site/` — quello che va online (web root del sito su Infomaniak).
- `site/core` — link simbolico a `../MAPCORE`, il core condiviso con SkateboardMap (leggi `../MAPCORE/CLAUDE.md` prima di toccarlo).

## Hosting
- Infomaniak, stesso hosting di skateboardmap.com ("Hosting Web 1"), sito PHP creato il 2026-11-13 con certificato SSL gratuito.
- Per ora online c'è solo la pagina "in arrivo" (`site/index.html`).
- Deploy automatico come SkateboardMap (GitHub Action FTPS) da impostare quando c'è il repository GitHub.

## Regole (come SkateboardMap)
- Zero spesa: niente servizi a pagamento per mappe, immagini o geocoding.
- Mai "free / gratis / forever" nei testi pubblici.
- Mai scrivere in chat, nei file o nei commit password, token o chiavi segrete.
