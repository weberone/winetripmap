<?php
// Aperture della mappa, una per riga (le più recenti prima): ora, da dove, dispositivo, lingua, mappa usata, iscritto.
// Nessun dato personale: il visitatore è solo un cookie casuale (mostrato accorciato per riconoscere chi torna).
require __DIR__ . '/layout.php';
admin_me();
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['day'] ?? '')) ? $_GET['day'] : '';
$st = db()->prepare('SELECT o.*, u.email FROM app_opens o LEFT JOIN users u ON u.id = o.user_id ' . ($day ? 'WHERE o.day = ? ' : '') . 'ORDER BY o.id DESC LIMIT 300');
$st->execute($day ? [$day] : []);
$list = $st->fetchAll();
// quante volte è tornato ogni visitatore (per "di ritorno")
$seen = [];
if ($list) { $vids = array_values(array_unique(array_filter(array_column($list, 'vid'))));
  if ($vids) { $in = implode(',', array_fill(0, count($vids), '?')); $s = db()->prepare("SELECT vid, COUNT(DISTINCT day) d FROM app_opens WHERE vid IN ($in) GROUP BY vid"); $s->execute($vids); foreach ($s as $r) $seen[$r['vid']] = (int)$r['d']; } }
$tz = new DateTimeZone('Europe/Rome');
admin_header($day ? 'Aperture del ' . (new DateTime($day))->format('d/m/Y') : 'Aperture della mappa', 'opens');
?>
<section class="card">
  <form method="get" class="usearch"><input type="date" name="day" value="<?= h($day) ?>" aria-label="Giorno"><button class="btn">Mostra</button><?php if ($day): ?> <a class="btn" href="opens.php">Tutte</a><?php endif; ?></form>
  <?php if (!$list): ?><p class="muted">Ancora nessuna apertura.</p><?php else: ?>
  <div class="tscroll"><table>
    <thead><tr><th>Ora</th><th>Visitatore</th><th>Da dove</th><th>Dispositivo</th><th>Lingua</th><th>Fuso</th><th>Mappa</th><th>Iscritto</th></tr></thead>
    <tbody><?php foreach ($list as $o): $n = $seen[$o['vid']] ?? 1; ?><tr>
      <td><?= (new DateTime($o['at'] . ' UTC'))->setTimezone($tz)->format($day ? 'H:i' : 'd/m H:i') ?></td>
      <td><code><?= h(substr((string)$o['vid'], 0, 6)) ?></code> <?= $n > 1 ? '<small class="muted">' . $n . ' giorni</small>' : '<small class="muted">nuovo</small>' ?></td>
      <td><?= h($o['source']) ?><?= $o['ref'] && $o['ref'] !== $o['source'] ? ' <small class="muted">' . h($o['ref']) . '</small>' : '' ?></td>
      <td><?= h($o['device']) ?></td><td><?= h($o['lang']) ?></td><td><small><?= h($o['tz']) ?></small></td><td><?= h($o['map'] ?: '—') ?></td>
      <td><?= $o['email'] ? '<a href="user.php?id=' . (int)$o['user_id'] . '">' . h($o['email']) . '</a>' : '—' ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
  <?php if (count($list) === 300): ?><p class="muted">Mostrate le 300 più recenti: scegli un giorno per vedere le altre.</p><?php endif; ?>
  <?php endif; ?>
</section>
<?php admin_footer(); ?>
