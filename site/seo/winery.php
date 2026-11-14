<?php
// Pagina di una cantina per i motori di ricerca: /winery/<id>-<nome>, /it/winery/<id>-<nome>… (vedi seo/common.php).
// Dove si trova, dettagli di OpenStreetMap, vini dal sito della cantina (con le foto delle bottiglie, caricate dal loro sito),
// la zona (cantine nel comune ed entro 20 km), domande frequenti (anche come FAQPage) e dati strutturati Winery.
require __DIR__ . '/common.php';
seo_cache();
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[nwr]\d{1,15}$/', $id)) not_found();
$wm = json_decode((string)@file_get_contents(DATA . '/seo/w.json'), true) ?: [];
$cc = isset($wm[$id]) ? strtoupper($wm[$id]) : null;
$P = $cc ? seo_country($cc) : null;
if (!$P) not_found();
$w = null; foreach ($P['w'] as $x) if ($x[0] === $id) { $w = $x; break; }
if (!$w) not_found();
[, $name, $wc, $ck, $lat, $lng] = $w;
// indirizzo canonico: se il nome nell'URL è diverso (cantina rinominata o link scritto a mano) si va a quello giusto
$path = winery_path($id, $name);
if (strtok($_SERVER['REQUEST_URI'], '?') !== $path) { header('Location: ' . $path, true, 301); exit; }

$C = seo_index()[$cc]; $CI = $P['ci'][$ck]; $R = $P['r'][$CI['r']];
$country = country_name($cc, $C['n']);
$where = implode(', ', array_unique([$CI['n'], $R['n'], $country]));
$label = $name ?: L('in', ['what' => L('winery'), 'where' => $CI['n']]);
$d = winery_det($id, $lat, $lng);
$wines = $d['v'] ?? [];
$site = $d['w'] ?? '';
$host = $site ? preg_replace('~^https?://(www\.)?~i', '', rtrim($site, '/')) : '';
$tel = !empty($d['p']) ? trim(explode(';', str_replace(',', ';', $d['p']))[0]) : '';

// cantine nel comune ed entro 20 km
$cityN = 0; $near = [];
foreach ($P['w'] as $x) {
  if ($x[3] === $ck) $cityN++;
  if ($x[0] === $id) continue;
  $k = km($lat, $lng, $x[4], $x[5]); if ($k <= 20) $near[] = $x + [6 => $k];
}
usort($near, fn($a, $b) => $a[6] <=> $b[6]);
$area = [$cityN > 1 ? L('w_city', ['n' => plural('p_winery', $cityN), 'city' => $CI['n']]) : L('w_city1', ['city' => $CI['n']])];
$area[] = $near ? L('w_near', ['n' => plural('p_winery', count($near))]) . ($near[0][1] ? ' ' . L('w_closest', ['name' => $near[0][1], 'd' => dist($near[0][6])]) : '') : L('w_near0');
$near = array_slice($near, 0, 10);

// domande frequenti: solo quelle di cui abbiamo la risposta
$coords = round($lat, 5) . ', ' . round($lng, 5);
$faq = [[L('q_where', ['name' => $label]), L('a_where', ['name' => $label, 'where' => implode(', ', array_filter([$d['a'] ?? '', $where])), 'c' => $coords])]];
if ($wines) $faq[] = [L('q_wines', ['name' => $label]), L('a_wines', ['list' => implode(sep(), array_slice(array_column($wines, 0), 0, 8))])];
if (!empty($d['o'])) $faq[] = [L('q_hours', ['name' => $label]), L('hours') . ': ' . $d['o'] . '.'];
$faq[] = [L('q_near', ['name' => $label]), $area[1]];

$extra = ($wc ? L('main_wine', ['v' => L('c_' . $wc)]) . '. ' : '') . ($wines ? plural('p_wine', count($wines)) . ': ' . implode(sep(), array_slice(array_column($wines, 0), 0, 3)) . '. ' : '');
$desc = L('w_desc', ['name' => $label, 'where' => $where, 'extra' => $extra]);
$title = $label . ' – ' . L('in', ['what' => L('winery'), 'where' => $CI['n'] . ', ' . $R['n']]) . ' | ' . SITE_NAME;
$pics = array_values(array_filter(array_map(fn($v) => $v[2] ?? '', $wines)));

$crumbs = [[L('wineries'), lp('/wineries/')], [$country, country_path($C)], [$R['n'], region_path($C['s'], $R)], [$CI['n'], city_path($C['s'], $R['s'], $CI)], [$label, null]];
$ld = ['@context' => 'https://schema.org', '@type' => 'Winery', 'name' => $label, 'url' => SITE . $path,
  'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng], 'hasMap' => SITE . map_link($id), 'inLanguage' => seo_lang(),
  'address' => array_filter(['@type' => 'PostalAddress', 'streetAddress' => $d['a'] ?? null, 'addressLocality' => $CI['n'], 'addressRegion' => $R['n'], 'addressCountry' => $cc])];
