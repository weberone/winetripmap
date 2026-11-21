<?php
// Itinerari (trip) dell'account: tappe in ordine, con un nome.
//   GET  api/trip.php                       → { items: [{ id, name, n, updated_at }] }   (i più recenti prima)
//   GET  api/trip.php?id=12                 → { trip: { id, name, stops: [{ id, n, c, lat, lng }] } }
//   POST api/trip.php { id?, name, stops, zone?, zlat?, zlng?, pub? }  → { id }   (senza id: nuovo trip; con id: lo aggiorna; pub: pin d'oro sulla mappa per tutti)
//   POST api/trip.php { id, del: true }     → { ok }
require __DIR__ . '/lib.php';
$u = current_user();
if (!$u) json_out(['error' => 'not_logged_in'], 401);
const TRIP_MAX = 300, STOP_MAX = 60;
$mine = function (int $id) use ($u) {
  $st = db()->prepare('SELECT * FROM trips WHERE id = ? AND user_id = ?'); $st->execute([$id, $u['id']]);
  return $st->fetch() ?: null;
};
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (isset($_GET['id'])) {
    $t = $mine((int)$_GET['id']);
    if (!$t) json_out(['error' => 'not_found'], 404);
    json_out(['trip' => ['id' => (int)$t['id'], 'name' => $t['name'], 'stops' => json_decode($t['stops'], true) ?: [], 'pub' => (int)($t['pub'] ?? 0)]]);
  }
  $st = db()->prepare('SELECT id, name, stops, updated_at FROM trips WHERE user_id = ? ORDER BY updated_at DESC'); $st->execute([$u['id']]);
  json_out(['items' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'n' => count(json_decode($r['stops'], true) ?: []), 'updated_at' => $r['updated_at']], $st->fetchAll())]);
}
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) json_out(['error' => 'content_type'], 415);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$id = (int)($in['id'] ?? 0);
if ($id && !$mine($id)) json_out(['error' => 'not_found'], 404);
if (!empty($in['del'])) {
  db()->prepare('DELETE FROM trips WHERE id = ? AND user_id = ?')->execute([$id, $u['id']]);
  db()->prepare('DELETE FROM trip_ratings WHERE trip_id = ?')->execute([$id]);
  json_out(['ok' => true]);
}
$name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? ''))), 0, 80);
if ($name === '') json_out(['error' => 'name'], 400);
$stops = [];
foreach (array_slice(is_array($in['stops'] ?? null) ? $in['stops'] : [], 0, STOP_MAX) as $s) {
  $sid = (string)($s['id'] ?? '');
  if (!preg_match('/^([nwrq]\d{1,15}|o[0-9a-f]{12})$/', $sid) || !is_numeric($s['lat'] ?? null) || !is_numeric($s['lng'] ?? null)) continue;
  $lat = (float)$s['lat']; $lng = (float)$s['lng'];
  if (abs($lat) > 90 || abs($lng) > 180) continue;
  $stops[] = ['id' => $sid, 'n' => mb_substr(trim((string)($s['n'] ?? '')), 0, 120), 'c' => mb_substr(trim((string)($s['c'] ?? '')), 0, 80), 'lat' => round($lat, 6), 'lng' => round($lng, 6)];
}
$json = json_encode($stops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
// zona del vino (dalla mappa) e pin d'oro per tutti: solo se pubblico e con almeno 2 tappe
$zone = mb_substr(trim((string)($in['zone'] ?? '')), 0, 120) ?: null;
$zl = fn($k, $lim) => is_numeric($in[$k] ?? null) && abs((float)$in[$k]) <= $lim ? round((float)$in[$k], 5) : null;
$pub = !empty($in['pub']) && count($stops) >= 2 ? 1 : 0;
if ($id) {
  db()->prepare('UPDATE trips SET name = ?, stops = ?, zone = ?, zlat = ?, zlng = ?, pub = ?, updated_at = ? WHERE id = ? AND user_id = ?')
    ->execute([$name, $json, $zone, $zl('zlat', 90), $zl('zlng', 180), $pub, now(), $id, $u['id']]);
} else {
  if ((int)one('SELECT COUNT(*) FROM trips WHERE user_id = ?', [$u['id']]) >= TRIP_MAX) json_out(['error' => 'too_many'], 429);
  db()->prepare('INSERT INTO trips (user_id, name, stops, zone, zlat, zlng, pub, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$u['id'], $name, $json, $zone, $zl('zlat', 90), $zl('zlng', 180), $pub, now(), now()]);
  $id = (int)db()->lastInsertId();
}
json_out(['id' => $id]);
