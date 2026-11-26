<?php
// Protezione dai robot, come SkateboardMap (2026-11-27). Incluso da api/lib.php.
//  - bot_guard(): limite di richieste al minuto per indirizzo sulle API e sulle pagine SEO; chi lo supera di 4 volte viene
//    bloccato per BAN_HOURS ore (tabella bot_bans, solo l'hash dell'IP) e riceve 429 ovunque, pagine SEO comprese.
//  - trappola: /wineries-archive-all/ (api/trap.php), link invisibile alle persone e vietato in robots.txt: blocco 24 ore.
//  - robot che si fingono browser (navigator.webdriver, browser senza finestra: l'app manda ab=1), aperture a raffica dalla
//    stessa rete (più di BOT_NET_HOUR l'ora), computer col fuso della Cina arrivati senza link, ondate con la stessa impronta:
//    niente riga in app_opens (solo il numero del giorno in app_events, bot:open), niente avvisi, la mappa gratuita e nessuna
//    chiamata a Google (foto, ricerche, Street View) né a Mapbox.
//  - robot maleducati respinti in .htaccess (403, senza PHP) e in robots.txt.
// Google, Bing, DuckDuckGo, Apple, Yandex e i robot di ricerca di ChatGPT e Perplexity non vengono mai bloccati.
// Le prove dello sviluppatore con "WTM-Test" nello user agent passano sempre.

const BAN_HOURS = 6;
const BOT_NET_HOUR = 12;     // aperture della mappa in un'ora dalla stessa rete oltre le quali è un robot (una persona ne fa 1-3)
const BOT_PATTERN_HOUR = 25; // aperture in un'ora con la stessa impronta (computer, fuso, arrivo diretto, senza account)
const BOT_CN_TZ = ['Asia/Shanghai', 'Asia/Chongqing', 'Asia/Chungking', 'Asia/Harbin', 'Asia/Urumqi', 'Asia/Kashgar', 'PRC'];

