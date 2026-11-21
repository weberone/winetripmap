<?php
// Itinerari pubblici sulla mappa (pin d'oro nella loro zona del vino), richiesta dell'utente 2026-11-21.
//   GET  api/trips.php            → { items: [{ id, lat, lng, name, n, r, rc, zone? }] }  per tutti; la zona solo agli iscritti
//   GET  api/trips.php?id=12      → { trip: { id, name, zone, stops, r, rc, my, mine } }   solo iscritti (tappe, voto medio, il tuo voto)
//   POST api/trips.php { id, stars: 1-5 } → { r, rc }   voto di un iscritto (non al proprio itinerario)
//   POST api/trips.php { id, copy: true } → { id }      copia nei propri trip
// Mai il nome o l'email di chi l'ha fatto.
require __DIR__ . '/lib.php';
$u = current_user();
$rating = function (int $id): array {
  $r = db()->prepare('SELECT AVG(stars) a, COUNT(*) n FROM trip_ratings WHERE trip_id = ?'); $r->execute([$id]); $x = $r->fetch();
  return ['r' => $x['n'] ? round((float)$x['a'], 1) : null, 'rc' => (int)$x['n']];
};
$pubTrip = function (int $id) use ($u) {
  $st = db()->prepare('SELECT * FROM trips WHERE id = ? AND (pub = 1 OR user_id = ?)'); $st->execute([$id, $u ? $u['id'] : -1]);
  return $st->fetch() ?: null;
};
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (isset($_GET['id'])) {
    if (!$u) json_out(['error' => 'not_logged_in'], 401);
    $t = $pubTrip((int)$_GET['id']); if (!$t) json_out(['error' => 'not_found'], 404);
    $my = one('SELECT stars FROM trip_ratings WHERE trip_id = ? AND user_id = ?', [$t['id'], $u['id']]);
    json_out(['trip' => ['id' => (int)$t['id'], 'name' => $t['name'], 'zone' => $t['zone'], 'stops' => json_decode($t['stops'], true) ?: [],
      'my' => $my !== null && $my !== false ? (int)$my : null, 'mine' => (int)$t['user_id'] === (int)$u['id']] + $rating((int)$t['id'])]);
  }
  header('Cache-Control: private, max-age=60');
  $rows = db()->query("SELECT t.id, t.name, t.zone, t.zlat, t.zlng, t.stops, (SELECT AVG(stars) FROM trip_ratings WHERE trip_id = t.id) r,
    (SELECT COUNT(*) FROM trip_ratings WHERE trip_id = t.id) rc FROM trips t WHERE t.pub = 1 AND t.zlat IS NOT NULL ORDER BY t.updated_at DESC LIMIT 2000")->fetchAll();
  // a tutti: punto del pin, titolo dato da chi l'ha salvato, numero di tappe e voto medio (richiesta dell'utente 2026-11-21); la zona agli iscritti
  json_out(['items' => array_map(fn($x) => ['id' => (int)$x['id'], 'lat' => (float)$x['zlat'], 'lng' => (float)$x['zlng'], 'name' => $x['name'],
    'n' => count(json_decode($x['stops'], true) ?: []), 'r' => $x['rc'] ? round((float)$x['r'], 1) : null, 'rc' => (int)$x['rc']]
    + ($u ? ['zone' => $x['zone'] ?: ''] : []), $rows)]);
}
if (!$u) json_out(['error' => 'not_logged_in'], 401);
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) json_out(['error' => 'content_type'], 415);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$t = $pubTrip((int)($in['id'] ?? 0)); if (!$t) json_out(['error' => 'not_found'], 404);
if (!empty($in['copy'])) {
  if ((int)one('SELECT COUNT(*) FROM trips WHERE user_id = ?', [$u['id']]) >= 300) json_out(['error' => 'too_many'], 429);
  db()->prepare('INSERT INTO trips (user_id, name, stops, zone, zlat, zlng, pub, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)')
    ->execute([$u['id'], $t['name'], $t['stops'], $t['zone'], $t['zlat'], $t['zlng'], now(), now()]);
  json_out(['id' => (int)db()->lastInsertId()]);
}
$stars = (int)($in['stars'] ?? 0);
if ($stars < 1 || $stars > 5) json_out(['error' => 'stars'], 400);
if ((int)$t['user_id'] === (int)$u['id']) json_out(['error' => 'own'], 400);
db()->prepare('INSERT OR REPLACE INTO trip_ratings (trip_id, user_id, stars, created_at) VALUES (?, ?, ?, ?)')->execute([$t['id'], $u['id'], $stars, now()]);
json_out($rating((int)$t['id']));
