<?php
// Sitemap per i motori di ricerca (vedi seo/common.php), per ognuna delle 12 lingue:
//   /sitemap.xml                → indice di tutti i file
//   /sitemap-pages.xml          → inglese: stati, regioni, comuni   (/sitemap-pages-it.xml … per le altre lingue)
//   /sitemap-wineries.xml       → inglese: tutte le cantine          (/sitemap-wineries-it.xml …); limite di Google 50.000 per file
// Finché il sito è in prova (SEO_INDEX = false) la sitemap esiste ma non è in robots.txt né in Search Console.
require __DIR__ . '/common.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
$part = (string)($_GET['part'] ?? '');
$url = function (string $loc) { echo '<url><loc>', h(SITE . $loc), "</loc></url>\n"; };
$sfx = fn($l) => $l === 'en' ? '' : "-$l";
if ($part === '') {
  $lm = '<lastmod>' . gmdate('Y-m-d', (int)@filemtime(DATA . '/seo/index.json')) . '</lastmod>';
  echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
  foreach (SEO_LANGS as $l) foreach (['pages', 'wineries'] as $k) echo '<sitemap><loc>', SITE, "/sitemap-$k", $sfx($l), '.xml</loc>', $lm, "</sitemap>\n";
  echo '</sitemapindex>';
  exit;
}
if (!preg_match('/^(pages|wineries)(?:-(' . implode('|', array_slice(SEO_LANGS, 1)) . '))?$/', $part, $m)) { http_response_code(404); echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>'; exit; }
seo_lang(($m[2] ?? '') ?: 'en');
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach (seo_index() as $cc => $C) {
  $P = seo_country($cc); if (!$P) continue;
  if ($m[1] === 'pages') {
    $url(country_path($C));
    foreach ($P['r'] as $r) $url(region_path($C['s'], $r));
    foreach ($P['ci'] as $ci) $url(city_path($C['s'], $P['r'][$ci['r']]['s'], $ci));
  } else foreach ($P['w'] as $w) $url(winery_path($w[0], $w[1]));
}
if ($m[1] === 'pages') $url(lp('/wineries/'));
echo '</urlset>';
