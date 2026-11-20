<?php
// Città e paese approssimati di chi apre la mappa, dall'indirizzo IP, con il database DB-IP "IP to City Lite"
// (CC BY 4.0, "IP Geolocation by DB-IP", https://db-ip.com), che sta nella cartella privata: l'IP non esce dal server
// e non viene salvato, si salvano solo città, regione e paese. Precisione da città (a volte la città del fornitore internet).
// Il file (~130 MB) si scarica da solo la prima volta e poi ogni mese (geo_refresh(), dopo aver risposto all'app).
// Lettore del formato MaxMind DB (.mmdb) senza librerie: albero di ricerca dei bit dell'IP + sezione dati.

const GEO_FILE = 'dbip-city-lite.mmdb';

function geo_path(): string { return priv(GEO_FILE); }

// ['city' => 'Milano', 'region' => 'Lombardia', 'cc' => 'IT'] oppure null (IP locale, file non ancora scaricato, non trovato)
function geo_lookup(string $ip): ?array {
  if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
  if (!is_file(geo_path())) return null;
  try {
    $r = (new MMDB(geo_path()))->get($ip);
  } catch (Throwable $e) { error_log('geoip: ' . $e->getMessage()); return null; }
  if (!is_array($r)) return null;
  $name = fn($o) => is_array($o) ? (string)($o['names']['it'] ?? $o['names']['en'] ?? '') : '';
  $out = ['city' => $name($r['city'] ?? null), 'region' => $name($r['subdivisions'][0] ?? null), 'cc' => (string)($r['country']['iso_code'] ?? '')];
  return $out['cc'] !== '' || $out['city'] !== '' ? $out : null;
}
// "Milano, Lombardia, Italia" (il nome del paese in italiano)
function geo_label(?array $g): string {
  if (!$g) return '';
  $country = $g['cc'] && class_exists('Locale') ? Locale::getDisplayRegion('-' . $g['cc'], 'it') : $g['cc'];
  return implode(', ', array_values(array_unique(array_filter([$g['city'] ?? '', $g['region'] ?? '', $country ?: ''], 'strlen'))));
}

// scarica il file del mese se manca o ha più di 35 giorni; un solo tentativo alla volta e al massimo uno ogni 6 ore
function geo_refresh(): void {
  $f = geo_path();
  if (is_file($f) && filemtime($f) > time() - 35 * 86400) return;
  $lock = priv('geoip.try');
  if (is_file($lock) && filemtime($lock) > time() - 6 * 3600) return;
  @touch($lock);
  register_shutdown_function(function () use ($f) {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    @set_time_limit(600);
    $gz = $f . '.gz.part'; $tmp = $f . '.part';
    foreach ([0, -1] as $m) { // file di questo mese; nei primi giorni del mese può non esserci ancora: quello di prima
      $ym = (new DateTime('first day of this month'))->modify("$m month")->format('Y-m');
      $fp = fopen($gz, 'wb');
      $ch = curl_init("https://download.db-ip.com/free/dbip-city-lite-$ym.mmdb.gz");
      curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_USERAGENT => 'winetripmap.com']);
      $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200; curl_close($ch); fclose($fp);
      if (!$ok) continue;
      $in = gzopen($gz, 'rb'); $out = fopen($tmp, 'wb');
      while (!gzeof($in)) fwrite($out, gzread($in, 1 << 20));
      gzclose($in); fclose($out);
      try { new MMDB($tmp); rename($tmp, $f); @chmod($f, 0600); error_log("geoip: scaricato $ym"); break; }
      catch (Throwable $e) { error_log('geoip: file non valido ' . $e->getMessage()); }
    }
    @unlink($gz); @unlink($tmp);
  });
}

