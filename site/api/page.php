<?php
// Testa e barra in alto delle pagine account/ e admin/: stesso logo della mappa (site/index.html; se cambia lì, cambia qui),
// stesso tema chiaro/scuro scelto nella mappa. Stile in /css/pages.css.
const WTM_WORDMARK = '<span class="wm">wine<b>tr<span class="wi">ı<svg class="wpin" viewBox="0 0 12 16" aria-hidden="true"><path d="M1 5.5A5 5 0 0 1 11 5.5C11 8.9 8.4 11.3 6.6 12V14.3H8.6C9.1 14.3 9.4 14.6 9.4 14.95S9.1 15.6 8.6 15.6H3.4C2.9 15.6 2.6 15.3 2.6 14.95S2.9 14.3 3.4 14.3H5.4V12C3.6 11.3 1 8.9 1 5.5Z"/><circle cx="6" cy="5.5" r="1.75" fill="#fff"/></svg></span>p</b>map</span>';
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function page_start(string $title, string $right = '', string $logoHref = '/', array $extraHead = []): void {
  header('X-Robots-Tag: noindex');
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
    . '<title>' . h($title) . ' · WineTripMap</title><meta name="robots" content="noindex">'
    // tema prima di disegnare (niente lampo): quello scelto nella mappa, altrimenti quello del sistema
    . '<script>try{const t=JSON.parse(localStorage.getItem("wtm.theme"));const d=document.documentElement;d.dataset.scheme=t==="light"||t==="dark"?t:(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light")}catch(e){}</script>'
    . '<meta name="theme-color" content="#8e1b33"><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="/apple-touch-icon.png"><link rel="stylesheet" href="/fonts/fonts.css"><link rel="stylesheet" href="/css/pages.css?v=4"><script src="/js/consent.js?v=1"></script>'
    . implode('', $extraHead) . '</head><body>'
    . '<header class="topbar"><a class="logo" href="' . h($logoHref) . '" aria-label="WineTripMap">' . WTM_WORDMARK . '</a><div class="topbar-r">' . $right . '</div></header>';
}
