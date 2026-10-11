<?php
// ============================================================
//  Сповіщення для браузера (Web Push, RFC 8291 + VAPID RFC 8292).
//  Працює без Firebase: пара ключів VAPID створюється сама при першому запуску
//  і лежить у таблиці settings. Тексти, групи й ліміти — ті самі, що й у застосунку.
// ============================================================
if (!defined('MIN_CLIENT')) { http_response_code(403); exit; }

const WP_TTL = 86400;          // скільки служба сповіщень тримає недоставлене
const WP_RS = 4096;            // розмір запису за RFC 8188

function wpB64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function wpUnb64(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
// сирий відкритий ключ (65 байтів, 0x04 || X || Y) з деталей ключа openssl
function wpRawPub(array $ec): string { return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT); }
// сирі 65 байтів → PEM, щоб openssl міг з ним працювати
function wpPubPem(string $raw): string {
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
function wpSupported(): bool {
  static $o = null;
  if ($o === null) $o = function_exists('openssl_pkey_derive') && function_exists('hash_hkdf') && function_exists('curl_init')
    && in_array('prime256v1', openssl_get_curve_names() ?: [], true) && in_array('aes-128-gcm', openssl_get_cipher_methods() ?: [], true);
  return $o;
}
// Пара ключів VAPID: створюється один раз і зберігається в settings
function wpKeys(): ?array {
  static $k = null; if ($k !== null) return $k ?: null;
  if (!wpSupported()) { $k = false; return null; }
  try {
    $row = q("SELECT v FROM settings WHERE k = 'vapid'")->fetchColumn();
    if ($row) { $j = json_decode((string)$row, true); if (!empty($j['priv']) && !empty($j['pub'])) { $k = $j; return $k; } }
    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$res) { $k = false; return null; }
    openssl_pkey_export($res, $pem);
    $d = openssl_pkey_get_details($res);
    $j = ['priv' => $pem, 'pub' => wpB64(wpRawPub($d['ec']))];
    q("INSERT IGNORE INTO settings (k, v) VALUES ('vapid', ?)", [json_encode($j)]);   // якщо інший процес устиг раніше — його ключі лишаються
    $row = q("SELECT v FROM settings WHERE k = 'vapid'")->fetchColumn();
    $k = json_decode((string)$row, true) ?: $j;
    return $k;
  } catch (Throwable $e) { error_log('[mx] vapid ' . $e->getMessage()); $k = false; return null; }
}
function wpPublicKey(): string { $k = wpKeys(); return $k ? (string)$k['pub'] : ''; }
function wpOn(): bool { return wpPublicKey() !== ''; }

