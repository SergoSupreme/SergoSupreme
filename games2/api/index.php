<?php
// ============================================================
//  API «Мій Хмарочос UA». Один вхід: POST /api/?a=дія, тіло — JSON.
//  Авторизація: заголовок Authorization: Bearer <токен>.
// ============================================================
declare(strict_types=1);
require __DIR__ . '/db.php';
// Жодні попередження PHP не повинні ламати JSON-відповідь (інакше гра вважає сервер недоступним)
ini_set('display_errors', '0'); error_reporting(E_ALL); ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . cfg('cors_origin'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') exit;

function out($d, int $code = 200) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(string $err, int $code = 400) { out(['ok' => false, 'error' => $err], $code); }
function rawBody(): string { static $r = null; if ($r === null) { $r = (string)file_get_contents('php://input'); if ($r !== '' && ($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip') $r = @gzdecode($r) ?: ''; } return $r; }
function body(): array { static $b = null; if ($b === null) { $raw = rawBody(); $b = $raw ? (json_decode($raw, true) ?: []) : []; if (!$b && !empty($_POST['payload'])) $b = json_decode($_POST['payload'], true) ?: []; } return $b; }
const MIN_CLIENT = '1.7';
function arg(string $k, $d = null) { $b = body(); return $b[$k] ?? ($_GET[$k] ?? $d); }

function rate(string $key, int $max, int $win) {
  $now = time();
  // один запит замість двох: лічильник повертається через LAST_INSERT_ID
  q('INSERT INTO rate_limits (rkey, hits, window_start) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE
       hits = IF(window_start < ? , 1, hits + 1), window_start = IF(window_start < ?, VALUES(window_start), window_start)', [$key, $now, $now - $win, $now - $win]);
  $h = (int)q('SELECT hits FROM rate_limits WHERE rkey = ?', [$key])->fetchColumn();
  if ($h > $max) fail('too_many_requests', 429);
}
function ip(): string { return substr($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0', 0, 45); }

function bearer(): ?string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $h = $v; }
  if (preg_match('/Bearer\s+([a-f0-9]{64})/i', $h, $m)) return $m[1];
  $t = arg('token'); return is_string($t) && preg_match('/^[a-f0-9]{64}$/', $t) ? $t : null;
}
function me(bool $required = true): ?array {
  static $p = false;
  if ($p !== false) return $p;
  $t = bearer(); $p = null;
  if ($t) {
    ensureSchema();
    $p = q('SELECT p.*, UNIX_TIMESTAMP(p.last_seen) _seen, UNIX_TIMESTAMP(s.last_used) _used FROM sessions s JOIN players p ON p.id = s.player_id WHERE s.token_hash = ?', [hash('sha256', $t)])->fetch() ?: null;
    // запис «був онлайн» — не частіше ніж раз на хвилину (менше навантаження на базу)
    if ($p) { if (time() - (int)$p['_used'] > 60) q('UPDATE sessions SET last_used = NOW() WHERE token_hash = ?', [hash('sha256', $t)]); if (time() - (int)$p['_seen'] > 15) q('UPDATE players SET last_seen = NOW() WHERE id = ?', [$p['id']]); } // точність ~15 с — для сповіщень «через хвилину неактивності»
  }
  if ($p) onlineSnap();
  if ($p && function_exists('pushTick')) pushTick(); // відкладені сповіщення надсилаються з будь-якого запиту гравців (не лише з beat)
  if ($required && !$p) fail('unauthorized', 401);
  if ($p && $p['banned']) {
    if (!empty($p['ban_until']) && strtotime($p['ban_until'] . ' UTC') < time()) { q('UPDATE players SET banned = 0, ban_until = NULL WHERE id = ?', [$p['id']]); $p['banned'] = 0; }
    else out(['ok' => false, 'error' => 'banned', 'until' => $p['ban_until'] ? strtotime($p['ban_until'] . ' UTC') : null, 'reason' => $p['ban_reason'] ?? null], 403);
  }
  return $p;
}
function newSession(int $pid): string {
  $t = bin2hex(random_bytes(32));
  q('INSERT INTO sessions (token_hash, player_id, device) VALUES (?, ?, ?)', [hash('sha256', $t), $pid, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120)]);
  return $t;
}
function ensureSchema(bool $force = false) {
  static $done = false; if ($done && !$force) return; $done = true;
  $flag = schemaFlag();
  if (!$force && is_file($flag)) return;
  // права доступу та обмеження гравців
  foreach (['access' => 'TINYINT(1) NOT NULL DEFAULT 0', 'muted' => 'TINYINT(1) NOT NULL DEFAULT 0', 'ban_until' => 'DATETIME NULL', 'ban_reason' => 'VARCHAR(200) NULL',
            'chat_until' => 'DATETIME NULL', 'chat_reason' => 'VARCHAR(200) NULL', 'dm_until' => 'DATETIME NULL', 'dm_reason' => 'VARCHAR(200) NULL', 'device' => 'CHAR(32) NULL'] as $c => $def)
    if (!q("SHOW COLUMNS FROM players LIKE '$c'")->fetch()) { db()->exec("ALTER TABLE players ADD COLUMN $c $def"); if ($c === 'access') db()->exec('UPDATE players SET access = 1 WHERE is_admin = 1'); }
  if (!q("SHOW COLUMNS FROM saves LIKE 'active_token'")->fetch()) db()->exec('ALTER TABLE saves ADD COLUMN active_token CHAR(64) NULL AFTER data');
  foreach (['maze_keys' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'lift_lvl' => 'INT UNSIGNED NOT NULL DEFAULT 1', 'lobby_lvl' => 'INT UNSIGNED NOT NULL DEFAULT 1',
            'hotel_lvl' => 'INT UNSIGNED NOT NULL DEFAULT 1', 'chests' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'achievements' => 'INT UNSIGNED NOT NULL DEFAULT 0'] as $c => $def)
    if (!q("SHOW COLUMNS FROM player_stats LIKE '$c'")->fetch()) db()->exec("ALTER TABLE player_stats ADD COLUMN $c $def");
  $sql = file_get_contents(__DIR__ . '/install.sql');
  foreach (['player_floors', 'player_residents', 'friends', 'player_blocks', 'dm_messages', 'promos', 'promo_claims', 'balance_log', 'econ_daily', 'admin_log', 'reward_claims', 'gifts', 'settings', 'corps', 'corp_members', 'corp_invites', 'corp_don', 'corp_log', 'corp_chat', 'donations', 'tour_cups', 'tour_corp', 'tr_cache', 'push_tokens', 'push_queue', 'push_bcast', 'push_state', 'push_log'] as $t) if (preg_match('/CREATE TABLE IF NOT EXISTS ' . $t . ' \(.*?\) ENGINE=[^;]+;/s', $sql, $m)) db()->exec($m[0]);
  // стандартні новини більше не потрібні — новини створює лише адміністратор
  q("DELETE FROM news WHERE id <= 7 AND title IN ('Ласкаво просимо!','Колекції','Лабіринт','Лотерея','Щаслива година','Вертолітний майданчик','Сезонний пропуск')");
  db()->exec('ALTER TABLE news AUTO_INCREMENT = 100');
  foreach (['xp' => 'DOUBLE NOT NULL DEFAULT 0', 'lvl' => 'INT UNSIGNED NOT NULL DEFAULT 1', 'boosts' => 'TEXT NULL', 'build' => 'TEXT NULL'] as $c => $def)
    if (q("SHOW TABLES LIKE 'corps'")->fetch() && !q("SHOW COLUMNS FROM corps LIKE '$c'")->fetch()) db()->exec("ALTER TABLE corps ADD COLUMN $c $def");
  foreach (['xp' => 'DOUBLE NOT NULL DEFAULT 0', 'ctask' => 'TEXT NULL', 'ctask_cancel' => 'INT UNSIGNED NOT NULL DEFAULT 0'] as $c => $def)
    if (q("SHOW TABLES LIKE 'corp_members'")->fetch() && !q("SHOW COLUMNS FROM corp_members LIKE '$c'")->fetch()) db()->exec("ALTER TABLE corp_members ADD COLUMN $c $def");
  foreach (['type' => "VARCHAR(16) NOT NULL DEFAULT 'discount'", 'cfg' => 'TEXT NULL'] as $c => $def)
    if (!q("SHOW COLUMNS FROM promos LIKE '$c'")->fetch()) db()->exec("ALTER TABLE promos ADD COLUMN $c $def");
  schemaSync();
  @file_put_contents($flag, '1');
}
// знімок «скільки гравців онлайн» кожні 5 хвилин — для графіка в адмін-панелі
function onlineSnap() {
  $f = sys_get_temp_dir() . '/mx_onl_' . md5(cfg('db_name'));
  if (is_file($f) && filemtime($f) > time() - 300) return; @touch($f);
  try { q('INSERT IGNORE INTO online_log (t, n) SELECT ?, COUNT(*) FROM players WHERE last_seen > NOW() - INTERVAL 10 MINUTE', [intdiv(time(), 300) * 300]); q('DELETE FROM online_log WHERE t < ?', [time() - 30 * 86400]); } catch (Throwable $e) {}
}
function schemaFlag(): string { return sys_get_temp_dir() . '/mx_schema_v19_' . md5(cfg('db_name')); }
// Звіряє ВСІ таблиці й колонки з install.sql: створює відсутні таблиці та додає відсутні колонки (стара або неповна база)
function schemaSync() {
  $sql = (string)@file_get_contents(__DIR__ . '/install.sql');
  if (!preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\n\) ENGINE=[^;]+;/s', $sql, $mm, PREG_SET_ORDER)) return;
  foreach ($mm as $m) {
    $t = $m[1];
    try { db()->exec($m[0]); } catch (Throwable $e) { error_log("[mx] schema create $t: " . $e->getMessage()); continue; }
    try { $have = array_map('strtolower', q("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { continue; }
    foreach (preg_split('/\n/', $m[2]) as $ln) {
      $ln = trim(preg_replace('/--.*$/', '', $ln)); $ln = rtrim($ln, ',');
      if (!preg_match('/^`?(\w+)`?\s+(.+)$/', $ln, $c)) continue;
      if (preg_match('/^(PRIMARY|KEY|UNIQUE|INDEX|CONSTRAINT|FOREIGN|FULLTEXT)$/i', $c[1]) || in_array(strtolower($c[1]), $have, true)) continue;
      $def = $c[2];
      if (stripos($def, 'AUTO_INCREMENT') !== false) continue;
      if (stripos($def, 'NOT NULL') !== false && stripos($def, 'DEFAULT') === false) $def = str_ireplace('NOT NULL', 'NULL', $def);
      try { db()->exec("ALTER TABLE `$t` ADD COLUMN `{$c[1]}` $def"); } catch (Throwable $e) { error_log("[mx] schema add $t.{$c[1]}: " . $e->getMessage()); }
    }
  }
}
// Розкладаємо стан гри по таблицях (поверхи, жителі) — лише якщо вони змінились
function mirrorState(int $pid, array $st) {
  $floors = []; $i = 0;
  foreach (($st['floors'] ?? []) as $f) {
    $w = 0; foreach (($st['residents'] ?? []) as $r) if (($r['job'] ?? null) === ($f['id'] ?? -1)) $w++;
    $floors[] = [$pid, $i++, (int)($f['id'] ?? 0), substr((string)($f['type'] ?? ''), 0, 16), isset($f['biz']) ? substr((string)$f['biz'], 0, 32) : null,
      (int)($f['stars'] ?? 0), (int)($f['lvl'] ?? 0), $w, isset($f['slots']) ? substr(implode(',', array_map(fn($x) => $x['st'] ?? '', $f['slots'])), 0, 64) : null];
  }
  $res = [];
  foreach (($st['residents'] ?? []) as $r) $res[] = [$pid, (int)($r['id'] ?? 0), mb_substr((string)($r['name'] ?? ''), 0, 40), substr((string)($r['ch'] ?? ''), 0, 16),
      isset($r['dream']) ? substr((string)$r['dream'], 0, 32) : null, isset($r['job']) && $r['job'] !== null ? (int)$r['job'] : null, (int)($r['rank'] ?? 0), (int)($r['stars'] ?? 0), !empty($r['dreamDone']) && ($r['job'] ?? null) ? 1 : 0];
  $hash = md5(json_encode([$floors, $res]));
  $flag = sys_get_temp_dir() . '/mx_mirror_' . md5(cfg('db_name')) . '_' . $pid;
  if (@file_get_contents($flag) === $hash) return;
  q('DELETE FROM player_floors WHERE player_id = ?', [$pid]);
  foreach (array_chunk($floors, 100) as $ch) q('INSERT INTO player_floors (player_id, floor_no, floor_id, type, biz, stars, level, workers, slots) VALUES ' . implode(',', array_fill(0, count($ch), '(?,?,?,?,?,?,?,?,?)')), array_merge(...$ch));
  q('DELETE FROM player_residents WHERE player_id = ?', [$pid]);
  foreach (array_chunk($res, 100) as $ch) q('INSERT INTO player_residents (player_id, res_id, name, ch, dream, job_floor, rank_lvl, stars, happy) VALUES ' . implode(',', array_fill(0, count($ch), '(?,?,?,?,?,?,?,?,?)')), array_merge(...$ch));
  @file_put_contents($flag, $hash);
}
function saveRow(int $pid) {
  $s = q('SELECT rev, data, active_token, UNIX_TIMESTAMP(updated_at) t FROM saves WHERE player_id = ?', [$pid])->fetch();
  return $s ? ['rev' => (int)$s['rev'], 'data' => json_decode($s['data']), 't' => (int)$s['t']] : null;
}
function pub(array $p): array { return ['id' => (int)$p['id'], 'nick' => $p['nick'], 'avatar' => $p['avatar'], 'lang' => $p['lang'], 'access' => (int)($p['access'] ?? 0), 'admin' => (int)($p['access'] ?? 0) === 1, 'hasPassword' => !empty($p['pass_hash'])]; }
function cleanNick($n): string {
  $n = trim(preg_replace('/\s+/u', ' ', (string)$n));
  if (mb_strlen($n) < 3 || mb_strlen($n) > 20) fail('nick_length');
  if (!preg_match('/^[\p{L}\p{N} _.\-]+$/u', $n)) fail('nick_chars');
  return $n;
}
// Обмеження: бан у чаті / бан в особистих (термін до дати; NULL — немає)
function restricted(array $p, string $kind): void {
  $u = $p[$kind . '_until'] ?? null;
  if ($u && strtotime($u . ' UTC') > time()) out(['ok' => false, 'error' => $kind . '_banned', 'until' => strtotime($u . ' UTC'), 'reason' => $p[$kind . '_reason'] ?? null], 403);
}
// маленький кеш (APCu, якщо є, інакше файл у тимчасовій теці)
function cacheGet(string $k, int $ttl, callable $fn) {
  $key = 'mx_' . md5(cfg('db_name') . $k);
  if (function_exists('apcu_fetch')) { $v = apcu_fetch($key, $ok); if ($ok) return $v; $v = $fn(); apcu_store($key, $v, $ttl); return $v; }
  $f = sys_get_temp_dir() . '/' . $key;
  if (is_file($f) && filemtime($f) > time() - $ttl && ($v = @unserialize((string)@file_get_contents($f))) !== false) return $v;
  $v = $fn(); @file_put_contents($f, serialize($v), LOCK_EX); return $v;
}
function cacheDrop(string $k) { $key = 'mx_' . md5(cfg('db_name') . $k); if (function_exists('apcu_delete')) apcu_delete($key); @unlink(sys_get_temp_dir() . '/' . $key); }
// машинний переклад (Google Translate, безкоштовний веб-клієнт): повертає [текст, мова оригіналу] або null
function mtTranslate(string $t, string $lang): ?array {
  $tl = $lang === 'zh' ? 'zh-CN' : $lang;
  $prot = preg_replace('/:e(\d{1,2}):/', ' [#$1] ', $t); // смайлики гри не перекладаємо
  $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=' . $tl . '&dt=t&q=' . rawurlencode($prot);
  $raw = null;
  if (function_exists('curl_init')) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_USERAGENT => 'Mozilla/5.0']); $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($code !== 200) $raw = null; }
  else $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: Mozilla/5.0\r\n"]]));
  if (!$raw) return null;
  $j = json_decode($raw, true); if (!is_array($j) || !is_array($j[0] ?? null)) return null;
  $out = ''; foreach ($j[0] as $seg) $out .= (string)($seg[0] ?? '');
  $src = (string)($j[2] ?? '');
  if ($src === $tl || $src === $lang) return [$t, $src]; // уже мовою гравця
  $out = preg_replace('/\s*\[\s*#\s*(\d{1,2})\s*\]\s*/u', ' :e$1: ', $out);
  return [trim(preg_replace('/ {2,}/', ' ', $out)), $src];
}
// пакети банкнот: [банкноти, ціна ₴] — так само, як DONATE у data.js
const DONATE_PACKS = [[100, 17], [300, 49], [600, 95], [1000, 155], [2500, 375], [6000, 850]];
const DONATE_ADMIN = 1; // з ким відкривається діалог після заявки
// бонус активних донат-акцій для пакета (найбільший % + усі додаткові нагороди)
function donateBonus(int $i): array {
  $pct = 0; $rw = [];
  foreach (q("SELECT rewards, cfg FROM promos WHERE active = 1 AND type = 'donate' AND cfg IS NOT NULL AND starts_at <= NOW() AND ends_at > NOW()")->fetchAll() as $r) {
    $c = json_decode((string)$r['cfg'], true) ?: []; $pk = (int)($c['pack'] ?? -1); if ($pk !== -1 && $pk !== $i) continue;
    $pct = max($pct, (int)($c['pct'] ?? 0)); $rw = array_merge($rw, json_decode((string)$r['rewards'], true) ?: []);
  }
  return [$pct, $rw];
}
function setting(string $k, $d = null) { static $c = null; if ($c === null) { $c = []; try { foreach (q('SELECT k, v FROM settings')->fetchAll() as $r) $c[$r['k']] = $r['v']; } catch (Throwable $e) {} } return $c[$k] ?? $d; }
function giftRow(array $r): array { return ['id' => (int)$r['id'], 'gift' => $r['gift'], 'text' => $r['body'], 't' => (int)$r['t'], 'from' => ['id' => (int)$r['fid'], 'nick' => $r['fnick'], 'avatar' => $r['favatar']]]; }
function cronKey(): string { return substr(sha1('mx-cron|' . cfg('db_pass') . '|' . cfg('admin_key')), 0, 24); }
function devId(): ?string { $d = (string)arg('device', ''); return preg_match('/^[a-f0-9]{32}$/', $d) ? $d : null; }
function isBlocked(int $a, int $b): bool { return (bool)q('SELECT 1 FROM player_blocks WHERE (player_id = ? AND blocked_id = ?) OR (player_id = ? AND blocked_id = ?)', [$a, $b, $b, $a])->fetchColumn(); }
function peer(): array { $id = (int)arg('id'); $p = $id ? q('SELECT * FROM players WHERE id = ?', [$id])->fetch() : null; if (!$p) fail('not_found', 404); return $p; }
function cleanText($s, int $max): string { $s = trim((string)$s); if ($s === '') fail('empty'); return mb_substr(strip_tags($s), 0, $max); }

try {
  $a = (string)($_GET['a'] ?? arg('a', ''));
  require_once __DIR__ . '/tour.php'; // турнір кубків: функції + дії tour*
  require_once __DIR__ . '/push.php'; // пуш-сповіщення: функції + дії push_*
  if (strncmp($a, 'adm_', 4) === 0) { require __DIR__ . '/admin_api.php'; exit; }
  if (strncmp($a, 'corp_', 5) === 0) { require __DIR__ . '/corp_api.php'; exit; }
  switch ($a) {

    // ---------- службове ----------
    case 'ping': q('SELECT 1'); ensureSchema(); out(['ok' => true, 'time' => time(), 'min' => MIN_CLIENT, 'cfg' => ['banner' => (string)setting('banner', ''), 'maint' => (int)setting('maint', 0), 'maint_msg' => (string)setting('maint_msg', '')]]);

    // ---------- вхід / реєстрація ----------
    case 'register': {
      rate('reg:' . ip(), 10, 3600);
      $nick = cleanNick(arg('nick'));
      $pass = (string)arg('password', '');
      if ($pass !== '' && mb_strlen($pass) < 6) fail('password_short');
      ensureSchema();
      if (q('SELECT 1 FROM players WHERE nick_lc = ?', [mb_strtolower($nick)])->fetchColumn()) fail('nick_taken');
      // з пристрою заблокованого гравця не можна створити новий акаунт, поки діє блокування
      if (($dev = devId()) && ($b = q('SELECT ban_until, ban_reason FROM players WHERE device = ? AND banned = 1 AND (ban_until IS NULL OR ban_until > NOW()) LIMIT 1', [$dev])->fetch()))
        out(['ok' => false, 'error' => 'banned', 'until' => $b['ban_until'] ? strtotime($b['ban_until'] . ' UTC') : null, 'reason' => $b['ban_reason']], 403);
      db()->beginTransaction(); // або акаунт створюється повністю, або нічого (щоб повторна спроба не казала «нік зайнятий»)
      q('INSERT INTO players (nick, nick_lc, pass_hash, avatar, lang) VALUES (?, ?, ?, ?, ?)',
        [$nick, mb_strtolower($nick), $pass !== '' ? password_hash($pass, PASSWORD_DEFAULT) : null, substr((string)arg('avatar', 'av_25'), 0, 16), substr((string)arg('lang', 'uk'), 0, 4)]);
      $pid = (int)db()->lastInsertId();
      if ($dev = devId()) q('UPDATE players SET device = ? WHERE id = ?', [$dev, $pid]);
      q('INSERT INTO player_stats (player_id) VALUES (?)', [$pid]);
      // Новий акаунт ЗАВЖДИ починає з нуля: стан від клієнта при реєстрації не приймаємо
      ensureSchema();
      $tok = newSession($pid);
      q('INSERT INTO saves (player_id, rev, data, active_token) VALUES (?, 0, ?, ?)', [$pid, '{}', hash('sha256', $tok)]);
      q('INSERT INTO history (player_id, kind, info) VALUES (?, "register", ?)', [$pid, $nick]);
      db()->commit();
      $p = q('SELECT * FROM players WHERE id = ?', [$pid])->fetch();
      out(['ok' => true, 'token' => $tok, 'player' => pub($p), 'rev' => 0]);
    }
    case 'login': {
      rate('login:' . ip(), 20, 900);
      $nick = cleanNick(arg('nick'));
      $p = q('SELECT * FROM players WHERE nick_lc = ?', [mb_strtolower($nick)])->fetch();
      if (!$p || empty($p['pass_hash']) || !password_verify((string)arg('password', ''), $p['pass_hash'])) fail('bad_login', 401);
      ensureSchema();
      if ($dev = devId()) q('UPDATE players SET device = ? WHERE id = ?', [$dev, $p['id']]);
      if ($p['banned'] && !(!empty($p['ban_until']) && strtotime($p['ban_until'] . ' UTC') < time())) out(['ok' => false, 'error' => 'banned', 'until' => !empty($p['ban_until']) ? strtotime($p['ban_until'] . ' UTC') : null, 'reason' => $p['ban_reason'] ?? null], 403);
      if ($p['banned']) q('UPDATE players SET banned = 0, ban_until = NULL WHERE id = ?', [$p['id']]);
      ensureSchema();
      $tok = newSession((int)$p['id']);
      q('UPDATE saves SET active_token = ? WHERE player_id = ?', [hash('sha256', $tok), $p['id']]);
      $save = saveRow((int)$p['id']);
      q('INSERT INTO history (player_id, kind, info) VALUES (?, "login", ?)', [$p['id'], ip()]);
      out(['ok' => true, 'token' => $tok, 'player' => pub($p), 'save' => ($save && $save['rev'] > 0) ? $save : null, 'rev' => $save ? $save['rev'] : 0]);
    }
    case 'logout': { $t = bearer(); if ($t) q('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $t)]); out(['ok' => true]); }
    case 'me': {
      $p = me();
      $save = q('SELECT rev, UNIX_TIMESTAMP(updated_at) t FROM saves WHERE player_id = ?', [$p['id']])->fetch();
      out(['ok' => true, 'player' => pub($p), 'rev' => $save ? (int)$save['rev'] : 0, 't' => $save ? (int)$save['t'] : 0]);
    }
    case 'profile': {
      $p = me();
      if (($n = arg('nick')) !== null && $n !== $p['nick']) {
        $n = cleanNick($n);
        if (q('SELECT 1 FROM players WHERE nick_lc = ? AND id <> ?', [mb_strtolower($n), $p['id']])->fetchColumn()) fail('nick_taken');
        q('UPDATE players SET nick = ?, nick_lc = ? WHERE id = ?', [$n, mb_strtolower($n), $p['id']]);
      }
      if (($av = arg('avatar')) !== null) q('UPDATE players SET avatar = ? WHERE id = ?', [substr((string)$av, 0, 16), $p['id']]);
      if (($lg = arg('lang')) !== null) q('UPDATE players SET lang = ? WHERE id = ?', [substr((string)$lg, 0, 4), $p['id']]);
      if (($pw = arg('password')) !== null && $pw !== '') {
        if (mb_strlen((string)$pw) < 6) fail('password_short');
        if (!empty($p['pass_hash']) && !password_verify((string)arg('old_password', ''), $p['pass_hash'])) fail('bad_password', 401);
        q('UPDATE players SET pass_hash = ? WHERE id = ?', [password_hash((string)$pw, PASSWORD_DEFAULT), $p['id']]);
      }
      out(['ok' => true, 'player' => pub(q('SELECT * FROM players WHERE id = ?', [$p['id']])->fetch())]);
    }

    // ---------- активний пристрій (одна гра одночасно) ----------
    case 'claim': {
      $p = me(); ensureSchema();
      $th = hash('sha256', bearer());
      q('INSERT INTO saves (player_id, rev, data, active_token) VALUES (?, 0, "{}", ?) ON DUPLICATE KEY UPDATE active_token = VALUES(active_token)', [$p['id'], $th]);
      $s = saveRow((int)$p['id']);
      out(['ok' => true, 'player' => pub($p), 'save' => ($s && $s['rev'] > 0) ? $s : null, 'rev' => $s ? $s['rev'] : 0]);
    }
    case 'beat': {
      $p = me();
      $r = q('SELECT rev, active_token FROM saves WHERE player_id = ?', [$p['id']])->fetch();
      ensureSchema();
      $pid0 = (int)$p['id'];
      cacheGet('tour_chk', 60, function () { try { tourFinalize(); } catch (Throwable $e) { error_log('[mx] tour ' . $e->getMessage()); } return 1; }); // підсумки турніру — не частіше разу на хвилину
      pushTick(); // черга сповіщень (якщо на хостингу немає cron)
      // спільне для всіх гравців (новини, акції, налаштування) — кешується на 10 с, щоб не смикати базу кожним гравцем
      $G = cacheGet('beat_global', 10, function () {
        return ['news' => (string)q('SELECT CONCAT(COALESCE(MAX(id), 0), ":", COUNT(*)) FROM news WHERE published = 1')->fetchColumn(),
          'promo' => md5((string)q('SELECT GROUP_CONCAT(CONCAT_WS(",", id, active, starts_at, ends_at, COALESCE(cfg, ""), title) ORDER BY id) FROM promos WHERE ends_at > NOW() - INTERVAL 1 DAY')->fetchColumn()),
          'cfg' => ['banner' => (string)setting('banner', ''), 'maint' => (int)setting('maint', 0), 'maint_msg' => (string)setting('maint_msg', '')]];
      });
      $news = $G['news']; $promo = $G['promo']; $cfg = $G['cfg'];
      // усе особисте — одним запитом
      $x = q('SELECT (SELECT COUNT(*) FROM dm_messages WHERE to_id = ? AND read_at IS NULL) dm,
        GREATEST((SELECT COALESCE(MAX(id), 0) FROM mail WHERE player_id = ?), (SELECT COALESCE(MAX(id), 0) FROM mail WHERE player_id IS NULL)) mx,
        (SELECT COUNT(*) FROM corp_invites WHERE player_id = ?) inv', [$pid0, $pid0, $pid0])->fetch();
      $dm = (int)$x['dm']; $mail = (int)$x['mx'];
      $cm = q('SELECT c.id, c.name, c.emblem, c.bld, c.boosts, c.build, m.role FROM corp_members m JOIN corps c ON c.id = m.corp_id WHERE m.player_id = ?', [$pid0])->fetch();
      $corp = $cm ? ['id' => (int)$cm['id'], 'name' => $cm['name'], 'emblem' => $cm['emblem'], 'role' => (int)$cm['role'], 'bld' => (function () use ($cm) { $b = json_decode((string)$cm['bld'], true) ?: []; foreach ((json_decode((string)$cm['build'], true) ?: []) as $k => $x) if ($x[1] <= time()) $b[$k] = max($b[$k] ?? 0, (int)$x[0]); return $b ?: new stdClass(); })(), 'boost' => json_decode((string)$cm['boosts'], true) ?: new stdClass()] : null;
      $hasInv = (int)$x['inv'] > 0;
      out(['ok' => true, 'corp' => $corp, 'cinv' => $hasInv, 'cfg' => $cfg, 'dm' => $dm, 'access' => (int)($p['access'] ?? 0), 'nst' => $news, 'pst' => $promo, 'mst' => $mail, 'active' => !$r || !$r['active_token'] || hash_equals($r['active_token'], hash('sha256', bearer())), 'rev' => $r ? (int)$r['rev'] : 0]);
    }

    // ---------- збереження прогресу ----------
    case 'load': {
      $p = me();
      $save = q('SELECT rev, data, UNIX_TIMESTAMP(updated_at) t FROM saves WHERE player_id = ?', [$p['id']])->fetch();
      out(['ok' => true, 'save' => ($save && (int)$save['rev'] > 0) ? ['rev' => (int)$save['rev'], 'data' => json_decode($save['data']), 't' => (int)$save['t']] : null]);
    }
    case 'save': {
      $p = me();
      rate('save:' . $p['id'], 60, 60);
      $state = arg('state'); $base = (int)arg('rev', 0); $force = (bool)arg('force', false);
      if (!is_array($state)) fail('bad_state');
      // старі (закешовані) версії гри не можуть перезаписати прогрес — їм треба оновитись
      if (version_compare((string)(arg('_v') ?? arg('v', '0')), MIN_CLIENT, '<')) fail('old_client', 426);
      // стан мусить належати саме цьому гравцю — захист від перенесення чужих/гостьових даних
      if ((int)($state['pid'] ?? 0) !== (int)$p['id'] || empty($state['floors'])) fail('wrong_player', 409);
      $obj = json_decode(rawBody()); $json = json_encode(is_object($obj) && isset($obj->state) ? $obj->state : $state, JSON_UNESCAPED_UNICODE);
      if (strlen($json) > 4 * 1024 * 1024) fail('state_too_big');
      db()->beginTransaction();
      $cur = q('SELECT rev, active_token FROM saves WHERE player_id = ? FOR UPDATE', [$p['id']])->fetch();
      if ($cur && $cur['active_token'] && !hash_equals($cur['active_token'], hash('sha256', bearer()))) { db()->rollBack(); fail('other_device', 409); }
      if ($cur && (int)$cur['rev'] > 0 && !$force && (int)$cur['rev'] !== $base) {
        db()->rollBack();
        $s = q('SELECT rev, data, UNIX_TIMESTAMP(updated_at) t FROM saves WHERE player_id = ?', [$p['id']])->fetch();
        out(['ok' => false, 'error' => 'conflict', 'save' => ['rev' => (int)$s['rev'], 'data' => json_decode($s['data']), 't' => (int)$s['t']]], 409);
      }
      $rev = $cur ? (int)$cur['rev'] + 1 : 1;
      q('INSERT INTO saves (player_id, rev, data, active_token) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rev = VALUES(rev), data = VALUES(data)', [$p['id'], $rev, $json, hash('sha256', bearer())]);
      $st = arg('stats', []);
      ensureSchema();
      // Ключові показники беремо прямо зі стану гри (не довіряємо окремим полям клієнта)
      $chests = 0; foreach (($state['chestInv'] ?? []) as $n) $chests += (int)$n;
      $ach = 0; foreach (($state['ach'] ?? []) as $n) $ach += (int)$n;
      $hotel = 1; foreach (($state['floors'] ?? []) as $f) if (($f['type'] ?? '') === 'hotel') $hotel = (int)($f['lvl'] ?? 1);
      $biz = 0; foreach (($state['floors'] ?? []) as $f) if (($f['type'] ?? '') === 'biz') $biz++;
      q('INSERT INTO player_stats (player_id, level, xp, coins, bucks, floors, businesses, residents, cups, earned, rev_hour, maze_keys, lift_lvl, lobby_lvl, hotel_lvl, chests, achievements)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE level=VALUES(level), xp=VALUES(xp), coins=VALUES(coins), bucks=VALUES(bucks), floors=VALUES(floors), businesses=VALUES(businesses),
          residents=VALUES(residents), cups=VALUES(cups), earned=VALUES(earned), rev_hour=VALUES(rev_hour), maze_keys=VALUES(maze_keys), lift_lvl=VALUES(lift_lvl),
          lobby_lvl=VALUES(lobby_lvl), hotel_lvl=VALUES(hotel_lvl), chests=VALUES(chests), achievements=VALUES(achievements)',
        [$p['id'], (int)($state['level'] ?? 1), (int)($state['xp'] ?? 0), (float)($state['coins'] ?? 0), (int)($state['bucks'] ?? 0), max(0, count($state['floors'] ?? []) - 1), $biz,
         count($state['residents'] ?? []), count($state['cups'] ?? []), (float)($state['stats']['earned'] ?? 0), (float)(is_array($st) ? ($st['revHour'] ?? 0) : 0),
         (int)($state['maze']['keys'] ?? 0), (int)($state['liftLvl'] ?? 1), (int)($state['lobbyLvl'] ?? 1), $hotel, $chests, $ach]);
      mirrorState((int)$p['id'], $state);
      if (!q('SELECT 1 FROM balance_log WHERE player_id = ? AND t > NOW() - INTERVAL 1 HOUR LIMIT 1', [$p['id']])->fetchColumn())
        q('INSERT INTO balance_log (player_id, coins, bucks, level) VALUES (?, ?, ?, ?)', [$p['id'], (float)($state['coins'] ?? 0), (int)($state['bucks'] ?? 0), (int)($state['level'] ?? 1)]);
      $ev = arg('events', []);
      if (is_array($ev)) foreach (array_slice($ev, 0, 50) as $e) if (is_array($e) && !empty($e['k']))
        q('INSERT INTO history (player_id, kind, info) VALUES (?, ?, ?)', [$p['id'], substr((string)$e['k'], 0, 32), substr((string)($e['i'] ?? ''), 0, 255)]);
      db()->commit();
      out(['ok' => true, 'rev' => $rev]);
    }
    case 'history': {
      $p = me();
      out(['ok' => true, 'items' => q('SELECT kind, info, UNIX_TIMESTAMP(created_at) t FROM history WHERE player_id = ? ORDER BY id DESC LIMIT 100', [$p['id']])->fetchAll()]);
    }

    // ---------- новини та пошта ----------
    case 'news': {
      out(['ok' => true, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'icon' => $r['icon'], 'title' => $r['title'], 'text' => $r['body'], 'date' => date('d.m', (int)$r['t'])],
        q('SELECT id, icon, title, body, UNIX_TIMESTAMP(created_at) t FROM news WHERE published = 1 ORDER BY id DESC LIMIT 30')->fetchAll())]);
    }
    case 'mail': {
      $p = me();
      $rows = q('SELECT m.id, m.sender, m.icon, m.title, m.body, m.reward, UNIX_TIMESTAMP(m.created_at) t FROM mail m
                 LEFT JOIN mail_claims c ON c.mail_id = m.id AND c.player_id = ?
                 WHERE (m.player_id = ? OR (m.player_id IS NULL AND m.created_at >= ?)) AND c.mail_id IS NULL ORDER BY m.id DESC LIMIT 30',
        [$p['id'], $p['id'], $p['created_at']])->fetchAll();
      out(['ok' => true, 'items' => array_map(fn($r) => ['sid' => (int)$r['id'], 'from' => $r['sender'], 'icon' => $r['icon'], 'title' => $r['title'], 'text' => $r['body'], 'reward' => $r['reward'] ? json_decode($r['reward'], true) : null, 't' => (int)$r['t']], $rows)]);
    }
    case 'mail_take': {
      $p = me();
      $id = (int)arg('id');
      $m = q('SELECT * FROM mail WHERE id = ? AND (player_id = ? OR player_id IS NULL)', [$id, $p['id']])->fetch();
      if (!$m) fail('not_found', 404);
      $ins = q('INSERT IGNORE INTO mail_claims (mail_id, player_id) VALUES (?, ?)', [$id, $p['id']]);
      out(['ok' => true, 'fresh' => $ins->rowCount() > 0, 'reward' => $m['reward'] ? json_decode($m['reward'], true) : null]);
    }

    // ---------- чат ----------
    case 'chat': {
      $me = me(); ensureSchema();
      $after = (int)arg('after', 0);
      $rows = q('SELECT c.id, c.body, UNIX_TIMESTAMP(c.created_at) t, p.id pid, p.nick, p.avatar, p.access is_admin, c.reply_to rid, rp.nick rnick, LEFT(r.body, 140) rbody FROM chat_messages c JOIN players p ON p.id = c.player_id
                 LEFT JOIN chat_messages r ON r.id = c.reply_to LEFT JOIN players rp ON rp.id = r.player_id
                 WHERE c.id > ? AND c.player_id NOT IN (SELECT blocked_id FROM player_blocks WHERE player_id = ?) ORDER BY c.id DESC LIMIT 50', [$after, $me['id']])->fetchAll();
      out(['ok' => true, 'items' => array_reverse($rows)]);
    }
    case 'chat_send': {
      $p = me(); restricted($p, 'chat');
      rate('chat:' . $p['id'], 6, 30);
      $rid = (int)arg('reply', 0); if ($rid && !q('SELECT 1 FROM chat_messages WHERE id = ?', [$rid])->fetchColumn()) $rid = 0; // відповідь на повідомлення
      $ctext = cleanText(arg('text'), 300);
      q('INSERT INTO chat_messages (player_id, body, reply_to) VALUES (?, ?, ?)', [$p['id'], $ctext, $rid ?: null]);
      $cid = (int)db()->lastInsertId();
      $to = $rid ? (int)q('SELECT player_id FROM chat_messages WHERE id = ?', [$rid])->fetchColumn() : 0;
      if ($to && $to !== (int)$p['id'] && !isBlocked($to, (int)$p['id'])) pushTo($to, 'reply', ['nick' => $p['nick'], 'text' => $ctext], 'chat', 'reply'); // тобі відповіли в чаті
      pushChatAll((int)$p['id'], $p['nick'], $ctext, $to);
      out(['ok' => true, 'id' => $cid]);
    }

    // ---------- форум ----------
    case 'forum_topics': {
      me();
      out(['ok' => true, 'items' => q('SELECT t.id, t.title, t.posts, t.pinned, UNIX_TIMESTAMP(t.last_post_at) t, p.nick FROM forum_topics t JOIN players p ON p.id = t.player_id
                                       ORDER BY t.pinned DESC, t.last_post_at DESC LIMIT 50')->fetchAll()]);
    }
    case 'forum_topic': {
      $me = me(); ensureSchema();
      $id = (int)arg('id');
      $t = q('SELECT t.id, t.title, p.nick FROM forum_topics t JOIN players p ON p.id = t.player_id WHERE t.id = ?', [$id])->fetch();
      if (!$t) fail('not_found', 404);
      out(['ok' => true, 'topic' => $t, 'posts' => q('SELECT f.id, f.body, UNIX_TIMESTAMP(f.created_at) t, p.id pid, p.nick, p.avatar, p.access is_admin FROM forum_posts f JOIN players p ON p.id = f.player_id
                                                     WHERE f.topic_id = ? AND f.player_id NOT IN (SELECT blocked_id FROM player_blocks WHERE player_id = ?) ORDER BY f.id ASC LIMIT 200', [$id, $me['id']])->fetchAll()]);
    }
    case 'forum_new': {
      $p = me(); restricted($p, 'chat');
      rate('forum:' . $p['id'], 5, 300);
      $title = cleanText(arg('title'), 120); $text = cleanText(arg('text'), 2000);
      db()->beginTransaction();
      q('INSERT INTO forum_topics (player_id, title, posts) VALUES (?, ?, 1)', [$p['id'], $title]);
      $tid = (int)db()->lastInsertId();
      q('INSERT INTO forum_posts (topic_id, player_id, body) VALUES (?, ?, ?)', [$tid, $p['id'], $text]);
      db()->commit();
      out(['ok' => true, 'id' => $tid]);
    }
    case 'forum_reply': {
      $p = me(); restricted($p, 'chat');
      rate('forum:' . $p['id'], 10, 300);
      $tid = (int)arg('id'); $text = cleanText(arg('text'), 2000);
      if (!q('SELECT 1 FROM forum_topics WHERE id = ?', [$tid])->fetchColumn()) fail('not_found', 404);
      q('INSERT INTO forum_posts (topic_id, player_id, body) VALUES (?, ?, ?)', [$tid, $p['id'], $text]);
      q('UPDATE forum_topics SET posts = posts + 1, last_post_at = NOW() WHERE id = ?', [$tid]);
      out(['ok' => true]);
    }

    // ---------- рейтинг ----------
    case 'top': {
      $by = ['level' => 's.level DESC, s.xp DESC', 'floors' => 's.floors DESC', 'earned' => 's.earned DESC', 'cups' => 's.cups DESC'][(string)arg('by', 'level')] ?? 's.level DESC';
      $per = 10; $page = max(0, (int)arg('page', 0));
      $total = (int)q('SELECT COUNT(*) FROM player_stats s JOIN players p ON p.id = s.player_id WHERE p.banned = 0')->fetchColumn();
      out(['ok' => true, 'total' => $total, 'per' => $per, 'page' => $page, 'items' => q("SELECT p.id pid, p.nick, p.avatar, s.level, s.floors, s.earned, s.cups, c.name corp, c.emblem cemblem FROM player_stats s JOIN players p ON p.id = s.player_id
        LEFT JOIN corp_members m ON m.player_id = p.id LEFT JOIN corps c ON c.id = m.corp_id WHERE p.banned = 0 ORDER BY $by LIMIT $per OFFSET " . ($page * $per))->fetchAll()]);
    }

    // ---------- акції від адміністрації ----------
    case 'promos': {
      $p = me();
      $rows = q('SELECT pr.id, pr.title, pr.body, pr.icon, pr.color, pr.rewards, pr.type, pr.cfg, UNIX_TIMESTAMP(pr.starts_at) s, UNIX_TIMESTAMP(pr.ends_at) e, c.player_id IS NOT NULL claimed
                 FROM promos pr LEFT JOIN promo_claims c ON c.promo_id = pr.id AND c.player_id = ? WHERE pr.active = 1 AND pr.cfg IS NOT NULL AND pr.starts_at <= NOW() AND pr.ends_at > NOW() ORDER BY pr.id DESC LIMIT 20', [$p['id']])->fetchAll();
      out(['ok' => true, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'text' => $r['body'], 'icon' => $r['icon'], 'color' => $r['color'],
        'rewards' => json_decode((string)$r['rewards'], true) ?: [], 'start' => (int)$r['s'], 'end' => (int)$r['e'], 'claimed' => (bool)$r['claimed'],
        'type' => $r['type'] ?: 'discount'] + (json_decode((string)$r['cfg'], true) ?: []), $rows)]);
    }
    case 'reward_claim': {
      $p = me(); $key = (string)arg('key', '');
      if (!preg_match('/^[a-z]{2,6}:[^\x00-\x1f]{1,70}$/u', $key)) fail('bad_key');
      rate('claim:' . $p['id'], 60, 60);
      $ins = q('INSERT IGNORE INTO reward_claims (player_id, ckey) VALUES (?, ?)', [$p['id'], $key]);
      out(['ok' => true, 'fresh' => $ins->rowCount() > 0]);
    }
    case 'promo_take': {
      $p = me(); $id = (int)arg('id');
      $pr = q('SELECT * FROM promos WHERE id = ? AND active = 1 AND starts_at <= NOW() AND ends_at > NOW()', [$id])->fetch();
      if (!$pr) fail('promo_over', 404);
      $ins = q('INSERT IGNORE INTO promo_claims (promo_id, player_id) VALUES (?, ?)', [$id, $p['id']]);
      if ($ins->rowCount() > 0) q('INSERT INTO history (player_id, kind, info) VALUES (?, "promo", ?)', [$p['id'], $pr['title']]);
      out(['ok' => true, 'fresh' => $ins->rowCount() > 0, 'rewards' => json_decode((string)$pr['rewards'], true) ?: []]);
    }

    // ---------- профілі гравців, друзі, чорний список ----------
    case 'player': {
      $me = me(); ensureSchema();
      $nick = trim((string)arg('nick', ''));
      $p = $nick !== '' ? q('SELECT * FROM players WHERE nick_lc = ?', [mb_strtolower($nick)])->fetch() : peer();
      if (!$p) fail('not_found', 404);
      $id = (int)$p['id']; $st = q('SELECT * FROM player_stats WHERE player_id = ?', [$id])->fetch() ?: [];
      $place = $st ? 1 + (int)q('SELECT COUNT(*) FROM player_stats s JOIN players x ON x.id = s.player_id WHERE x.banned = 0 AND (s.level > ? OR (s.level = ? AND s.xp > ?))', [$st['level'], $st['level'], $st['xp']])->fetchColumn() : 0;
      out(['ok' => true, 'player' => [
        'id' => $id, 'nick' => $p['nick'], 'avatar' => $p['avatar'], 'admin' => (int)($p['access'] ?? 0) === 1, 'banned' => (bool)$p['banned'],
        'created' => strtotime($p['created_at'] . ' UTC'), 'seen' => strtotime($p['last_seen'] . ' UTC'), 'online' => strtotime($p['last_seen'] . ' UTC') > time() - 600,
        'level' => (int)($st['level'] ?? 1), 'floors' => (int)($st['floors'] ?? 0), 'businesses' => (int)($st['businesses'] ?? 0), 'residents' => (int)($st['residents'] ?? 0),
        'cups' => (int)($st['cups'] ?? 0), 'earned' => (float)($st['earned'] ?? 0), 'hotel' => (int)($st['hotel_lvl'] ?? 1), 'achievements' => (int)($st['achievements'] ?? 0), 'place' => $place,
        'friends' => (int)q('SELECT COUNT(*) FROM friends WHERE player_id = ?', [$id])->fetchColumn(),
        'me' => $id === (int)$me['id'],
        'friend' => (bool)q('SELECT 1 FROM friends WHERE player_id = ? AND friend_id = ?', [$me['id'], $id])->fetchColumn(),
        'blocked' => (bool)q('SELECT 1 FROM player_blocks WHERE player_id = ? AND blocked_id = ?', [$me['id'], $id])->fetchColumn(),
        'blockedMe' => (bool)q('SELECT 1 FROM player_blocks WHERE player_id = ? AND blocked_id = ?', [$id, $me['id']])->fetchColumn(),
        'giftCount' => (int)q('SELECT COUNT(*) FROM gifts WHERE to_id = ?', [$id])->fetchColumn(),
        'corp' => (function () use ($id) { $c = q('SELECT c.id, c.name, c.emblem, m.role FROM corp_members m JOIN corps c ON c.id = m.corp_id WHERE m.player_id = ?', [$id])->fetch(); return $c ? ['id' => (int)$c['id'], 'name' => $c['name'], 'emblem' => $c['emblem'], 'role' => (int)$c['role']] : null; })(),
        'invited' => (bool)q('SELECT 1 FROM corp_invites WHERE player_id = ?', [$id])->fetchColumn(),
        'gifts' => array_map('giftRow', q('SELECT g.id, g.gift, g.body, UNIX_TIMESTAMP(g.created_at) t, f.id fid, f.nick fnick, f.avatar favatar FROM gifts g JOIN players f ON f.id = g.from_id WHERE g.to_id = ? ORDER BY g.id DESC LIMIT 6', [$id])->fetchAll()),
      ], 'data' => (function () use ($id) { $d = json_decode((string)q('SELECT data FROM saves WHERE player_id = ? AND rev > 0', [$id])->fetchColumn());
          if (!is_object($d)) return null; foreach (['mail', 'dt', 'vip', 'coll', 'lotto', 'heli', 'pass', 'queue', 'chestInv', 'tipsDay', 'revLog'] as $k) unset($d->$k); return $d; })()]);
    }
    case 'friend_add': {
      $me = me(); ensureSchema(); $p = peer(); rate('soc:' . $me['id'], 30, 60);
      if ((int)$p['id'] === (int)$me['id']) fail('self');
      if (isBlocked((int)$me['id'], (int)$p['id'])) fail('blocked', 403);
      if ((int)q('SELECT COUNT(*) FROM friends WHERE player_id = ?', [$me['id']])->fetchColumn() >= 200) fail('friends_limit');
      q('INSERT IGNORE INTO friends (player_id, friend_id) VALUES (?, ?)', [$me['id'], $p['id']]);
      q('INSERT INTO history (player_id, kind, info) VALUES (?, "friend", ?)', [$me['id'], $p['nick']]);
      out(['ok' => true]);
    }
    case 'friend_del': { $me = me(); ensureSchema(); $p = peer(); q('DELETE FROM friends WHERE player_id = ? AND friend_id = ?', [$me['id'], $p['id']]); out(['ok' => true]); }
    case 'block': {
      $me = me(); ensureSchema(); $p = peer(); rate('soc:' . $me['id'], 30, 60);
      if ((int)$p['id'] === (int)$me['id']) fail('self');
      q('INSERT IGNORE INTO player_blocks (player_id, blocked_id) VALUES (?, ?)', [$me['id'], $p['id']]);
      q('DELETE FROM friends WHERE (player_id = ? AND friend_id = ?) OR (player_id = ? AND friend_id = ?)', [$me['id'], $p['id'], $p['id'], $me['id']]);
      out(['ok' => true]);
    }
    case 'unblock': { $me = me(); ensureSchema(); $p = peer(); q('DELETE FROM player_blocks WHERE player_id = ? AND blocked_id = ?', [$me['id'], $p['id']]); out(['ok' => true]); }
    case 'friends': {
      $me = me(); ensureSchema();
      $fr = q('SELECT p.id, p.nick, p.avatar, UNIX_TIMESTAMP(p.last_seen) seen, COALESCE(s.level, 1) level FROM friends f JOIN players p ON p.id = f.friend_id LEFT JOIN player_stats s ON s.player_id = p.id
               WHERE f.player_id = ? ORDER BY p.last_seen DESC LIMIT 200', [$me['id']])->fetchAll();
      $bl = q('SELECT p.id, p.nick, p.avatar FROM player_blocks b JOIN players p ON p.id = b.blocked_id WHERE b.player_id = ? ORDER BY b.created_at DESC LIMIT 200', [$me['id']])->fetchAll();
      foreach ($fr as &$f) $f['online'] = (int)$f['seen'] > time() - 600;
      out(['ok' => true, 'friends' => $fr, 'blocked' => $bl]);
    }

    // ---------- хто зараз онлайн (статус тримається 10 хв після останньої активності) ----------
    case 'online': {
      me(); ensureSchema();
      $per = 10; $page = max(0, (int)arg('page', 0)); $nc = (string)arg('f', '') === 'nocorp';
      $w = 'p.banned = 0 AND p.last_seen > NOW() - INTERVAL 10 MINUTE' . ($nc ? ' AND m.player_id IS NULL' : '');
      $total = (int)q("SELECT COUNT(*) FROM players p LEFT JOIN corp_members m ON m.player_id = p.id WHERE $w")->fetchColumn();
      $rows = q("SELECT p.id, p.nick, p.avatar, p.access, UNIX_TIMESTAMP(p.last_seen) seen, COALESCE(s.level, 1) level, COALESCE(s.floors, 0) floors, c.name corp, c.emblem cemblem
                 FROM players p LEFT JOIN player_stats s ON s.player_id = p.id LEFT JOIN corp_members m ON m.player_id = p.id LEFT JOIN corps c ON c.id = m.corp_id
                 WHERE $w ORDER BY s.level DESC, p.last_seen DESC LIMIT $per OFFSET " . ($page * $per))->fetchAll();
      out(['ok' => true, 'total' => $total, 'per' => $per, 'items' => $rows]);
    }
    // ---------- подарунки між гравцями ----------
    case 'gift_send': {
      $me = me(); ensureSchema(); $p = peer(); rate('gift:' . $me['id'], 20, 60);
      if ((int)$p['id'] === (int)$me['id']) fail('self');
      if (isBlocked((int)$me['id'], (int)$p['id'])) fail('blocked', 403);
      $cat = require __DIR__ . '/gifts.php'; $g = (string)arg('gift'); if (!isset($cat[$g])) fail('bad_gift');
      $text = mb_substr(strip_tags(trim((string)arg('text', ''))), 0, 300);
      q('INSERT INTO gifts (from_id, to_id, gift, price, body) VALUES (?, ?, ?, ?, ?)', [$me['id'], $p['id'], $g, $cat[$g], $text !== '' ? $text : null]);
      q('INSERT INTO mail (player_id, sender, icon, title, body) VALUES (?, ?, ?, ?, ?)', [$p['id'], $me['nick'], 'gift_red', 'Тобі подарунок!', ($text !== '' ? $text . "\n" : '') . 'Подивись у своєму профілі → Подарунки']);
      q('INSERT INTO history (player_id, kind, info) VALUES (?, "gift", ?)', [$me['id'], $g . ' → ' . $p['nick']]);
      pushTo((int)$p['id'], 'gift', ['nick' => $me['nick']], 'gifts', 'gift');
      out(['ok' => true]);
    }
    case 'gifts': {
      me(); ensureSchema(); $id = (int)arg('id'); $page = max(0, (int)arg('page', 0)); $per = 30;
      out(['ok' => true, 'total' => (int)q('SELECT COUNT(*) FROM gifts WHERE to_id = ?', [$id])->fetchColumn(), 'per' => $per,
        'items' => array_map('giftRow', q("SELECT g.id, g.gift, g.body, UNIX_TIMESTAMP(g.created_at) t, f.id fid, f.nick fnick, f.avatar favatar FROM gifts g JOIN players f ON f.id = g.from_id WHERE g.to_id = ? ORDER BY g.id DESC LIMIT $per OFFSET " . ($page * $per), [$id])->fetchAll())]);
    }

    // ---------- особисті повідомлення ----------
    case 'dm_threads': {
      $me = me(); ensureSchema(); $m = (int)$me['id'];
      $rows = q('SELECT x.peer, d.body, d.from_id, UNIX_TIMESTAMP(d.created_at) t, p.nick, p.avatar, UNIX_TIMESTAMP(p.last_seen) seen,
                   (SELECT COUNT(*) FROM dm_messages u WHERE u.to_id = ? AND u.from_id = x.peer AND u.read_at IS NULL) unread
                 FROM (SELECT IF(from_id = ?, to_id, from_id) peer, MAX(id) mid FROM dm_messages WHERE from_id = ? OR to_id = ? GROUP BY peer) x
                 JOIN dm_messages d ON d.id = x.mid JOIN players p ON p.id = x.peer ORDER BY x.mid DESC LIMIT 100', [$m, $m, $m, $m])->fetchAll();
      foreach ($rows as &$r) { $r['online'] = (int)$r['seen'] > time() - 600; $r['mine'] = (int)$r['from_id'] === $m; unset($r['from_id']); }
      out(['ok' => true, 'items' => $rows]);
    }
    case 'dm_get': {
      $me = me(); ensureSchema(); $p = peer(); $m = (int)$me['id']; $o = (int)$p['id']; $after = (int)arg('after', 0);
      $rows = q('SELECT d.id, d.from_id, d.body, UNIX_TIMESTAMP(d.created_at) t, d.read_at IS NOT NULL seen, d.reply_to rid, r.from_id rfrom, LEFT(r.body, 140) rbody FROM dm_messages d
                 LEFT JOIN dm_messages r ON r.id = d.reply_to
                 WHERE ((d.from_id = ? AND d.to_id = ?) OR (d.from_id = ? AND d.to_id = ?)) AND d.id > ? ORDER BY d.id DESC LIMIT 100', [$m, $o, $o, $m, $after])->fetchAll();
      q('UPDATE dm_messages SET read_at = NOW() WHERE to_id = ? AND from_id = ? AND read_at IS NULL', [$m, $o]);
      out(['ok' => true, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'mine' => (int)$r['from_id'] === $m, 'body' => $r['body'], 't' => (int)$r['t'], 'seen' => (bool)$r['seen']]
             + ($r['rid'] ? ['rid' => (int)$r['rid'], 'rmine' => (int)$r['rfrom'] === $m, 'rbody' => $r['rbody']] : []), array_reverse($rows)),
           'peer' => ['id' => $o, 'nick' => $p['nick'], 'avatar' => $p['avatar'], 'online' => strtotime($p['last_seen'] . ' UTC') > time() - 600],
           'blocked' => isBlocked($m, $o)]);
    }
    // ---------- донат: заявка на пакет банкнот ----------
    case 'donate_req': {
      $p = me(); ensureSchema(); rate('don:' . $p['id'], 10, 3600);
      $i = (int)arg('pack', -1); if (!isset(DONATE_PACKS[$i])) fail('bad_pack');
      if ((int)q("SELECT COUNT(*) FROM donations WHERE player_id = ? AND status = 'pending'", [$p['id']])->fetchColumn() >= 3) fail('don_too_many');
      [$b, $u] = DONATE_PACKS[$i]; [$pct, $rw] = donateBonus($i);
      q('INSERT INTO donations (player_id, pack, bucks, uah, bonus_pct, bonus_rw) VALUES (?, ?, ?, ?, ?, ?)', [$p['id'], $i, $b, $u, $pct, $rw ? json_encode($rw) : null]);
      $id = (int)db()->lastInsertId();
      if ((int)$p['id'] !== DONATE_ADMIN && q('SELECT 1 FROM players WHERE id = ?', [DONATE_ADMIN])->fetchColumn())
        q('INSERT INTO dm_messages (from_id, to_id, body) VALUES (?, ?, ?)', [$p['id'], DONATE_ADMIN,
          'Купую ' . number_format($b, 0, '.', ' ') . " банкнот за $u грн" . (($loc = mb_substr(strip_tags((string)arg('local', '')), 0, 16)) !== '' ? " (≈ $loc)" : '') . ". Допоможіть здійснити купівлю 🙏 (заявка №$id" . ($pct || $rw ? ', діє акція' . ($pct ? " +$pct%" : '') . ($rw ? ' + подарунки' : '') : '') . ')']);
      out(['ok' => true, 'id' => $id, 'admin' => DONATE_ADMIN]);
    }
    // ---------- автоматичний переклад повідомлень, новин і акцій на мову гравця ----------
    case 'tr': {
      $p = me(); ensureSchema(); rate('tr:' . $p['id'], 90, 60);
      $lang = (string)arg('lang', 'uk'); if (!in_array($lang, ['uk', 'en', 'ru', 'de', 'fr', 'it', 'ja', 'ko', 'zh'], true)) fail('bad_lang');
      $res = [];
      foreach (array_slice((array)arg('items', []), 0, 40) as $it) {
        $t = trim(mb_substr((string)$it, 0, 1200)); if ($t === '') { $res[] = ''; continue; }
        $h = sha1($lang . "\n" . $t);
        $c = q('SELECT out_text FROM tr_cache WHERE h = ?', [$h])->fetchColumn();
        if ($c !== false) { $res[] = $c; continue; }
        $o = mtTranslate($t, $lang);
        if ($o === null) { $res[] = $t; continue; } // сервіс недоступний — показуємо оригінал, у кеш не пишемо
        q('INSERT IGNORE INTO tr_cache (h, lang, src, out_text) VALUES (?, ?, ?, ?)', [$h, $lang, $o[1], $o[0]]);
        $res[] = $o[0];
      }
      out(['ok' => true, 'items' => $res]);
    }
    case 'dm_send': {
      $me = me(); restricted($me, 'dm'); ensureSchema(); $p = peer(); rate('dm:' . $me['id'], 20, 60);
      if ((int)$p['id'] === (int)$me['id']) fail('self');
      if (isBlocked((int)$me['id'], (int)$p['id'])) fail('blocked', 403);
      $text = cleanText(arg('text'), 1000);
      $rid = (int)arg('reply', 0); if ($rid && !q('SELECT 1 FROM dm_messages WHERE id = ? AND ((from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?))', [$rid, $me['id'], $p['id'], $p['id'], $me['id']])->fetchColumn()) $rid = 0;
      q('INSERT INTO dm_messages (from_id, to_id, body, reply_to) VALUES (?, ?, ?, ?)', [$me['id'], $p['id'], $text, $rid ?: null]);
      $mid = (int)db()->lastInsertId();
      pushTo((int)$p['id'], 'dm', ['nick' => $me['nick'], 'text' => $text], 'dm:' . $me['id'], 'dm' . $me['id'], ['mid' => $mid]);
      out(['ok' => true, 'id' => $mid]);
    }
    case 'dm_del': {
      $me = me(); ensureSchema(); $p = peer();
      q('DELETE FROM dm_messages WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)', [$me['id'], $p['id'], $p['id'], $me['id']]);
      out(['ok' => true]);
    }

    // ---------- завдання за розкладом (cron.php або посилання з ключем) ----------
    case 'cron': {
      if (PHP_SAPI !== 'cli' && !hash_equals(cronKey(), (string)arg('key', ''))) fail('forbidden', 403);
      ensureSchema(); @set_time_limit(120);
      @touch(sys_get_temp_dir() . '/mx_cron_' . md5(cfg('db_name')));
      try { tourFinalize(); } catch (Throwable $e) { error_log('[mx] cron tour ' . $e->getMessage()); }
      $t0 = microtime(true); do { pushRun(300); } while (microtime(true) - $t0 < 40 && (int)q('SELECT COUNT(*) FROM push_queue WHERE send_after <= UTC_TIMESTAMP()')->fetchColumn() > 0 && pushOn());
      out(['ok' => true, 'push' => pushOn(), 'queue' => (int)q('SELECT COUNT(*) FROM push_queue')->fetchColumn()]);
    }
    default: fail('unknown_action', 404);
  }
} catch (Throwable $e) {
  try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $e2) {}
  error_log('[game api] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
  // справжня причина — в журнал помилок PHP на хостингу; гравцю — лише код (без паролів і запитів)
  error_log('[mx] ' . get_class($e) . ' ' . $e->getCode() . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
  if ($e instanceof PDOException && in_array((string)$e->getCode(), ['42S22', '42S02'], true)) { @unlink(schemaFlag()); try { ensureSchema(true); } catch (Throwable $e3) { error_log('[mx] schema heal ' . $e3->getMessage()); } } // бракує колонки/таблиці — лагодимо базу, клієнт повторить запит
  if ($e instanceof PDOException) { $c = (string)$e->getCode(); out(['ok' => false, 'error' => in_array($c, ['1045', '2002', '2005', '2006', '1044', '1049', 'HY000'], true) && stripos($e->getMessage(), 'connect') !== false || in_array($c, ['1045', '2002', '1044', '1049'], true) ? 'db_connect' : 'db_error', 'code' => $c], 500); }
  fail('server_error', 500);
}
