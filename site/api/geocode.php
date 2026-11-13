<?php
// GET api/geocode.php?q=...&lang=it[&cc=it] → { name, full, lat, lng } oppure { error: "not_found" } (404)
// cc: cerca solo in quel paese (l'app prova prima l'Italia: "Alba" è Alba in Piemonte, non Alba in Francia)
// Ricerca di un luogo con Nominatim di OpenStreetMap: gratuita. Regole di Nominatim (come in SkateboardMap):
// al massimo una richiesta al secondo (lock condiviso), user agent riconoscibile, solo all'invio, risultati tenuti 30 giorni.
header('Content-Type: application/json; charset=utf-8');
function out(array $r, int $code = 200): never { http_response_code($code); echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }

$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 120));
if (mb_strlen($q) < 2) out(['error' => 'q'], 400);
$lang = preg_match('/^[a-z]{2}$/', (string)($_GET['lang'] ?? '')) ? $_GET['lang'] : 'it';
$cc = preg_match('/^[a-z]{2}$/', (string)($_GET['cc'] ?? '')) ? $_GET['cc'] : '';
$f = sys_get_temp_dir() . '/wtm-geo-' . md5(mb_strtolower($q) . "|$lang|$cc");
if (is_file($f) && filemtime($f) > time() - 30 * 86400) {
  $r = json_decode((string)file_get_contents($f), true);
} else {
  $lock = fopen(sys_get_temp_dir() . '/wtm-nominatim.lock', 'c+');
  flock($lock, LOCK_EX);
  $wait = 1.1 - (microtime(true) - (float)stream_get_contents($lock));
  if ($wait > 0) usleep((int)($wait * 1e6));
  $ch = curl_init('https://nominatim.openstreetmap.org/search?' . http_build_query(['q' => $q, 'format' => 'jsonv2', 'limit' => 1, 'accept-language' => $lang] + ($cc ? ['countrycodes' => $cc] : [])));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'WineTripMap/1.0 (+https://winetripmap.com)']);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  ftruncate($lock, 0); rewind($lock); fwrite($lock, (string)microtime(true)); flock($lock, LOCK_UN); fclose($lock);
  if ($code !== 200) out(['error' => 'lookup'], 502);
  $x = (json_decode((string)$res, true) ?: [])[0] ?? null;
  $r = $x ? ['name' => $x['name'] ?: $q, 'full' => $x['display_name'] ?? '', 'lat' => (float)$x['lat'], 'lng' => (float)$x['lon']] : ['error' => 'not_found'];
  @file_put_contents($f, json_encode($r));
}
header('Cache-Control: public, max-age=86400');
out($r, isset($r['error']) ? 404 : 200);
