<?php
// Account degli utenti, in JSON (come api/auth.php di SkateboardMap). Funzioni in api/lib.php.
//   GET  api/auth.php?a=me[&map=1&tz=…&ref=…&utm=…&m=…&ck=…&land=…&sid=…] → { user, google_client_id, open }; map=1 = apertura della mappa (dashboard admin)
//   POST api/auth.php?a=register  { email, password, name }        → account creato, email di conferma, già dentro
//   POST api/auth.php?a=login     { email, password }              → { user }
//   POST api/auth.php?a=google    { credential }                   → { user } (crea l'account se non c'è)
//   POST api/auth.php?a=logout
//   POST api/auth.php?a=verify    { token }                        → email confermata
//   POST api/auth.php?a=resend                                     → nuova email di conferma
//   POST api/auth.php?a=forgot    { email }                        → email con il link per la nuova password (risposta sempre ok)
//   POST api/auth.php?a=reset     { token, password }              → password cambiata, dentro
//   POST api/auth.php?a=update    { name, password_current, password_new } → profilo aggiornato
//   GET  api/auth.php?a=export                                     → tutti i dati dell'utente (diritto di accesso, GDPR)
//   POST api/auth.php?a=delete    { confirm: "DELETE" }            → account cancellato
require __DIR__ . '/lib.php';
require __DIR__ . '/geoip.php';
require __DIR__ . '/visit_notify.php';
header('Cache-Control: no-store');

$a = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) json_out(['error' => 'content_type'], 415); // anti-CSRF
$in = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
$str = fn($k, $max = 200) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
$lang = preg_match('/^it\b/i', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'it')) ? 'it' : 'en';
$pwOk = fn(string $p) => mb_strlen($p) >= 8 && mb_strlen($p) <= 200;
$me = current_user();
$need = function () use ($me) { if (!$me) json_out(['error' => 'not_logged_in'], 401); return $me; };

if ($a === 'me' && $method === 'GET') {
  // apertura della mappa: una riga in app_opens (non per i robot né per gli amministratori)
  if (!empty($_GET['map']) && is_admin($me)) mark_admin_device(); // l'amministratore collegato: questo browser non conta più, anche senza accesso
  if (!empty($_GET['map']) && !is_bot() && !is_admin($me) && !is_admin_device()) {
    try {
      $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? ''); $ref = mb_substr((string)($_GET['ref'] ?? ''), 0, 300);
      $map = preg_match('/^(google|mapbox|free)$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : null;
      // ck: scelta sui cookie già fatta (1/0, vuoto = non ancora); land: pagina d'ingresso (home, ?c= cantina condivisa, ?trip=…)
      $ck = in_array($_GET['ck'] ?? '', ['0', '1'], true) ? (int)$_GET['ck'] : null;
      $land = (string)($_GET['land'] ?? ''); $land = $land === '' ? null : mb_substr(preg_replace('/[\x00-\x1f]/', '', $land), 0, 120);
      $sid = preg_match('/^[a-z0-9]{8,40}$/', (string)($_GET['sid'] ?? '')) ? $_GET['sid'] : null;
      // luogo approssimato dall'IP (api/geoip.php, database DB-IP nella cartella privata): si salvano solo città, regione e paese
      $geo = geo_lookup((string)($_SERVER['REMOTE_ADDR'] ?? '')); geo_refresh();
      $row = ['vid' => visitor_id(), 'source' => traffic_source($ref, $ua, (string)($_GET['utm'] ?? '')),
        'ref' => mb_substr((string)parse_url($ref, PHP_URL_HOST), 0, 80) ?: null, 'device' => device_info($ua),
        'lang' => substr(strtolower(explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))[0]), 0, 10) ?: null, 'land' => $land, 'sid' => $sid];
      db()->prepare('INSERT INTO app_opens (at, day, vid, user_id, source, ref, device, lang, tz, map, ck, land, sid, last_at, city, region, cc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        now(), today_it(), $row['vid'], $me ? (int)$me['id'] : null, $row['source'], $row['ref'], $row['device'], $row['lang'],
        preg_match('/^[A-Za-z_\/+-]{1,40}$/', (string)($_GET['tz'] ?? '')) ? $_GET['tz'] : null, $map, $ck, $land, $sid, now(),
        mb_substr($geo['city'] ?? '', 0, 80) ?: null, mb_substr($geo['region'] ?? '', 0, 80) ?: null, ($geo['cc'] ?? '') ?: null]);
      $openId = (int)db()->lastInsertId();
      notify_visit($me, $row + ['id' => $openId], $geo); // notifica push all'admin (api/visit_notify.php)
      if ($me) db()->prepare('UPDATE users SET visit_count = visit_count + 1, last_seen_at = ? WHERE id = ?')->execute([now(), $me['id']]);
    } catch (Throwable $e) { error_log('opens: ' . $e->getMessage()); }
  }
  // open: numero dell'apertura, che la mappa rimanda ad api/track.php (GPS, cookie, tempo, cantine aperte)
  json_out(['user' => $me ? public_user($me) : null, 'google_client_id' => google_client_id() ?: null, 'open' => $openId ?? null]);
}
if ($a === 'export' && $method === 'GET') {
  $u = $need();
  $fav = db()->prepare('SELECT winery, name, town, lat, lng, created_at FROM favorites WHERE user_id = ? ORDER BY created_at'); $fav->execute([$u['id']]);
  $vis = db()->prepare('SELECT winery, name, town, lat, lng, created_at FROM visits WHERE user_id = ? ORDER BY created_at'); $vis->execute([$u['id']]);
  $fb = db()->prepare('SELECT kind, message, created_at FROM feedback WHERE user_id = ? ORDER BY id'); $fb->execute([$u['id']]);
  header('Content-Disposition: attachment; filename="winetripmap-account.json"');
  $tr = db()->prepare('SELECT name, stops, created_at, updated_at FROM trips WHERE user_id = ? ORDER BY created_at'); $tr->execute([$u['id']]);
  $trips = array_map(fn($r) => ['stops' => json_decode($r['stops'], true)] + $r, $tr->fetchAll());
  json_out(['account' => public_user($u) + ['last_login_at' => $u['last_login_at']], 'favorites' => $fav->fetchAll(), 'visits' => $vis->fetchAll(), 'trips' => $trips, 'feedback' => $fb->fetchAll()]);
}
if ($method !== 'POST') json_out(['error' => 'method'], 405);