// підпис ES256: openssl дає DER, службам сповіщень потрібні сирі R||S
function wpEs256(string $data, string $pem): ?string {
  if (!openssl_sign($data, $der, $pem, 'sha256WithRSAEncryption')) return null;
  $o = 0; if (($der[$o++] ?? '') !== "\x30") return null;
  if ((ord($der[$o]) & 0x80) !== 0) $o += (ord($der[$o]) & 0x7f); $o++;
  $part = function () use ($der, &$o) {
    if (($der[$o++] ?? '') !== "\x02") return null;
    $len = ord($der[$o++]); $v = substr($der, $o, $len); $o += $len;
    return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
  };
  $r = $part(); $s = $part();
  return ($r === null || $s === null) ? null : $r . $s;
}
// Заголовок Authorization для служби сповіщень (один на домен, діє 12 годин)
function wpAuthHeader(string $endpoint): ?string {
  $k = wpKeys(); if (!$k) return null;
  $p = parse_url($endpoint); if (empty($p['host'])) return null;
  $aud = ($p['scheme'] ?? 'https') . '://' . $p['host'];
  static $cache = [];
  if (isset($cache[$aud]) && $cache[$aud][1] > time() + 600) return $cache[$aud][0];
  $sub = (string)(cfg('push_contact') ?: 'mailto:admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
  $exp = time() + 12 * 3600;
  $jwt = wpB64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . wpB64(json_encode(['aud' => $aud, 'exp' => $exp, 'sub' => $sub]));
  $sig = wpEs256($jwt, $k['priv']); if ($sig === null) return null;
  $h = 'Authorization: vapid t=' . $jwt . '.' . wpB64($sig) . ', k=' . $k['pub'];
  $cache[$aud] = [$h, $exp];
  return $h;
}
// Шифрування тіла за RFC 8291: aes128gcm, ключ — з ECDH між нашим разовим ключем і ключем браузера
// $salt і $localPem задаються лише в тестах (звірка з контрольним прикладом RFC 8291)
function wpEncrypt(string $plain, string $p256dh, string $auth, ?string $salt = null, ?string $localPem = null): ?string {
  $uaPub = wpUnb64($p256dh); $authSecret = wpUnb64($auth);
  if (strlen($uaPub) !== 65 || strlen($authSecret) < 16) return null;
  $local = $localPem ? openssl_pkey_get_private($localPem) : openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  if (!$local) return null;
  $asPub = wpRawPub(openssl_pkey_get_details($local)['ec']);
  $shared = openssl_pkey_derive(wpPubPem($uaPub), $local, 32);
  if ($shared === false) return null;
  $salt = $salt ?? random_bytes(16);
  $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $asPub, $authSecret);
  $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
  $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
  $tag = '';
  $ct = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
  if ($ct === false) return null;
  return $salt . pack('N', WP_RS) . chr(strlen($asPub)) . $asPub . $ct . $tag;
}
// Надсилання пачкою паралельно; повертає [індекс => 'ok' | 'gone' | 'err'], як fcmSendMany
function wpSendMany(array $msgs): array {
  $res = []; if (!$msgs || !wpOn()) return array_fill_keys(array_keys($msgs), 'err');
  foreach (array_chunk($msgs, 20, true) as $chunk) {
    $mh = curl_multi_init(); $hs = [];
    foreach ($chunk as $i => $m) {
      $body = wpEncrypt(json_encode($m['data'], JSON_UNESCAPED_UNICODE), $m['p256dh'], $m['auth']);
      $auth = wpAuthHeader($m['endpoint']);
      if ($body === null || $auth === null) { $res[$i] = 'err'; continue; }
      $ch = curl_init($m['endpoint']);
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => [$auth, 'Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: ' . WP_TTL, 'Urgency: normal'],
        CURLOPT_POSTFIELDS => $body]);
      curl_multi_add_handle($mh, $ch); $hs[$i] = $ch;
    }
    if ($hs) {
      do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
      foreach ($hs as $i => $ch) {
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $res[$i] = ($code >= 200 && $code < 300) ? 'ok' : (($code === 404 || $code === 410) ? 'gone' : 'err');
        if ($res[$i] === 'err') error_log('[mx] webpush ' . $code . ' ' . mb_substr((string)curl_multi_getcontent($ch), 0, 200));
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
      }
    }
    curl_multi_close($mh);
  }
  return $res;
}
// Повідомлення для service worker (sw.js)
function wpMessage(array $sub, string $title, string $body, string $kind, string $open, string $tag): array {
  return ['endpoint' => $sub['endpoint'], 'p256dh' => $sub['p256dh'], 'auth' => $sub['auth'],
    'data' => ['title' => $title, 'body' => $body, 'kind' => $kind, 'open' => $open, 'tag' => $tag !== '' ? $tag : $kind]];
}

// ---------- дії гри ----------
if (isset($a) && strncmp($a, 'webpush_', 8) === 0) {
  switch ($a) {
    // відкритий ключ для підписки в браузері
    case 'webpush_key': { me(); ensureSchema(); out(['ok' => true, 'key' => wpPublicKey(), 'on' => wpOn()]); }
    case 'webpush_reg': {
      $p = me(); ensureSchema();
      $ep = trim((string)arg('endpoint', '')); $k = trim((string)arg('p256dh', '')); $au = trim((string)arg('auth', ''));
      if ($ep === '' || strlen($ep) > 500 || !preg_match('~^https://~', $ep) || $k === '' || $au === '') fail('bad_token');
      $lang = in_array(arg('lang'), PUSH_LANGS, true) ? arg('lang') : 'uk';
      $tz = max(-720, min(840, (int)arg('tz', 120)));
      $prefs = implode(',', array_intersect((array)arg('off', []), PUSH_PREFS));
      q('INSERT INTO push_web (ep_hash, endpoint, p256dh, auth, player_id, lang, tz, prefs) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth), player_id = VALUES(player_id),
           lang = VALUES(lang), tz = VALUES(tz), prefs = VALUES(prefs), updated_at = NOW()',
        [sha1($ep), $ep, mb_substr($k, 0, 255), mb_substr($au, 0, 64), $p['id'], $lang, $tz, $prefs]);
      out(['ok' => true, 'on' => wpOn()]);
    }
    case 'webpush_unreg': {
      me(); $ep = trim((string)arg('endpoint', '')); if ($ep !== '') q('DELETE FROM push_web WHERE ep_hash = ?', [sha1($ep)]);
      out(['ok' => true]);
    }
  }
  fail('bad_op');
}
