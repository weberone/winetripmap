<?php
// Pagine per i motori di ricerca, generate dal server (la mappa è tutta in JavaScript e i crawler ne vedono poco), come SkateboardMap:
//   /wineries/                               → seo/places.php (stati)
//   /wineries/<stato>/                       → seo/places.php (regioni dello stato)
//   /wineries/<stato>/<regione>/             → seo/places.php (comuni della regione)
//   /wineries/<stato>/<regione>/<comune>/    → seo/places.php (cantine del comune)
//   /winery/<id>-<nome>                      → seo/winery.php
//   /sitemap.xml                             → seo/sitemap.php
// Nelle 12 lingue di SkateboardMap: l'inglese senza prefisso, le altre con /it/, /es/… davanti (testi in seo/i18n.php).
// Ogni pagina dichiara le sue versioni nelle altre lingue (hreflang). Regole degli indirizzi in /.htaccess.
// Dati: site/data/seo/ (tools/seo-dati.mjs, da OpenStreetMap e GeoNames) e site/data/det5/ (dettagli e vini delle cantine).
require_once dirname(__DIR__) . '/api/page.php'; // logo (WTM_WORDMARK) e h()

const SITE = 'https://winetripmap.com';
const SITE_NAME = 'WineTripMap';
// IN PROVA: finché il sito non è lanciato le pagine dicono ai motori di ricerca di non indicizzarle (noindex).
// Al lancio: true, e la sitemap va in robots.txt e in Google Search Console.
const SEO_INDEX = false;
const SEO_LANGS = ['en', 'it', 'es', 'pt', 'fr', 'de', 'ru', 'ja', 'zh', 'pl', 'ko', 'id'];
const SEO_LOCALE = ['en' => 'en_GB', 'it' => 'it_IT', 'es' => 'es_ES', 'pt' => 'pt_PT', 'fr' => 'fr_FR', 'de' => 'de_DE', 'ru' => 'ru_RU', 'ja' => 'ja_JP', 'zh' => 'zh_CN', 'pl' => 'pl_PL', 'ko' => 'ko_KR', 'id' => 'id_ID'];
const DATA = __DIR__ . '/../data';

