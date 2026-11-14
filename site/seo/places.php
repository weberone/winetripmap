<?php
// Pagine elenco per i motori di ricerca (vedi seo/common.php), nelle 12 lingue:
//   /wineries/                               → tutti gli stati e i comuni con più cantine
//   /wineries/<stato>/                       → le regioni dello stato
//   /wineries/<stato>/<regione>/             → i comuni della regione
//   /wineries/<stato>/<regione>/<comune>/    → le cantine del comune
require __DIR__ . '/common.php';
seo_cache();
$cs = (string)($_GET['country'] ?? ''); $rs = (string)($_GET['region'] ?? ''); $ts = (string)($_GET['city'] ?? '');
foreach ([$cs, $rs, $ts] as $x) if ($x !== '' && !preg_match('/^[a-z0-9-]+$/', $x)) not_found();
$idx = seo_index();
$wine = fn($n) => plural('p_winery', $n);

// ---------- tutti gli stati ----------
if ($cs === '') {
  uasort($idx, fn($a, $b) => $b['k'] <=> $a['k']);
  $total = array_sum(array_column($idx, 'k'));
  $path = lp('/wineries/');
  $v = ['n' => $wine($total), 'c' => plural('p_country', count($idx))];
  ob_start(); $bc = breadcrumbs([[L('wineries'), null]]); $crumb = ob_get_clean();
  seo_head(L('world_title', $v) . ' | ' . SITE_NAME, L('world_desc', $v), $path,
    [$bc, ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => L('world_h1'), 'url' => SITE . $path, 'inLanguage' => seo_lang()]]);
  echo $crumb; ?>
  <h1><?= h(L('world_h1')) ?></h1>
  <p class="lead"><?= h(L('world_lead', ['n' => fmt_n($total), 'c' => plural('p_country', count($idx))])) ?> <a href="/mappa/"><?= h(L('world_lead_link')) ?></a>.</p>
  <h2><?= h(L('f_countries')) ?></h2>
  <ul class="grid"><?php foreach ($idx as $cc => $c): ?><li><a href="<?= h(country_path($c)) ?>"><b><?= h(country_name($cc, $c['n'])) ?></b><small><?= h($wine($c['k'])) ?> · <?= h(plural('p_region', count($c['r']))) ?></small></a></li><?php endforeach; ?></ul>
  <?php seo_foot($path); exit;
}

$cc = cc_by_slug($cs); if (!$cc) not_found();
$C = $idx[$cc]; $P = seo_country($cc); if (!$P) not_found();
$country = country_name($cc, $C['n']);

// ---------- regioni di uno stato ----------
if ($rs === '') {
  $regions = $P['r']; uasort($regions, fn($a, $b) => $b['k'] <=> $a['k']);
  $path = country_path($C);
  $top = implode(sep(), array_slice(array_column($regions, 'n'), 0, 4));
  $v = ['country' => $country, 'n' => $wine($C['k']), 'c' => plural('p_region', count($regions)), 'top' => $top];
  ob_start(); $bc = breadcrumbs([[L('wineries'), lp('/wineries/')], [$country, null]]); $crumb = ob_get_clean();
  seo_head(L('country_title', $v) . ' | ' . SITE_NAME, L('country_desc', $v), $path, [$bc]);
  echo $crumb;
  // i comuni con più cantine dello stato
  $cities = $P['ci']; uasort($cities, fn($a, $b) => $b['k'] <=> $a['k']); $cities = array_slice($cities, 0, 24, true); ?>
  <h1><?= h(L('in', ['what' => L('wineries'), 'where' => $country])) ?></h1>
  <p class="lead"><?= h(L('country_lead', $v)) ?></p>
  <h2><?= h(L('regions')) ?></h2>
  <ul class="grid"><?php foreach ($regions as $r): ?><li><a href="<?= h(region_path($C['s'], $r)) ?>"><b><?= h($r['n']) ?></b><small><?= h($wine($r['k'])) ?></small></a></li><?php endforeach; ?></ul>
  <h2><?= h(L('top_cities')) ?></h2>
  <ul class="grid"><?php foreach ($cities as $ci): $r = $P['r'][$ci['r']]; ?><li><a href="<?= h(city_path($C['s'], $r['s'], $ci)) ?>"><b><?= h($ci['n']) ?></b><small><?= h($r['n']) ?> · <?= h($wine($ci['k'])) ?></small></a></li><?php endforeach; ?></ul>
  <?php seo_foot($path); exit;
}

$rk = null; foreach ($P['r'] as $k => $r) if ($r['s'] === $rs) { $rk = $k; break; }
if (!$rk) not_found();
$R = $P['r'][$rk];

