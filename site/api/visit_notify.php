<?php
// Avviso all'amministratore quando qualcuno apre la mappa (api/auth.php?a=me&map=1), come SkateboardMap: notifica push ai
// dispositivi iscritti da Admin → Impostazioni (api/webpush.php). Interruttori in webpush.json ("opts"):
//   new: visitatori nuovi (prima volta) · return: visitatori che tornano · users: iscritti
// Mai per gli amministratori, per chi ha il cookie wtm_ns dell'admin né per i robot; una sola per scheda del browser
// (il ricaricamento della pagina non conta); al massimo NOTIFY_MAX_HOUR avvisi l'ora.
// Toccando la notifica si apre la pagina del visitatore nell'admin (percorso.php): aperture, luogo, percorso nella mappa.
require_once __DIR__ . '/webpush.php';
const NOTIFY_MAX_HOUR = 30;

function notify_opts(): array { return (webpush_data()['opts'] ?? []) + ['new' => true, 'return' => true, 'users' => true]; }

// $o: la riga appena scritta in app_opens (id, vid, source, ref, device, lang, land, sid), $geo da geo_lookup()
function notify_visit(?array $me, array $o, ?array $geo): void {
  $d = webpush_data();
  if (!$d['subs']) return;
  $s = notify_opts();
  $db = db();
  $vid = (string)($o['vid'] ?? '');
  // stessa scheda del browser (sid) già avvisata: è un ricaricamento
  if (!empty($o['sid']) && (int)one('SELECT COUNT(*) FROM app_opens WHERE sid = ? AND id < ?', [$o['sid'], $o['id']]) > 0) return;
  $days = $vid !== '' ? (int)one('SELECT COUNT(DISTINCT day) FROM app_opens WHERE vid = ? AND id < ?', [$vid, $o['id']]) : 0;
  $first = !$me && $days === 0 && (int)one('SELECT COUNT(*) FROM app_opens WHERE vid = ? AND id < ?', [$vid, $o['id']]) === 0;
  if ($me ? !$s['users'] : !($first ? $s['new'] : $s['return'])) return;
  // tetto orario (contatore in un file, si azzera da solo)
  $hk = priv('notify-hour.json'); $h = json_decode((string)@file_get_contents($hk), true) ?: [];
  $hour = gmdate('YmdH');
  if (($h['h'] ?? '') !== $hour) $h = ['h' => $hour, 'n' => 0];
  if (++$h['n'] > NOTIFY_MAX_HOUR) return;
  @file_put_contents($hk, json_encode($h), LOCK_EX);

  $today = $vid !== '' ? (int)one('SELECT COUNT(*) FROM app_opens WHERE vid = ? AND day = ?', [$vid, today_it()]) : 1;
  $tag = '#' . substr($vid, 0, 4);
  $where = geo_label($geo);
  if ($me) { $title = '🍷 ' . ($me['name'] ?: $me['email']) . ' ha aperto la mappa'; }
  elseif ($first) { $title = '🍷 Nuovo visitatore' . ($where ? ' da ' . ($geo['city'] ?: $where) : ''); }
  else { $title = '🍷 Torna un visitatore' . ($where ? ' da ' . ($geo['city'] ?: $where) : ''); }
  $from = ($o['source'] ?? '') ?: 'diretto';
  if (!empty($o['ref']) && $o['ref'] !== $from) $from .= ' (' . $o['ref'] . ')';
  require_once dirname(__DIR__) . '/admin/eventi_lib.php';
  $lines = [
    ($where ?: 'Luogo non noto') . ' · ' . (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('H:i'),
    'Arriva da: ' . $from . ' · ' . land_kind($o['land'] ?? null),
    ($o['device'] ?? '') . ($o['lang'] ? ' · ' . $o['lang'] : ''),
    $me ? 'Iscritto' . ($today > 1 ? " · oggi {$today}ª volta" : '')
      : "Visitatore $tag · " . ($first ? 'prima volta' : "giorni sulla mappa: " . ($days + 1)) . ($today > 1 ? " · oggi {$today}ª volta" : ''),
  ];
  $url = '/admin/percorso.php?' . ($vid !== '' ? 'vid=' . $vid : 'sid=' . ($o['sid'] ?? ''));
  register_shutdown_function(function () use ($title, $lines, $url) {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    webpush_send($title, implode("\n", $lines), $url);
  });
}
