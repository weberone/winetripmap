<?php
// Visitatori di un giorno, una riga per persona (stesso browser = stesso cookie wtm_vid; con account = l'iscritto), per la dashboard:
// tipo (nuovo: primo giorno sulla mappa · di ritorno · iscritto), ora della prima e dell'ultima apertura, luogo (dall'IP), da dove arriva,
// ingresso, aperture, tempo con la mappa davanti, cantine aperte, GPS, cookie, dispositivo, link al visitatore e al percorso.
// visits_by_day(): per il grafico, persone al giorno divise in [nuovi, di ritorno, iscritti].
require_once __DIR__ . '/eventi_lib.php';

// chiave della persona: l'iscritto se c'è un account, sennò il cookie del browser (o l'apertura stessa se manca)
function visit_key(array $o): string { return $o['user_id'] ? 'u' . $o['user_id'] : ($o['vid'] ? 'v' . $o['vid'] : 'o' . $o['id']); }
// primo giorno sulla mappa di ogni cookie
function visit_first_days(): array {
  static $f = null;
  if ($f === null) { $f = []; foreach (db()->query('SELECT vid, MIN(day) d FROM app_opens WHERE vid IS NOT NULL GROUP BY vid') as $r) $f[$r['vid']] = $r['d']; }
  return $f;
}
// tipo: 2 iscritto, 0 nuovo, 1 di ritorno
function visit_type(array $o, string $day): int {
  if ($o['user_id']) return 2;
  $f = visit_first_days()[$o['vid'] ?? ''] ?? $day;
  return $f >= $day ? 0 : 1;
}
// [giorno => [nuovi, di ritorno, iscritti]] dal giorno $from a oggi
function visits_by_day(string $from): array {
  $out = []; $tz = new DateTimeZone('Europe/Rome');
  for ($d = new DateTime($from, $tz), $end = today_it(); $d->format('Y-m-d') <= $end; $d->modify('+1 day')) $out[$d->format('Y-m-d')] = [0, 0, 0];
  $seen = [];
  $st = db()->prepare('SELECT id, day, vid, user_id FROM app_opens WHERE day >= ? ORDER BY user_id IS NULL, id'); $st->execute([$from]);
  foreach ($st as $o) {
    // iscritto in quel giorno: le aperture dello stesso browser senza accesso non contano due volte
    $k = $o['day'] . visit_key($o); $kv = $o['day'] . 'v' . $o['vid'];
    if (isset($seen[$k]) || (!$o['user_id'] && $o['vid'] && isset($seen[$kv]))) continue;
    $seen[$k] = 1; if ($o['vid']) $seen[$kv] = 1;
    if (isset($out[$o['day']])) $out[$o['day']][visit_type($o, $o['day'])]++;
  }
  return $out;
}
// righe del giorno, una per persona, la più recente in cima
function visits_of_day(string $day): array {
  $st = db()->prepare('SELECT o.*, u.email, u.name uname, (SELECT 1 FROM user_sessions s WHERE s.sid = o.sid) has_path FROM app_opens o LEFT JOIN users u ON u.id = o.user_id WHERE o.day = ? ORDER BY o.id');
  $st->execute([$day]);
  $p = []; $vidUser = [];
  $rows = $st->fetchAll();
  foreach ($rows as $o) if ($o['user_id'] && $o['vid']) $vidUser[$o['vid']] = $o['user_id']; // stesso browser prima o dopo l'accesso
  foreach ($rows as $o) {
    if (!$o['user_id'] && $o['vid'] && isset($vidUser[$o['vid']])) { $o['user_id'] = $vidUser[$o['vid']]; }
    $k = visit_key($o);
    if (!isset($p[$k])) $p[$k] = ['first' => $o['at'], 'type' => visit_type($o, $day), 'opens' => 0, 'secs' => 0, 'views' => 0, 'gps' => null, 'ck' => null,
      'sids' => [], 'o' => $o, 'days' => 0];
    $x = &$p[$k];
    $x['opens']++; $x['secs'] += (int)$o['secs']; $x['views'] += (int)$o['views']; $x['last'] = $o['last_at'] ?: $o['at'];
    if ($o['gps'] !== null) $x['gps'] = max((int)$x['gps'], (int)$o['gps']);
    if ($o['ck'] !== null) $x['ck'] = (int)$o['ck'];
    if ($o['has_path']) $x['sids'][] = $o['sid'];
    if ($o['email']) { $x['o']['email'] = $o['email']; $x['o']['uname'] = $o['uname']; }
    foreach (['city', 'region', 'cc'] as $f) if ($o[$f]) $x['o'][$f] = $o[$f];
    unset($x);
  }
  // giorni sulla mappa (per chi torna)
  $vids = array_values(array_filter(array_map(fn($x) => $x['o']['vid'], $p)));
  if ($vids) {
    $in = implode(',', array_fill(0, count($vids), '?'));
    $st = db()->prepare("SELECT vid, COUNT(DISTINCT day) d FROM app_opens WHERE vid IN ($in) AND day <= ? GROUP BY vid"); $st->execute([...$vids, $day]);
    $d = []; foreach ($st as $r) $d[$r['vid']] = (int)$r['d'];
    foreach ($p as &$x) $x['days'] = $d[$x['o']['vid']] ?? 1; unset($x);
  }
  usort($p, fn($a, $b) => strcmp($b['last'], $a['last']));
  return $p;
}
function visits_day_html(string $day): string {
  $tz = new DateTimeZone('Europe/Rome');
  $t = fn($s) => $s ? (new DateTime($s . ' UTC'))->setTimezone($tz)->format('H:i') : '';
  $mins = fn($s) => $s >= 60 ? floor($s / 60) . ' min' : ($s ? $s . ' s' : '—');
  $list = visits_of_day($day);
  $names = ['nuovo', 'di ritorno', 'iscritto']; $cnt = [0, 0, 0]; foreach ($list as $x) $cnt[$x['type']]++;
  $prev = (new DateTime($day))->modify('-1 day')->format('Y-m-d'); $next = (new DateTime($day))->modify('+1 day')->format('Y-m-d');
  $label = $day === today_it() ? 'oggi' : ($day === (new DateTime('-1 day', $tz))->format('Y-m-d') ? 'ieri' : (new DateTime($day))->format('d/m/Y'));
  $h = '<div class="daynav"><h2 style="margin:0">Chi ha aperto la mappa ' . ($label === 'oggi' || $label === 'ieri' ? $label : 'il ' . $label) . '</h2>'
    . '<a class="btn" href="?day=' . $prev . '#dayList" data-day="' . $prev . '">← giorno prima</a>'
    . ($day < today_it() ? '<a class="btn" href="?day=' . $next . '#dayList" data-day="' . $next . '">giorno dopo →</a> <a class="btn" href="dashboard.php#dayList" data-day="' . today_it() . '">oggi</a>' : '') . '</div>';
  $h .= '<p class="muted" style="margin-top:0">' . count($list) . ' person' . (count($list) === 1 ? 'a' : 'e') . ': <span class="vtype s0">' . $cnt[0] . ' nuov' . ($cnt[0] === 1 ? 'o' : 'i') . '</span> '
    . '<span class="vtype s1">' . $cnt[1] . ' di ritorno</span> <span class="vtype s2">' . $cnt[2] . ' iscritt' . ($cnt[2] === 1 ? 'o' : 'i') . '</span> · la più recente in cima · tocca il codice per tutte le sue visite</p>';
  if (!$list) return $h . '<p class="muted">Nessuno.</p>';
  $h .= '<div class="tscroll"><table><thead><tr><th>Ora</th><th>Chi</th><th>Luogo</th><th>Da dove</th><th>Ingresso</th><th class="r">Aperture</th><th class="r">Tempo</th><th class="r">Cantine</th><th>GPS</th><th>Cookie</th><th>Dispositivo</th><th>Percorso</th></tr></thead><tbody>';
  foreach ($list as $x) { $o = $x['o'];
    $who = $x['type'] === 2 ? '<a href="user.php?id=' . (int)$o['user_id'] . '">' . h($o['uname'] ?: $o['email'] ?: 'iscritto') . '</a>'
      : ($o['vid'] ? '<a href="percorso.php?vid=' . h($o['vid']) . '"><code>#' . h(substr($o['vid'], 0, 4)) . '</code></a>' : '—');
    $h .= '<tr><td>' . $t($x['first']) . ($t($x['last']) !== $t($x['first']) ? '<small class="muted">–' . $t($x['last']) . '</small>' : '') . '</td>'
      . '<td><span class="vtype s' . $x['type'] . '">' . $names[$x['type']] . '</span> ' . $who . ($x['type'] === 1 ? ' <small class="muted">' . $x['days'] . '° giorno</small>' : '') . '</td>'
      . '<td><small>' . (h(implode(', ', array_filter([$o['city'] ?? '', $o['cc'] ?? '']))) ?: '—') . '</small></td>'
      . '<td>' . h($o['source'] ?: '—') . ($o['ref'] && $o['ref'] !== $o['source'] ? ' <small class="muted">' . h($o['ref']) . '</small>' : '') . '</td>'
      . '<td><small>' . h(land_kind($o['land'])) . '</small></td>'
      . '<td class="r">' . $x['opens'] . '</td><td class="r">' . $mins($x['secs']) . '</td><td class="r">' . ($x['views'] ?: '—') . '</td>'
      . '<td>' . ($x['gps'] === null ? '—' : ($x['gps'] ? '<b>sì</b>' : 'no')) . '</td><td>' . ($x['ck'] === null ? '—' : ($x['ck'] ? 'Accetta' : 'Rifiuta')) . '</td>'
      . '<td><small>' . h($o['device']) . '</small></td>'
      . '<td>' . ($x['sids'] ? '<a href="percorso.php?sid=' . h(end($x['sids'])) . '">vedi →</a>' : '<small class="muted">—</small>') . '</td></tr>';
  }
  return $h . '</tbody></table></div>';
}