function seo_lang(?string $set = null): string {
  static $l = null;
  if ($set !== null) $l = $set;
  return $l ??= in_array($_GET['lang'] ?? '', SEO_LANGS, true) ? $_GET['lang'] : 'en';
}
// indirizzo nella lingua: /winery/1-x → /it/winery/1-x (l'inglese resta senza prefisso)
function lp(string $path, ?string $lang = null): string { $l = $lang ?? seo_lang(); return $l === 'en' ? $path : "/$l$path"; }
function base_path(string $path): string { return preg_replace('~^/(?:' . implode('|', array_slice(SEO_LANGS, 1)) . ')(?=/)~', '', $path); }
function seo_texts(): array { static $T = null; return $T ??= require __DIR__ . '/i18n.php'; }
function L(string $k, array $v = []): string {
  $T = seo_texts();
  $s = $T[seo_lang()][$k] ?? $T['en'][$k] ?? $k;
  if (is_array($s)) $s = $s[0];
  return $v ? preg_replace_callback('/\{(\w+)\}/', fn($m) => (string)($v[$m[1]] ?? $m[0]), $s) : $s;
}
function fmt_n($n): string {
  static $f = []; $l = seo_lang();
  if (!isset($f[$l])) $f[$l] = class_exists('NumberFormatter') ? new NumberFormatter(SEO_LOCALE[$l], NumberFormatter::DECIMAL) : null;
  return $f[$l] ? $f[$l]->format((int)$n) : number_format((int)$n);
}
// "12 cantine", "12 виноделен", "12軒のワイナリー": numero e parola giusta per la lingua
function plural(string $k, int $n): string {
  $T = seo_texts(); $l = seo_lang(); $f = $T[$l][$k] ?? $T['en'][$k];
  if (in_array($l, ['ru', 'pl'], true) && count($f) === 3) {
    $m10 = $n % 10; $m100 = $n % 100;
    $w = $l === 'ru' ? ($m10 === 1 && $m100 !== 11 ? $f[0] : ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14) ? $f[1] : $f[2]))
      : ($n === 1 ? $f[0] : ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14) ? $f[1] : $f[2]));
  } else $w = count($f) === 1 ? $f[0] : ($n === 1 ? $f[0] : $f[1]);
  return in_array($l, ['ja', 'zh', 'ko'], true) ? fmt_n($n) . $w : fmt_n($n) . ' ' . $w;
}
// nome dello stato nella lingua della pagina (intl); nei dati c'è quello inglese di GeoNames
function country_name(string $cc, string $en): string {
  $l = seo_lang();
  if ($l === 'en' || !class_exists('Locale')) return $en;
  $n = Locale::getDisplayRegion('-' . $cc, $l);
  return $n && $n !== $cc ? $n : $en;
}
function slugify(string $s): string {
  $s = function_exists('transliterator_transliterate') ? transliterator_transliterate('Any-Latin; Latin-ASCII', $s) : $s;
  $s = strtolower((string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
  return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}
// nome di una regione o di un comune nella lingua della pagina (Wikidata, tools/nomi.mjs); altrimenti quello di GeoNames
function tn(array $x): string { return $x['t'][seo_lang()] ?? $x['n']; }
// "Cantine in Piemonte": per stati e regioni alcune lingue vogliono un'altra preposizione che per i comuni ("a Barbaresco")
function in_area(string $what, string $where): string { $T = seo_texts(); $p = $T[seo_lang()]['in_area'] ?? $T[seo_lang()]['in'] ?? $T['en']['in']; return str_replace(['{what}', '{where}'], [$what, $where], $p); }
function sep(): string { return in_array(seo_lang(), ['ja', 'zh'], true) ? '、' : ', '; }

// ---------- dati ----------
function seo_index(): array { static $i = null; return $i ??= json_decode((string)@file_get_contents(DATA . '/seo/index.json'), true) ?: []; }
function seo_country(string $cc): ?array {
  static $c = [];
  $cc = strtolower($cc);
  if (!preg_match('/^[a-z]{2}$/', $cc)) return null;
  return $c[$cc] ??= json_decode((string)@file_get_contents(DATA . "/seo/$cc.json"), true) ?: null;
}
// stato dal suo slug (italy → IT)
function cc_by_slug(string $s): ?string { foreach (seo_index() as $cc => $c) if ($c['s'] === $s) return $cc; return null; }
// dettagli di una cantina (indirizzo, telefono, sito, orari, vini): stesso riquadro di 2,5° della mappa (site/js/app.js, cell)
function winery_det(string $id, float $lat, float $lng): array {
  $f = DATA . '/det5/' . floor($lat / 2.5) . '_' . floor($lng / 2.5) . '.json';
  static $cache = [];
  $cache[$f] ??= json_decode((string)@file_get_contents($f), true) ?: [];
  return $cache[$f][$id] ?? [];
}
function country_path(array $c): string { return lp('/wineries/' . $c['s'] . '/'); }
function region_path(string $cs, array $r): string { return lp("/wineries/$cs/{$r['s']}/"); }
function city_path(string $cs, string $rs, array $ci): string { return lp("/wineries/$cs/$rs/{$ci['s']}/"); }
function winery_path(string $id, string $name): string { $sl = slugify($name); return lp('/winery/' . $id . ($sl ? "-$sl" : '')); }
function map_link(string $id): string { return '/?c=' . rawurlencode($id); }
// distanza in km tra due punti
function km(float $a, float $b, float $c, float $d): float { $r = M_PI / 180; $x = ($d - $b) * $r * cos(($a + $c) / 2 * $r); $y = ($c - $a) * $r; return sqrt($x * $x + $y * $y) * 6371; }
function dist(float $k): string { return $k < 1 ? round($k * 1000) . ' m' : number_format($k, 1, seo_lang() === 'en' ? '.' : ',', '') . ' km'; }

// ---------- copia delle pagine già pronte (12 ore, compresse), come SkateboardMap: i crawler di 12 lingue non pesano ----------
const SEO_CACHE_TTL = 12 * 3600;
function seo_cache(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'GET') return;
  $h = md5((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) . '|' . seo_lang() . '|' . @file_get_contents(DATA . '/seo/v.txt') . '|' . @filemtime(__DIR__ . '/i18n.php'));
  $key = sys_get_temp_dir() . '/wtm-seo/' . substr($h, 0, 2) . '/' . $h . '.gz';
  if (is_file($key) && filemtime($key) > time() - SEO_CACHE_TTL) {
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: public, max-age=3600'); header('X-Cache: hit'); header('Vary: Accept-Encoding');
    if (!SEO_INDEX) header('X-Robots-Tag: noindex, follow');
    if (str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') && !ini_get('zlib.output_compression')) { header('Content-Encoding: gzip'); header('Content-Length: ' . filesize($key)); readfile($key); }
    else echo gzdecode((string)file_get_contents($key));
    exit;
  }
  ob_start(function ($html) use ($key) {
    if (http_response_code() === 200 && strlen($html) > 500) {
      @mkdir(dirname($key), 0700, true);
      $tmp = $key . '.' . getmypid();
      if (@file_put_contents($tmp, gzencode($html, 6)) !== false) @rename($tmp, $key);
    }
    return $html;
  });
}

// ---------- testa e piede ----------
function seo_head(string $title, string $desc, string $path, array $jsonld = [], ?string $image = null, bool $index = true): void {
  $url = SITE . $path; $lang = seo_lang(); $base = base_path($path); $index = $index && SEO_INDEX;
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: public, max-age=3600');
  header('Content-Language: ' . $lang);
  if (!$index) header('X-Robots-Tag: noindex, follow');
  ?><!doctype html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($desc) ?>">
<link rel="canonical" href="<?= h($url) ?>">
<?php foreach (SEO_LANGS as $l): ?><link rel="alternate" hreflang="<?= $l === 'zh' ? 'zh-Hans' : $l ?>" href="<?= h(SITE . lp($base, $l)) ?>">
<?php endforeach; ?><link rel="alternate" hreflang="x-default" href="<?= h(SITE . $base) ?>">
<meta name="robots" content="<?= $index ? 'index,follow,max-image-preview:large' : 'noindex,follow' ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="<?= SEO_LOCALE[$lang] ?>">
<meta property="og:site_name" content="<?= SITE_NAME ?>">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($desc) ?>">
<meta property="og:url" content="<?= h($url) ?>">
<?php if ($image): ?><meta property="og:image" content="<?= h($image) ?>">
<?php endif; ?>
<meta name="theme-color" content="#8e1b33">
<script>try{const t=JSON.parse(localStorage.getItem("wtm.theme"));if(t==="light"||t==="dark")document.documentElement.dataset.scheme=t}catch(e){}</script>
<link rel="stylesheet" href="/fonts/fonts.css">
<link rel="stylesheet" href="/seo/seo.css?v=2">
<?php foreach ($jsonld as $j): ?><script type="application/ld+json"><?= json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
</head>
<body>
<header class="topbar"><a class="logo" href="<?= h(lp('/wineries/')) ?>" aria-label="WineTripMap"><?= WTM_WORDMARK ?></a>
  <div class="topbar-r"><a class="btn primary" href="/"><?= h(L('open_map')) ?></a></div></header>
<main>
<?php
}
function seo_foot(string $path): void { $base = base_path($path); $T = seo_texts(); ?>
</main>
<footer>
  <p><a href="/"><?= h(L('f_map')) ?></a> · <a href="<?= h(lp('/wineries/')) ?>"><?= h(L('f_countries')) ?></a></p>
  <p class="langs"><?= h(L('f_langs')) ?>: <?php foreach (SEO_LANGS as $i => $l): ?><?= $i ? ' · ' : '' ?><a href="<?= h(lp($base, $l)) ?>" hreflang="<?= $l ?>" lang="<?= $l ?>"<?= $l === seo_lang() ? ' aria-current="page"' : '' ?>><?= h($T[$l]['lang_name']) ?></a><?php endforeach; ?></p>
  <p><?= str_replace(['OpenStreetMap', 'GeoNames'], ['<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>', '<a href="https://www.geonames.org/" target="_blank" rel="noopener">GeoNames</a> (<a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">CC BY 4.0</a>)'], h(L('f_data'))) ?></p>
  <p>© <?= date('Y') ?> winetripmap.com</p>
</footer>
</body></html>
<?php }
// briciole di pane, visibili e come dati strutturati
function breadcrumbs(array $items): array {
  echo '<nav class="crumbs" aria-label="Breadcrumb">';
  foreach ($items as $i => [$name, $path]) echo ($i ? ' › ' : ''), $path ? '<a href="' . h($path) . '">' . h($name) . '</a>' : '<span>' . h($name) . '</span>';
  echo '</nav>';
  return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn($i, $x) =>
    ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $x[0]] + ($x[1] ? ['item' => SITE . $x[1]] : []), array_keys($items), $items)];
}
function not_found(): never {
  http_response_code(404);
  seo_head(L('nf_title') . ' | ' . SITE_NAME, L('nf_text'), lp('/wineries/'), [], null, false);
  echo '<h1>' . h(L('nf_title')) . '</h1><p><a href="/">' . h(L('open_map')) . '</a> · <a href="' . h(lp('/wineries/')) . '">' . h(L('nf_link')) . '</a></p>';
  seo_foot(lp('/wineries/')); exit;
}
const WINE_DOT = ['r' => '#8e1b33', 'b' => '#d6a51f', 'o' => '#e27591', 's' => '#b38b2e'];
function wdot(string $c): string { return isset(WINE_DOT[$c]) ? '<span class="wdot" style="background:' . WINE_DOT[$c] . '" title="' . h(L('c_' . $c)) . '"></span>' : '<span class="wdot none"></span>'; }