// ---------- comuni di una regione ----------
if ($ts === '') {
  $cities = array_filter($P['ci'], fn($c) => $c['r'] === $rk); uasort($cities, fn($a, $b) => $b['k'] <=> $a['k'] ?: strcmp($a['n'], $b['n']));
  $path = region_path($C['s'], $R);
  $top = implode(sep(), array_slice(array_column($cities, 'n'), 0, 4));
  $v = ['region' => $R['n'], 'country' => $country, 'n' => $wine($R['k']), 'c' => plural('p_city', count($cities)), 'top' => $top];
  ob_start(); $bc = breadcrumbs([[L('wineries'), lp('/wineries/')], [$country, country_path($C)], [$R['n'], null]]); $crumb = ob_get_clean();
  seo_head(L('region_title', $v) . ' | ' . SITE_NAME, L('region_desc', $v), $path, [$bc]);
  echo $crumb; ?>
  <h1><?= h(L('in', ['what' => L('wineries'), 'where' => $R['n']])) ?></h1>
  <p class="lead"><?= h(L('region_lead', $v)) ?> <a href="/mappa/"><?= h(L('open_map')) ?></a>.</p>
  <h2><?= h(L('towns')) ?></h2>
  <ul class="grid"><?php foreach ($cities as $ci): ?><li><a href="<?= h(city_path($C['s'], $R['s'], $ci)) ?>"><b><?= h($ci['n']) ?></b><small><?= h($wine($ci['k'])) ?></small></a></li><?php endforeach; ?></ul>
  <?php seo_foot($path); exit;
}

// ---------- cantine di un comune ----------
$ck = null; foreach ($P['ci'] as $k => $c) if ($c['r'] === $rk && $c['s'] === $ts) { $ck = (int)$k; break; }
if (!$ck) not_found();
$CI = $P['ci'][$ck];
$list = array_values(array_filter($P['w'], fn($w) => $w[3] === $ck));
usort($list, fn($a, $b) => strcmp($a[1] ?: 'zzz', $b[1] ?: 'zzz'));
$path = city_path($C['s'], $R['s'], $CI);
$names = implode(sep(), array_slice(array_filter(array_column($list, 1)), 0, 4));
$v = ['city' => $CI['n'], 'region' => $R['n'], 'country' => $country, 'n' => $wine(count($list)), 'names' => $names ?: $CI['n']];
// comuni vicini della stessa regione (entro 30 km), per i link
$near = [];
foreach ($P['ci'] as $k => $c) { if ((int)$k === $ck) continue; $d = km($CI['lat'], $CI['lng'], $c['lat'], $c['lng']); if ($d < 30) $near[] = $c + ['d' => $d]; }
usort($near, fn($a, $b) => $a['d'] <=> $b['d']); $near = array_slice($near, 0, 12);
ob_start(); $bc = breadcrumbs([[L('wineries'), lp('/wineries/')], [$country, country_path($C)], [$R['n'], region_path($C['s'], $R)], [$CI['n'], null]]); $crumb = ob_get_clean();
$ld = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => L('in', ['what' => L('wineries'), 'where' => $CI['n']]), 'numberOfItems' => count($list),
  'itemListElement' => array_map(fn($w, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => SITE . winery_path($w[0], $w[1]), 'name' => $w[1] ?: L('winery')], $list, array_keys($list))];
seo_head(L(count($list) === 1 ? 'city_title1' : 'city_title', $v) . ' | ' . SITE_NAME, L('city_desc', $v), $path, [$bc, $ld]);
echo $crumb; ?>
<h1><?= h(L('in', ['what' => L('wineries'), 'where' => $CI['n']])) ?></h1>
<p class="lead"><?= h(L('city_lead', $v)) ?> <a href="/mappa/?c=<?= h(rawurlencode($list[0][0])) ?>"><?= h(L('city_lead_link')) ?></a>.</p>
<ul class="cards"><?php foreach ($list as $w): ?><li><a href="<?= h(winery_path($w[0], $w[1])) ?>"><?= wdot($w[2]) ?><span><b><?= h($w[1] ?: L('in', ['what' => L('winery'), 'where' => $CI['n']])) ?></b><?php if ($w[2]): ?><small><?= h(L('main_wine', ['v' => L('c_' . $w[2])])) ?></small><?php endif; ?></span></a></li><?php endforeach; ?></ul>
<?php if ($near): ?><h2><?= h(L('nearby')) ?></h2>
  <ul class="grid"><?php foreach ($near as $c): $r = $P['r'][$c['r']]; ?><li><a href="<?= h(city_path($C['s'], $r['s'], $c)) ?>"><b><?= h($c['n']) ?></b><small><?= h($wine($c['k'])) ?> · <?= dist($c['d']) ?></small></a></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php seo_foot($path);
