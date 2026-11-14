<?php
// Scheda di un utente: dati dell'account, cantine preferite, ultime aperture della mappa, feedback.
// Azioni: rendere o togliere amministratore, cancellare l'account (come farebbe lui dal suo profilo).
require __DIR__ . '/layout.php';
$me = admin_me();
$id = (int)($_GET['id'] ?? 0);
$u = user_by_id($id);
if (!$u) { header('Location: users.php'); exit; }
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'admin_on') { admin_set($id, true); $flash = 'Ora è un amministratore.'; }
  elseif ($act === 'admin_off' && $id !== (int)$me['id']) { admin_set($id, false); $flash = 'Non è più un amministratore.'; }
  elseif ($act === 'delete' && $id !== (int)$me['id'] && ($_POST['confirm'] ?? '') === $u['email']) {
    foreach (['user_tokens', 'favorites', 'trips', 'feedback'] as $t) db()->prepare("DELETE FROM $t WHERE user_id = ?")->execute([$id]);
    db()->prepare('UPDATE app_opens SET user_id = NULL WHERE user_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    admin_set($id, false);
    header('Location: users.php'); exit;
  } elseif ($act === 'delete') $flash = 'Per cancellare scrivi esattamente la sua email.';
}
$favs = db()->prepare('SELECT * FROM favorites WHERE user_id = ? ORDER BY created_at DESC'); $favs->execute([$id]); $favs = $favs->fetchAll();
$opens = db()->prepare('SELECT * FROM app_opens WHERE user_id = ? ORDER BY id DESC LIMIT 30'); $opens->execute([$id]); $opens = $opens->fetchAll();
$fb = db()->prepare('SELECT * FROM feedback WHERE user_id = ? ORDER BY id DESC'); $fb->execute([$id]); $fb = $fb->fetchAll();
admin_header($u['name'] ?: $u['email'], 'users');
?>
<?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
<section class="card"><table><tbody>
  <tr><th>Email</th><td><?= h($u['email']) ?> <?= $u['email_verified_at'] ? '✓ confermata ' . when($u['email_verified_at']) : '<span class="muted">da confermare</span>' ?></td></tr>
  <tr><th>Nome</th><td><?= h($u['name'] ?: '—') ?></td></tr>
  <tr><th>Accesso</th><td><?= implode(' + ', array_filter([$u['google_sub'] ? 'Google' : '', $u['pass_hash'] ? 'password' : ''])) ?></td></tr>
  <tr><th>Ruolo</th><td><?= is_admin($u) ? '<b class="role sa">Admin</b>' : 'Utente' ?></td></tr>
  <tr><th>Iscritto</th><td><?= when($u['created_at']) ?></td></tr>
  <tr><th>Ultimo accesso</th><td><?= when($u['last_login_at']) ?></td></tr>
  <tr><th>Visite</th><td><?= num($u['visit_count']) ?> · ultima <?= when($u['last_seen_at']) ?></td></tr>
</tbody></table></section>
<section class="grid2">
  <div class="card"><h2>Cantine preferite (<?= count($favs) ?>)</h2>
    <?php if (!$favs): ?><p class="muted">Nessuna.</p><?php else: ?><table><tbody><?php foreach ($favs as $f): ?>
      <tr><td><a href="/mappa/?c=<?= h($f['winery']) ?>" target="_blank" rel="noopener"><?= h($f['name'] ?: $f['winery']) ?></a> <small class="muted"><?= h($f['town']) ?></small></td><td class="r"><?= when($f['created_at']) ?></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?></div>
  <div class="card"><h2>Ultime aperture della mappa</h2>
    <?php if (!$opens): ?><p class="muted">Nessuna da collegato.</p><?php else: ?><table><tbody><?php foreach ($opens as $o): ?>
      <tr><td><?= when($o['at']) ?></td><td><?= h($o['source']) ?></td><td><small class="muted"><?= h($o['device']) ?></small></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?></div>
</section>
<?php if ($fb): ?><section class="card"><h2>Feedback</h2><?php foreach ($fb as $f): ?>
  <div class="fbitem"><span class="tag<?= $f['kind'] === 'bug' ? ' bug' : '' ?>"><?= $f['kind'] === 'bug' ? 'Malfunzionamento' : 'Consiglio' ?></span> <small class="muted"><?= when($f['created_at']) ?></small><p><?= h($f['message']) ?></p></div>
<?php endforeach; ?></section><?php endif; ?>
<section class="card"><h2>Azioni</h2>
  <div class="row">
    <?php if (!is_admin($u)): ?><form method="post"><?= csrf_field() ?><button class="btn" name="act" value="admin_on">Rendi amministratore</button></form>
    <?php elseif ($id !== (int)$me['id']): ?><form method="post"><?= csrf_field() ?><button class="btn" name="act" value="admin_off">Togli amministratore</button></form><?php endif; ?>
  </div>
  <?php if ($id !== (int)$me['id']): ?>
  <form method="post" class="form" style="margin-top:14px;max-width:420px"><?= csrf_field() ?>
    <div class="field"><label for="cf">Cancella l'account: scrivi la sua email per confermare</label><input id="cf" name="confirm" autocomplete="off"></div>
    <div class="row"><button class="btn danger" name="act" value="delete">Cancella l'account</button></div>
  </form><?php endif; ?>
</section>
<?php admin_footer(); ?>
