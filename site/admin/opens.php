<?php
// Aperture della mappa, una per riga (le più recenti prima): ora, da dove, pagina d'ingresso, GPS, cookie, tempo sulla mappa,
// cantine aperte, dispositivo, lingua, mappa usata, iscritto. Filtri: ?day=AAAA-MM-GG, ?src=fonte.
// Nessun dato personale: il visitatore è solo un cookie casuale (mostrato accorciato per riconoscere chi torna).
require __DIR__ . '/layout.php';
require __DIR__ . '/eventi_lib.php';
admin_me();
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['day'] ?? '')) ? $_GET['day'] : '';
$src = preg_replace('/[^a-z0-9._-]/', '', (string)($_GET['src'] ?? ''));
$w = []; $p = [];
if ($day) { $w[] = 'o.day = ?'; $p[] = $day; }
if ($src !== '') { $w[] = $src === 'sconosciuta' ? 'o.source IS NULL' : 'o.source = ?'; if ($src !== 'sconosciuta') $p[] = $src; }
$st = db()->prepare('SELECT o.*, u.email, (SELECT 1 FROM user_sessions s WHERE s.sid = o.sid) has_path FROM app_opens o LEFT JOIN users u ON u.id = o.user_id '
  . ($w ? 'WHERE ' . implode(' AND ', $w) . ' ' : '') . 'ORDER BY o.id DESC LIMIT 300');
$st->execute($p);
$mins = function ($s) { $s = (int)$s; return $s >= 60 ? floor($s / 60) . ' min' . ($s % 60 ? ' ' . ($s % 60) . ' s' : '') : $s . ' s'; };
$list = $st->fetchAll();
// quante volte è tornato ogni visitatore (per "di ritorno")
$seen = [];
if ($list) { $vids = array_values(array_unique(array_filter(array_column($list, 'vid'))));
  if ($vids) { $in = implode(',', array_fill(0, count($vids), '?')); $s = db()->prepare("SELECT vid, COUNT(DISTINCT day) d FROM app_opens WHERE vid IN ($in) GROUP BY vid"); $s->execute($vids); foreach ($s as $r) $seen[$r['vid']] = (int)$r['d']; } }
$tz = new DateTimeZone('Europe/Rome');
admin_header(($day ? 'Aperture del ' . (new DateTime($day))->format('d/m/Y') : 'Aperture della mappa') . ($src !== '' ? ' · ' . $src : ''), 'opens');
?>
<section class="card">
  <form method="get" class="usearch"><input type="date" name="day" value="<?= h($day) ?>" aria-label="Giorno"><?php if ($src !== ''): ?><input type="hidden" name="src" value="<?= h($src) ?>"><?php endif; ?><button class="btn">Mostra</button><?php if ($day || $src !== ''): ?> <a class="btn" href="opens.php">Tutte</a><?php endif; ?> <a href="visite.php">Riepilogo delle visite →</a></form>
  <?php if (!$list): ?><p class="muted">Ancora nessuna apertura.</p><?php else: ?>
  <div class="tscroll"><table>
    <thead><tr><th>Ora</th><th>Visitatore</th><th>Luogo</th><th>Da dove</th><th>Ingresso</th><th title="GPS attivato">GPS</th><th title="Scelta sui cookie">Cookie</th><th class="r" title="Con la mappa davanti">Tempo</th><th class="r" title="Schede di cantine aperte">Cantine</th><th>Dispositivo</th><th>Lingua</th><th>Mappa</th><th>Iscritto</th><th>Percorso</th></tr></thead>
    <tbody><?php foreach ($list as $o): $n = $seen[$o['vid']] ?? 1; ?><tr>
      <td><?= (new DateTime($o['at'] . ' UTC'))->setTimezone($tz)->format($day ? 'H:i' : 'd/m H:i') ?></td>
      <td><a href="percorso.php?vid=<?= h($o['vid']) ?>"><code><?= h(substr((string)$o['vid'], 0, 6)) ?></code></a> <?= $n > 1 ? '<small class="muted">' . $n . ' giorni</small>' : '<small class="muted">nuovo</small>' ?></td>
      <td><small><?= h(implode(', ', array_filter([$o['city'] ?? '', $o['cc'] ?? '']))) ?: '—' ?></small></td>
      <td><?= h($o['source']) ?><?= $o['ref'] && $o['ref'] !== $o['source'] ? ' <small class="muted">' . h($o['ref']) . '</small>' : '' ?></td>
      <td><small><?= h(land_kind($o['land'] ?? null)) ?></small></td>
      <td><?= ($o['gps'] ?? null) === null ? '<small class="muted">—</small>' : ($o['gps'] ? '<b>sì</b>' : 'no') ?></td>
      <td><?= ($o['ck'] ?? null) === null ? '<small class="muted">—</small>' : ($o['ck'] ? 'Accetta' : 'Rifiuta') ?></td>
      <td class="r"><?= $o['secs'] ? $mins($o['secs']) : '—' ?></td><td class="r"><?= $o['views'] ? num($o['views']) : '—' ?></td>
      <td><small><?= h($o['device']) ?></small></td><td><?= h($o['lang']) ?></td><td><?= h($o['map'] ?: '—') ?></td>
      <td><?= $o['email'] ? '<a href="user.php?id=' . (int)$o['user_id'] . '">' . h($o['email']) . '</a>' : '—' ?></td>
      <td><?= $o['has_path'] ? '<a href="percorso.php?sid=' . h($o['sid']) . '">vedi →</a>' : '<small class="muted">—</small>' ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
  <?php if (count($list) === 300): ?><p class="muted">Mostrate le 300 più recenti: scegli un giorno per vedere le altre.</p><?php endif; ?>
  <?php endif; ?>
</section>
<?php admin_footer(); ?>
