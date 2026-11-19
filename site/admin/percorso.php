<?php
// Percorso nella mappa azione per azione (user_events di api/track.php): una sessione (?sid=) o tutte quelle di un visitatore (?vid=).
// C'è solo per gli iscritti e per chi ha accettato i cookie. Il visitatore è un codice casuale del browser, nessun dato personale.
require __DIR__ . '/layout.php';
require __DIR__ . '/eventi_lib.php';
admin_me();
$sid = preg_match('/^[a-z0-9]{8,40}$/', (string)($_GET['sid'] ?? '')) ? $_GET['sid'] : '';
$vid = preg_match('/^[a-f0-9]{24}$/', (string)($_GET['vid'] ?? '')) ? $_GET['vid'] : '';
$tz = new DateTimeZone('Europe/Rome');
$mins = function ($s) { $s = (int)$s; return $s >= 60 ? floor($s / 60) . ' min' . ($s % 60 ? ' ' . ($s % 60) . ' s' : '') : $s . ' s'; };
if ($sid) { $st = db()->prepare('SELECT s.*, u.email FROM user_sessions s LEFT JOIN users u ON u.id = s.user_id AND s.user_id > 0 WHERE s.sid = ?'); $st->execute([$sid]); }
elseif ($vid) { $st = db()->prepare('SELECT s.*, NULL email FROM user_sessions s WHERE s.vid = ? ORDER BY s.started_at DESC LIMIT 50'); $st->execute([$vid]); }
else { header('Location: visite.php'); exit; }
$sessions = $st->fetchAll();
// aperture di questo visitatore (anche quelle senza percorso): da dove arriva, GPS, cookie
$opens = [];
if ($vid) { $st = db()->prepare('SELECT * FROM app_opens WHERE vid = ? ORDER BY id DESC LIMIT 50'); $st->execute([$vid]); $opens = $st->fetchAll(); }
admin_header($vid ? 'Visitatore ' . substr($vid, 0, 6) : 'Percorso nella mappa', 'vis');
?>
<p><a href="visite.php">← Visite</a></p>
<?php if ($vid && $opens): ?>
<section class="card"><h2>Aperture <small class="muted"><?= count($opens) ?><?= count($opens) === 50 ? '+' : '' ?></small></h2><div class="tscroll"><table>
  <thead><tr><th>Ora</th><th>Da dove</th><th>Ingresso</th><th>GPS</th><th>Cookie</th><th class="r">Tempo</th><th class="r">Cantine</th><th>Dispositivo</th></tr></thead>
  <tbody><?php foreach ($opens as $o): ?><tr><td><?= when($o['at']) ?></td><td><?= h($o['source']) ?></td><td><small><?= h(land_kind($o['land'])) ?></small></td>
    <td><?= $o['gps'] === null ? '—' : ($o['gps'] ? 'sì' : 'no') ?></td><td><?= $o['ck'] === null ? '—' : ($o['ck'] ? 'Accetta' : 'Rifiuta') ?></td>
    <td class="r"><?= $mins($o['secs']) ?></td><td class="r"><?= num($o['views']) ?></td><td><small><?= h($o['device']) ?></small></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
<?php if (!$sessions): ?><section class="card"><p class="muted">Nessun percorso registrato (non ha accettato i cookie, o è più vecchio di 90 giorni).</p></section><?php endif; ?>
<?php foreach ($sessions as $s):
  $inf = json_decode((string)$s['info'], true) ?: [];
  $st = db()->prepare('SELECT at, ev, x FROM user_events WHERE sid = ? ORDER BY at, id LIMIT 1000'); $st->execute([$s['sid']]); $evs = $st->fetchAll(); ?>
<section class="card">
  <h2><?= when($s['started_at']) ?> <small class="muted">· <?= num($s['events']) ?> azioni · <?= $mins($s['active_secs']) ?> con la mappa davanti</small></h2>
  <p class="muted" style="margin-top:0">
    <?= $s['email'] ? 'Iscritto: <a href="user.php?id=' . (int)$s['user_id'] . '">' . h($s['email']) . '</a> · ' : ($s['vid'] && !$vid ? 'Visitatore <a href="percorso.php?vid=' . h($s['vid']) . '"><code>' . h(substr($s['vid'], 0, 6)) . '</code></a> (tutte le sue visite) · ' : '') ?>
    <?= h($s['device'] ?: '') ?><?= isset($inf['w']) ? ' · schermo ' . (int)$inf['w'] . '×' . (int)($inf['h'] ?? 0) : '' ?><?= !empty($inf['lang']) ? ' · ' . h($inf['lang']) : '' ?><?= !empty($inf['tz']) ? ' · ' . h($inf['tz']) : '' ?>
    <?= !empty($inf['pwa']) ? ' · app installata' : '' ?><?= !empty($inf['dark']) ? ' · tema scuro' : '' ?>
    <?= !empty($inf['land']) ? '<br>Ingresso: <code>' . h($inf['land']) . '</code>' : '' ?><?= !empty($inf['ref']) ? ' · da <code>' . h($inf['ref']) . '</code>' : '' ?></p>
  <?php if (!$evs): ?><p class="muted">Nessuna azione registrata.</p><?php else: ?><div class="tscroll"><table>
    <thead><tr><th>Ora</th><th>Azione</th><th>Dettaglio</th></tr></thead>
    <tbody><?php foreach ($evs as $e): ?><tr><td><?= (new DateTime($e['at'] . ' UTC'))->setTimezone($tz)->format('H:i:s') ?></td>
      <td><?= h(ev_label($e['ev'])) ?></td><td><small><?= h($e['x'] ?? '') ?></small></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php endforeach; ?>
<?php admin_footer(); ?>
