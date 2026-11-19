<?php
// Visite della mappa (come Visitatori e Clic di SkateboardMap): quante volte si apre, chi torna, da dove arriva, pagina d'ingresso,
// GPS attivato o no, scelta sui cookie, tempo sulla mappa, cantine aperte, dispositivi, clic, percorsi azione per azione.
// Fonti: app_opens (api/auth.php?a=me&map=1, poi api/track.php), app_events (totali anonimi), user_sessions/user_events (percorsi,
// solo iscritti e chi ha accettato i cookie). Gli amministratori non contano.
require __DIR__ . '/layout.php';
require __DIR__ . '/eventi_lib.php';
admin_me();
$tz = new DateTimeZone('Europe/Rome');
$days = in_array((int)($_GET['d'] ?? 30), [1, 7, 30, 90], true) ? (int)($_GET['d'] ?? 30) : 30;
$from = (new DateTime('-' . ($days - 1) . ' days', $tz))->format('Y-m-d');
$fromUtc = (new DateTime($from . ' 00:00:00', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$pct = fn($a, $b) => $b ? round($a / $b * 100) . '%' : '—';
$mins = function ($s) { $s = (int)round((float)$s); return $s >= 60 ? floor($s / 60) . ' min' . ($s % 60 ? ' ' . ($s % 60) . ' s' : '') : $s . ' s'; };
$P = [$from];

// ---------- numeri del periodo ----------
$k = db()->prepare("SELECT COUNT(*) n, COUNT(DISTINCT vid) v, SUM(gps = 1) g1, SUM(gps = 0) g0, SUM(ck = 1) c1, SUM(ck = 0) c0,
  SUM(secs) secs, SUM(secs > 0) withsecs, SUM(views) views, SUM(views > 0) withviews, SUM(user_id IS NOT NULL) users FROM app_opens WHERE day >= ?");
$k->execute($P); $k = $k->fetch();
$n = (int)$k['n'];
// nuovi: la loro prima apertura in assoluto è nel periodo
$st = db()->prepare('SELECT COUNT(*) FROM (SELECT vid FROM app_opens WHERE vid IS NOT NULL GROUP BY vid HAVING MIN(day) >= ?)'); $st->execute($P);
$newV = (int)$st->fetchColumn();
$ev = function (string $e) use ($from) { $st = db()->prepare('SELECT COALESCE(SUM(n), 0) FROM app_events WHERE day >= ? AND ev = ?'); $st->execute([$from, $e]); return (int)$st->fetchColumn(); };
$cy = $ev('consent:yes'); $cn = $ev('consent:no');
$gDenied = $ev('gps:denied'); $gFail = $ev('gps:timeout') + $ev('gps:unavailable') + $ev('gps:nobrowser');

// ---------- al giorno ----------
$sOpen = day_series(max(7, $days)); $sVis = $sOpen; $sGps = $sOpen;
$f0 = array_key_first($sOpen);
$st = db()->prepare('SELECT day, COUNT(*) n, COUNT(DISTINCT vid) v, SUM(gps = 1) g FROM app_opens WHERE day >= ? GROUP BY day'); $st->execute([$f0]);
foreach ($st as $r) if (isset($sOpen[$r['day']])) { $sOpen[$r['day']] = (int)$r['n']; $sVis[$r['day']] = (int)$r['v']; $sGps[$r['day']] = (int)$r['g']; }

// ---------- da dove arrivano ----------
$st = db()->prepare("SELECT COALESCE(source, 'sconosciuta') s, COUNT(*) n, COUNT(DISTINCT vid) v, SUM(gps = 1) g, SUM(ck = 1) c1, SUM(ck IS NOT NULL) cc,
  AVG(NULLIF(secs, 0)) t, SUM(views) w FROM app_opens WHERE day >= ? GROUP BY s ORDER BY n DESC");
$st->execute($P); $sources = $st->fetchAll();
$srcLabel = ['diretto' => 'Diretto (link digitato, preferiti, app)', 'interno' => 'Dal sito stesso (pagine delle cantine, account…)', 'google' => 'Google',
  'bing' => 'Bing', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'twitter' => 'X / Twitter', 'whatsapp' => 'WhatsApp',
  'telegram' => 'Telegram', 'reddit' => 'Reddit', 'youtube' => 'YouTube', 'chatgpt' => 'ChatGPT', 'perplexity' => 'Perplexity', 'claude' => 'Claude', 'duckduckgo' => 'DuckDuckGo'];

// ---------- pagina d'ingresso, dispositivi, lingua, mappa ----------
$st = db()->prepare('SELECT land, gps, secs, views FROM app_opens WHERE day >= ?'); $st->execute($P);
$lands = [];
foreach ($st as $r) { $lk = land_kind($r['land']); $lands[$lk] ??= ['n' => 0, 'g' => 0, 't' => 0, 'w' => 0]; $lands[$lk]['n']++; $lands[$lk]['g'] += (int)($r['gps'] == 1); $lands[$lk]['t'] += (int)$r['secs']; $lands[$lk]['w'] += (int)$r['views']; }
uasort($lands, fn($a, $b) => $b['n'] <=> $a['n']);
$devT = $devO = $devB = [];
$st = db()->prepare('SELECT device, COUNT(*) n FROM app_opens WHERE day >= ? GROUP BY device'); $st->execute($P);
foreach ($st as $r) { $p = array_map('trim', explode('·', (string)$r['device'])); if (count($p) < 3) continue;
  $devT[$p[0]] = ($devT[$p[0]] ?? 0) + (int)$r['n']; $devO[$p[1]] = ($devO[$p[1]] ?? 0) + (int)$r['n']; $devB[$p[2]] = ($devB[$p[2]] ?? 0) + (int)$r['n']; }
$rows = fn($a) => array_map(fn($k, $v) => ['k' => $k, 'n' => $v], array_keys($a), $a);
arsort($devT); arsort($devO); arsort($devB);
$q = function ($sql) use ($P) { $st = db()->prepare($sql); $st->execute($P); return $st->fetchAll(); };
$langs = $q("SELECT substr(lang, 1, 2) k, COUNT(*) n FROM app_opens WHERE day >= ? AND lang IS NOT NULL GROUP BY k ORDER BY n DESC LIMIT 10");
$tzs = $q("SELECT tz k, COUNT(*) n FROM app_opens WHERE day >= ? AND tz IS NOT NULL GROUP BY tz ORDER BY n DESC LIMIT 10");
$mapsUsed = $q("SELECT CASE map WHEN 'globe' THEN 'Globo iniziale (poi la mappa vera)' WHEN 'google' THEN 'Google' WHEN 'mapbox' THEN 'Mapbox' WHEN 'free' THEN 'OpenFreeMap' ELSE COALESCE(map, '—') END k, COUNT(*) n FROM app_opens WHERE day >= ? GROUP BY map ORDER BY n DESC");
$hours = array_fill(0, 24, 0);
foreach ($q('SELECT at FROM app_opens WHERE day >= ?') as $r) $hours[(int)(new DateTime($r['at'] . ' UTC'))->setTimezone($tz)->format('G')]++;

// ---------- clic e azioni (totali anonimi) ----------
$st = db()->prepare("SELECT ev, SUM(CASE WHEN who = 'ospite' THEN n ELSE 0 END) g, SUM(CASE WHEN who = 'iscritto' THEN n ELSE 0 END) u, SUM(n) t
  FROM app_events WHERE day >= ? GROUP BY ev ORDER BY t DESC"); $st->execute($P);
$byGroup = []; foreach ($st as $r) $byGroup[explode(':', $r['ev'])[0]][] = $r;

// ---------- percorsi recenti ----------
$st = db()->prepare('SELECT s.*, u.email FROM user_sessions s LEFT JOIN users u ON u.id = s.user_id AND s.user_id > 0 WHERE s.last_at >= ? ORDER BY s.last_at DESC LIMIT 100');
$st->execute([$fromUtc]); $sess = $st->fetchAll();
$nSess = (int)one('SELECT COUNT(*) FROM user_sessions WHERE last_at >= ?', [$fromUtc]);

admin_header('Visite della mappa', 'vis');
?>
<p class="muted">Periodo:
  <?php foreach ([1 => 'oggi', 7 => '7 giorni', 30 => '30 giorni', 90 => '90 giorni'] as $dk => $dl): ?><a href="?d=<?= $dk ?>"<?= $days === $dk ? ' class="on" aria-current="page" style="font-weight:700"' : '' ?>><?= $dl ?></a> <?php endforeach; ?>
  · Un'apertura = la mappa caricata in una scheda del browser; un visitatore = un browser (chi usa due dispositivi conta due volte). Gli amministratori non contano.</p>
<section class="kpis">
  <a class="kpi" href="opens.php"><span>Aperture</span><b><?= num($n) ?></b><small><?= num($k['v']) ?> visitatori · <?= num($k['users']) ?> da iscritti</small></a>
  <div class="kpi"><span>Visitatori nuovi</span><b><?= num($newV) ?></b><small><?= num(max(0, (int)$k['v'] - $newV)) ?> tornati da prima</small></div>
  <div class="kpi"><span>Tempo sulla mappa</span><b><?= $k['withsecs'] ? $mins($k['secs'] / $k['withsecs']) : '—' ?></b><small>in media per apertura, con la pagina davanti</small></div>
  <div class="kpi"><span>Cantine aperte</span><b><?= num($k['views']) ?></b><small>schede · in <?= $pct($k['withviews'], $n) ?> delle aperture</small></div>
  <div class="kpi"><span>GPS attivato</span><b><?= $pct($k['g1'], $n) ?></b><small><?= num($k['g1']) ?> aperture · negato <?= num($gDenied) ?> volte<?= $gFail ? ', non riuscito ' . num($gFail) : '' ?></small></div>
  <div class="kpi"><span>Cookie accettati</span><b><?= $pct($cy, $cy + $cn) ?></b><small><?= num($cy) ?> Accetta · <?= num($cn) ?> Rifiuta (tutte le pagine)</small></div>
  <div class="kpi"><span>Senza scelta sui cookie</span><b><?= $pct($n - (int)$k['c1'] - (int)$k['c0'], $n) ?></b><small>aperture in cui il banner è stato ignorato</small></div>
</section>
<?= chart('Aperture della mappa al giorno', $sOpen) ?>
<section class="grid2">
  <?= chart('Visitatori al giorno', $sVis, count($sVis) . ' giorni · browser diversi') ?>
  <?= chart('Aperture con il GPS attivato', $sGps) ?>
</section>
<section class="card">
  <h2>Da dove arrivano</h2>
  <?php if (!$sources): ?><p class="muted">Ancora nessun dato.</p><?php else: ?><div class="tscroll"><table>
    <thead><tr><th>Fonte</th><th class="r">Aperture</th><th class="r">%</th><th class="r">Visitatori</th><th class="r">GPS</th><th class="r">Cookie sì</th><th class="r">Tempo medio</th><th class="r">Cantine aperte</th></tr></thead>
    <tbody><?php foreach ($sources as $r): ?><tr>
      <td><a href="opens.php?src=<?= h($r['s']) ?>"><?= h($srcLabel[$r['s']] ?? $r['s']) ?></a></td><td class="r"><b><?= num($r['n']) ?></b></td><td class="r muted"><?= $pct($r['n'], $n) ?></td>
      <td class="r"><?= num($r['v']) ?></td><td class="r"><?= $pct($r['g'], $r['n']) ?></td><td class="r"><?= $pct($r['c1'], $r['cc']) ?></td>
      <td class="r"><?= $r['t'] ? $mins($r['t']) : '—' ?></td><td class="r"><?= num($r['w']) ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
  <p class="muted">"Diretto" = nessun sito di provenienza (link copiato o digitato, preferiti, app di messaggi che non lo dicono). Instagram, Facebook e TikTok si riconoscono anche dal loro browser interno; una campagna si segna con <code>?utm_source=…</code> nel link.</p><?php endif; ?>
</section>
<section class="grid2">
  <div class="card"><h2>Pagina d'ingresso</h2>
    <?php if (!$lands): ?><p class="muted">Ancora nessun dato.</p><?php else: ?><table><thead><tr><th>Arriva su</th><th class="r">Aperture</th><th class="r">GPS</th><th class="r">Tempo medio</th></tr></thead><tbody>
    <?php foreach ($lands as $lk => $r): ?><tr><td><?= h($lk) ?></td><td class="r"><?= num($r['n']) ?></td><td class="r"><?= $pct($r['g'], $r['n']) ?></td><td class="r"><?= $mins($r['t'] / max(1, $r['n'])) ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?></div>
  <div class="card"><h2>Ora del giorno <small class="muted">ora italiana</small></h2>
    <?= hbars(array_values(array_filter(array_map(fn($h, $c) => ['k' => sprintf('%02d:00', $h), 'n' => $c], array_keys($hours), $hours), fn($r) => $r['n'] > 0))) ?></div>
  <div class="card"><h2>Dispositivo</h2><?= hbars($rows($devT)) ?></div>
  <div class="card"><h2>Sistema</h2><?= hbars($rows($devO)) ?></div>
  <div class="card"><h2>Browser</h2><?= hbars($rows($devB)) ?></div>
  <div class="card"><h2>Lingua del browser</h2><?= hbars($langs) ?></div>
  <div class="card"><h2>Fuso orario</h2><?= hbars($tzs) ?></div>
  <div class="card"><h2>Mappa all'apertura</h2><?= hbars($mapsUsed) ?></div>
</section>
<section class="card">
  <h2>Percorsi nella mappa <small class="muted"><?= num($nSess) ?> nel periodo</small></h2>
  <p class="muted" style="margin-top:0">Azione per azione, solo per gli iscritti e per chi ha premuto "Accetta" sui cookie. Si cancellano dopo 90 giorni (180 per gli iscritti).</p>
  <?php if (!$sess): ?><p class="muted">Ancora nessun percorso.</p><?php else: ?><div class="tscroll"><table>
    <thead><tr><th>Inizio</th><th>Chi</th><th class="r">Azioni</th><th class="r">Tempo</th><th>Dispositivo</th><th>Ingresso</th><th></th></tr></thead>
    <tbody><?php foreach ($sess as $s): $inf = json_decode((string)$s['info'], true) ?: []; ?><tr>
      <td><?= when($s['started_at']) ?></td>
      <td><?= $s['email'] ? '<a href="user.php?id=' . (int)$s['user_id'] . '">' . h($s['email']) . '</a>' : '<a href="percorso.php?vid=' . h($s['vid']) . '"><code>' . h(substr((string)$s['vid'], 0, 6)) . '</code></a>' ?></td>
      <td class="r"><?= num($s['events']) ?></td><td class="r"><?= $mins($s['active_secs']) ?></td><td><small><?= h($s['device'] ?: '—') ?></small></td>
      <td><small><?= h(land_kind($inf['land'] ?? null)) ?></small></td><td><a href="percorso.php?sid=<?= h($s['sid']) ?>">percorso →</a></td>
    </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php if (!$byGroup): ?><section class="card"><h2>Clic e azioni</h2><p class="muted">Ancora nessun dato: arrivano dalle prossime visite.</p></section><?php endif; ?>
<?php foreach (EV_GROUPS + array_fill_keys(array_diff(array_keys($byGroup), array_keys(EV_GROUPS)), '') as $g => $gl): if (empty($byGroup[$g])) continue; ?>
<section class="card"><h2><?= h($gl ?: $g) ?></h2>
  <div class="tscroll"><table>
    <thead><tr><th>Cosa</th><th class="r">Ospiti</th><th class="r">Iscritti</th><th class="r">Totale</th></tr></thead>
    <tbody><?php foreach ($byGroup[$g] as $r): ?><tr><td><?= h(ev_label($r['ev'])) ?> <small class="muted"><?= h($r['ev']) ?></small></td>
      <td class="r"><?= num($r['g']) ?></td><td class="r"><?= num($r['u']) ?></td><td class="r"><b><?= num($r['t']) ?></b></td></tr><?php endforeach; ?></tbody>
  </table></div></section>
<?php endforeach; ?>
<?php admin_footer(true); ?>
