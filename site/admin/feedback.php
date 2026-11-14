<?php
// Consigli e malfunzionamenti mandati dagli iscritti (api/feedback.php): da leggere, poi "Fatto".
require __DIR__ . '/layout.php';
admin_me();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && isset($_POST['id'])) {
  db()->prepare('UPDATE feedback SET status = ? WHERE id = ?')->execute([($_POST['act'] ?? '') === 'reopen' ? 'new' : 'done', (int)$_POST['id']]);
  header('Location: feedback.php'); exit;
}
$list = db()->query('SELECT f.*, u.email FROM feedback f LEFT JOIN users u ON u.id = f.user_id ORDER BY f.status = \'done\', f.id DESC LIMIT 300')->fetchAll();
admin_header('Feedback', 'fb');
?>
<section class="card">
  <?php if (!$list): ?><p class="muted">Ancora nessun messaggio. Gli iscritti scrivono dalla pagina del loro account.</p><?php endif; ?>
  <?php foreach ($list as $f): ?>
    <div class="fbitem"<?= $f['status'] === 'done' ? ' style="opacity:.55"' : '' ?>>
      <span class="tag<?= $f['kind'] === 'bug' ? ' bug' : '' ?>"><?= $f['kind'] === 'bug' ? 'Malfunzionamento' : 'Consiglio' ?></span>
      <small class="muted"><?= when($f['created_at']) ?> · <?= $f['email'] ? '<a href="user.php?id=' . (int)$f['user_id'] . '">' . h($f['email']) . '</a>' : 'account cancellato' ?></small>
      <p><?= h($f['message']) ?></p>
      <?php if ($f['context']): ?><small class="muted"><?= h($f['context']) ?></small><?php endif; ?>
      <form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
        <?= $f['status'] === 'done' ? '<button class="btn small" name="act" value="reopen">Da rileggere</button>' : '<button class="btn small primary" name="act" value="done">Fatto</button>' ?></form>
    </div>
  <?php endforeach; ?>
</section>
<?php admin_footer(); ?>
