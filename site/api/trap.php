<?php
// Trappola per robot: /wineries-archive-all/ (riscritto qui da .htaccess). Il link è nascosto alle persone (index.html e pagine
// SEO) e vietato in robots.txt: lo apre solo un programma che legge tutti i link senza rispettare le regole.
// Chi lo apre viene bloccato per 24 ore su API e pagine pubbliche (api/bots.php: ban_ip); i motori di ricerca mai.
require __DIR__ . '/lib.php';
if (!good_bot() && !wtm_test()) { ban_ip('trappola', 24); bot_stat('bot:trap'); }
header('X-Robots-Tag: noindex, nofollow');
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
