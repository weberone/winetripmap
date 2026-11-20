<?php
function priv(string $f = ''): string { return getenv('RUNNER_TEMP') . '/' . $f; }
require __DIR__ . '/../../site/api/geoip.php';
foreach (['8.8.8.8', '151.101.1.69', '93.39.0.1', '2.228.0.1', '212.237.0.1', '2001:4860:4860::8888', '127.0.0.1'] as $ip) {
  $t = microtime(true); $g = geo_lookup($ip);
  echo str_pad($ip, 22), json_encode($g, JSON_UNESCAPED_UNICODE), ' → ', geo_label($g), ' (', round((microtime(true) - $t) * 1000, 1), " ms)\n";
}
$raw = (new MMDB(geo_path()))->get('93.39.0.1'); echo json_encode($raw, JSON_UNESCAPED_UNICODE), "\n";
