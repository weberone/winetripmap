<?php
// Dashboard. In cima: aperture della mappa al giorno come SkateboardMap (colonne per tipo + colonnina con/senza cookie, pallini
// oggi contro ieri alla stessa ora per aperture e minuti, elenco delle aperture del giorno: ?olist=AAAA-MM-GG). Poi: persone al giorno a colonne divise (nuovi, di ritorno, iscritti; ultimi 30 giorni) con il confronto con ieri
// alla stessa ora, e sotto chi ha aperto la mappa nel giorno scelto (admin/visite_giorno.php; ?list=AAAA-MM-GG = solo l'elenco, per il clic sulle colonne).
// Poi: aperture della mappa e visitatori, iscritti, mappe usate nel mese (Google, Mapbox, OpenFreeMap: api/counter.php),
// da dove arrivano, dispositivi, cantine più salvate. Gli amministratori non entrano nei conteggi (api/auth.php).
require __DIR__ . '/layout.php';
admin_me();
// «Sblocca tutti» (protezione dai robot, api/bots.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && isset($_POST['unban'])) { db()->exec('DELETE FROM bot_bans'); header('Location: dashboard.php#robot'); exit; }
require __DIR__ . '/visite_giorno.php';
$isDay = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d <= today_it();
if (isset($_GET['list'])) { if (!$isDay($_GET['list'])) exit; echo visits_day_html($_GET['list']); exit; }
if (isset($_GET['olist'])) { if (!$isDay($_GET['olist'])) exit; echo opens_day_html($_GET['olist']); exit; }
$selDay = $isDay($_GET['day'] ?? null) ? $_GET['day'] : today_it();
$today = today_it(); $from30 = (new DateTime('-29 days', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
$from7 = (new DateTime('-6 days', new DateTimeZone('Europe/Rome')))->format('Y-m-d');

$opensToday = (int)one('SELECT COUNT(*) FROM app_opens WHERE day = ?', [$today]);
$visToday = (int)one('SELECT COUNT(DISTINCT vid) FROM app_opens WHERE day = ?', [$today]);
$opens7 = (int)one('SELECT COUNT(*) FROM app_opens WHERE day >= ?', [$from7]);
$vis7 = (int)one('SELECT COUNT(DISTINCT vid) FROM app_opens WHERE day >= ?', [$from7]);
$vis30 = (int)one('SELECT COUNT(DISTINCT vid) FROM app_opens WHERE day >= ?', [$from30]);
$users = (int)one('SELECT COUNT(*) FROM users');
$users7 = (int)one('SELECT COUNT(*) FROM users WHERE created_at > ?', [ago_days(7)]);
$favs = (int)one('SELECT COUNT(*) FROM favorites');
$trips = (int)one('SELECT COUNT(*) FROM trips');
$fbNew = (int)one("SELECT COUNT(*) FROM feedback WHERE status = 'new'");
// GPS e cookie degli ultimi 7 giorni (dettagli in visite.php)
$g7 = db()->prepare('SELECT COUNT(*) n, SUM(gps = 1) g, AVG(NULLIF(secs, 0)) t, SUM(views) w FROM app_opens WHERE day >= ?'); $g7->execute([$from7]); $g7 = $g7->fetch();
$ck7 = db()->prepare("SELECT SUM(CASE WHEN ev = 'consent:yes' THEN n ELSE 0 END) y, SUM(CASE WHEN ev = 'consent:no' THEN n ELSE 0 END) no FROM app_events WHERE day >= ?"); $ck7->execute([$from7]); $ck7 = $ck7->fetch();

$opens = day_series(30); $vis = day_series(30); $signups = day_series(30);
foreach (db()->query("SELECT day, COUNT(*) n, COUNT(DISTINCT vid) v FROM app_opens WHERE day >= '$from30' GROUP BY day") as $r) { if (isset($opens[$r['day']])) { $opens[$r['day']] = (int)$r['n']; $vis[$r['day']] = (int)$r['v']; } }
foreach (db()->query("SELECT created_at FROM users WHERE created_at > '" . ago_days(31) . "'") as $r) {
  $d = (new DateTime($r['created_at'] . ' UTC'))->setTimezone(new DateTimeZone('Europe/Rome'))->format('Y-m-d');
  if (isset($signups[$d])) $signups[$d]++;
}
// persone al giorno divise per tipo (dal primo giorno con dati, al massimo 30)
$vFrom = max($from30, (string)(one('SELECT MIN(day) FROM app_opens') ?: $today));
$vDays = visits_by_day($vFrom);
$vSum = fn($i) => array_sum(array_column($vDays, $i));
// oggi fino ad adesso contro ieri fino alla stessa ora (persone)
$people = function (string $day, ?string $until) { $st = db()->prepare('SELECT id, vid, user_id FROM app_opens WHERE day = ?' . ($until ? ' AND at <= ?' : '')); $st->execute($until ? [$day, $until] : [$day]);
  $k = []; foreach ($st as $o) $k[visit_key($o)] = 1; return count($k); };
$pToday = array_sum($vDays[$today] ?? [0]);
$pYest = $people((new DateTime('-1 day', new DateTimeZone('Europe/Rome')))->format('Y-m-d'), gmdate('Y-m-d H:i:s', time() - 86400));
$pPct = $pYest ? round(($pToday - $pYest) / $pYest * 100) : null;
$pCls = $pPct === null ? ($pToday ? 'up' : '') : ($pPct > 0 ? 'up' : ($pPct < 0 ? 'down' : ''));
// aperture al giorno (ogni apertura, anche della stessa persona): colonne divise per tipo e, accanto, con/senza cookie
[$oDays, $oStk, $oSpl, $oCmp] = opens_by_day($vFrom);
$oSum = fn($i) => array_sum(array_column($oStk, $i)); $oCk = array_sum(array_column($oSpl, 0)) + array_sum(array_column($oSpl, 2)) + array_sum(array_column($oSpl, 4));
// pallino grande: oggi fino ad adesso contro ieri fino alla stessa ora; senza il dato di ieri, la differenza in numero
$bigBadge = function (float $now, float $before, string $small, string $title): string {
  $p = $before > 0 ? round(($now - $before) / $before * 100) : null;
  $c = $p === null ? ($now > 0 ? 'up' : 'flat') : ($p > 0 ? 'up' : ($p < 0 ? 'down' : 'flat'));
  $t = $p === null ? ($now > 0 ? '+' . num($now) : '—') : (($p > 0 ? '+' : ($p < 0 ? '−' : '')) . abs($p) . '%');
  return '<div class="bigtrend ' . $c . '" title="' . h($title) . '"><i class="barr">' . ($c === 'up' ? '▲' : ($c === 'down' ? '▼' : '')) . '</i><b>' . h($t) . '</b><small>' . $small . '</small></div>';
};
$q = fn($sql) => db()->query($sql)->fetchAll();
$sources = $q("SELECT source k, COUNT(*) n FROM app_opens WHERE day >= '$from30' GROUP BY source ORDER BY n DESC LIMIT 10");
$devices = $q("SELECT device k, COUNT(*) n FROM app_opens WHERE day >= '$from30' GROUP BY device ORDER BY n DESC LIMIT 8");
$langs = $q("SELECT substr(lang, 1, 2) k, COUNT(DISTINCT vid) n FROM app_opens WHERE day >= '$from30' AND lang IS NOT NULL GROUP BY k ORDER BY n DESC LIMIT 8");
$topFav = $q('SELECT name, town, winery, COUNT(*) n FROM favorites GROUP BY winery ORDER BY n DESC, name LIMIT 10');

// mappe del mese: contatore di api/counter.php (file counter/maps-AAAA-MM.json, mese UTC come il contatore)
$maps = json_decode((string)@file_get_contents(dirname(__DIR__) . '/counter/maps-' . gmdate('Y-m') . '.json'), true) ?: [];
require_once dirname(__DIR__) . '/api/caps.php'; $caps = CAPS;
admin_header('Dashboard', 'dash');
?>
<figure class="card chart visits opens">
  <figcaption><b>Aperture della mappa al giorno</b><span class="muted">ogni apertura, anche della stessa persona · oggi <?= num($oDays[$today] ?? 0) ?> · ultimi <?= count($oDays) ?> giorni <?= num(array_sum($oDays)) ?> · tocca un giorno per vederne le aperture</span></figcaption>
  <div class="bigwrap">
    <div class="bigs">
      <?= $bigBadge($oCmp['ot'], $oCmp['oy'], num($oCmp['ot']) . ' vs ' . num($oCmp['oy']) . '<br>aperture', "Aperture di oggi fino ad adesso rispetto a ieri alla stessa ora") ?>
      <?= $bigBadge(round($oCmp['st'] / 60), round($oCmp['sy'] / 60), num(round($oCmp['st'] / 60)) . ' vs ' . num(round($oCmp['sy'] / 60)) . ' min', "Minuti con la mappa davanti oggi fino ad adesso, rispetto a ieri alla stessa ora") ?>
    </div>
    <div class="cols labeled" id="opensChart" data-series='<?= h(json_encode(array_map(fn($d, $n) => [$d, $n], array_keys($oDays), $oDays))) ?>'
      data-stack='<?= h(json_encode(array_values($oStk))) ?>' data-stack-names='["nuovi","di ritorno","iscritti"]' data-split='<?= h(json_encode(array_values($oSpl))) ?>'
      role="img" aria-label="Aperture della mappa al giorno: nuovi, di ritorno e iscritti; accanto chi ha accettato i cookie e chi no"></div>
  </div>
  <p class="stleg"><span class="s0"></span>nuovi <b><?= num($oSum(0)) ?></b> <span class="s1"></span>di ritorno <b><?= num($oSum(1)) ?></b> <span class="s2"></span>iscritti <b><?= num($oSum(2)) ?></b>
    <span class="sep">│ colonnina a destra, per ogni tipo:</span> <span class="c0"></span>con cookie <b><?= num($oCk) ?></b> <span class="s3"></span>senza cookie <b><?= num(array_sum($oDays) - $oCk) ?></b></p>
  <div id="opensDay"><?= opens_day_html($today) ?></div>
</figure>
<script>
// aperture: tocco su un giorno del grafico → le aperture di quel giorno
(() => {
  const box = document.getElementById("opensDay"), chart = document.getElementById("opensChart"); let seq = 0;
  chart.addEventListener("click", async (e) => { const c = e.target.closest(".col"); if (!c) return; const n = ++seq;
    chart.querySelectorAll(".col.sel").forEach((x) => x.classList.remove("sel")); c.classList.add("sel");
    try { const r = await fetch("dashboard.php?olist=" + c.dataset.d, { credentials: "same-origin" }); if (n === seq && r.ok) box.innerHTML = await r.text(); } catch (e) {} });
})();
</script>
<figure class="card chart visits">
  <figcaption><b>Persone sulla mappa al giorno</b><span class="muted">tocca una colonna per vedere chi era · ultimi <?= count($vDays) ?> giorni · <?= num(array_sum(array_map('array_sum', $vDays))) ?> in tutto</span></figcaption>
  <div class="vtop">
    <div class="vbadge" title="Persone di oggi fino ad adesso rispetto a ieri fino alla stessa ora">
      <b class="<?= $pCls ?>"><?= $pPct === null ? ($pToday ? '+' . num($pToday) : '—') : ($pPct > 0 ? '▲ +' : ($pPct < 0 ? '▼ −' : '')) . ($pPct === null ? '' : abs($pPct) . '%') ?></b>
      <small>oggi <?= num($pToday) ?> · ieri a quest'ora <?= num($pYest) ?></small></div>
    <div class="cols labeled" id="visitsChart" data-series='<?= h(json_encode(array_map(fn($d, $v) => [$d, array_sum($v)], array_keys($vDays), $vDays))) ?>'
      data-stack='<?= h(json_encode(array_values($vDays))) ?>' data-stack-names='["nuovi","di ritorno","iscritti"]'
      role="img" aria-label="Persone sulla mappa al giorno: nuovi, di ritorno e iscritti"></div>
  </div>
  <p class="stleg"><span class="s0"></span>nuovi <b><?= num($vSum(0)) ?></b> <span class="s1"></span>di ritorno <b><?= num($vSum(1)) ?></b> <span class="s2"></span>iscritti <b><?= num($vSum(2)) ?></b>
    <small>· una persona = un browser (o un account) al giorno, anche se apre la mappa più volte · nuovo = il suo primo giorno</small></p>
</figure>
<section class="card" id="dayList"><?= visits_day_html($selDay) ?></section>
<script>
// clic su una colonna o sui pulsanti dei giorni: l'elenco di quel giorno senza ricaricare la pagina
(() => {
  const box = document.getElementById("dayList");
  const load = async (d) => { const r = await fetch("dashboard.php?list=" + d, { credentials: "same-origin" }); if (r.ok) { box.innerHTML = await r.text(); history.replaceState(null, "", d === <?= json_encode($today) ?> ? "dashboard.php" : "?day=" + d); } };
  document.getElementById("visitsChart").addEventListener("click", (e) => { const c = e.target.closest(".col"); if (c) load(c.dataset.d); });
  box.addEventListener("click", (e) => { const a = e.target.closest("a[data-day]"); if (a) { e.preventDefault(); load(a.dataset.day); } });
  // la colonna del giorno scelto resta evidenziata
  if (<?= json_encode($selDay !== $today) ?>) addEventListener("DOMContentLoaded", () => { // dopo core/admin/charts.js, che disegna le colonne
    const c = document.querySelector('#visitsChart .col[data-d="<?= h($selDay) ?>"]'); if (c) { c.onclick && c.onclick(); } });
})();
</script>
<section class="kpis">
  <a class="kpi" href="opens.php"><span>Aperture oggi</span><b><?= num($opensToday) ?></b><small><?= num($visToday) ?> visitatori</small></a>
  <a class="kpi" href="opens.php"><span>Ultimi 7 giorni</span><b><?= num($opens7) ?></b><small><?= num($vis7) ?> visitatori</small></a>
  <a class="kpi" href="visite.php?d=7"><span>GPS attivato</span><b><?= $g7['n'] ? round($g7['g'] / $g7['n'] * 100) . '%' : '—' ?></b><small>delle aperture in 7 giorni</small></a>
  <a class="kpi" href="visite.php?d=7"><span>Cookie accettati</span><b><?= ($ck7['y'] + $ck7['no']) ? round($ck7['y'] / ($ck7['y'] + $ck7['no']) * 100) . '%' : '—' ?></b><small><?= num($ck7['y']) ?> sì · <?= num($ck7['no']) ?> no in 7 giorni</small></a>
  <a class="kpi" href="visite.php?d=7"><span>Tempo sulla mappa</span><b><?= $g7['t'] ? (round($g7['t']) >= 60 ? floor($g7['t'] / 60) . ' min' : round($g7['t']) . ' s') : '—' ?></b><small>medio per apertura · <?= num($g7['w']) ?> cantine aperte</small></a>
  <div class="kpi"><span>Visitatori in 30 giorni</span><b><?= num($vis30) ?></b><small>browser diversi</small></div>
  <a class="kpi" href="users.php"><span>Iscritti</span><b><?= num($users) ?></b><small><?= num($users7) ?> negli ultimi 7 giorni</small></a>
  <div class="kpi"><span>Cantine salvate</span><b><?= num($favs) ?></b><small>preferite degli iscritti</small></div>
  <div class="kpi"><span>Trip salvati</span><b><?= num($trips) ?></b><small>itinerari degli iscritti</small></div>
  <a class="kpi" href="feedback.php"><span>Feedback da leggere</span><b><?= num($fbNew) ?></b><small>consigli e malfunzionamenti</small></a>
</section>
<?= chart('Aperture della mappa al giorno', $opens) ?>
<section class="grid2">
  <?= chart('Visitatori al giorno', $vis, '30 giorni · browser diversi') ?>
  <?= chart('Iscrizioni al giorno', $signups) ?>
</section>
<section class="card"><h2><a href="consumi.php" style="color:inherit;text-decoration:none">Mappe di questo mese</a></h2>
  <p class="muted" style="margin-top:0">Prima Google finché la quota gratuita regge, poi Mapbox, poi OpenFreeMap (senza limiti). Il sito non paga mai.</p>
  <div class="grid2" style="margin:0">
  <?php foreach (['google' => 'Google Maps', 'mapbox' => 'Mapbox', 'free' => 'OpenFreeMap'] as $k => $label): $n = (int)($maps[$k] ?? 0); ?>
    <div class="mapcard<?= $n ? ' on' : '' ?>"><b><?= h($label) ?></b><span style="font-size:24px;font-weight:700"><?= num($n) ?></span>
      <?php if (isset($caps[$k])): ?><div class="mbar"><i style="width:<?= min(100, round($n / $caps[$k] * 100, 1)) ?>%"></i></div><small class="muted">tetto <?= num($caps[$k]) ?> al mese</small>
      <?php else: ?><small class="muted">gratuita, senza tetto</small><?php endif; ?></div>
  <?php endforeach; ?>
  </div>
  <p class="muted"><a href="consumi.php">Tutti i consumi →</a> foto e satellite della scheda, giorno per giorno, previsione a fine mese, mesi passati.</p>
</section>
<section class="grid2">
  <div class="card"><h2>Da dove arrivano</h2><?= hbars($sources) ?><p class="muted"><a href="visite.php">Visite nel dettaglio →</a> ingresso, GPS, cookie, tempo, clic e percorsi.</p></div>
  <div class="card"><h2>Dispositivi</h2><?= hbars($devices) ?></div>
  <div class="card"><h2>Lingua del browser</h2><?= hbars($langs) ?></div>
  <div class="card"><h2>Cantine più salvate</h2>
    <?php if (!$topFav): ?><p class="muted">Ancora nessuna.</p><?php else: ?><table><tbody>
      <?php foreach ($topFav as $r): ?><tr><td><a href="/?c=<?= h($r['winery']) ?>" target="_blank" rel="noopener"><?= h($r['name'] ?: $r['winery']) ?></a> <small class="muted"><?= h($r['town']) ?></small></td><td class="r"><?= num($r['n']) ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?></div>
</section>
<?php // donazioni: clic dalla mappa e dalla pagina /dona/ (app_events support:/donate:) e donazioni vere dal webhook di Ko-fi (api/kofi.php)
$dFrom = (new DateTime('now', new DateTimeZone('Europe/Rome')))->modify('-29 days')->format('Y-m-d');
$dn = []; try { $st = db()->prepare("SELECT ev, SUM(n) AS t FROM app_events WHERE day >= ? AND (ev LIKE 'donate:%' OR ev LIKE 'support:%') GROUP BY ev"); $st->execute([$dFrom]); foreach ($st as $r) $dn[$r['ev']] = (int)$r['t']; } catch (Throwable $e) {}
$dv = fn($k) => $dn[$k] ?? 0;
$dKofi = $dv('donate:kofi-2') + $dv('donate:kofi-5') + $dv('donate:kofi-10') + $dv('donate:kofi-free');
$dons = []; $dTot = []; try { $dons = db()->query('SELECT * FROM donations ORDER BY at DESC LIMIT 10')->fetchAll();
  foreach (db()->query('SELECT currency, SUM(amount) AS t, COUNT(*) AS n FROM donations GROUP BY currency') as $r) $dTot[] = number_format((float)$r['t'], 2, ',', '.') . ' ' . $r['currency'] . ' (' . $r['n'] . ')'; } catch (Throwable $e) {}
?>
<section class="card" id="donazioni"><h2>Donazioni <small class="muted">ultimi 30 giorni</small></h2>
  <section class="kpis" style="margin:0 0 10px">
    <div class="kpi"><span>Tocchi su "🥂 Cin cin!" / Supporta</span><b><?= $dv('support:map') + $dv('support:header') + $dv('support:footer') ?></b><small>mappa <?= $dv('support:map') ?> · in alto <?= $dv('support:header') ?> · in fondo <?= $dv('support:footer') ?></small></div>
    <div class="kpi"><span>Visite pagina /dona/</span><b><?= $dv('donate:view') ?></b><small>senza andare su Ko-fi <?= $dv('donate:onlyview') ?></small></div>
    <div class="kpi"><span>Clic verso Ko-fi</span><b><?= $dKofi ?></b><small>2 € <?= $dv('donate:kofi-2') ?> · 5 € <?= $dv('donate:kofi-5') ?> · 10 € <?= $dv('donate:kofi-10') ?> · libero <?= $dv('donate:kofi-free') ?></small></div>
    <div class="kpi"><span>Donazioni arrivate</span><b><?= $dv('donate:paid') ?></b><small>da sempre: <?= $dTot ? h(implode(' · ', $dTot)) : 'nessuna' ?></small></div>
  </section>
  <?php if (!$dons): ?><p class="muted">Ancora nessuna donazione. <?= empty(settings()['kofi_token']) ? 'Per riceverle qui: Impostazioni → Donazioni (link Ko-fi e token del webhook).' : '' ?></p>
  <?php else: ?><table><thead><tr><th>Quando</th><th>Chi</th><th class="r">Importo</th><th>Messaggio</th></tr></thead><tbody>
    <?php foreach ($dons as $r): ?><tr><td><?= h(substr($r['at'], 0, 16)) ?></td><td><?= h($r['name'] ?: '—') ?><?= $r['user_id'] ? ' <a href="user.php?id=' . (int)$r['user_id'] . '">(iscritto)</a>' : '' ?></td>
      <td class="r"><?= h(number_format((float)$r['amount'], 2, ',', '.') . ' ' . $r['currency']) ?></td><td><?= h($r['message'] ?: '') ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>
<?php // protezione dai robot (api/bots.php, api/trap.php): indirizzi bloccati adesso, numeri del giorno in app_events (bot:…)
$bn = []; try { $st = db()->prepare("SELECT ev, SUM(n) AS t, SUM(CASE WHEN day = ? THEN n ELSE 0 END) AS o FROM app_events WHERE day >= ? AND ev LIKE 'bot:%' GROUP BY ev"); $st->execute([today_it(), $dFrom]); foreach ($st as $r) $bn[$r['ev']] = [(int)$r['t'], (int)$r['o']]; } catch (Throwable $e) {}
$bv = fn($k, $i = 0) => $bn[$k][$i] ?? 0;
$bans = []; try { $st = db()->prepare('SELECT reason, until, hits, at FROM bot_bans WHERE until > ? ORDER BY at DESC'); $st->execute([now()]); $bans = $st->fetchAll(); } catch (Throwable $e) {}
$rome = fn($t) => (new DateTime($t . ' UTC'))->setTimezone(new DateTimeZone('Europe/Rome'))->format('d/m H:i');
?>
<section class="card" id="robot"><h2>Protezione dai robot <small class="muted">ultimi 30 giorni</small></h2>
  <p class="muted">Limite di richieste al minuto per indirizzo su API e pagine delle cantine (sopra il limite: «riprova tra un minuto»; 4 volte oltre: blocco per 6 ore),
    trappola invisibile alle persone (chi la apre: blocco per 24 ore), robot maleducati respinti dal server (Bytespider, PetalBot, SemrushBot, AhrefsBot…).
    I robot che si fingono browser non entrano nelle statistiche e hanno la mappa gratuita, senza consumare Google e Mapbox.
    Google, Bing, DuckDuckGo, Apple e i robot di ricerca di ChatGPT e Perplexity non vengono mai bloccati.</p>
  <section class="kpis" style="margin:0 0 10px">
    <div class="kpi"><span>Bloccati adesso</span><b><?= num(count($bans)) ?></b><small>blocchi oggi <?= num($bv('bot:ban', 1)) ?> · in 30 giorni <?= num($bv('bot:ban')) ?></small></div>
    <div class="kpi"><span>Aperture di robot escluse</span><b><?= num($bv('bot:open')) ?></b><small>oggi <?= num($bv('bot:open', 1)) ?> · browser automatici, raffiche dalla stessa rete, ondate</small></div>
    <div class="kpi"><span>🇨🇳 Fermati prima della mappa</span><b><?= num($bv('bot:cn')) ?></b><small>oggi <?= num($bv('bot:cn', 1)) ?> · computer col fuso della Cina senza link</small></div>
    <div class="kpi"><span>Nella trappola</span><b><?= num($bv('bot:trap')) ?></b><small>oggi <?= num($bv('bot:trap', 1)) ?></small></div>
  </section>
  <?php if ($bans): ?><ul><?php foreach (array_slice($bans, 0, 10) as $b): ?><li><?= h($rome($b['at'])) ?> · <?= $b['reason'] === 'trappola' ? 'trappola' : 'troppe richieste' ?><?= $b['hits'] > 1 ? ' · ' . num($b['hits']) . ' volte' : '' ?> · fino alle <?= h($rome($b['until'])) ?></li><?php endforeach; ?></ul>
  <form method="post" onsubmit="return confirm('Sbloccare tutti gli indirizzi bloccati?')"><?= csrf_field() ?><input type="hidden" name="unban" value="1"><button class="btn">Sblocca tutti</button></form><?php endif; ?>
  <p class="muted">Una persona vera col fuso della Cina tocca «Apri la mappa» e usa il sito normalmente. Le notifiche sul telefono (ondata di robot, robot bloccato) arrivano ai dispositivi iscritti in Impostazioni, al massimo una ogni 3 ore.</p>
</section>
<?php admin_footer(true); ?>
