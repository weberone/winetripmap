<?php
// Cantine preferite dell'account.
//   GET  api/fav.php                                   → { items: [{ id, name, town, lat, lng }] }
//   POST api/fav.php { id, on, name?, town?, lat?, lng? } → aggiunge (on: true) o toglie (on: false)
require __DIR__ . '/lib.php';
$u = current_user();
if (!$u) json_out(['error' => 'not_logged_in'], 401);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $st = db()->prepare('SELECT winery AS id, name, town, lat, lng FROM favorites WHERE user_id = ? ORDER BY created_at DESC'); $st->execute([$u['id']]);
  json_out(['items' => array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'town' => $r['town'], 'lat' => $r['lat'] !== null ? (float)$r['lat'] : null, 'lng' => $r['lng'] !== null ? (float)$r['lng'] : null], $st->fetchAll())]);
}
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) json_out(['error' => 'content_type'], 415);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$id = (string)($in['id'] ?? '');
if (!preg_match('/^[nwrq]\d{1,15}$/', $id)) json_out(['error' => 'id'], 400);
if (empty($in['on'])) {
  db()->prepare('DELETE FROM favorites WHERE user_id = ? AND winery = ?')->execute([$u['id'], $id]);
} else {
  if ((int)one('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$u['id']]) >= 2000) json_out(['error' => 'too_many'], 429);
  $num = fn($k, $lim) => is_numeric($in[$k] ?? null) && abs((float)$in[$k]) <= $lim ? (float)$in[$k] : null;
  db()->prepare('INSERT OR REPLACE INTO favorites (user_id, winery, name, town, lat, lng, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
    $u['id'], $id, mb_substr(trim((string)($in['name'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($in['town'] ?? '')), 0, 80) ?: null, $num('lat', 90), $num('lng', 180), now()]);
}
json_out(['ok' => true]);
