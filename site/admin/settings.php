<?php
// Impostazioni salvate nella cartella privata (settings.json, mai nel repository né online come file):
// - accesso con Google: Client ID OAuth (pubblico) di Google Cloud
// - posta del sito (SMTP di Infomaniak): per conferma email, nuova password e avvisi all'admin; la password non si rimostra mai
// - email dove ricevere gli avvisi (nuovi iscritti, feedback)
// - titolare ed email per la pagina privacy (/privacy/)
// - notifiche push degli avvisi di visita (api/visit_notify.php): dispositivi iscritti e interruttori, in webpush.json
require __DIR__ . '/layout.php';
require_once dirname(__DIR__) . '/api/webpush.php';
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
      $kofi = trim((string)($_POST['kofi_url'] ?? ''));
      if ($kofi !== '' && !preg_match('~^https://ko-fi\.com/[A-Za-z0-9_]+/?$~', $kofi)) $err = 'Il link di Ko-fi ha questa forma: https://ko-fi.com/winetripmap';
      $ktok = trim((string)($_POST['kofi_token'] ?? '')); if ($ktok === '') $ktok = (string)($s['kofi_token'] ?? ''); // vuoto = resta quello salvato
      if (!empty($_POST['kofi_clear'])) $ktok = '';
    }
    if (!$err) {
      $s = ['google_client_id' => $gid, 'notify_email' => $notify, 'smtp' => $smtp, 'kofi_url' => rtrim($kofi, '/'), 'kofi_token' => $ktok,
        'privacy_controller' => trim((string)($_POST['privacy_controller'] ?? '')), 'privacy_email' => trim((string)($_POST['privacy_email'] ?? ''))];
      jwrite('settings.json', $s);
      $flash = 'Salvato.';
    }
  } elseif ($act === 'ptoggle') { // interruttori degli avvisi di visita
    $d = webpush_data(); $k = (string)($_POST['k'] ?? '');
    if (in_array($k, ['new', 'return', 'users'], true)) { $d['opts'] = ($d['opts'] ?? []) + ['new' => true, 'return' => true, 'users' => true]; $d['opts'][$k] = !empty($_POST['on']); webpush_save($d); }
    $flash = 'Avvisi aggiornati.';
  } elseif ($act === 'ptest') {
    $n = webpush_send('🍷 Prova di WineTripMap', "Le notifiche funzionano.\nTocca per aprire le Visite.", '/admin/visite.php');
    if ($n) $flash = "Notifica di prova mandata a $n dispositiv" . ($n === 1 ? 'o' : 'i') . '.'; else $err = 'Nessun dispositivo l\'ha ricevuta: attiva le notifiche qui sotto, dal dispositivo che deve riceverle.';
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
<?php $pd = webpush_data(); $po = ($pd['opts'] ?? []) + ['new' => true, 'return' => true, 'users' => true]; ?>
<section class="card" id="push"><h2>Notifiche quando qualcuno apre la mappa</h2>
  <p class="muted" style="margin-top:0">Arrivano come le notifiche delle app, con il luogo (città dall'indirizzo IP), da dove arriva, dispositivo e lingua; toccandola si apre la pagina del visitatore
    con tutte le sue aperture e, se ha accettato i cookie o ha un account, il percorso nella mappa. Una per visita (ricaricare la pagina non conta), al massimo <?= 30 ?> l'ora; mai per gli amministratori né per i robot.
    <b>Su iPhone</b>: Safari → Condividi → Aggiungi alla schermata Home, apri WineTripMap dall'icona, accedi, vai qui (account → Admin → Impostazioni) e tocca il pulsante.</p>
  <p class="muted" id="pushList"><?= $pd['subs'] ? 'Dispositivi attivi: ' . h(implode(' · ', array_map(fn($x) => $x['label'] . ' (dal ' . $x['added'] . ')', $pd['subs']))) : 'Nessun dispositivo attivo.' ?></p>
  <div class="row"><button type="button" class="btn primary" id="pushOn">Attiva su questo dispositivo</button> <button type="button" class="btn" id="pushOff" hidden>Disattiva su questo dispositivo</button>
    <form method="post" style="display:inline"><?= csrf_field() ?><button class="btn" name="act" value="ptest">Manda una notifica di prova</button></form></div>
  <p class="muted" id="pushMsg" role="status"></p>
  <div class="tscroll"><table><tbody>
  <?php foreach (['new' => 'Visitatori nuovi (prima volta sulla mappa)', 'return' => 'Visitatori che tornano', 'users' => 'Iscritti'] as $k => $label): ?>
    <tr><td><?= h($label) ?></td><td class="r"><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="ptoggle"><input type="hidden" name="k" value="<?= $k ?>">
      <?php if ($po[$k]): ?><b>Accesi</b> <button class="btn" name="on" value="">Spegni</button><?php else: ?><span class="muted">Spenti</span> <button class="btn" name="on" value="1">Accendi</button><?php endif; ?></form></td></tr>
  <?php endforeach; ?></tbody></table></div>
</section>
<script>
// notifiche: service worker /push-sw.js e iscrizione salvata da push.php (come SkateboardMap)
(() => {
  const on = document.getElementById("pushOn"), off = document.getElementById("pushOff"), msg = document.getElementById("pushMsg");
  const csrf = <?= json_encode(csrf()) ?>, say = (t) => { msg.textContent = t; };
  if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
    on.disabled = true;
    say(/iPhone|iPad/.test(navigator.userAgent) ? "Su iPhone: aggiungi WineTripMap alla schermata Home, aprilo dall'icona e torna qui da lì." : "Questo browser non supporta le notifiche push.");
    return;
  }
  const key = (b) => Uint8Array.from(atob(b.replace(/-/g, "+").replace(/_/g, "/") + "===".slice((b.length + 3) % 4)), (c) => c.charCodeAt(0));
  const post = (body) => fetch("push.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ csrf, ...body }) }).then((r) => r.json());
  const reg = navigator.serviceWorker.register("/push-sw.js", { scope: "/" });
  reg.then((r) => r.pushManager.getSubscription()).then((s) => { if (s) { on.hidden = true; off.hidden = false; say("Attive su questo dispositivo."); } }).catch(() => {});
  on.onclick = async () => {
    try {
      if (await Notification.requestPermission() !== "granted") return say("Permesso negato: riattivalo dalle Impostazioni del telefono (Notifiche → WineTripMap).");
      const r = await reg, { key: k } = await (await fetch("push.php?a=key")).json();
      const sub = await r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key(k) });
      const d = await post({ a: "subscribe", sub: sub.toJSON() });
      if (!d.ok) throw new Error(d.error || "errore");
      on.hidden = true; off.hidden = false; say("Attivate su questo dispositivo. Premi \"Manda una notifica di prova\" per provarle.");
    } catch (e) { say("Non è stato possibile attivarle: " + e.message); }
  };
  off.onclick = async () => {
    const s = await (await reg).pushManager.getSubscription();
    if (s) { await post({ a: "unsubscribe", endpoint: s.endpoint }); await s.unsubscribe(); }
    on.hidden = false; off.hidden = true; say("Disattivate su questo dispositivo.");
  };
})();
</script>
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
<section class="card"><h2>Donazioni (Ko-fi)</h2>
  <p class="muted" style="margin-top:0">Il link è quello della pagina <a href="/dona/">/dona/</a> (vuoto = "Le donazioni aprono a breve"). Su Ko-fi → Settings → API: Webhook URL
    <code><?= h(SITE_URL) ?>/api/kofi.php</code> e qui sotto il Verification Token; poi su Ko-fi "Send Test".
    <?php $kt = @file_get_contents(priv('kofi_test.txt')); ?><?= $kt ? 'Ultimo test ricevuto: ' . h($kt) . ' UTC.' : 'Nessun test ricevuto finora.' ?> <?php $kl = @file_get_contents(priv('kofi_last.txt')); ?><?= $kl ? 'Ultimo messaggio da Ko-fi: ' . h($kl) . ' (UTC).' : '' ?> <?= !empty($s['kofi_token']) ? 'Token salvato: lascialo vuoto per tenerlo.' : 'Nessun token salvato.' ?></p>
  <div class="grid2" style="margin:0">
    <div class="field"><label for="kurl">Pagina Ko-fi</label><input id="kurl" name="kofi_url" value="<?= h($s['kofi_url'] ?? '') ?>" placeholder="https://ko-fi.com/winetripmap" autocomplete="off"></div>
    <div class="field"><label for="ktok">Verification Token</label><input id="ktok" name="kofi_token" type="password" autocomplete="new-password" placeholder="<?= !empty($s['kofi_token']) ? '••••••••' : '' ?>"></div>
  </div>
  <label class="muted"><input type="checkbox" name="kofi_clear" value="1"> Cancella il token</label>
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
