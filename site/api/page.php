<?php
// Testa e barra in alto delle pagine account/ e admin/: stesso logo della mappa (site/index.html; se cambia lì, cambia qui),
// stesso tema chiaro/scuro scelto nella mappa. Stile in /css/pages.css.
const WTM_WORDMARK = '<span class="wm">wine<b>tr<svg class="wi" viewBox="0 0 12 22" role="img" aria-label="i"><path fill-rule="evenodd" d="M1 5.5A5 5 0 0 1 11 5.5C11 8.5 9 10.8 7.65 11.7V20H9.3C9.75 20 10.1 20.4 10.1 20.8S9.75 21.6 9.3 21.6H2.7C2.25 21.6 1.9 21.2 1.9 20.8S2.25 20 2.7 20H4.35V11.7C3 10.8 1 8.5 1 5.5ZM7.8 5.5A1.8 1.8 0 1 0 4.2 5.5A1.8 1.8 0 1 0 7.8 5.5Z"/></svg>p</b>map</span>';
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function page_start(string $title, string $right = '', string $logoHref = '/', array $extraHead = []): void {
  header('X-Robots-Tag: noindex');
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
    . '<title>' . h($title) . ' · WineTripMap</title><meta name="robots" content="noindex">'
    // tema prima di disegnare (niente lampo): quello scelto nella mappa, altrimenti quello del sistema
    . '<script>try{const t=JSON.parse(localStorage.getItem("wtm.theme"));const d=document.documentElement;d.dataset.scheme=t==="light"||t==="dark"?t:(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light")}catch(e){}</script>'
    . '<meta name="theme-color" content="#8e1b33"><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="/apple-touch-icon.png"><link rel="manifest" href="/manifest.webmanifest"><link rel="stylesheet" href="/fonts/fonts.css"><link rel="stylesheet" href="/css/pages.css?v=7"><script src="/js/consent.js?v=2"></script>'
    . implode('', $extraHead) . '</head><body>'
    . '<header class="topbar"><a class="logo" href="' . h($logoHref) . '" aria-label="WineTripMap">' . WTM_WORDMARK . '</a><div class="topbar-r">' . $right . '</div></header>';
}
