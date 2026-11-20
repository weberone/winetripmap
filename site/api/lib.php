<?php
// Base comune di account, admin e API di winetripmap.com (rifatta sul modello di SkateboardMap):
// cartella privata, database SQLite, sessioni, gettoni, email (SMTP), accesso con Google, limiti anti-abuso.
//
// Sicurezza (come SkateboardMap):
//   - password solo come hash (password_hash); gettoni casuali di 32 byte, nel database solo il loro sha256
//   - sessione in un cookie HttpOnly + Secure + SameSite=Lax
//   - le richieste che cambiano qualcosa vogliono Content-Type JSON: un altro sito non può inviarle con un semplice form (CSRF)
//   - tentativi limitati per IP; risposte uguali per email esistenti e non (nessuno scopre chi è iscritto)
declare(strict_types=1);

const SITE_URL = 'https://winetripmap.com';
const SESSION_COOKIE = 'wtm_session';
const SESSION_DAYS = 90;

// ---------- cartella privata ----------
// Fuori dal sito, nella radice dello spazio web (come skatespot-private di SkateboardMap): wtm-private/.
// Se PHP non può crearla lì, si usa site/private/ (chiusa al web da .htaccess, esclusa dal deploy).
// Dentro: config.json (sale casuale, creato da solo), wtm.sqlite (database), admins.json (id degli amministratori),
// settings.json (Google e posta, dalla pagina Impostazioni dell'admin), features.json.
function priv(string $file = ''): string {
  static $dir = null;
  if ($dir === null) {
    foreach ([dirname(__DIR__, 3) . '/wtm-private', dirname(__DIR__) . '/private'] as $d) {
      if (is_dir($d) || @mkdir($d, 0700, true)) {
        if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\n");
        if (is_writable($d)) { $dir = $d; break; }
      }
    }
    if ($dir === null) { http_response_code(500); exit('private dir'); }
  }
  return $file === '' ? $dir : "$dir/$file";
}
function jread(string $file): array { return json_decode((string)@file_get_contents(priv($file)), true) ?: []; }
function jwrite(string $file, array $data): void { file_put_contents(priv($file), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); @chmod(priv($file), 0600); }

function cfg(): array {
  static $c = null;
  if ($c !== null) return $c;
  $c = jread('config.json');
  if (empty($c['ip_salt'])) { $c['ip_salt'] = bin2hex(random_bytes(24)); jwrite('config.json', $c); }
  return $c;
}
// impostazioni scelte dall'admin (admin/settings.php): Google client ID (pubblico), posta (SMTP)
function settings(): array { return jread('settings.json'); }

