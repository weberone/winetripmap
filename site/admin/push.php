<?php
// Iscrizione dei dispositivi alle notifiche push degli avvisi di visita (api/webpush.php, api/visit_notify.php). Solo amministratori.
//   GET  push.php?a=key                               → { key } chiave pubblica VAPID
//   POST push.php {a: 'subscribe', sub, csrf}         → dispositivo aggiunto (o aggiornato)
//   POST push.php {a: 'unsubscribe', endpoint, csrf}
require_once dirname(__DIR__) . '/api/lib.php';
require_once dirname(__DIR__) . '/api/webpush.php';
header('Cache-Control: no-store');
$me = current_user();
if (!is_admin($me)) json_out(['error' => 'forbidden'], 403);
if (($_GET['a'] ?? '') === 'key') json_out(['key' => webpush_data()['pub']]);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$csrf = hash_hmac('sha256', (string)($_COOKIE[SESSION_COOKIE] ?? ''), cfg()['ip_salt'] . ':csrf'); // come csrf() di layout.php
if (!hash_equals($csrf, (string)($in['csrf'] ?? ''))) json_out(['error' => 'csrf'], 403);
$d = webpush_data();
if (($in['a'] ?? '') === 'subscribe') {
  $s = $in['sub'] ?? []; $ep = (string)($s['endpoint'] ?? ''); $k = $s['keys'] ?? [];
  if (!preg_match('~^https://~', $ep) || empty($k['p256dh']) || empty($k['auth'])) json_out(['error' => 'sub'], 400);
  $d['subs'] = array_values(array_filter($d['subs'], fn($x) => $x['endpoint'] !== $ep));
  $d['subs'][] = ['endpoint' => $ep, 'p256dh' => (string)$k['p256dh'], 'auth' => (string)$k['auth'],
    'label' => mb_substr(device_info((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 60), 'added' => (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('d/m/Y H:i')];
  webpush_save($d);
  json_out(['ok' => true, 'n' => count($d['subs'])]);
}
if (($in['a'] ?? '') === 'unsubscribe') {
  $d['subs'] = array_values(array_filter($d['subs'], fn($x) => $x['endpoint'] !== (string)($in['endpoint'] ?? '')));
  webpush_save($d);
  json_out(['ok' => true, 'n' => count($d['subs'])]);
}
json_out(['error' => 'action'], 400);
