<?php
// Consigli e malfunzionamenti dagli iscritti: POST api/feedback.php { kind: "idea" | "bug", message, context? } → si leggono in admin/feedback.php
require __DIR__ . '/lib.php';
bot_guard('feedback', 10); // limite di richieste al minuto per indirizzo e blocco dei robot (api/bots.php)
$u = current_user();
if (!$u) json_out(['error' => 'not_logged_in'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) json_out(['error' => 'method'], 405);
if (!attempt_ok('feedback:' . $u['id'], 10, 60)) json_out(['error' => 'too_many'], 429);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$kind = ($in['kind'] ?? '') === 'bug' ? 'bug' : 'idea';
$msg = mb_substr(trim((string)($in['message'] ?? '')), 0, 3000);
if (mb_strlen($msg) < 3) json_out(['error' => 'message'], 400);
$ctx = mb_substr(trim((string)($in['context'] ?? '')), 0, 500) . ' · ' . device_info((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
db()->prepare('INSERT INTO feedback (user_id, kind, message, context, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$u['id'], $kind, $msg, $ctx, now()]);
notify_admin($kind === 'bug' ? 'malfunzionamento segnalato' : 'nuovo consiglio', "Da: {$u['email']}\n\n$msg");
json_out(['ok' => true]);