switch ($a) {
  case 'register':
    if (!attempt_ok('register:' . ip_hash(), 5, 60)) json_out(['error' => 'too_many'], 429);
    $email = mb_strtolower($str('email', 190)); $pw = (string)($in['password'] ?? ''); $name = $str('name', 60);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'email'], 400);
    if (!$pwOk($pw)) json_out(['error' => 'password'], 400);
    $st = db()->prepare('SELECT * FROM users WHERE email = ?'); $st->execute([$email]);
    if ($old = $st->fetch()) json_out(['error' => 'exists', 'google' => (bool)$old['google_sub']], 409);
    db()->prepare('INSERT INTO users (email, pass_hash, name, lang, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$email, password_hash($pw, PASSWORD_DEFAULT), $name ?: null, $lang, now()]);
    $u = user_by_id((int)db()->lastInsertId());
    $sent = send_verify_mail($u, $lang);
    notify_admin('nuovo utente', "Email: {$u['email']}\nNome: " . ($u['name'] ?: '—') . "\nAccesso: email e password\nIscritti: " . one('SELECT COUNT(*) FROM users'));
    login_user($u);
    json_out(['user' => public_user($u), 'mail_sent' => $sent]);

  case 'login':
    if (!attempt_ok('login:' . ip_hash(), 10, 15)) json_out(['error' => 'too_many'], 429);
    $st = db()->prepare('SELECT * FROM users WHERE email = ?'); $st->execute([mb_strtolower($str('email', 190))]);
    $u = $st->fetch();
    if (!$u || !$u['pass_hash'] || !password_verify((string)($in['password'] ?? ''), $u['pass_hash'])) {
      // account solo Google: lo si dice, così non si cerca una password che non c'è
      if ($u && !$u['pass_hash'] && $u['google_sub']) json_out(['error' => 'google_only'], 401);
      json_out(['error' => 'wrong'], 401);
    }
    if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash((string)$in['password'], PASSWORD_DEFAULT), $u['id']]);
    login_user($u);
    json_out(['user' => public_user($u)]);

  case 'google':
    if (!attempt_ok('google:' . ip_hash(), 20, 15)) json_out(['error' => 'too_many'], 429);
    $g = google_verify($str('credential', 4000));
    if (!$g) json_out(['error' => 'google'], 401);
    $st = db()->prepare('SELECT * FROM users WHERE google_sub = ? OR email = ? ORDER BY google_sub IS NULL LIMIT 1'); $st->execute([$g['sub'], $g['email']]);
    $u = $st->fetch(); $existed = (bool)$u;
    if ($u) {
      // stessa email già iscritta con la password: si collega Google (Google ha verificato l'indirizzo)
      db()->prepare('UPDATE users SET google_sub = ?, email_verified_at = COALESCE(email_verified_at, ?), name = COALESCE(name, ?) WHERE id = ?')->execute([$g['sub'], now(), $g['name'] ?: null, $u['id']]);
    } else {
      db()->prepare('INSERT INTO users (email, google_sub, name, lang, email_verified_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$g['email'], $g['sub'], $g['name'] ?: null, $lang, now(), now()]);
      notify_admin('nuovo utente', "Email: {$g['email']}\nNome: " . ($g['name'] ?: '—') . "\nAccesso: Google\nIscritti: " . one('SELECT COUNT(*) FROM users'));
    }
    $u = user_by_id($u ? (int)$u['id'] : (int)db()->lastInsertId());
    login_user($u);
    json_out(['user' => public_user($u), 'new' => !$existed]);

  case 'logout':
    logout_user();
    json_out(['ok' => true]);

  case 'verify':
    $u = token_user($str('token', 100), 'verify', true);
    if (!$u) json_out(['error' => 'token'], 400);
    db()->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?')->execute([now(), $u['id']]);
    if (!$me) login_user($u);
    json_out(['user' => public_user(user_by_id((int)$u['id']))]);

  case 'resend':
    $u = $need();
    if ($u['email_verified_at']) json_out(['ok' => true]);
    if (!attempt_ok('resend:' . $u['id'], 3, 60)) json_out(['error' => 'too_many'], 429);
    json_out(['ok' => send_verify_mail($u, $lang)]);

  case 'forgot':
    if (!attempt_ok('forgot:' . ip_hash(), 5, 60)) json_out(['error' => 'too_many'], 429);
    $st = db()->prepare('SELECT * FROM users WHERE email = ?'); $st->execute([mb_strtolower($str('email', 190))]);
    if ($u = $st->fetch()) send_reset_mail($u, $lang);
    json_out(['ok' => true, 'mail' => mail_ready()]); // sempre ok: non si dice se l'email è iscritta

  case 'reset':
    $pw = (string)($in['password'] ?? '');
    if (!$pwOk($pw)) json_out(['error' => 'password'], 400);
    $u = token_user($str('token', 100), 'reset', true);
    if (!$u) json_out(['error' => 'token'], 400);
    // nuova password: via tutte le sessioni aperte (anche quelle di chi l'aveva rubata), email confermata (il link è arrivato lì)
    db()->prepare("DELETE FROM user_tokens WHERE user_id = ? AND kind IN ('session', 'reset')")->execute([$u['id']]);
    db()->prepare('UPDATE users SET pass_hash = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), now(), $u['id']]);
    login_user($u);
    json_out(['user' => public_user(user_by_id((int)$u['id']))]);

  case 'update':
    $u = $need();
    if (array_key_exists('name', $in)) db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$str('name', 60) ?: null, $u['id']]);
    $new = (string)($in['password_new'] ?? '');
    if ($new !== '') {
      if (!$pwOk($new)) json_out(['error' => 'password'], 400);
      // chi ha già una password deve dare quella attuale; chi è entrato solo con Google la aggiunge
      if ($u['pass_hash'] && !password_verify((string)($in['password_current'] ?? ''), $u['pass_hash'])) json_out(['error' => 'wrong'], 401);
      db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    }
    json_out(['user' => public_user(user_by_id((int)$u['id']))]);

  case 'delete':
    $u = $need();
    if (($in['confirm'] ?? '') !== 'DELETE') json_out(['error' => 'confirm'], 400);
    foreach (['user_tokens', 'favorites', 'visits', 'trips', 'feedback'] as $t) db()->prepare("DELETE FROM $t WHERE user_id = ?")->execute([$u['id']]);
    db()->prepare('UPDATE app_opens SET user_id = NULL WHERE user_id = ?')->execute([$u['id']]);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]);
    admin_set((int)$u['id'], false);
    logout_user();
    json_out(['ok' => true]);
}
json_out(['error' => 'action'], 400);