function wtm_test(): bool { return str_contains((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'WTM-Test'); }
function ip_key(): string { return substr(ip_hash(), 0, 24); }
// rete dell'indirizzo (IPv4 /24, IPv6 /48), solo come hash: riconosce i robot che aprono a raffica da tanti indirizzi vicini
function ip_net(): string {
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $net = implode('.', array_slice(explode('.', $ip), 0, 3));
  elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $net = bin2hex(substr((string)inet_pton($ip), 0, 6));
  else $net = $ip;
  return substr(hash('sha256', cfg()['ip_salt'] . ':net:' . $net), 0, 16);
}
// motori di ricerca e assistenti che portano visite: mai bloccati (rispettano robots.txt, quindi non cadono nella trappola)
function good_bot(): bool {
  return (bool)preg_match('/Googlebot|Google-InspectionTool|GoogleOther|AdsBot-Google|Storebot-Google|bingbot|BingPreview|DuckDuckBot|Applebot|YandexBot|OAI-SearchBot|ChatGPT-User|PerplexityBot|Perplexity-User|facebookexternalhit|Twitterbot|WhatsApp|TelegramBot|Slackbot/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}
// browser pilotato da un programma: dichiarato dall'app (ab=1) o nello user agent
function automated_client(): bool {
  return !wtm_test() && (!empty($_GET['ab']) || (bool)preg_match('/HeadlessChrome|PhantomJS|Puppeteer|Playwright|Selenium/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
}
// computer col fuso della Cina continentale (ondate di robot da computer Windows, nov 2026, viste su SkateboardMap)
function cn_desktop(string $tz, string $ua): bool { return in_array($tz, BOT_CN_TZ, true) && !preg_match('/Mobi|iPhone|iPad|Android/i', $ua); }

// contatori al minuto/all'ora in file temporanei (niente database: costano poco anche durante un'ondata)
function tmp_count(string $key, bool $add = true): int {
  $f = sys_get_temp_dir() . '/wtm-' . $key;
  if (random_int(1, 500) === 1) foreach (glob(sys_get_temp_dir() . '/wtm-rl-*') ?: [] as $old) if (@filemtime($old) < time() - 7200) @unlink($old);
  $n = (int)@file_get_contents($f);
  if ($add) @file_put_contents($f, (string)++$n, LOCK_EX);
  return $n;
}
// aperture della mappa da questa rete nell'ultima ora (ora corrente + la precedente pesata)
function net_opens(bool $add = false): int {
  $h = (int)gmdate('YmdH'); $m = (int)gmdate('i');
  $now = tmp_count('rl-net-' . ip_net() . '-' . $h, $add);
  $prev = tmp_count('rl-net-' . ip_net() . '-' . gmdate('YmdH', time() - 3600), false);
  return $now + (int)round($prev * (60 - $m) / 60);
}
function bot_net_busy(): bool { return net_opens() >= BOT_NET_HOUR; }

function ip_banned(): bool {
  static $b = null;
  if ($b !== null) return $b;
  try { $st = db()->prepare('SELECT 1 FROM bot_bans WHERE ip = ? AND until > ?'); $st->execute([ip_key(), now()]); return $b = (bool)$st->fetchColumn(); }
  catch (Throwable $e) { return $b = false; }
}
function is_bot_visit(): bool {
  if (wtm_test()) return false;
  return automated_client() || bot_net_busy() || ip_banned() || (cn_desktop((string)($_GET['tz'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) && empty($_GET['cnok']));
}
// numero del giorno (app_events, chi = ospite): bot:open, bot:ban, bot:cn, bot:trap
function bot_stat(string $ev, int $n = 1): void {
  try { db()->prepare("INSERT INTO app_events (day, ev, who, n) VALUES (?, ?, 'ospite', ?) ON CONFLICT(day, ev, who) DO UPDATE SET n = n + excluded.n")->execute([today_it(), $ev, $n]); } catch (Throwable $e) {}
}
function ban_ip(string $reason, int $hours = BAN_HOURS): void {
  try {
    db()->prepare('INSERT INTO bot_bans (ip, until, reason, at) VALUES (?, ?, ?, ?) ON CONFLICT(ip) DO UPDATE SET until = excluded.until, reason = excluded.reason, hits = hits + 1')
      ->execute([ip_key(), gmdate('Y-m-d H:i:s', time() + $hours * 3600), $reason, now()]);
    if (random_int(1, 50) === 1) db()->prepare('DELETE FROM bot_bans WHERE until < ?')->execute([gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
    bot_stat('bot:ban');
    bot_alert("🤖 Robot bloccato per {$hours} ore", $reason === 'trappola' ? 'Ha aperto il link trappola invisibile alle persone.' : 'Troppe richieste al sito in un minuto.');
  } catch (Throwable $e) {}
}
// notifica push all'admin (dispositivi iscritti in Impostazioni), al massimo una ogni 3 ore per tipo, dopo la risposta
function bot_alert(string $title, string $body): void {
  $f = sys_get_temp_dir() . '/wtm-botalert-' . substr(md5($title), 0, 8);
  if ((int)@file_get_contents($f) > time() - 3 * 3600) return;
  @file_put_contents($f, (string)time(), LOCK_EX);
  register_shutdown_function(function () use ($title, $body) {
    try {
      require_once __DIR__ . '/webpush.php';
      if (empty(webpush_data()['subs'])) return;
      if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
      webpush_send($title, $body, '/admin/dashboard.php#robot');
    } catch (Throwable $e) {}
  });
}
// limite di richieste al minuto per indirizzo (non per rete: un operatore telefonico mette tante persone dietro la stessa rete).
// Sopra il limite: 429 per quel minuto; 4 volte il limite: blocco per BAN_HOURS ore. $html: risposta testuale (pagine SEO).
function bot_guard(string $bucket, int $perMin, bool $html = false): void {
  if (PHP_SAPI === 'cli' || wtm_test()) return;
  $good = good_bot();
  if ($good) $perMin *= 4; // i motori di ricerca leggono più in fretta: limite più largo e mai il blocco
  $tooMany = function (int $retry) use ($html) {
    header('Retry-After: ' . $retry);
    if ($html) { http_response_code(429); header('Content-Type: text/plain; charset=utf-8'); header('X-Robots-Tag: noindex'); exit('Too many requests'); }
    json_out(['error' => 'rate_limited'], 429);
  };
  if (!$good && ip_banned()) $tooMany(3600);
  $n = tmp_count('rl-' . $bucket . '-' . ip_key() . '-' . gmdate('YmdHi'));
  if ($n === $perMin * 4 && !$good) ban_ip('raffica');
  if ($n > $perMin) $tooMany(60);
}

// apertura della mappa (api/auth.php?a=me&map=1) senza account: è un robot? Se sì la conta solo come numero e, quando una rete
// o un'impronta (computer, fuso, arrivo diretto) diventano robot, toglie anche le loro aperture dell'ultima ora già registrate.
function open_is_bot(string $source, string $device, string $tz): bool {
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  // computer col fuso della Cina arrivati senza link: l'app non parte da sola (app.js: cnGate); cnok = ha toccato «Apri la mappa»
  if ($source === 'diretto' && cn_desktop($tz, $ua) && empty($_GET['cnok'])) { bot_stat('bot:cn'); return true; }
  net_opens(true);
  $since = gmdate('Y-m-d H:i:s', time() - 3600);
  $db = db(); $gone = 0;
  $bot = automated_client() || bot_net_busy() || ip_banned();
  // ondata con la stessa impronta da tanti indirizzi diversi (solo computer: tante persone vere arrivano dal telefono)
  if (!$bot && $source === 'diretto' && str_starts_with($device, 'Computer')) {
    $w = "at > ? AND user_id IS NULL AND source = 'diretto' AND device = ? AND COALESCE(tz, '') = ?";
    $st = $db->prepare("SELECT COUNT(*) FROM app_opens WHERE $w"); $st->execute([$since, $device, $tz]);
    if ((int)$st->fetchColumn() + 1 >= BOT_PATTERN_HOUR) { $bot = true; $d = $db->prepare("DELETE FROM app_opens WHERE $w"); $d->execute([$since, $device, $tz]); $gone += $d->rowCount(); }
  }
  if (!$bot) return false;
  $d = $db->prepare('DELETE FROM app_opens WHERE net = ? AND user_id IS NULL AND at > ?'); $d->execute([ip_net(), $since]); $gone += $d->rowCount();
  bot_stat('bot:open', 1 + $gone);
  $st = $db->prepare("SELECT n FROM app_events WHERE day = ? AND ev = 'bot:open' AND who = 'ospite'"); $st->execute([today_it()]);
  if (($nb = (int)$st->fetchColumn()) >= 50) bot_alert('🤖 Ondata di robot', "$nb aperture automatiche della mappa oggi: escluse dalle statistiche, con la mappa gratuita.");
  return true;
}
