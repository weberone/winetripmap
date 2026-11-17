<?php
// Consumi dei servizi a pagamento (Google Maps, Google Places, Mapbox) e della mappa OpenFreeMap, dal contatore del sito
// (api/counter.php, file counter/maps-AAAA-MM.json, mese e giorni in UTC come il contatore). Tetti e limiti in api/caps.php.
// Per ogni servizio: usati nel mese, tetto del sito (limite gratuito meno 100), limite gratuito, tetto giornaliero nella console,
// media al giorno e previsione a fine mese; grafico giorno per giorno; storico dei mesi passati.
require __DIR__ . '/layout.php';
require_once dirname(__DIR__) . '/api/caps.php';
admin_me();

$dir = dirname(__DIR__) . '/counter';
$month = gmdate('Y-m');
$read = fn($m) => json_decode((string)@file_get_contents("$dir/maps-$m.json"), true) ?: [];
$n = $read($month);
$daysIn = (int)gmdate('t'); $today = (int)gmdate('j');
$elapsed = $today - 1 + (time() % 86400) / 86400; // giorni passati del mese, con la frazione di oggi
$caps = CAPS + API_CAPS;

// mappa servita adesso: la prima sotto il tetto (come api/counter.php, con le chiavi presenti in app.js)
$now = 'free';
foreach (array_keys(CAPS) as $p) if (($n[$p] ?? 0) < CAPS[$p]) { $now = $p; break; }

// giorno per giorno: chiavi AAAA-MM-GG dal primo del mese a oggi
$series = [];
foreach (array_keys(SERVICES) as $k) { $series[$k] = [];
  for ($d = 1; $d <= $today; $d++) $series[$k][sprintf('%s-%02d', $month, $d)] = (int)($n['d'][sprintf('%02d', $d)][$k] ?? 0); }
$hasDays = !empty($n['d']);

// storico: i file dei mesi passati
$hist = [];
foreach (glob("$dir/maps-*.json") ?: [] as $f) { $m = substr(basename($f, '.json'), 5); if ($m !== $month) $hist[$m] = $read($m); }
krsort($hist);

admin_header('Consumi', 'cons');
$label = SERVICES[$now][0];
?>
<section class="card">
  <h2>Adesso</h2>
  <p style="margin:0">Le nuove visite aprono la <b><?= h($label) ?></b>. <span class="muted">Ordine: Google finché resta sotto il tetto del mese, poi Mapbox, poi OpenFreeMap (senza costi). Mese <?= h($month) ?> (UTC), giorno <?= $today ?> di <?= $daysIn ?>.</span></p>
</section>

<section class="grid2 cons">
<?php foreach (SERVICES as $k => [$name, $who, $free, $daily]):
  $used = (int)($n[$k] ?? 0); $cap = $caps[$k] ?? 0;
  $avg = $elapsed > 0.05 ? $used / $elapsed : 0; $proj = (int)round($avg * $daysIn);
  $pct = $cap ? min(100, round($used / $cap * 100, 1)) : 0;
  $state = !$cap ? 'ok' : ($used >= $cap ? 'stop' : ($proj > $cap ? 'warn' : 'ok'));
  $daysLeft = ($cap && $avg > 0 && $used < $cap) ? (int)floor(($cap - $used) / $avg) : null;
?>
  <div class="card scard <?= $state ?>">
    <div class="shead"><b><?= h($name) ?></b><span class="sstate"><?= $state === 'stop' ? 'Tetto raggiunto' : ($state === 'warn' ? 'Arriverà al tetto' : 'Nei limiti') ?></span></div>
    <small class="muted"><?= h($who) ?></small>
    <div class="snum"><b><?= num($used) ?></b><?php if ($cap): ?><span> / <?= num($cap) ?></span><?php endif; ?></div>
    <?php if ($cap): ?><div class="mbar"><i style="width:<?= $pct ?>%"></i></div><?php endif; ?>
    <dl class="sdl">
      <?php if ($cap): ?><div><dt>Usato</dt><dd><?= str_replace('.', ',', (string)$pct) ?>% del tetto</dd></div>
      <div><dt>Limite gratuito</dt><dd><?= num($free) ?> al mese</dd></div><?php endif; ?>
      <div><dt>Media</dt><dd><?= num(round($avg)) ?> al giorno</dd></div>
      <div><dt>A fine mese</dt><dd>circa <?= num($proj) ?><?php if ($cap && $proj > $cap): ?> → si ferma<?= $daysLeft !== null ? ' tra ' . $daysLeft . ' giorni' : '' ?><?php endif; ?></dd></div>
      <?php if ($daily): ?><div><dt>Tetto Google Cloud</dt><dd><?= num($daily) ?> al giorno</dd></div><?php endif; ?>
    </dl>
  </div>
