<?php
// prova temporanea: c'è un servizio di geolocalizzazione dell'IP sul server? (solo nomi, nessun valore)
header('Content-Type: application/json');
echo json_encode(['server_keys' => array_values(array_filter(array_keys($_SERVER), fn($k) => preg_match('/GEO|COUNTRY|CITY|CF_|CDN/i', $k))),
  'ext' => array_values(array_filter(get_loaded_extensions(), fn($e) => preg_match('/geo|maxmind|intl|gmp|openssl|curl/i', $e))),
  'php' => PHP_VERSION, 'mem' => ini_get('memory_limit'), 'maxexec' => ini_get('max_execution_time'), 'fcgi' => function_exists('fastcgi_finish_request'),
  'disk_free_gb' => round(@disk_free_space(__DIR__) / 1e9, 1)]);