// ---------- aperture della mappa (ogni apertura, anche della stessa persona), come la dashboard di SkateboardMap ----------
// k: 0 nuovo (primo giorno sulla mappa di quel browser), 1 di ritorno, 2 iscritto; ck: ha accettato i cookie (ck = 1 in app_opens)
function opens_rows(string $from, ?string $day = null): array {
  $st = db()->prepare('SELECT o.*, u.name uname, u.email FROM app_opens o LEFT JOIN users u ON u.id = o.user_id WHERE ' . ($day ? 'o.day = ?' : 'o.day >= ?') . ' ORDER BY o.id DESC');
  $st->execute([$day ?: $from]); $rows = $st->fetchAll();
  foreach ($rows as &$r) { $r['k'] = visit_type($r, $r['day']); $r['ckok'] = (int)$r['ck'] === 1; }
  return $rows;
}
// grafico: [aperture per giorno, [nuovi, di ritorno, iscritti], [nuovi con/senza cookie, di ritorno con/senza, iscritti con/senza]];
// in più oggi fino ad adesso e ieri fino alla stessa ora (aperture e secondi con la mappa davanti)
function opens_by_day(string $from): array {
  $tz = new DateTimeZone('Europe/Rome'); $n = []; $stk = []; $spl = [];
  for ($d = new DateTime($from, $tz), $end = today_it(); $d->format('Y-m-d') <= $end; $d->modify('+1 day')) { $k = $d->format('Y-m-d'); $n[$k] = 0; $stk[$k] = [0, 0, 0]; $spl[$k] = [0, 0, 0, 0, 0, 0]; }
  $today = today_it(); $yest = (new DateTime('-1 day', $tz))->format('Y-m-d'); $nowHm = (new DateTime('now', $tz))->format('H:i:s');
  $cmp = ['ot' => 0, 'oy' => 0, 'st' => 0, 'sy' => 0];
  foreach (opens_rows($from) as $r) { $d = $r['day']; if (!isset($n[$d])) continue;
    $n[$d]++; $stk[$d][$r['k']]++; $spl[$d][$r['k'] * 2 + ($r['ckok'] ? 0 : 1)]++;
    if ($d !== $today && $d !== $yest) continue;
    if ((new DateTime($r['at'] . ' UTC'))->setTimezone($tz)->format('H:i:s') > $nowHm) continue;
    $t = $d === $today ? 't' : 'y'; $cmp['o' . $t]++; $cmp['s' . $t] += (int)$r['secs'];
  }
  return [$n, $stk, $spl, $cmp];
}
// elenco delle aperture di un giorno: fonti, paesi, una riga per apertura (la più recente in cima)
function opens_day_html(string $day): string {
  $tz = new DateTimeZone('Europe/Rome');
  $rows = opens_rows($day, $day);
  $country = fn($cc) => $cc ? ((class_exists('Locale') ? Locale::getDisplayRegion('-' . $cc, 'it') : '') ?: $cc) : '';
  $label = $day === today_it() ? 'Oggi' : ($day === (new DateTime('-1 day', $tz))->format('Y-m-d') ? 'Ieri' : (class_exists('IntlDateFormatter')
    ? ucfirst((new IntlDateFormatter('it_IT', IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'Europe/Rome', null, 'EEEE d MMMM'))->format(new DateTime($day . ' 12:00', $tz)))
    : (new DateTime($day))->format('d/m/Y')));
  $h = '<h3 class="oday">' . h($label) . ' · <b>' . num(count($rows)) . '</b> ' . (count($rows) === 1 ? 'apertura' : 'aperture') . '</h3>';
  if (!$rows) return $h . '<p class="muted">Nessuna apertura in questo giorno.</p>';
  $src = array_count_values(array_map(fn($o) => $o['source'] ?: 'diretto', $rows)); arsort($src);
  $h .= '<p class="osrc">'; foreach ($src as $k => $n) $h .= '<span>' . h($k) . ' <b>' . num($n) . '</b></span>'; $h .= '</p>';
  $cc = array_count_values(array_filter(array_map(fn($o) => (string)$o['cc'], $rows), 'strlen')); arsort($cc);
  if ($cc) { $h .= '<p class="osrc occ-sum">'; foreach ($cc as $c => $n) $h .= '<span><span class="occ">' . h($country($c)) . '</span> <b>' . num($n) . '</b></span>'; $h .= '</p>'; }
  $h .= '<ul class="olist">';
  foreach ($rows as $o) {
    $t = (new DateTime($o['at'] . ' UTC'))->setTimezone($tz)->format('H:i');
    // ora locale di chi ha aperto la mappa (fuso orario del browser), solo se diversa dall'ora italiana
    $lt = '';
    if (!empty($o['tz']) && in_array($o['tz'], DateTimeZone::listIdentifiers(), true)) {
      $l = (new DateTime($o['at'] . ' UTC'))->setTimezone(new DateTimeZone($o['tz']));
      if ($l->format('H:i') !== $t) $lt = $l->format(in_array($o['cc'], ['US', 'CA', 'AU', 'NZ', 'IN', 'PH', 'PK', 'MX', 'CO', 'EG', 'SA'], true) ? 'g:i A' : 'H:i');
    }
    $where = $o['cc'] ? '<span class="ocr">' . h($country($o['cc'])) . '</span>' . ($o['city'] ? ' <span class="ocity">· ' . h($o['city']) . '</span>' : '') . ($lt ? ' <span class="olocal">(' . $lt . ' locali)</span>' : '') : '<span class="muted">luogo non noto</span>';
    $who = $o['user_id'] ? '<a href="user.php?id=' . (int)$o['user_id'] . '">iscritto ' . h($o['uname'] ?: $o['email']) . '</a>'
      : ($o['vid'] ? '<a href="percorso.php?vid=' . h($o['vid']) . '">ID utente ' . h($o['vid']) . '</a>' : 'senza ID');
    $s = (int)$o['secs'];
    $perm = $s > 0 ? '<span class="perm on">permanenza ' . ($s < 60 ? $s . ' s' : floor($s / 60) . ' min' . ($s % 60 ? ' ' . $s % 60 . ' s' : '')) . '</span>' : '<span class="perm">permanenza: nessun dato</span>';
    $extra = array_filter([$o['views'] ? $o['views'] . ' cantin' . ($o['views'] == 1 ? 'a' : 'e') . ' aperte' : '', (int)$o['gps'] === 1 ? 'GPS attivato' : '']);
    $h .= '<li><time>' . $t . '</time> ' . $where . '<br><b>' . h($o['source'] ?: 'diretto') . '</b>'
      . ($o['ref'] && $o['ref'] !== $o['source'] ? ' <button type="button" class="rinfo" aria-label="Sito di provenienza" data-tip="' . h($o['ref']) . '">i</button>' : '')
      . ' · ' . h((string)$o['device']) . ' · <small class="muted">' . h(land_kind($o['land'])) . '</small>'
      . '<br><span class="tdot s' . $o['k'] . '"></span><span class="wv v' . $o['k'] . '">' . $who . '</span><br>' . $perm
      . ($extra ? ' · <span class="muted">' . h(implode(' · ', $extra)) . '</span>' : '') . ($o['ckok'] ? '' : ' · <span class="ncv">senza cookie</span>') . '</li>';
  }
  return $h . '</ul>';
}
