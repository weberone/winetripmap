<?php
// Itinerari pubblici sulla mappa (voto da 1 a 3 stelle) (pin d'oro nella loro zona del vino), richiesta dell'utente 2026-11-21.
//   GET  api/trips.php            → { items: [{ id, lat, lng, name, n, r, rc, c, zone? }] }  per tutti (c = itinerari con le stesse tappe); la zona solo agli iscritti
//   GET  api/trips.php?id=12      → { trip: { id, name, zone, stops, r, rc, my, mine } }   solo iscritti (tappe, voto medio, il tuo voto)
//   POST api/trips.php { id, stars: 1-3 } → { r, rc }   voto di un iscritto (non al proprio itinerario)
//   POST api/trips.php { id, copy: true } → { id }      copia nei propri trip
// Mai il nome o l'email di chi l'ha fatto.
require __DIR__ . '/lib.php';
bot_guard('trips', 30); // limite di richieste al minuto per indirizzo e blocco dei robot (api/bots.php)
$u = current_user();
// firma delle tappe: le stesse cantine (in qualunque ordine) = lo stesso itinerario
function trip_sig(string $stops): string { $ids = array_map(fn($x) => (string)($x['id'] ?? ''), json_decode($stops, true) ?: []); sort($ids); return implode(',', $ids); }
// voto di tutti gli itinerari pubblici con le stesse tappe
$rating = function (int $id): array {
  $sig = trip_sig((string)one('SELECT stops FROM trips WHERE id = ?', [$id]));
  $ids = [$id];
  foreach (db()->query('SELECT id, stops FROM trips WHERE pub = 1')->fetchAll() as $x) if ((int)$x['id'] !== $id && trip_sig($x['stops']) === $sig) $ids[] = (int)$x['id'];
  $in = implode(',', array_fill(0, count($ids), '?'));
  $r = db()->prepare("SELECT AVG(stars) a, COUNT(*) n FROM trip_ratings WHERE trip_id IN ($in)"); $r->execute($ids); $x = $r->fetch();
  return ['r' => $x['n'] ? round((float)$x['a'], 1) : null, 'rc' => (int)$x['n'], 'c' => count($ids)];
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
  // itinerari con le stesse tappe (es. salvato da un altro e ripubblicato senza cambiarle) = un solo pin, con il numero di copie (c)
  // e il voto di tutto il gruppo; si apre il più vecchio. Con le tappe cambiate è un itinerario nuovo, con il suo pin
  $rows = db()->query("SELECT id, name, zone, zlat, zlng, stops FROM trips WHERE pub = 1 AND zlat IS NOT NULL ORDER BY id LIMIT 3000")->fetchAll();
  $rt = []; foreach (db()->query('SELECT trip_id, SUM(stars) s, COUNT(*) n FROM trip_ratings GROUP BY trip_id')->fetchAll() as $x) $rt[(int)$x['trip_id']] = [(int)$x['s'], (int)$x['n']];
  $groups = [];
  foreach ($rows as $x) {
    $sig = trip_sig($x['stops']);
    if (!isset($groups[$sig])) $groups[$sig] = ['t' => $x, 'c' => 0, 's' => 0, 'n' => 0];
    $g = &$groups[$sig]; $g['c']++;
    if (isset($rt[(int)$x['id']])) { $g['s'] += $rt[(int)$x['id']][0]; $g['n'] += $rt[(int)$x['id']][1]; }
    unset($g);
  }
  json_out(['items' => array_values(array_map(fn($g) => ['id' => (int)$g['t']['id'], 'lat' => (float)$g['t']['zlat'], 'lng' => (float)$g['t']['zlng'], 'name' => $g['t']['name'],
    'n' => count(json_decode($g['t']['stops'], true) ?: []), 'r' => $g['n'] ? round($g['s'] / $g['n'], 1) : null, 'rc' => $g['n'], 'c' => $g['c']]
    + ($u ? ['zone' => $g['t']['zone'] ?: ''] : []), $groups))]);
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
if ($stars < 1 || $stars > 3) json_out(['error' => 'stars'], 400); // da 1 a 3 stelle (richiesta dell'utente 2026-11-21)
if ((int)$t['user_id'] === (int)$u['id']) json_out(['error' => 'own'], 400);
db()->prepare('INSERT OR REPLACE INTO trip_ratings (trip_id, user_id, stars, created_at) VALUES (?, ?, ?, ?)')->execute([$t['id'], $u['id'], $stars, now()]);
json_out($rating((int)$t['id']));
