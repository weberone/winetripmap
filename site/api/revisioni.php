<?php
// Scelte fatte a mano nell'admin sulle cantine dubbie (admin/revisione.php): { "t": [id da togliere], "k": [id da tenere] }.
// Le legge ogni giorno il workflow .github/workflows/revisioni.yml, che toglie dalla mappa quelle da togliere.
// Sono solo codici OpenStreetMap delle cantine (chi resta sulla mappa si vede comunque): nessun dato personale.
require __DIR__ . '/lib.php';
header('Cache-Control: no-store');
$out = ['t' => [], 'k' => []];
foreach (db()->query('SELECT id, d FROM revisioni ORDER BY id') as $r) if (isset($out[$r['d']])) $out[$r['d']][] = $r['id'];
json_out($out);
