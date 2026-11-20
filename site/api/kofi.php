<?php
// Webhook di Ko-fi (account dedicato di WineTripMap): ogni donazione vera arriva qui.
// Ko-fi → Settings → API → Webhook URL: https://winetripmap.com/api/kofi.php. Ko-fi manda un POST con il campo "data" (JSON)
// e il "verification_token", che deve essere uguale a quello salvato in Admin → Impostazioni → Donazioni (settings.json, privato).
// Si salva: importo, valuta, nome scelto, messaggio, tipo; l'email solo per riconoscere un account iscritto (non si tiene).
require __DIR__ . '/lib.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
$tok = (string)(settings()['kofi_token'] ?? '');
$d = json_decode((string)($_POST['data'] ?? ''), true);
if (!is_array($d) || $tok === '' || !hash_equals($tok, (string)($d['verification_token'] ?? ''))) json_out(['error' => 'token'], 403);
$kid = substr((string)($d['kofi_transaction_id'] ?? $d['message_id'] ?? ''), 0, 80);
if ($kid === '') json_out(['error' => 'id'], 400);
// pulsante "Send Test" di Ko-fi: id finto fisso, non è una donazione; si segna solo che il collegamento funziona (Impostazioni)
if ($kid === '00000000-1111-2222-3333-444444444444') {
  @file_put_contents(priv('kofi_test.txt'), gmdate('Y-m-d H:i:s'), LOCK_EX);
  json_out(['ok' => true, 'test' => true]);
}
$clean = fn($s, $n) => ($s = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string)$s))) === '' ? null : mb_substr($s, 0, $n);
$amount = round((float)str_replace(',', '.', (string)($d['amount'] ?? 0)), 2);
$cur = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($d['currency'] ?? '')), 0, 3)) ?: null;
$t = strtotime((string)($d['timestamp'] ?? '')) ?: time();
$uid = null; $email = trim((string)($d['email'] ?? ''));
if ($email !== '') $uid = one('SELECT id FROM users WHERE email = ? COLLATE NOCASE', [$email]) ?: null;
$db = db();
$st = $db->prepare('INSERT OR IGNORE INTO donations (kofi_id, at, type, amount, currency, name, message, is_public, first_sub, tier, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$st->execute([$kid, gmdate('Y-m-d H:i:s', $t), $clean($d['type'] ?? '', 30), $amount, $cur, $clean($d['from_name'] ?? '', 80), $clean($d['message'] ?? '', 500),
  !empty($d['is_public']) ? 1 : 0, !empty($d['is_first_subscription_payment']) ? 1 : 0, $clean($d['tier_name'] ?? '', 60), $uid]);
if ($st->rowCount()) {
  // nei conteggi giornalieri (Visite) e, se è un iscritto, nel suo percorso
  $day = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
  $who = $uid ? 'iscritto' : 'ospite';
  try {
    $db->prepare('INSERT INTO app_events (day, ev, who, n) VALUES (?, ?, ?, 1) ON CONFLICT(day, ev, who) DO UPDATE SET n = n + 1')->execute([$day, 'donate:paid', $who]);
  } catch (Throwable $e) {}
  // avviso sul telefono dell'admin (notifiche push) e per email
  $txt = number_format($amount, 2, ',', '.') . ' ' . $cur;
  $from = ($clean($d['from_name'] ?? '', 80) ?? 'Qualcuno') . ($uid ? ' (iscritto)' : '');
  $msg = $clean($d['message'] ?? '', 120);
  try { require_once __DIR__ . '/webpush.php'; webpush_send('🥂 Nuova donazione: ' . $txt, $from . ($msg ? ': ' . $msg : ''), '/admin/dashboard.php#donazioni'); } catch (Throwable $e) {}
  notify_admin('nuova donazione ' . $txt, "Da: $from\nMessaggio: " . ($msg ?: '—') . "\nTipo: " . ($clean($d['type'] ?? '', 30) ?? '—'));
}
json_out(['ok' => true]);
