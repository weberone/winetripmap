<?php
// Impostazioni salvate nella cartella privata (settings.json, mai nel repository né online come file):
// - accesso con Google: Client ID OAuth (pubblico) di Google Cloud
// - posta del sito (SMTP di Infomaniak): per conferma email, nuova password e avvisi all'admin; la password non si rimostra mai
// - email dove ricevere gli avvisi (nuovi iscritti, feedback)
// - titolare ed email per la pagina privacy (/privacy/)
require __DIR__ . '/layout.php';
$me = admin_me();
$s = settings(); $flash = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
  $act = (string)($_POST['act'] ?? 'save');
  if ($act === 'save') {
    $gid = trim((string)($_POST['google_client_id'] ?? ''));
    if ($gid !== '' && !preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $gid)) $err = 'Il Client ID di Google ha questa forma: 1234567890-abc123.apps.googleusercontent.com';
    $notify = trim((string)($_POST['notify_email'] ?? ''));
    if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) $err = "L'email per gli avvisi non è valida.";
    $pm = trim((string)($_POST['privacy_email'] ?? ''));
    if ($pm !== '' && !filter_var($pm, FILTER_VALIDATE_EMAIL)) $err = "L'email per la privacy non è valida.";
    if (!$err) {
      $smtp = $s['smtp'] ?? [];
      foreach (['host', 'user', 'from'] as $k) $smtp[$k] = trim((string)($_POST["smtp_$k"] ?? ''));
      $smtp['port'] = (int)($_POST['smtp_port'] ?? 587) ?: 587;
      if (($_POST['smtp_pass'] ?? '') !== '') $smtp['pass'] = (string)$_POST['smtp_pass']; // vuota = resta quella salvata
      if (!empty($_POST['smtp_clear'])) $smtp = [];
      $s = ['google_client_id' => $gid, 'notify_email' => $notify, 'smtp' => $smtp,
        'privacy_controller' => trim((string)($_POST['privacy_controller'] ?? '')), 'privacy_email' => trim((string)($_POST['privacy_email'] ?? ''))];
      jwrite('settings.json', $s);
      $flash = 'Salvato.';
    }
  } elseif ($act === 'test') {
    $flash = send_action_mail($me['email'], 'it', 'Prova della posta · WineTripMap', 'Se leggi questa email, la posta del sito funziona: conferme, nuove password e avvisi partiranno da qui.', 'Apri la mappa', SITE_URL . '/', 'Email di prova mandata dalla pagina Impostazioni dell\'admin.')
      ? 'Email di prova mandata a ' . $me['email'] . '.' : '';
    if (!$flash) $err = 'Invio non riuscito: controlla server, utente e password della casella.';
  }
}
$smtp = $s['smtp'] ?? [];
admin_header('Impostazioni', 'set');
?>
<?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
<?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
<form method="post" class="form"><?= csrf_field() ?>
<section class="card"><h2>Accesso con Google</h2>
  <p class="muted" style="margin-top:0">Google Cloud → API e servizi → Credenziali → Crea credenziali → ID client OAuth (applicazione web).
    Origini JavaScript autorizzate: <code>https://winetripmap.com</code>. Vuoto = pulsante Google spento.</p>
  <div class="field"><label for="gid">Client ID</label><input id="gid" name="google_client_id" value="<?= h($s['google_client_id'] ?? '') ?>" placeholder="1234567890-abc123.apps.googleusercontent.com" autocomplete="off"></div>
</section>
<section class="card"><h2>Posta del sito</h2>
  <p class="muted" style="margin-top:0">Serve per conferma email, nuova password e avvisi. Su Infomaniak: crea la casella (es. <code>noreply@winetripmap.com</code>) e usa
    server <code>mail.infomaniak.com</code>, porta 587. <?= !empty($smtp['pass']) ? 'Password salvata: lasciala vuota per tenerla.' : 'Nessuna password salvata.' ?></p>
  <div class="grid2" style="margin:0">
    <div class="field"><label for="sh">Server</label><input id="sh" name="smtp_host" value="<?= h($smtp['host'] ?? '') ?>" placeholder="mail.infomaniak.com" autocomplete="off"></div>
    <div class="field"><label for="sp">Porta</label><input id="sp" name="smtp_port" inputmode="numeric" value="<?= h($smtp['port'] ?? 587) ?>"></div>
    <div class="field"><label for="su">Utente (indirizzo della casella)</label><input id="su" name="smtp_user" value="<?= h($smtp['user'] ?? '') ?>" placeholder="noreply@winetripmap.com" autocomplete="off"></div>
    <div class="field"><label for="spw">Password della casella</label><input id="spw" name="smtp_pass" type="password" autocomplete="new-password" placeholder="<?= !empty($smtp['pass']) ? '•••••••• (salvata)' : '' ?>"></div>
    <div class="field"><label for="sf">Mittente</label><input id="sf" name="smtp_from" value="<?= h($smtp['from'] ?? '') ?>" placeholder="noreply@winetripmap.com" autocomplete="off"></div>
  </div>
  <label class="muted"><input type="checkbox" name="smtp_clear" value="1"> Cancella la configurazione della posta</label>
</section>
<section class="card"><h2>Avvisi all'amministratore</h2>
  <div class="field"><label for="ne">Email dove ricevere nuovi iscritti e feedback <small>(vuoto = nessun avviso)</small></label><input id="ne" name="notify_email" type="email" value="<?= h($s['notify_email'] ?? '') ?>"></div>
</section>
<section class="card"><h2>Privacy</h2>
  <p class="muted" style="margin-top:0">Compaiono nella pagina <a href="/privacy/">/privacy/</a> (informativa e cookie): chi tratta i dati e dove scrivere.</p>
  <div class="field"><label for="pc">Titolare del trattamento <small>(nome e cognome o società)</small></label><input id="pc" name="privacy_controller" value="<?= h($s['privacy_controller'] ?? '') ?>" autocomplete="off"></div>
  <div class="field"><label for="pe">Email per le richieste privacy</label><input id="pe" name="privacy_email" type="email" value="<?= h($s['privacy_email'] ?? '') ?>" autocomplete="off"></div>
</section>
<div class="row"><button class="btn primary" name="act" value="save">Salva</button>
  <?php if (mail_ready()): ?><button class="btn" name="act" value="test">Mandami un'email di prova</button><?php endif; ?></div>
</form>
<?php admin_footer(); ?>
