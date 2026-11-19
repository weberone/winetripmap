<?php
// Statistiche della mappa per l'admin (come api/track.php di SkateboardMap), chiamate da js/app.js ogni pochi secondi e alla chiusura:
//   POST api/track.php {
//     "e": ["btn:meBtn", "gps:ok", "view:winery", …],   totali anonimi per giorno (app_events): nome dell'evento e ospite/iscritto
//     "o": 123, "a": 14, "gps": 1, "ck": 1,              l'apertura di api/auth.php?a=me&map=1: secondi con la mappa davanti,
//                                                         GPS (1 attivato, 0 negato o non riuscito), scelta sui cookie (1/0)
//     "sid": "…", "j": [[ms, "view:winery", "Nome"], …], "s": {schermo, lingua…}   percorso azione per azione (user_events):
//   }                                                     solo per gli iscritti e per chi ha accettato i cookie (la mappa manda "j" solo allora)
// Il banner dei cookie (js/consent.js) manda {"e": ["consent:yes"]} anche dalle altre pagine; le pagine SEO {"e": ["page:seo"]}.
// Gli amministratori (account in admins.json o browser con il cookie wtm_ns dell'admin) e i robot non contano.
require __DIR__ . '/lib.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
$u = current_user();
if (is_bot() || is_admin($u) || ($_COOKIE['wtm_ns'] ?? '') === '1') json_out(['ok' => true]);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 60000) json_out(['error' => 'size'], 413);
$in = json_decode($raw, true) ?: [];
$EV = '/^[a-z]{2,12}:[A-Za-z0-9_.\/=-]{1,60}$/';
$evs = array_slice(array_values(array_filter((array)($in['e'] ?? []), fn($e) => is_string($e) && preg_match($EV, $e))), 0, 50);
$vid = (string)($_COOKIE['wtm_vid'] ?? '');
if (!preg_match('/^[a-f0-9]{24}$/', $vid)) $vid = '';
$uid = $u ? (int)$u['id'] : 0;
$now = time();
$db = db();
$db->beginTransaction();
try {
  if ($evs) {
    $st = $db->prepare('INSERT INTO app_events (day, ev, who, n) VALUES (?, ?, ?, 1) ON CONFLICT(day, ev, who) DO UPDATE SET n = n + 1');
    foreach ($evs as $e) $st->execute([today_it(), $e, $u ? 'iscritto' : 'ospite']);
  }
  // l'apertura: solo la propria (stesso cookie wtm_vid), solo nelle 24 ore dopo
  $o = (int)($in['o'] ?? 0);
  if ($o > 0 && $vid !== '') {
    $set = ['secs = secs + ?', 'acts = acts + ?', 'views = views + ?', 'last_at = ?'];
    $p = [max(0, min(120, (int)($in['a'] ?? 0))), count(array_filter($evs, fn($e) => !str_starts_with($e, 'app:'))),
      count(array_filter($evs, fn($e) => $e === 'view:winery')), gmdate('Y-m-d H:i:s', $now)];
    // GPS: "attivato" vince su un "negato" di prima (chi riprova e ci riesce); cookie: l'ultima scelta
    if (in_array($in['gps'] ?? null, [0, 1], true)) { $set[] = 'gps = MAX(COALESCE(gps, 0), ?)'; $p[] = $in['gps']; }
    if (in_array($in['ck'] ?? null, [0, 1], true)) { $set[] = 'ck = ?'; $p[] = $in['ck']; }
    $p[] = $o; $p[] = $vid; $p[] = gmdate('Y-m-d H:i:s', $now - 86400);
    $db->prepare('UPDATE app_opens SET ' . implode(', ', $set) . ' WHERE id = ? AND vid = ? AND at > ?')->execute($p);
  }
  // percorso: iscritti, o visitatori con il cookie wtm_vid che hanno accettato i cookie (la mappa manda "j" solo allora)
  $sid = (string)($in['sid'] ?? '');
  if (($u || $vid !== '') && preg_match('/^[a-z0-9]{8,40}$/', $sid) && is_array($in['j'] ?? null)) {
    $first = null; $n = 0;
    $st = $db->prepare('INSERT INTO user_events (sid, user_id, vid, at, ev, x) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (array_slice($in['j'], 0, 150) as $r) {
      if (!is_array($r) || !is_string($r[1] ?? null) || !preg_match($EV, $r[1])) continue;
      $t = (int)(((float)($r[0] ?? 0)) / 1000); if (abs($t - $now) > 86400) $t = $now; // orologio del telefono sballato: ora del server
      $x = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string)($r[2] ?? '')));
      $st->execute([$sid, $uid, $vid ?: null, gmdate('Y-m-d H:i:s', $t), $r[1], $x === '' ? null : mb_substr($x, 0, 160)]);
      $first = $first === null ? $t : min($first, $t); $n++;
    }
    $act = max(0, min(120, (int)($in['a'] ?? 0)));
    if ($n || $act) {
      // dispositivo e schermo: solo alla prima chiamata della sessione, solo valori semplici e corti
      $info = null;
      if (is_array($in['s'] ?? null)) {
        $keep = [];
        foreach (['w', 'h', 'vw', 'vh', 'dpr', 'lang', 'ui', 'tz', 'pwa', 'net', 'touch', 'dark', 'map', 'gps', 'ck', 'land', 'ref'] as $k)
          if (isset($in['s'][$k]) && is_scalar($in['s'][$k])) $keep[$k] = is_string($in['s'][$k]) ? mb_substr($in['s'][$k], 0, 120) : $in['s'][$k];
        $info = json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }
      $dev = $info ? device_info((string)($_SERVER['HTTP_USER_AGENT'] ?? '')) : null;
      $db->prepare('INSERT INTO user_sessions (sid, user_id, vid, started_at, last_at, events, active_secs, device, info) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(sid) DO UPDATE SET last_at = MAX(last_at, excluded.last_at), events = events + excluded.events, active_secs = active_secs + excluded.active_secs,
          device = COALESCE(excluded.device, device), info = COALESCE(excluded.info, info)
        WHERE user_id = excluded.user_id AND COALESCE(vid, \'\') = COALESCE(excluded.vid, \'\')')
        ->execute([$sid, $uid, $vid ?: null, gmdate('Y-m-d H:i:s', $first ?? $now), gmdate('Y-m-d H:i:s', $now), $n, $act, $dev, $info]);
    }
  }
  // pulizia ogni tanto: percorsi degli iscritti più vecchi di 180 giorni, dei visitatori di 90
  if (random_int(1, 300) === 1) {
    foreach (['user_id > 0' => 180, 'user_id = 0' => 90] as $w => $d) {
      $old = gmdate('Y-m-d H:i:s', $now - $d * 86400);
      $db->prepare("DELETE FROM user_events WHERE $w AND at < ?")->execute([$old]);
      $db->prepare("DELETE FROM user_sessions WHERE $w AND last_at < ?")->execute([$old]);
    }
  }
  $db->commit();
} catch (Throwable $e) { $db->rollBack(); error_log('track: ' . $e->getMessage()); }
json_out(['ok' => true]);
