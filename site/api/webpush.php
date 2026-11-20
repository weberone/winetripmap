<?php
// Notifiche push (Web Push con chiavi VAPID) all'amministratore, come SkateboardMap, senza librerie: solo OpenSSL di PHP.
// I dispositivi si iscrivono da Admin → Impostazioni (pulsante "Attiva su questo dispositivo", service worker /push-sw.js);
// su iPhone serve WineTripMap aggiunto alla schermata Home e aperto da lì.
// Chiavi, dispositivi e interruttori degli avvisi in webpush.json nella cartella privata. Il messaggio è cifrato come vuole
// lo standard (RFC 8291, aes128gcm); l'intestazione Authorization è un JWT firmato ES256 (RFC 8292).

function webpush_data(): array {
  $d = jread('webpush.json');
  if (empty($d['priv'])) { // prima volta: coppia di chiavi VAPID (P-256)
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($k, $pem);
    $d = ['priv' => $pem, 'pub' => b64u(ec_point($k)), 'subs' => [], 'opts' => ['new' => true, 'return' => true, 'users' => true]];
    webpush_save($d);
  }
  return $d + ['subs' => [], 'opts' => []];
}
function webpush_save(array $d): void { jwrite('webpush.json', $d); }

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
// punto pubblico "non compresso" (0x04 || x || y) di una chiave EC
function ec_point($key): string {
  $e = openssl_pkey_get_details($key)['ec'];
  return "\x04" . str_pad($e['x'], 32, "\0", STR_PAD_LEFT) . str_pad($e['y'], 32, "\0", STR_PAD_LEFT);
}
// chiave pubblica OpenSSL da un punto P-256 grezzo (65 byte)
function ec_pub_from_point(string $p) {
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $p;
  return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}
// firma ES256 per il JWT: OpenSSL la dà in DER, il JWT la vuole come r || s (32 + 32 byte)
function es256_sign(string $data, $priv): string {
  openssl_sign($data, $der, $priv, OPENSSL_ALGO_SHA256);
  $o = 3; // SEQUENCE(2 byte) + INTEGER
  $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl); $o += 1 + $rl + 1;
  $sl = ord($der[$o]); $s = substr($der, $o + 1, $sl);
  $fix = fn($n) => str_pad(ltrim($n, "\0"), 32, "\0", STR_PAD_LEFT);
  return $fix($r) . $fix($s);
}
// cifra $payload per un'iscrizione (RFC 8291) → corpo della richiesta
function webpush_encrypt(string $payload, string $p256dh, string $auth): string {
  $ua = b64u_dec($p256dh); $authSecret = b64u_dec($auth);
  $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  $as = ec_point($eph);
  $shared = openssl_pkey_derive(ec_pub_from_point($ua), $eph, 32);
  $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua . $as, $authSecret);
  $salt = random_bytes(16);
  $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
  $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
  $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
  return $salt . pack('N', 4096) . chr(65) . $as . $ct . $tag;
}

// manda {title, body, url} a tutti i dispositivi iscritti; toglie quelli che il servizio push non riconosce più. → quanti l'hanno ricevuta
function webpush_send(string $title, string $body, string $url = '/admin/'): int {
  $d = webpush_data();
  if (!$d['subs']) return 0;
  $priv = openssl_pkey_get_private($d['priv']);
  $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $ok = 0; $keep = [];
  foreach ($d['subs'] as $sub) {
    $p = parse_url($sub['endpoint']);
    $head = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64u(json_encode(['aud' => $p['scheme'] . '://' . $p['host'], 'exp' => time() + 12 * 3600, 'sub' => 'mailto:winetripmap@gmail.com']));
    $jwt = "$head.$claims." . b64u(es256_sign("$head.$claims", $priv));
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => webpush_encrypt($payload, $sub['p256dh'], $sub['auth']),
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: high',
        "Authorization: vapid t=$jwt, k={$d['pub']}"]]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) $ok++;
    else error_log("webpush: $code {$p['host']}");
    if ($code !== 404 && $code !== 410) $keep[] = $sub; // 404/410: iscrizione scaduta o revocata
  }
  if (count($keep) !== count($d['subs'])) { $d = webpush_data(); $d['subs'] = $keep; webpush_save($d); }
  return $ok;
}