<?php endforeach; ?>
</section>

<?php if ($hasDays): ?>
<section class="grid2">
  <?php foreach (SERVICES as $k => [$name]): if (!array_sum($series[$k])) continue; ?>
    <?= chart($name . ' al giorno', $series[$k], 'questo mese · ' . num(array_sum($series[$k]))) ?>
  <?php endforeach; ?>
</section>
<?php else: ?>
<p class="muted">Il grafico giorno per giorno parte da oggi: i conteggi per giorno si raccolgono da questa versione del contatore.</p>
<?php endif; ?>

<section class="card"><h2>Mesi passati</h2>
<?php if (!$hist): ?><p class="muted">Ancora nessun mese chiuso.</p><?php else: ?>
  <div class="tscroll"><table><thead><tr><th>Mese</th><?php foreach (SERVICES as [$name]): ?><th class="r"><?= h($name) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($hist as $m => $h): ?><tr><td><?= h($m) ?></td><?php foreach (SERVICES as $k => $s): $v = (int)($h[$k] ?? 0); ?><td class="r<?= isset($caps[$k]) && $v >= $caps[$k] ? ' over' : '' ?>"><?= num($v) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</section>

<section class="card"><h2>Protezioni contro la spesa</h2>
  <ol class="prot">
    <li><b>Contatore del sito</b> (questa pagina): ogni servizio si ferma al tetto, 100 sotto il limite gratuito del mese; poi la mappa passa alla successiva e la scheda resta senza foto.</li>
    <li><b>Tetti giornalieri in Google Cloud</b>: mappa 319, ricerche 158, foto 29 al giorno (× 31 giorni sempre sotto il limite gratuito).</li>
    <li><b>Budget "WTM zero spesa"</b>: email a 0,50 €, 0,90 € e 1 € di spesa reale.</li>
    <li><b>Blocco automatico</b> (Cloud Function <code>stop-billing</code>): alla prima spesa reale scollega la fatturazione dal progetto.</li>
    <li><b>Mapbox senza carta</b>: oltre i limiti gratuiti si ferma, non può addebitare nulla.</li>
  </ol>
  <p class="muted" style="margin-bottom:0">Conteggi del sito; Google e Mapbox contano anche le visite che il contatore non vede (per esempio chi blocca gli script), per questo i tetti stanno 100 sotto.
    Consumi ufficiali: <a href="https://console.cloud.google.com/google/maps-apis/metrics?project=project-01cb823d-edc4-46be-8f7" target="_blank" rel="noopener">Google Cloud</a> ·
    <a href="https://console.mapbox.com/" target="_blank" rel="noopener">Mapbox</a></p>
</section>
<style>
.cons{grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr))}
.scard{display:flex;flex-direction:column;gap:4px}
.shead{display:flex;justify-content:space-between;align-items:center;gap:8px}
.sstate{font-size:12px;font-weight:700;padding:2px 9px;border-radius:99px;background:rgb(30 140 70 / .14);color:#1e8c46}
.scard.warn .sstate{background:rgb(210 140 0 / .16);color:#a86b00}
.scard.stop .sstate{background:rgb(180 35 24 / .14);color:var(--err,#b42318)}
.scard.warn .mbar i{background:#d28c00} .scard.stop .mbar i{background:var(--err,#b42318)}
.snum{margin-top:6px} .snum b{font-size:28px;font-variant-numeric:tabular-nums} .snum span{color:var(--muted);font-size:15px}
.sdl{display:grid;grid-template-columns:auto 1fr;gap:3px 12px;margin:8px 0 0;font-size:13.5px}
.sdl div{display:contents} .sdl dt{color:var(--muted)} .sdl dd{margin:0;text-align:right;font-variant-numeric:tabular-nums}
td.over{color:var(--err,#b42318);font-weight:700}
.prot{margin:0 0 10px;padding-left:20px;line-height:1.5} .prot li{margin:4px 0}
</style>
<?php admin_footer(true); ?>
