<?php
// Il tuo account: accesso e registrazione (email e password o Google), conferma email, nuova password,
// profilo, cantine preferite, consigli, dati e cancellazione. Le azioni passano da api/auth.php (account.js).
// ?next=/... = dove tornare dopo l'accesso (solo indirizzi di questo sito).
require dirname(__DIR__) . '/api/lib.php';
require dirname(__DIR__) . '/api/page.php';
$me = current_user();
$next = (string)($_GET['next'] ?? '');
if (!preg_match('~^/(?!/)[^\s]*$~', $next)) $next = '/';
$verify = (string)($_GET['verify'] ?? ''); $reset = (string)($_GET['reset'] ?? '');
$gid = google_client_id();
$head = $gid ? ['<script src="https://accounts.google.com/gsi/client" async></script>'] : [];
$right = $me ? (is_admin($me) ? '<a class="navlink" href="/admin/">Admin</a>' : '') . '<a class="navlink" href="/">Mappa</a>' : '<a class="navlink" href="/">Mappa</a>';
page_start($me ? 'Il tuo account' : 'Accedi', $right, '/', $head);
$cfg = ['next' => $next, 'google' => $gid ?: null, 'verify' => $verify, 'reset' => $reset];
?>
<main>
<?php if ($verify !== ''): ?>
  <div class="authwrap"><div class="card"><h1 class="ptitle" style="margin-top:0">Conferma email</h1><p id="vMsg" class="muted">Controllo il link…</p>
    <p><a class="btn primary" href="/">Apri la mappa</a></p></div></div>

<?php elseif ($reset !== ''): ?>
  <div class="authwrap"><div class="card"><h1 class="ptitle" style="margin-top:0">Nuova password</h1>
    <form class="form" id="resetForm">
      <div class="field"><label for="rPw">Nuova password</label><input id="rPw" type="password" autocomplete="new-password" minlength="8" required><small>Almeno 8 caratteri</small></div>
      <p class="err" id="rErr" hidden></p>
      <button class="btn primary">Salva e accedi</button>
    </form></div></div>

<?php elseif (!$me): ?>
  <div class="authwrap">
    <h1 class="ptitle">Il tuo account WineTripMap</h1>
    <p class="lead">Salva le cantine che vuoi visitare e ritrovale su ogni dispositivo.</p>
    <div class="card">
      <div class="tabs" role="tablist">
        <button type="button" role="tab" id="tLogin" aria-selected="true">Accedi</button>
        <button type="button" role="tab" id="tReg" aria-selected="false">Registrati</button>
      </div>
      <?php if ($gid): ?><div class="gbtn" id="gBtn"></div><div class="or">oppure con l'email</div><?php endif; ?>
      <form class="form" id="loginForm">
        <div class="field"><label for="lEmail">Email</label><input id="lEmail" type="email" autocomplete="email" required></div>
        <div class="field"><label for="lPw">Password</label><input id="lPw" type="password" autocomplete="current-password" required></div>
        <p class="err" id="lErr" hidden></p>
        <button class="btn primary">Accedi</button>
        <button type="button" class="linkbtn" id="toForgot">Password dimenticata?</button>
      </form>
      <form class="form" id="regForm" hidden>
        <div class="field"><label for="gName">Nome <small>(facoltativo)</small></label><input id="gName" autocomplete="given-name" maxlength="60"></div>
        <div class="field"><label for="gEmail">Email</label><input id="gEmail" type="email" autocomplete="email" required></div>
        <div class="field"><label for="gPw">Password</label><input id="gPw" type="password" autocomplete="new-password" minlength="8" required><small>Almeno 8 caratteri</small></div>
        <p class="err" id="gErr" hidden></p>
        <button class="btn primary">Crea l'account</button>
        <p class="muted" style="margin:0">Registrandoti accetti che salviamo email, nome e cantine preferite per farti usare l'account. Puoi scaricare i tuoi dati o cancellare l'account quando vuoi.</p>
      </form>
      <form class="form" id="forgotForm" hidden>
        <p class="muted" style="margin:0">Ti mandiamo un link per scegliere una nuova password.</p>
        <div class="field"><label for="fEmail">Email</label><input id="fEmail" type="email" autocomplete="email" required></div>
        <p class="ok" id="fOk" hidden></p><p class="err" id="fErr" hidden></p>
        <button class="btn primary">Mandami il link</button>
        <button type="button" class="linkbtn" id="backLogin">Torna all'accesso</button>
      </form>
    </div>
  </div>

<?php else: $u = public_user($me); ?>
  <div class="w">
    <h1 class="ptitle">Ciao<?= $u['name'] ? ' ' . h($u['name']) : '' ?></h1>
    <p class="lead"><?= h($u['email']) ?><?= $u['google'] ? ' · accesso con Google' : '' ?></p>
    <?php if (!$u['email_verified']): ?>
      <div class="flash err">Email da confermare: apri il link che ti abbiamo mandato. <button class="linkbtn" id="resend">Mandalo di nuovo</button> <span id="resendMsg"></span></div>
    <?php endif; ?>

    <section class="card"><h2>I tuoi trip</h2>
      <ul class="favs" id="trips"><li class="muted">Carico…</li></ul>
    </section>

    <section class="card"><h2>Cantine preferite</h2>
      <ul class="favs" id="favs"><li class="muted">Carico…</li></ul>
    </section>

    <section class="card"><h2>Profilo</h2>
      <form class="form" id="profForm">
        <div class="field"><label for="pName">Nome</label><input id="pName" maxlength="60" value="<?= h($u['name']) ?>"></div>
        <?php if ($u['has_password']): ?>
          <div class="field"><label for="pCur">Password attuale <small>(solo per cambiarla)</small></label><input id="pCur" type="password" autocomplete="current-password"></div>
        <?php endif; ?>
        <div class="field"><label for="pNew"><?= $u['has_password'] ? 'Nuova password' : 'Aggiungi una password' ?> <small>(facoltativo)</small></label><input id="pNew" type="password" autocomplete="new-password" minlength="8"></div>
        <p class="err" id="pErr" hidden></p><p class="ok" id="pOk" hidden></p>
        <div class="row"><button class="btn primary">Salva</button></div>
      </form>
    </section>

    <section class="card"><h2>Consigli e malfunzionamenti</h2>
      <form class="form" id="fbForm">
        <div class="field"><label for="fbKind">Tipo</label><select id="fbKind"><option value="idea">Un consiglio o un'idea</option><option value="bug">Qualcosa non funziona</option></select></div>
        <div class="field"><label for="fbMsg">Messaggio</label><textarea id="fbMsg" maxlength="3000" required placeholder="Una cantina che manca, un vino sbagliato, un'idea…"></textarea></div>
        <p class="err" id="fbErr" hidden></p><p class="ok" id="fbOk" hidden></p>
        <div class="row"><button class="btn primary">Manda</button></div>
      </form>
    </section>

    <section class="card"><h2>I tuoi dati</h2>
      <div class="row">
        <a class="btn" href="/api/auth.php?a=export">Scarica i miei dati</a>
        <button class="btn" id="logout">Esci</button>
        <button class="btn danger" id="del">Cancella l'account</button>
      </div>
      <p class="muted">Iscritto dal <?= h((new DateTime($u['created_at'] . ' UTC'))->setTimezone(new DateTimeZone('Europe/Rome'))->format('d/m/Y')) ?>.</p>
    </section>
  </div>
<?php endif; ?>
</main>
<script>window.WTM_ACCOUNT = <?= json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="/account/account.js?v=2"></script>
</body></html>
