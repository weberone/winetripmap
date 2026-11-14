<?php
// Utenti registrati: numeri, iscrizioni al giorno, elenco con ricerca (email, nome, accesso, date, visite, preferite).
// Mai password o gettoni.
require __DIR__ . '/layout.php';
admin_me();
$tot = (int)one('SELECT COUNT(*) FROM users');
$verified = (int)one('SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL');
$google = (int)one('SELECT COUNT(*) FROM users WHERE google_sub IS NOT NULL');
$new7 = (int)one('SELECT COUNT(*) FROM users WHERE created_at > ?', [ago_days(7)]);
$active30 = (int)one("SELECT COUNT(*) FROM users WHERE MAX(COALESCE(last_seen_at, ''), COALESCE(last_login_at, '')) > ?", [ago_days(30)]);
$days = day_series(30);
foreach (db()->query("SELECT created_at FROM users WHERE created_at > '" . ago_days(31) . "'") as $r) {
  $d = (new DateTime($r['created_at'] . ' UTC'))->setTimezone(new DateTimeZone('Europe/Rome'))->format('Y-m-d');
  if (isset($days[$d])) $days[$d]++;
}
$q = trim((string)($_GET['q'] ?? ''));
$st = db()->prepare('SELECT u.*, (SELECT COUNT(*) FROM favorites f WHERE f.user_id = u.id) n_fav FROM users u '
  . ($q !== '' ? 'WHERE u.email LIKE :q OR u.name LIKE :q ' : '') . 'ORDER BY u.id DESC LIMIT 300');
$st->execute($q !== '' ? [':q' => "%$q%"] : []);
$list = $st->fetchAll();
admin_header('Utenti', 'users');
?>
<section class="kpis">
  <div class="kpi"><span>Iscritti</span><b><?= num($tot) ?></b><small><?= num($new7) ?> negli ultimi 7 giorni</small></div>
  <div class="kpi"><span>Attivi in 30 giorni</span><b><?= num($active30) ?></b><small>hanno aperto la mappa</small></div>
  <div class="kpi"><span>Email confermata</span><b><?= num($verified) ?></b><small><?= $tot ? round($verified / $tot * 100) : 0 ?>% degli iscritti</small></div>
  <div class="kpi"><span>Con Google</span><b><?= num($google) ?></b><small><?= num($tot - $google) ?> con password</small></div>
  <div class="kpi"><span>Amministratori</span><b><?= num(count(admin_ids())) ?></b><small>entrano nell'admin</small></div>
</section>
<?= chart('Iscrizioni al giorno', $days) ?>
<section class="card">
  <form method="get" class="usearch"><input type="search" name="q" value="<?= h($q) ?>" placeholder="Cerca per email o nome" aria-label="Cerca utenti">
    <button class="btn">Cerca</button><?php if ($q !== ''): ?> <a class="btn" href="users.php">Tutti</a><?php endif; ?></form>
  <?php if (!$list): ?><p class="muted"><?= $q !== '' ? 'Nessun utente trovato.' : 'Ancora nessun iscritto.' ?></p>
  <?php else: ?><div class="tscroll"><table>
    <thead><tr><th>Email</th><th>Nome</th><th>Ruolo</th><th>Accesso</th><th>Iscritto</th><th class="r">Visite</th><th>Ultima visita</th><th class="r">Preferite</th></tr></thead>
    <tbody><?php foreach ($list as $u): ?><tr>
      <td><a href="user.php?id=<?= (int)$u['id'] ?>"><?= h($u['email']) ?></a><?= $u['email_verified_at'] ? ' <span title="email confermata">✓</span>' : ' <small class="muted">da confermare</small>' ?></td>
      <td><?= h($u['name'] ?: '—') ?></td>
      <td><?= is_admin($u) ? '<b class="role sa">Admin</b>' : '<span class="role">Utente</span>' ?></td>
      <td><?= implode(' + ', array_filter([$u['google_sub'] ? 'Google' : '', $u['pass_hash'] ? 'password' : ''])) ?></td>
      <td><?= when($u['created_at']) ?></td>
      <td class="r"><?= num($u['visit_count']) ?></td>
      <td><?= when($u['last_seen_at'] ?: $u['last_login_at']) ?></td>
      <td class="r"><?= num($u['n_fav']) ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?php if (count($list) === 300): ?><p class="muted">Mostrati i 300 più recenti: usa la ricerca per gli altri.</p><?php endif; ?>
  <?php endif; ?>
</section>
<?php admin_footer(true); ?>