// ---------- database ----------
const SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  email             TEXT NOT NULL UNIQUE COLLATE NOCASE,
  pass_hash         TEXT,
  google_sub        TEXT UNIQUE,
  name              TEXT,
  lang              TEXT,
  email_verified_at TEXT,
  created_at        TEXT NOT NULL,
  last_login_at     TEXT,
  last_seen_at      TEXT,
  visit_count       INTEGER NOT NULL DEFAULT 0
);
-- gettoni: session (accesso), verify (conferma email), reset (nuova password). Solo l'hash del gettone
CREATE TABLE IF NOT EXISTS user_tokens (
  token_hash  TEXT PRIMARY KEY,
  user_id     INTEGER NOT NULL,
  kind        TEXT NOT NULL,
  expires_at  TEXT NOT NULL,
  created_at  TEXT NOT NULL,
  user_agent  TEXT
);
CREATE INDEX IF NOT EXISTS user_tokens_user ON user_tokens (user_id, kind);
-- cantine preferite (id OSM come nella mappa: n123, w456), con nome e comune per mostrarle senza caricare i dati
CREATE TABLE IF NOT EXISTS favorites (
  user_id    INTEGER NOT NULL,
  winery     TEXT NOT NULL,
  name       TEXT,
  town       TEXT,
  lat        REAL,
  lng        REAL,
  created_at TEXT NOT NULL,
  PRIMARY KEY (user_id, winery)
);
-- itinerari degli iscritti (api/trip.php): nome e tappe in ordine, JSON [{ id, n, c, lat, lng }]
CREATE TABLE IF NOT EXISTS trips (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  name       TEXT NOT NULL,
  stops      TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS trips_user ON trips (user_id, updated_at);
-- consigli e malfunzionamenti mandati dagli iscritti (admin/feedback.php)
CREATE TABLE IF NOT EXISTS feedback (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL,
  kind        TEXT NOT NULL,
  message     TEXT NOT NULL,
  context     TEXT,
  status      TEXT NOT NULL DEFAULT 'new',
  created_at  TEXT NOT NULL
);
-- ogni apertura della mappa (api/auth.php?a=me&map=1): dashboard dell'admin. vid = cookie casuale del browser, nessun dato personale
CREATE TABLE IF NOT EXISTS app_opens (
  id       INTEGER PRIMARY KEY,
  at       TEXT NOT NULL,
  day      TEXT NOT NULL,
  vid      TEXT,
  user_id  INTEGER,
  source   TEXT,
  ref      TEXT,
  device   TEXT,
  lang     TEXT,
  tz       TEXT,
  map      TEXT
);
CREATE INDEX IF NOT EXISTS app_opens_day ON app_opens (day, id);
CREATE INDEX IF NOT EXISTS app_opens_vid ON app_opens (vid);
-- clic e azioni nella mappa (api/track.php): solo totali per giorno, nome dell'evento e ospite/iscritto, nessun identificativo
CREATE TABLE IF NOT EXISTS app_events (
  day  TEXT NOT NULL,
  ev   TEXT NOT NULL,
  who  TEXT NOT NULL,
  n    INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (day, ev, who)
);
-- percorso nell'app azione per azione (admin/visite.php): solo iscritti e visitatori che hanno accettato i cookie.
-- Una sessione = una scheda del browser; si cancellano dopo 180 giorni (iscritti) o 90 (visitatori)
CREATE TABLE IF NOT EXISTS user_sessions (
  sid         TEXT PRIMARY KEY,
  user_id     INTEGER NOT NULL DEFAULT 0,
  vid         TEXT,
  started_at  TEXT NOT NULL,
  last_at     TEXT NOT NULL,
  events      INTEGER NOT NULL DEFAULT 0,
  active_secs INTEGER NOT NULL DEFAULT 0,
  device      TEXT,
  info        TEXT
);
CREATE INDEX IF NOT EXISTS user_sessions_last ON user_sessions (last_at);
CREATE INDEX IF NOT EXISTS user_sessions_vid ON user_sessions (vid);
CREATE TABLE IF NOT EXISTS user_events (
  id      INTEGER PRIMARY KEY,
  sid     TEXT NOT NULL,
  user_id INTEGER NOT NULL DEFAULT 0,
  vid     TEXT,
  at      TEXT NOT NULL,
  ev      TEXT NOT NULL,
  x       TEXT
);
CREATE INDEX IF NOT EXISTS user_events_sid ON user_events (sid, at);
-- scelte a mano sulle cantine dubbie (admin/revisione.php): d = 't' togli, 'k' tieni; il workflow revisioni.yml le applica ai dati
CREATE TABLE IF NOT EXISTS revisioni (
  id TEXT PRIMARY KEY,
  d TEXT NOT NULL,
  at TEXT NOT NULL,
  by_user INTEGER
);
SQL;
// colonne aggiunte dopo (le tabelle già create non le prendono da CREATE TABLE): [tabella, colonna, tipo]
const SCHEMA_ADD = [
  // per ogni apertura (api/track.php): GPS (1 attivato, 0 negato o non riuscito), cookie (1 accetta, 0 rifiuta), secondi con la
  // mappa davanti, azioni, cantine aperte, pagina d'ingresso (home, cantina condivisa, trip…), scheda del browser (sessione)
  ['app_opens', 'gps', 'INTEGER'], ['app_opens', 'ck', 'INTEGER'], ['app_opens', 'secs', 'INTEGER NOT NULL DEFAULT 0'],
  ['app_opens', 'acts', 'INTEGER NOT NULL DEFAULT 0'], ['app_opens', 'views', 'INTEGER NOT NULL DEFAULT 0'],
  ['app_opens', 'land', 'TEXT'], ['app_opens', 'sid', 'TEXT'], ['app_opens', 'last_at', 'TEXT'],
  // luogo approssimato dall'IP (api/geoip.php, l'IP non si salva): città, regione, codice del paese
  ['app_opens', 'city', 'TEXT'], ['app_opens', 'region', 'TEXT'], ['app_opens', 'cc', 'TEXT'],
];
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO('sqlite:' . priv('wtm.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
  // tabelle: solo quando SCHEMA cambia (la sua impronta sta in user_version)
  $ver = crc32(SCHEMA . json_encode(SCHEMA_ADD)) & 0x7fffffff;
  if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() !== $ver) {
    $pdo->exec(SCHEMA);
    foreach (SCHEMA_ADD as [$t, $c, $type]) {
      $have = array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
      if (!in_array($c, $have, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN $c $type");
    }
    $pdo->exec("PRAGMA user_version = $ver");
  }
  return $pdo;
}
function one(string $sql, array $p = []) { $st = db()->prepare($sql); $st->execute($p); return $st->fetchColumn(); }

function now(): string { return gmdate('Y-m-d H:i:s'); }
function today_it(): string { return (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d'); }
function json_out($data, int $code = 200): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function ip_hash(): string { return hash('sha256', cfg()['ip_salt'] . ':' . ($_SERVER['REMOTE_ADDR'] ?? '')); }
// al massimo $max tentativi in $minutes minuti per chiave (es. accesso per IP); true = consentito
function attempt_ok(string $key, int $max, int $minutes): bool {
  $f = sys_get_temp_dir() . '/wtm-att-' . hash('sha256', cfg()['ip_salt'] . $key);
  $now = time();
  $list = array_filter(json_decode((string)@file_get_contents($f), true) ?: [], fn($t) => $t > $now - $minutes * 60);
  $list[] = $now;
  @file_put_contents($f, json_encode(array_values($list)), LOCK_EX);
  return count($list) <= $max;
}

// ---------- gettoni e sessione ----------
function tok_new(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
function tok_hash(string $t): string { return hash('sha256', $t); }
function token_create(int $uid, string $kind, int $seconds): string {
  $t = tok_new();
  db()->prepare('INSERT INTO user_tokens (token_hash, user_id, kind, expires_at, created_at, user_agent) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([tok_hash($t), $uid, $kind, gmdate('Y-m-d H:i:s', time() + $seconds), now(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
  return $t;
}
function token_user(string $t, string $kind, bool $consume = false): ?array {
  if ($t === '') return null;
  $st = db()->prepare('SELECT u.* FROM user_tokens k JOIN users u ON u.id = k.user_id WHERE k.token_hash = ? AND k.kind = ? AND k.expires_at > ?');
  $st->execute([tok_hash($t), $kind, now()]);
  $u = $st->fetch() ?: null;
  if ($u && $consume) db()->prepare('DELETE FROM user_tokens WHERE token_hash = ?')->execute([tok_hash($t)]);
  return $u;
}
function current_user(): ?array {
  static $u = false;
  if ($u === false) $u = token_user((string)($_COOKIE[SESSION_COOKIE] ?? ''), 'session');
  return $u;
}
function user_by_id(int $id): ?array { $st = db()->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null; }
function login_user(array $u): void {
  $t = token_create((int)$u['id'], 'session', SESSION_DAYS * 86400);
  db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([now(), $u['id']]);
  setcookie(SESSION_COOKIE, $t, ['expires' => time() + SESSION_DAYS * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
  // il browser usato finora senza account: le sue aperture della mappa passano all'account
  $vid = (string)($_COOKIE['wtm_vid'] ?? '');
  if (preg_match('/^[a-f0-9]{24}$/', $vid)) { try { db()->prepare('UPDATE app_opens SET user_id = ? WHERE vid = ? AND user_id IS NULL')->execute([$u['id'], $vid]); } catch (Throwable $e) {} }
}
function logout_user(): void {
  $t = (string)($_COOKIE[SESSION_COOKIE] ?? '');
  if ($t !== '') db()->prepare('DELETE FROM user_tokens WHERE token_hash = ?')->execute([tok_hash($t)]);
  setcookie(SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

// ---------- amministratori ----------
// id degli account amministratori in admins.json. Il primo si crea dalla pagina admin/ quando non ce n'è ancora nessuno
// (chi è collegato lo diventa); poi solo un amministratore può aggiungerne altri (admin/users.php).
function admin_ids(): array { return array_map('intval', jread('admins.json')['ids'] ?? []); }
function is_admin(?array $u): bool { return $u !== null && in_array((int)$u['id'], admin_ids(), true); }
// dispositivi dell'amministratore (cookie wtm_vid dei browser in cui è entrato con l'account admin), in admin-vids.json:
// non contano nelle statistiche né mandano notifiche, anche quando poi apre la mappa senza essere collegato
function admin_vids(): array { return jread('admin-vids.json')['vids'] ?? []; }
function is_admin_device(): bool {
  if (($_COOKIE['wtm_ns'] ?? '') === '1') return true;
  $v = (string)($_COOKIE['wtm_vid'] ?? '');
  return $v !== '' && in_array($v, admin_vids(), true);
}
// segna questo browser come dell'amministratore (cookie wtm_ns + wtm_vid in admin-vids.json) e toglie dalle statistiche
// quello che vi aveva già registrato
function mark_admin_device(): void {
  if (($_COOKIE['wtm_ns'] ?? '') !== '1') setcookie('wtm_ns', '1', ['expires' => time() + 400 * 86400, 'path' => '/', 'secure' => true, 'samesite' => 'Lax']);
  $vids = admin_vids();
  $v = visitor_id();
  if (!in_array($v, $vids, true)) { $vids[] = $v; jwrite('admin-vids.json', ['vids' => array_values($vids)]); purge_admin_stats(); }
  elseif ((jread('admin-vids.json')['purged'] ?? 0) < 1) purge_admin_stats();
}
function purge_admin_stats(): void {
  $vids = admin_vids(); $ids = admin_ids();
  if (!$vids && !$ids) return;
  $db = db();
  $qv = implode(',', array_fill(0, count($vids), '?')) ?: "''"; $qi = implode(',', array_fill(0, count($ids), '?')) ?: '0';
  $p = array_merge($vids, $ids);
  foreach (['app_opens', 'user_sessions', 'user_events'] as $t) $db->prepare("DELETE FROM $t WHERE vid IN ($qv) OR user_id IN ($qi)")->execute($p);
  jwrite('admin-vids.json', ['vids' => $vids, 'purged' => 1]);
}
function admin_set(int $id, bool $on): void {
  $ids = array_values(array_unique(array_filter(admin_ids(), fn($x) => $x !== $id)));
  if ($on) $ids[] = $id;
  jwrite('admins.json', ['ids' => $ids]);
}

function public_user(array $u): array {
  return ['id' => (int)$u['id'], 'email' => $u['email'], 'name' => $u['name'], 'email_verified' => (bool)$u['email_verified_at'],
    'has_password' => (bool)$u['pass_hash'], 'google' => (bool)$u['google_sub'], 'created_at' => $u['created_at']] + (is_admin($u) ? ['admin' => true] : []);
}

// ---------- email ----------
// Su Infomaniak mail() di PHP è spenta: si spedisce con SMTP. Casella e password dalla pagina Impostazioni dell'admin
// (settings.json, nella cartella privata). false = posta non configurata o non funzionante: chi chiama va avanti lo stesso.
function smtp_send(string $to, string $subject, string $body, string $contentType): bool {
  $c = settings()['smtp'] ?? [];
  if (empty($c['host']) || empty($c['user']) || empty($c['pass'])) { error_log('smtp: non configurato'); return false; }
  $from = $c['from'] ?: $c['user'];
  $fp = @stream_socket_client('tcp://' . $c['host'] . ':' . (int)($c['port'] ?? 587), $errno, $err, 10);
  if (!$fp) { error_log("smtp: connessione fallita $err"); return false; }
  stream_set_timeout($fp, 15);
  $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 1024)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
  $cmd = function (string $line, string $expect, string $label = '') use ($fp, $read) {
    fwrite($fp, $line . "\r\n"); $r = $read();
    if (strpos($r, $expect) !== 0) throw new RuntimeException(($label ?: explode(' ', $line)[0]) . ': ' . trim($r));
    return $r;
  };
  try {
    if (strpos($read(), '220') !== 0) throw new RuntimeException('saluto');
    $cmd('EHLO winetripmap.com', '250');
    $cmd('STARTTLS', '220');
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('tls');
    $cmd('EHLO winetripmap.com', '250');
    $cmd('AUTH LOGIN', '334'); $cmd(base64_encode($c['user']), '334', 'AUTH utente'); $cmd(base64_encode($c['pass']), '235', 'AUTH password');
    $cmd("MAIL FROM:<$from>", '250'); $cmd("RCPT TO:<$to>", '25'); $cmd('DATA', '354');
    $headers = "From: WineTripMap <$from>\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nDate: " . date('r')
      . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . "@winetripmap.com>\r\nMIME-Version: 1.0\r\nContent-Type: $contentType";
    $cmd(preg_replace('/^\./m', '..', $headers . "\r\n\r\n" . $body) . "\r\n.", '250');
    $cmd('QUIT', '221');
    fclose($fp);
    return true;
  } catch (Throwable $e) { error_log('smtp: ' . $e->getMessage()); @fclose($fp); return false; }
}
function mail_ready(): bool { $c = settings()['smtp'] ?? []; return !empty($c['host']) && !empty($c['user']) && !empty($c['pass']); }
function send_mail(string $to, string $subject, string $text, string $html): bool {
  $b = 'wtm' . bin2hex(random_bytes(8));
  $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
    . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--";
  return smtp_send($to, $subject, $body, "multipart/alternative; boundary=\"$b\"");
}
// email con titolo, testo, pulsante e nota (tabelle e stili in linea per Gmail, Outlook, Apple Mail), nei colori del sito
function send_action_mail(string $to, string $lang, string $subject, string $intro, string $button, string $url, string $outro): bool {
  $it = $lang === 'it';
  $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  $title = trim(preg_replace('/\s*·\s*WineTripMap$/u', '', $subject));
  $f = 'font-family:Arial,Helvetica,sans-serif;';
  $html = '<!doctype html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($title) . '</title></head>'
    . '<body style="margin:0;padding:0;background:#f3ede6;"><div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $e(mb_substr($intro, 0, 140)) . '</div>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3ede6;"><tr><td align="center" style="padding:28px 12px;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;">'
    . '<tr><td style="padding:22px 28px;border-bottom:4px solid #8e1b33;"><a href="' . SITE_URL . '" style="text-decoration:none;' . $f . 'font-weight:900;font-size:24px;letter-spacing:-.5px;color:#2a1a1d;">wine<span style="color:#8e1b33;">trip</span>map</a></td></tr>'
    . '<tr><td style="padding:34px 28px 8px;"><h1 style="margin:0 0 16px;' . $f . 'font-size:24px;line-height:1.25;color:#2a1a1d;">' . $e($title) . '</h1>'
    . '<p style="margin:0;' . $f . 'font-size:16px;line-height:1.6;color:#4d3f3a;">' . $e($intro) . '</p></td></tr>'
    . '<tr><td style="padding:28px 28px 8px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:999px;background:#8e1b33;">'
    . '<a href="' . $e($url) . '" style="display:inline-block;padding:15px 28px;' . $f . 'font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:999px;">' . $e($button) . ' &rarr;</a></td></tr></table></td></tr>'
    . '<tr><td style="padding:24px 28px 30px;"><p style="margin:0 0 8px;' . $f . 'font-size:13px;line-height:1.5;color:#8f8580;">' . $e($outro) . '</p>'
    . '<p style="margin:0;' . $f . 'font-size:12px;line-height:1.5;color:#a89e98;word-break:break-all;">' . ($it ? 'Il pulsante non funziona? Copia questo link: ' : 'Button not working? Copy this link: ') . '<a href="' . $e($url) . '" style="color:#8e1b33;">' . $e($url) . '</a></p></td></tr>'
    . '<tr><td align="center" style="padding:18px 28px 22px;border-top:1px solid #eee3dc;"><p style="margin:0;' . $f . 'font-size:12px;line-height:1.7;color:#a89e98;">'
    . '<a href="' . SITE_URL . '/" style="color:#8f8580;">' . ($it ? 'Apri la mappa' : 'Open the map') . '</a> &nbsp;·&nbsp; <a href="' . SITE_URL . '/account/" style="color:#8f8580;">' . ($it ? 'Il tuo account' : 'Your account') . '</a><br>'
    . ($it ? 'Ricevi questa email perché hai un account su winetripmap.com.' : 'You get this email because you have an account on winetripmap.com.') . '</p></td></tr>'
    . '</table></td></tr></table></body></html>';
  return send_mail($to, $subject, "$title\n\n$intro\n\n$button: $url\n\n$outro\n\n— WineTripMap", $html);
}
function send_verify_mail(array $u, string $lang): bool {
  $url = SITE_URL . '/account/?verify=' . token_create((int)$u['id'], 'verify', 7 * 86400);
  $hi = $u['name'] ? ' ' . $u['name'] : '';
  return $lang === 'it'
    ? send_action_mail($u['email'], 'it', 'Conferma la tua email · WineTripMap', "Ciao$hi, grazie per esserti iscritto a WineTripMap. Conferma la tua email per attivare l'account.", 'Conferma email', $url, 'Il link vale 7 giorni. Se non ti sei iscritto tu, ignora questa email.')
    : send_action_mail($u['email'], 'en', 'Confirm your email · WineTripMap', "Hi$hi, thanks for joining WineTripMap. Confirm your email to activate your account.", 'Confirm email', $url, 'The link is valid for 7 days. If you did not sign up, ignore this email.');
}
function send_reset_mail(array $u, string $lang): bool {
  $url = SITE_URL . '/account/?reset=' . token_create((int)$u['id'], 'reset', 3600);
  return $lang === 'it'
    ? send_action_mail($u['email'], 'it', 'Nuova password · WineTripMap', 'Hai chiesto di cambiare la password del tuo account WineTripMap.', 'Scegli una nuova password', $url, "Il link vale 1 ora. Se non l'hai chiesto tu, ignora questa email: la password resta quella di prima.")
    : send_action_mail($u['email'], 'en', 'New password · WineTripMap', 'You asked to change the password of your WineTripMap account.', 'Choose a new password', $url, 'The link is valid for 1 hour. If you did not ask for it, ignore this email: your password stays the same.');
}
// avviso all'amministratore (email della pagina Impostazioni), dopo aver risposto all'utente
function notify_admin(string $subject, string $body): void {
  $to = (string)(settings()['notify_email'] ?? '');
  if ($to === '' || !mail_ready()) return;
  register_shutdown_function(function () use ($to, $subject, $body) {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    smtp_send($to, 'winetripmap.com: ' . $subject, base64_encode($body . "\n\nAdmin: " . SITE_URL . "/admin/\n") , "text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64");
  });
}

// ---------- Google ----------
// id client OAuth di Google (pubblico), dalla pagina Impostazioni dell'admin; vuoto = accesso con Google spento
function google_client_id(): string { return trim((string)(settings()['google_client_id'] ?? '')); }
// verifica il "credential" (ID token) di Google Identity Services; restituisce sub, email, name o null
function google_verify(string $idToken): ?array {
  $cid = google_client_id();
  if ($cid === '' || $idToken === '') return null;
  $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $c = $code === 200 ? (json_decode((string)$res, true) ?: []) : [];
  if (($c['aud'] ?? '') !== $cid || !in_array($c['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
    || (int)($c['exp'] ?? 0) < time() || ($c['email_verified'] ?? '') !== 'true' || empty($c['sub']) || empty($c['email'])) return null;
  return ['sub' => (string)$c['sub'], 'email' => mb_strtolower((string)$c['email']), 'name' => (string)($c['name'] ?? '')];
}

// ---------- visitatori (dashboard dell'admin) ----------
// cookie casuale del browser (nessun dato personale), per contare persone diverse e non solo aperture
function visitor_id(): string {
  $v = (string)($_COOKIE['wtm_vid'] ?? '');
  if (!preg_match('/^[a-f0-9]{24}$/', $v)) {
    $v = bin2hex(random_bytes(12));
    setcookie('wtm_vid', $v, ['expires' => time() + 400 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
  }
  return $v;
}
// da dove arriva (utm_source, app che apre il link, sito di provenienza)
function traffic_source(string $ref, string $ua, string $utm = ''): string {
  $utm = strtolower(trim($utm));
  if ($utm !== '') return preg_match('/^(ig|insta)/', $utm) ? 'instagram' : (preg_match('/^(fb|facebook)/', $utm) ? 'facebook' : substr(preg_replace('/[^a-z0-9._-]/', '', $utm), 0, 30));
  if (preg_match('/Instagram/i', $ua)) return 'instagram';
  if (preg_match('/FBAN|FBAV|FB_IAB/i', $ua)) return 'facebook';
  if (preg_match('/musical_ly|TikTok|BytedanceWebview/i', $ua)) return 'tiktok';
  $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
  if ($host === '') return 'diretto';
  if (preg_match('/(^|\.)winetripmap\.com$/', $host)) return 'interno';
  $exact = ['t.co' => 'twitter', 'x.com' => 'twitter', 't.me' => 'telegram', 'l.instagram.com' => 'instagram', 'lm.facebook.com' => 'facebook', 'l.facebook.com' => 'facebook'];
  if (isset($exact[$host])) return $exact[$host];
  foreach (['instagram', 'facebook', 'google', 'bing', 'duckduckgo', 'yahoo', 'yandex', 'ecosia', 'twitter', 'tiktok', 'reddit', 'youtube', 'whatsapp', 'telegram', 'chatgpt', 'openai', 'perplexity', 'claude'] as $k)
    if (preg_match('/(^|\.)' . $k . '\./', $host)) return $k === 'openai' ? 'chatgpt' : $k;
  return preg_replace('/^www\./', '', $host);
}
// "Telefono · iOS · Safari" dallo user agent
function device_info(string $ua): string {
  $tablet = preg_match('/iPad|Tablet/i', $ua) || (preg_match('/Android/', $ua) && !preg_match('/Mobile/', $ua));
  $type = $tablet ? 'Tablet' : (preg_match('/Mobi|iPhone|iPod|Android/i', $ua) ? 'Telefono' : 'Computer');
  $os = preg_match('/iPhone|iPad|iPod/', $ua) ? 'iOS' : (preg_match('/Android/', $ua) ? 'Android' : (preg_match('/Windows/', $ua) ? 'Windows'
    : (preg_match('/CrOS/', $ua) ? 'ChromeOS' : (preg_match('/Mac OS X|Macintosh/', $ua) ? 'macOS' : (preg_match('/Linux/', $ua) ? 'Linux' : 'altro')))));
  $br = preg_match('/Instagram/i', $ua) ? 'Instagram (app)' : (preg_match('/FBAN|FBAV|FB_IAB/', $ua) ? 'Facebook (app)' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet'
    : (preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/Firefox\/|FxiOS/', $ua) ? 'Firefox'
    : (preg_match('/CriOS|Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'altro')))))));
  return "$type · $os · $br";
}
function is_bot(): bool { return (bool)preg_match('/bot|crawl|spider|slurp|preview|headless/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')); }