// lettore minimo del formato MaxMind DB (https://maxmind.github.io/MaxMind-DB/)
final class MMDB {
  private $fh; private array $meta; private int $nodes; private int $rec; private int $treeSize; private int $v4start = 0;
  public function __construct(string $file) {
    $this->fh = fopen($file, 'rb');
    if (!$this->fh) throw new RuntimeException('apertura');
    $size = fstat($this->fh)['size'];
    $tail = min($size, 128 * 1024);
    fseek($this->fh, $size - $tail);
    $buf = fread($this->fh, $tail);
    $pos = strrpos($buf, "\xAB\xCD\xEFMaxMind.com");
    if ($pos === false) throw new RuntimeException('metadati');
    $start = $size - $tail + $pos + 14;
    [$this->meta] = $this->decode($start, $start);
    $this->nodes = (int)$this->meta['node_count']; $this->rec = (int)$this->meta['record_size'];
    if (!in_array($this->rec, [24, 28, 32], true)) throw new RuntimeException('record_size');
    $this->treeSize = intdiv($this->rec * 2, 8) * $this->nodes;
    if ((int)$this->meta['ip_version'] === 6) { // gli IPv4 stanno sotto ::/96
      $n = 0; for ($i = 0; $i < 96 && $n < $this->nodes; $i++) $n = $this->node($n, 0);
      $this->v4start = $n;
    }
  }
  public function get(string $ip) {
    $bin = inet_pton($ip);
    if ($bin === false) return null;
    $bits = strlen($bin) * 8;
    $n = $bits === 32 ? $this->v4start : 0;
    if ($bits === 128 && (int)$this->meta['ip_version'] === 4) return null;
    for ($i = 0; $i < $bits && $n < $this->nodes; $i++) $n = $this->node($n, (ord($bin[$i >> 3]) >> (7 - ($i & 7))) & 1);
    if ($n <= $this->nodes) return null; // == nodes: nessun dato
    $off = $n - $this->nodes + $this->treeSize; // = (n - nodes - 16) + treeSize + 16
    return $this->decode($off, $this->treeSize + 16)[0];
  }
  private function node(int $n, int $bit): int {
    $bytes = $this->rec * 2 / 8;
    fseek($this->fh, $n * $bytes);
    $b = fread($this->fh, $bytes);
    if ($this->rec === 24) $v = $bit ? substr($b, 3, 3) : substr($b, 0, 3);
    elseif ($this->rec === 32) $v = $bit ? substr($b, 4, 4) : substr($b, 0, 4);
    else { // 28: il byte di mezzo ha i 4 bit alti del record sinistro e i 4 bassi del destro
      $mid = ord($b[3]);
      $v = $bit ? chr($mid & 0x0F) . substr($b, 4, 3) : chr($mid >> 4) . substr($b, 0, 3);
    }
    return (int)hexdec(bin2hex($v));
  }
  private function read(int $off, int $len): string { if ($len === 0) return ''; fseek($this->fh, $off); return (string)fread($this->fh, $len); }
  private function uint(string $s): int { return $s === '' ? 0 : (int)hexdec(bin2hex($s)); }
  // decodifica il valore che inizia a $off; $base = inizio della sezione dati (i puntatori sono relativi a lì). → [valore, offset dopo]
  private function decode(int $off, int $base): array {
    $ctrl = ord($this->read($off++, 1));
    $type = $ctrl >> 5;
    if ($type === 1) { // puntatore
      $ss = ($ctrl >> 3) & 3; $vvv = $ctrl & 7;
      $p = match ($ss) {
        0 => ($vvv << 8) | $this->uint($this->read($off, 1)),
        1 => (($vvv << 16) | $this->uint($this->read($off, 2))) + 2048,
        2 => (($vvv << 24) | $this->uint($this->read($off, 3))) + 526336,
        3 => $this->uint($this->read($off, 4)),
      };
      return [$this->decode($base + $p, $base)[0], $off + $ss + 1];
    }
    if ($type === 0) $type = 7 + ord($this->read($off++, 1));
    $size = $ctrl & 0x1F;
    if ($size >= 29) {
      $n = $size - 28;
      $x = $this->uint($this->read($off, $n)); $off += $n;
      $size = [1 => 29, 2 => 285, 3 => 65821][$n] + $x;
    }
    switch ($type) {
      case 2: return [$this->read($off, $size), $off + $size];
      case 3: return [unpack('E', $this->read($off, 8))[1], $off + 8];
      case 4: return [$this->read($off, $size), $off + $size];
      case 5: case 6: case 9: case 10: return [$this->uint($this->read($off, $size)), $off + $size];
      case 8: $v = $this->uint($this->read($off, $size)); if ($size === 4 && $v >= 0x80000000) $v -= 0x100000000; return [$v, $off + $size];
      case 7: $m = [];
        for ($i = 0; $i < $size; $i++) { [$k, $off] = $this->decode($off, $base); [$v, $off] = $this->decode($off, $base); $m[$k] = $v; }
        return [$m, $off];
      case 11: $a = [];
        for ($i = 0; $i < $size; $i++) { [$a[], $off] = $this->decode($off, $base); }
        return [$a, $off];
      case 14: return [$size !== 0, $off];
      case 15: return [unpack('G', $this->read($off, 4))[1], $off + 4];
      default: return [null, $off + $size];
    }
  }
}
