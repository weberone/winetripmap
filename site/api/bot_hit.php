<?php
// Aperture fermate prima della mappa (js/app.js: cnGate, computer col fuso della Cina arrivati senza link): solo un numero al
// giorno (app_events, bot:cn), senza righe nelle aperture, per pesare il meno possibile sul server durante le ondate di robot.
require __DIR__ . '/lib.php';
bot_guard('bothit', 30);
if ($_SERVER['REQUEST_METHOD'] === 'POST') bot_stat('bot:cn');
http_response_code(204);
