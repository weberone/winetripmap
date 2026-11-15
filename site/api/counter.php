<?php
// GET api/counter.php?have=google,mapbox[&skip=google] → { use: "google" | "mapbox" | "free" }
// Sceglie la mappa di questa visita, come SkateboardMap: prima Google finché la quota gratuita del mese regge,
// poi Mapbox, poi OpenFreeMap (gratuita, senza limiti). Il sito non paga mai.
// have: le mappe che l'app sa usare (chiavi presenti); skip: quelle già fallite in questa visita (chiave rifiutata, quota finita).
// Ogni risposta "google" o "mapbox" conta un caricamento di mappa del mese (Google: un "map load" di Dynamic Maps; Mapbox: un "map load" web).
// Tetti un po' sotto le soglie gratuite (Google 10.000 al mese, Mapbox 50.000 al mese), per i caricamenti che il contatore non vede.
// GET api/counter.php?api=gtext|gphoto|static&n=1 → { ok: true|false }: le altre chiamate a pagamento oltre la soglia gratuita,
//   contate allo stesso modo e fermate prima (Google Places: ricerca della cantina e foto, 1.000 gratis al mese ciascuna;
//   Mapbox Static Images: immagine satellitare della scheda). ok=false = niente chiamata, la scheda resta senza.
// GET api/counter.php?stats → i conteggi del mese (solo numeri, nessun dato dei visitatori).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const CAPS = ['google' => 9500, 'mapbox' => 47500];
const API_CAPS = ['gtext' => 900, 'gphoto' => 900, 'static' => 4700];

// contatori fuori dal deploy (counter/** escluso in .github/workflows/deploy.yml) e non leggibili dal web
$dir = dirname(__DIR__) . '/counter';
if (!is_dir($dir)) { @mkdir($dir, 0750, true); @file_put_contents("$dir/.htaccess", "Require all denied\n"); }
$file = "$dir/maps-" . gmdate('Y-m') . '.json';

$fh = @fopen($file, 'c+');
if (!$fh) { echo json_encode(['use' => 'free']); exit; } // senza contatore non si rischia: mappa gratuita
flock($fh, LOCK_EX);
$n = json_decode((string)stream_get_contents($fh), true) ?: [];

if (isset($_GET['stats'])) { flock($fh, LOCK_UN); fclose($fh); echo json_encode(['month' => gmdate('Y-m'), 'caps' => CAPS + API_CAPS] + $n); exit; }
if (isset($_GET['api'])) {
  $k = (string)$_GET['api']; $add = max(1, min(6, (int)($_GET['n'] ?? 1)));
  $ok = isset(API_CAPS[$k]) && ($n[$k] ?? 0) + $add <= API_CAPS[$k];
  if ($ok) { $n[$k] = ($n[$k] ?? 0) + $add; ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($n)); fflush($fh); }
  flock($fh, LOCK_UN); fclose($fh);
  echo json_encode(['ok' => $ok]); exit;
}

$list = fn($k) => array_filter(explode(',', strtolower((string)($_GET[$k] ?? ''))));
$have = $list('have'); $skip = $list('skip');
$use = 'free';
foreach (array_keys(CAPS) as $p) {
  if (in_array($p, $have, true) && !in_array($p, $skip, true) && ($n[$p] ?? 0) < CAPS[$p]) { $use = $p; break; }
}
$n[$use] = ($n[$use] ?? 0) + 1;
ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($n)); fflush($fh);
flock($fh, LOCK_UN); fclose($fh);
echo json_encode(['use' => $use]);