if ($site) $ld['sameAs'] = $site;
if ($tel) $ld['telephone'] = $tel;
if (!empty($d['o'])) $ld['openingHours'] = $d['o'];
if ($pics) $ld['image'] = array_slice($pics, 0, 3);
$faqLd = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'inLanguage' => seo_lang(),
  'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
ob_start(); $bc = breadcrumbs($crumbs); $crumb = ob_get_clean();
seo_head($title, $desc, $path, [$ld, $bc, $faqLd], $pics[0] ?? null);
echo $crumb;
?>
<article class="winery">
  <p class="kind"><?= h(L('winery')) ?></p>
  <h1><?= h($label) ?></h1>
  <p class="where"><?= h(implode(' · ', array_filter([$d['a'] ?? '', $where]))) ?></p>
  <p class="actions"><a class="btn primary" href="<?= h(map_link($id)) ?>"><?= h(L('open_on_map')) ?></a>
    <a class="btn" href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= h($lat . ',' . $lng) ?>" rel="nofollow"><?= h(L('directions')) ?></a></p>
  <?php if ($wines): ?><h2><?= h(L('wines')) ?> (<?= count($wines) ?>)</h2>
    <?php if ($pics): ?><ul class="bottles"><?php foreach ($wines as $v): ?><li><span class="bimg"><?php if (!empty($v[2])): ?><img src="<?= h($v[2]) ?>" alt="<?= h($v[0]) ?>" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?></span><span><?= wdot($v[1] ?? '') ?><?= h($v[0]) ?></span></li><?php endforeach; ?></ul>
    <?php else: ?><ul class="wines"><?php foreach ($wines as $v): ?><li><?= wdot($v[1] ?? '') ?><?= h($v[0]) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <p class="note"><?= h(L('wines_src')) ?><?php if (!empty($d['vu'])): ?> <a href="<?= h($d['vu']) ?>" rel="nofollow noopener"><?= h(mb_strimwidth(preg_replace('~^https?://(www\.)?~i', '', $d['vu']), 0, 50, '…')) ?></a><?php endif; ?></p>
  <?php endif; ?>
  <h2><?= h(L('details')) ?></h2>
  <ul class="facts">
    <?php if (!empty($d['a'])): ?><li><?= h(L('address')) ?>: <?= h($d['a'] . ', ' . $CI['n']) ?></li><?php endif; ?>
    <?php if ($wc): ?><li><?= h(L('main_wine', ['v' => L('c_' . $wc)])) ?></li><?php endif; ?>
    <?php if (!empty($d['o'])): ?><li><?= h(L('hours')) ?>: <?= h($d['o']) ?></li><?php endif; ?>
    <?php if ($tel): ?><li><?= h(L('phone')) ?>: <a href="tel:<?= h(preg_replace('/[^\d+]/', '', $tel)) ?>"><?= h($tel) ?></a></li><?php endif; ?>
    <?php if ($site): ?><li><?= h(L('website')) ?>: <a href="<?= h($site) ?>" rel="nofollow noopener"><?= h($host) ?></a></li><?php endif; ?>
    <li><?= h(L('coords')) ?>: <?= h($coords) ?></li>
    <li><?= h(L('source')) ?>: <a href="https://www.openstreetmap.org/<?= ['n' => 'node', 'w' => 'way', 'r' => 'relation'][$id[0]] . '/' . substr($id, 1) ?>" rel="nofollow">OpenStreetMap</a></li>
  </ul>
  <h2><?= h(L('w_area')) ?></h2>
  <p><?= h(implode(' ', $area)) ?></p>
  <?php if ($near): ?><h2><?= h(L('nearby')) ?></h2>
    <ul class="list"><?php foreach ($near as $x): ?><li><a href="<?= h(winery_path($x[0], $x[1])) ?>"><?= h($x[1] ?: L('winery')) ?></a> <small><?= dist($x[6]) ?></small></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <h2><?= h(L('faq')) ?></h2>
  <div class="faq"><?php foreach ($faq as [$q, $a]): ?><details open><summary><?= h($q) ?></summary><p><?= h($a) ?></p></details><?php endforeach; ?></div>
  <p class="more"><a href="<?= h(city_path($C['s'], $R['s'], $CI)) ?>"><?= h(L('all_in', ['where' => $CI['n']])) ?></a> · <a href="<?= h(region_path($C['s'], $R)) ?>"><?= h(L('all_in', ['where' => $R['n']])) ?></a></p>
</article>
<?php seo_foot($path);
