<?php
// Ingresso dell'admin. Si entra con l'account del sito: gli amministratori sono in admins.json (cartella privata).
// Finché non c'è nessun amministratore, chi è collegato può diventarlo (il primo, una volta sola: poi gli altri li aggiunge lui da Utenti).
require __DIR__ . '/layout.php';
$me = current_user();
if (is_admin($me)) { header('Location: dashboard.php'); exit; }
$none = !admin_ids();
if ($me && $none && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && ($_POST['claim'] ?? '') === '1') {
  admin_set((int)$me['id'], true);
  header('Location: dashboard.php'); exit;
}
page_start('Admin', '<a class="navlink" href="/mappa/">Mappa</a>');
?>
<main class="authwrap"><div class="card">
  <h1 class="ptitle" style="margin-top:0">Admin</h1>
  <?php if (!$me): ?>
    <p>Entra con il tuo account del sito: se sei un amministratore, da qui vai alla dashboard.</p>
    <p><a class="btn primary" href="/account/?next=/admin/">Accedi</a></p>
  <?php elseif ($none): ?>
    <p>Non c'è ancora nessun amministratore. Sei collegato come <b><?= h($me['email']) ?></b>: vuoi diventarlo tu?</p>
    <p class="muted">Si fa una volta sola: poi gli altri amministratori li aggiungi tu dalla pagina Utenti.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="claim" value="1"><button class="btn primary">Diventa amministratore</button></form>
  <?php else: ?>
    <p>Sei collegato come <b><?= h($me['email']) ?></b>, che non è un amministratore.</p>
    <p><a class="btn" href="/account/">Il mio account</a></p>
  <?php endif; ?>
</div></main>
</body></html>
