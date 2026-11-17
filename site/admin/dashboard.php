<?php
// Dashboard: aperture della mappa e visitatori, iscritti, mappe usate nel mese (Google, Mapbox, OpenFreeMap: api/counter.php),
// da dove arrivano, dispositivi, cantine più salvate. Gli amministratori non entrano nei conteggi (api/auth.php).
require __DIR__ . '/layout.php';
admin_me();
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

$opens = day_series(30); $vis = day_series(30); $signups = day_series(30);
foreach (db()->query("SELECT day, COUNT(*) n, COUNT(DISTINCT vid) v FROM app_opens WHERE day >= '$from30' GROUP BY day") as $r) { if (isset($opens[$r['day']])) { $opens[$r['day']] = (int)$r['n']; $vis[$r['day']] = (int)$r['v']; } }
foreach (db()->query("SELECT created_at FROM users WHERE created_at > '" . ago_days(31) . "'") as $r) {
  $d = (new DateTime($r['created_at'] . ' UTC'))->setTimezone(new DateTimeZone('Europe/Rome'))->format('Y-m-d');
  if (isset($signups[$d])) $signups[$d]++;
}
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
<section class="kpis">
  <a class="kpi" href="opens.php"><span>Aperture oggi</span><b><?= num($opensToday) ?></b><small><?= num($visToday) ?> visitatori</small></a>
  <a class="kpi" href="opens.php"><span>Ultimi 7 giorni</span><b><?= num($opens7) ?></b><small><?= num($vis7) ?> visitatori</small></a>
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
  <div class="card"><h2>Da dove arrivano</h2><?= hbars($sources) ?></div>
  <div class="card"><h2>Dispositivi</h2><?= hbars($devices) ?></div>
  <div class="card"><h2>Lingua del browser</h2><?= hbars($langs) ?></div>
  <div class="card"><h2>Cantine più salvate</h2>
    <?php if (!$topFav): ?><p class="muted">Ancora nessuna.</p><?php else: ?><table><tbody>
      <?php foreach ($topFav as $r): ?><tr><td><a href="/mappa/?c=<?= h($r['winery']) ?>" target="_blank" rel="noopener"><?= h($r['name'] ?: $r['winery']) ?></a> <small class="muted"><?= h($r['town']) ?></small></td><td class="r"><?= num($r['n']) ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?></div>
</section>
<?php admin_footer(true); ?>
