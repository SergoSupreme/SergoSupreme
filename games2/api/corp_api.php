<?php
// ============================================================
//  Корпорації: створення, запрошення, звання, бюджет, споруди, спільні завдання й колекції.
//  Підключається з index.php для дій corp_* (усередині його try/catch).
// ============================================================
if (!defined('MIN_CLIENT')) { http_response_code(403); exit; }

const CORP_BLD = ['rev', 'xp', 'liftxp', 'liftrev', 'lobby', 'tips'];
const CORP_MAX = 30;
const CORP_ROLES = ['Новачок', 'Менеджер', 'Адміністратор', 'Зам. директора', 'Директор'];
const CORP_COLL = ['tech', 'energy', 'finance', 'auto', 'retail', 'industry', 'fashion', 'medicine', 'food'];
const CORP_XP = [150000000,185000000,229000000,282000000,349000000,431000000,532000000,657000000,811000000,1000000000,1240000000,1530000000,1880000000,2330000000,2870000000,3550000000,4380000000,5410000000,6680000000,8250000000,10200000000,12600000000,15500000000,19200000000,23700000000,29200000000,36100000000,44600000000,55100000000,68000000000,83900000000,104000000000,128000000000,158000000000,195000000000,241000000000,298000000000,367000000000,454000000000,560000000000,692000000000,854000000000,1050000000000,1300000000000,1610000000000,1990000000000,2450000000000,3030000000000,3740000000000,4620000000000,5700000000000,7040000000000,8690000000000,10700000000000,13300000000000,16400000000000,20200000000000,25000000000000,30800000000000,38000000000000,47000000000000,58000000000000,71600000000000,88400000000000,109000000000000,135000000000000,167000000000000,206000000000000,254000000000000,314000000000000,387000000000000,478000000000000,590000000000000,729000000000000,900000000000000]; // досвід для переходу з рівня N на N+1 (індекс N-1):
// 75 рівнів, +23,5% за рівень — від 150 млн на 1-му до 900 трлн на 75-му. Досвід корпорації = 20% досвіду учасників.
const CORP_LMAX = 75;
const COLL_RB = [100, 150, 250, 400, 700, 1000, 1500, 2500, 4000];
const COLL_RC = [10e6, 15e6, 25e6, 40e6, 70e6, 100e6, 150e6, 250e6, 400e6];
const BOOSTABLE = ['rev', 'xp', 'liftxp', 'liftrev'];
const CORP_TASKS = [ // вид => [назва, база, множиться на рівень]
  'rides' => ['Перевезти відвідувачів ліфтом', 80, true], 'vips' => ['Розвезти VIP-гостей', 15, true], 'stocked' => ['Замовити товар', 80, true],
  'unloaded' => ['Розвантажити товар', 80, true], 'cashed' => ['Зібрати виручку (разів)', 80, true], 'earned' => ['Зібрати виручки (монет)', 100000, true],
  'evict' => ['Виселити жителів з готелю', 20, true], 'tips' => ['Отримати чайових банкнотами', 30, false], 'built' => ['Побудувати поверхів', 1, true],
];
const CORP_TASK_N = 8; const CORP_TASK_TTL = 48 * 3600;
const CTASK = ['rides' => 40, 'stocked' => 15, 'unloaded' => 15, 'cashed' => 15, 'vips' => 3, 'evicted' => 3]; // особисті завдання колекцій

$me = me(); ensureSchema();
$pid = (int)$me['id'];

function myCorp(int $pid): ?array {
  $m = q('SELECT c.*, m.corp_id, m.role, m.xp mxp, m.ctask, m.ctask_cancel, UNIX_TIMESTAMP(m.joined_at) joined FROM corp_members m JOIN corps c ON c.id = m.corp_id WHERE m.player_id = ?', [$pid])->fetch();
  return $m ?: null;
}
function corpXpNeed(int $L): float { return (float)(CORP_XP[min(CORP_LMAX, $L) - 1] ?? end(CORP_XP)); }
function mailReward(int $to, string $title, string $body, array $rw) { q('INSERT INTO mail (player_id, sender, icon, title, body, reward) VALUES (?, ?, ?, ?, ?, ?)', [$to, 'Корпорація', 'bz_office', $title, $body, json_encode($rw)]); }
function bldOf(array $c): array { $b = json_decode((string)($c['bld'] ?? ''), true) ?: []; $o = []; foreach (CORP_BLD as $k) $o[$k] = (int)($b[$k] ?? 0); return $o; }
// Колекції: збираються строго по черзі, цикл оновлюється раз на 3 дні (новий порядок, прогрес з нуля)
function collOf(array $c): array {
  $b = json_decode((string)($c['coll'] ?? ''), true) ?: []; $cyc = intdiv(time(), 3 * 86400);
  if (($b['cycle'] ?? -1) !== $cyc || empty($b['order'])) {
    $o = CORP_COLL; mt_srand((int)$c['id'] * 7919 + $cyc); for ($i = count($o) - 1; $i > 0; $i--) { $j = mt_rand(0, $i); [$o[$i], $o[$j]] = [$o[$j], $o[$i]]; } mt_srand();
    $b = ['cycle' => $cyc, 'order' => $o, 'pos' => 0, 'items' => array_fill(0, 8, 0)];
  }
  $b['ends'] = ($cyc + 1) * 3 * 86400; $b['items'] = array_map('intval', array_pad(array_slice((array)$b['items'], 0, 8), 8, 0));
  return $b;
}
function corpLevel(array $c): int { return max(1, (int)($c['lvl'] ?? 1)); }
function memberCap(int $L): int { return 20 + 2 * ($L - 1); }
function boostsOf(array $c): array { $b = json_decode((string)($c['boosts'] ?? ''), true) ?: []; return array_filter($b, fn($t) => $t > time()); }
// Ціна наступного рівня споруди: банкноти й монети почергово
function bldCost(int $lvl): array { return $lvl % 2 === 0 ? ['bucks' => (int)(round(1500 * pow(1.14, $lvl) / 10) * 10)] : ['coins' => (float)(round(50e6 * pow(1.22, $lvl) / 1e6) * 1e6)]; }
function today(): string { return (new DateTime('now', new DateTimeZone('Europe/Kyiv')))->format('Y-m-d'); }
function newTask(int $L, array $skip = []): array {
  $kinds = array_values(array_diff(array_keys(CORP_TASKS), $skip)); if (!$kinds) $kinds = array_keys(CORP_TASKS);
  $k = $kinds[random_int(0, count($kinds) - 1)]; [, $base, $mul] = CORP_TASKS[$k];
  $n = $mul ? $base * $L : $base;
  if ($k === 'earned') $n = round($n / 1000) * 1000;
  return ['id' => bin2hex(random_bytes(4)), 'kind' => $k, 'n' => (int)$n, 'p' => 0, 'taker' => null, 'who' => [], 'exp' => time() + CORP_TASK_TTL, 'day' => today()];
}
// 8 завдань: прострочені (48 год) замінюються, а щодня о 00:00 оновлюються всі, які ніхто не взяв
function tasksOf(array $c, int $L): array {
  $t = json_decode((string)($c['tasks'] ?? ''), true) ?: []; $d = today();
  // виконані лишаються в списку до кінця дня (внизу), взяті — до кінця 48 год
  $t = array_values(array_filter($t, fn($x) => isset($x['exp'], $x['day']) && (!empty($x['done']) ? $x['day'] === $d : ($x['exp'] > time() && ($x['day'] === $d || !empty($x['who']))))));
  while (count($t) < CORP_TASK_N) $t[] = newTask($L, array_column($t, 'kind'));
  return $t;
}
// додати предмет у поточну колекцію (по черзі); якщо колекцію зібрано — нагорода в бюджет і 10% кожному учаснику
function collAdd(int $cid, array &$coll, int &$addB, float &$addC): ?array {
  if ($coll['pos'] >= count($coll['order'])) return null;
  $miss = array_keys(array_filter($coll['items'], fn($v) => $v < 1));
  if ($miss) $coll['items'][$miss[random_int(0, count($miss) - 1)]] = 1;
  if (min($coll['items']) < 1) return null;
  $pos = $coll['pos']; $rb = COLL_RB[$pos] ?? end(COLL_RB); $rc = COLL_RC[$pos] ?? end(COLL_RC);
  $addB += $rb; $addC += $rc;
  foreach (q('SELECT player_id FROM corp_members WHERE corp_id = ?', [$cid])->fetchAll(PDO::FETCH_COLUMN) as $mid)
    mailReward((int)$mid, 'Колекцію корпорації зібрано!', 'Корпорація отримала ' . $rb . ' банкнот і ' . number_format($rc, 0, '.', ' ') . ' монет. Твоя частка — 10%:', [['kind' => 'bucks', 'n' => (int)round($rb / 10)], ['kind' => 'coins', 'n' => (int)round($rc / 10)]]);
  $coll['pos']++; $coll['items'] = array_fill(0, 8, 0);
  return [$coll['order'][$pos], $rb, $rc];
}
// будівництво споруд триває певний час: 1 год для 1-го рівня … 21 день для 30-го
function buildTime(int $to): int { return (int)round(3600 * pow(504, ($to - 1) / 29)); }
function buildOf(array $c): array { return json_decode((string)($c['build'] ?? ''), true) ?: []; }
function finalizeBuild(array &$c): void {
  $b = buildOf($c); if (!$b) return; $bld = bldOf($c); $ch = false;
  foreach ($b as $k => [$to, $until]) if ($until <= time()) { $bld[$k] = max($bld[$k] ?? 0, (int)$to); unset($b[$k]); $ch = true; }
  if (!$ch) return;
  $c['bld'] = json_encode($bld); $c['build'] = json_encode($b ?: new stdClass());
  q('UPDATE corps SET bld = ?, build = ?, lvl_sum = ? WHERE id = ?', [$c['bld'], $c['build'], array_sum($bld), (int)($c['corp_id'] ?? $c['id'])]);
}
function clog(int $cid, ?int $pid, string $kind, string $info = '') { q('INSERT INTO corp_log (corp_id, player_id, kind, info) VALUES (?, ?, ?, ?)', [$cid, $pid, $kind, mb_substr($info, 0, 255)]); }
function donLimit(int $pid): array {
  $don = (int)q('SELECT COALESCE(SUM(CAST(info AS UNSIGNED)), 0) FROM history WHERE player_id = ? AND kind = "donate"', [$pid])->fetchColumn();
  $today = q('SELECT bucks, coins FROM corp_don WHERE player_id = ? AND day = UTC_DATE()', [$pid])->fetch() ?: ['bucks' => 0, 'coins' => 0];
  return ['bucks' => 100 + $don, 'coins' => 10000000, 'usedB' => (int)$today['bucks'], 'usedC' => (float)$today['coins'], 'donated' => $don];
}
function corpName($n, int $self = 0): string {
  $name = trim(preg_replace('/\s+/u', ' ', (string)$n));
  if (mb_strlen($name) < 3 || mb_strlen($name) > 24) fail('corp_name_len');
  if (!preg_match('/^[\p{L}\p{N} _.\-«»"\'!]+$/u', $name)) fail('corp_name_chars');
  if (q('SELECT 1 FROM corps WHERE name_lc = ? AND id <> ?', [mb_strtolower($name), $self])->fetchColumn()) fail('corp_name_taken');
  return $name;
}
function needCorp(int $pid): array { $c = myCorp($pid); if (!$c) fail('no_corp'); return $c; }
function needRole(array $c, int $r) { if ((int)$c['role'] < $r) fail('corp_rights', 403); }
function corpMail(int $to, string $title, string $body) { q('INSERT INTO mail (player_id, sender, icon, title, body) VALUES (?, ?, ?, ?, ?)', [$to, 'Корпорація', 'bz_office', $title, $body]); }

switch ($a) {

  // ---------- моя корпорація або сторінка іншої ----------
  case 'corp_info': {
    $view = (int)arg('id', 0);
    $c = $view ? q('SELECT * FROM corps WHERE id = ?', [$view])->fetch() : myCorp($pid);
    $inv = q('SELECT i.corp_id, c.name, c.emblem, p.nick fnick FROM corp_invites i JOIN corps c ON c.id = i.corp_id JOIN players p ON p.id = i.from_id WHERE i.player_id = ?', [$pid])->fetch() ?: null;
    $lvl = (int)(q('SELECT level FROM player_stats WHERE player_id = ?', [$pid])->fetchColumn() ?: 1);
    if (!$c) out(['ok' => true, 'corp' => null, 'invite' => $inv, 'level' => $lvl]);
    finalizeBuild($c);
    $cid = (int)$c['id']; $bld = bldOf($c); $L = corpLevel($c);
    $members = q('SELECT p.id, p.nick, p.avatar, UNIX_TIMESTAMP(p.last_seen) seen, m.role, UNIX_TIMESTAMP(m.joined_at) joined, m.d_bucks, m.d_coins, m.xp, COALESCE(s.level, 1) level
                  FROM corp_members m JOIN players p ON p.id = m.player_id LEFT JOIN player_stats s ON s.player_id = p.id WHERE m.corp_id = ? ORDER BY m.role DESC, s.level DESC', [$cid])->fetchAll();
    $res = ['ok' => true, 'level' => $lvl, 'invite' => $inv, 'corp' => [
      'id' => $cid, 'name' => $c['name'], 'emblem' => $c['emblem'], 'bucks' => (int)$c['bucks'], 'coins' => (float)$c['coins'], 'bld' => $bld, 'boost' => boostsOf($c) ?: new stdClass(), 'build' => buildOf($c) ?: new stdClass(),
      'lvl' => $L, 'xp' => (float)$c['xp'], 'need' => corpXpNeed($L), 'max' => CORP_LMAX, 'cap' => memberCap($L),
      'created' => strtotime($c['created_at'] . ' UTC'), 'director' => (int)$c['director_id'], 'members' => $members,
      'role' => $view ? null : (int)$c['role'], 'joined' => $view ? null : (int)$c['joined'],
    ]];
    if (!$view) {
      $t = tasksOf($c, $L); $co = collOf($c); $tj = json_encode($t); $cj = json_encode($co);
      if ($tj !== (string)$c['tasks'] || $cj !== (string)$c['coll']) q('UPDATE corps SET tasks = ?, coll = ? WHERE id = ?', [$tj, $cj, $cid]); // пишемо лише якщо щось змінилось
      $co['rb'] = COLL_RB; $co['rc'] = COLL_RC;
      $res['corp']['tasks'] = $t; $res['corp']['reset'] = (new DateTime('tomorrow', new DateTimeZone('Europe/Kyiv')))->getTimestamp(); $res['corp']['coll'] = $co; $res['limit'] = donLimit($pid);
      $ct = json_decode((string)($c['ctask'] ?? ''), true); if ($ct && $ct['exp'] <= time()) $ct = null;
      $res['corp']['ctask'] = $ct; $res['corp']['ctaskCancel'] = (int)$c['ctask_cancel'];
    }
    out($res);
  }

  // ---------- створення ----------
  case 'corp_create': {
    rate('corp:' . $pid, 10, 60);
    if (myCorp($pid)) fail('in_corp');
    if ((int)(q('SELECT level FROM player_stats WHERE player_id = ?', [$pid])->fetchColumn() ?: 1) < 10) fail('corp_level');
    $name = corpName(arg('name'));
    $em = preg_match('/^shield_\d{1,2}$/', (string)arg('emblem')) ? arg('emblem') : 'shield_1';
    $t = []; for ($i = 0; $i < CORP_TASK_N; $i++) $t[] = newTask(1, array_column($t, 'kind'));
    q('INSERT INTO corps (name, name_lc, emblem, director_id, bucks, coins, bld, coll, tasks, lvl, xp) VALUES (?, ?, ?, ?, 2000, 10000000, ?, ?, ?, 1, 0)',
      [$name, mb_strtolower($name), $em, $pid, json_encode(array_fill_keys(CORP_BLD, 0)), '{}', json_encode($t)]);
    $cid = (int)db()->lastInsertId();
    q('INSERT INTO corp_members (player_id, corp_id, role) VALUES (?, ?, 4)', [$pid, $cid]);
    q('DELETE FROM corp_invites WHERE player_id = ?', [$pid]);
    clog($cid, $pid, 'create', $name);
    out(['ok' => true, 'id' => $cid]);
  }

  // ---------- запрошення ----------
  case 'corp_invite': {
    rate('corpinv:' . $pid, 30, 60);
    $c = needCorp($pid); needRole($c, 1);
    $p = peer(); $tid = (int)$p['id'];
    if ((int)(q('SELECT level FROM player_stats WHERE player_id = ?', [$tid])->fetchColumn() ?: 1) < 10) fail('corp_target_level');
    if (q('SELECT 1 FROM corp_members WHERE player_id = ?', [$tid])->fetchColumn()) fail('corp_target_in');
    if (q('SELECT 1 FROM corp_invites WHERE player_id = ?', [$tid])->fetchColumn()) fail('corp_target_invited');
    if ((int)q('SELECT COUNT(*) FROM corp_members WHERE corp_id = ?', [$c['corp_id']])->fetchColumn() >= memberCap(corpLevel($c))) fail('corp_full');
    if (isBlocked($pid, $tid)) fail('blocked', 403);
    q('INSERT INTO corp_invites (player_id, corp_id, from_id) VALUES (?, ?, ?)', [$tid, $c['corp_id'], $pid]);
    q('INSERT INTO mail (player_id, sender, icon, title, body, reward) VALUES (?, ?, ?, ?, ?, ?)', [$tid, $me['nick'], 'bz_office', 'Запрошення в корпорацію',
      "Гравець {$me['nick']} запрошує тебе вступити до корпорації «{$c['name']}».", json_encode(['kind' => 'corpinv', 'corp' => (int)$c['corp_id'], 'name' => $c['name'], 'emblem' => $c['emblem']])]);
    pushTo($tid, 'cinv', ['nick' => $me['nick'], 'corp' => $c['name']], 'corp', 'cinv');
    out(['ok' => true]);
  }
  case 'corp_answer': {
    $inv = q('SELECT * FROM corp_invites WHERE player_id = ?', [$pid])->fetch();
    if (!$inv) fail('no_invite');
    q('DELETE FROM corp_invites WHERE player_id = ?', [$pid]);
    if (!(int)arg('accept')) out(['ok' => true, 'joined' => false]);
    if (myCorp($pid)) fail('in_corp');
    if ((int)(q('SELECT level FROM player_stats WHERE player_id = ?', [$pid])->fetchColumn() ?: 1) < 10) fail('corp_level');
    $c = q('SELECT * FROM corps WHERE id = ?', [$inv['corp_id']])->fetch(); if (!$c) fail('corp_gone');
    if ((int)q('SELECT COUNT(*) FROM corp_members WHERE corp_id = ?', [$c['id']])->fetchColumn() >= memberCap(corpLevel($c))) fail('corp_full');
    q('INSERT INTO corp_members (player_id, corp_id, role) VALUES (?, ?, 0)', [$pid, $inv['corp_id']]);
    clog((int)$inv['corp_id'], $pid, 'join', $me['nick']);
    out(['ok' => true, 'joined' => true]);
  }

  // ---------- учасники: звання, виключення, вихід ----------
  case 'corp_role': {
    $c = needCorp($pid); $my = (int)$c['role']; needRole($c, 2);
    $tid = (int)arg('id'); $new = (int)arg('role');
    $t = q('SELECT * FROM corp_members WHERE player_id = ? AND corp_id = ?', [$tid, $c['corp_id']])->fetch(); if (!$t || $tid === $pid) fail('not_found', 404);
    if ((int)$t['role'] >= $my) fail('corp_rights', 403);
    if ($new === 4) {
      if ($my !== 4) fail('corp_rights', 403);
      q('UPDATE corp_members SET role = 3 WHERE player_id = ?', [$pid]); q('UPDATE corp_members SET role = 4 WHERE player_id = ?', [$tid]); q('UPDATE corps SET director_id = ? WHERE id = ?', [$tid, $c['corp_id']]);
    } else {
      if ($new < 0 || $new >= $my) fail('corp_rights', 403);
      q('UPDATE corp_members SET role = ? WHERE player_id = ?', [$new, $tid]);
    }
    corpMail($tid, 'Нове звання', "Тобі присвоєно звання «" . CORP_ROLES[$new] . "» у корпорації «{$c['name']}».");
    out(['ok' => true]);
  }
  case 'corp_kick': {
    $c = needCorp($pid); needRole($c, 2);
    $tid = (int)arg('id'); $t = q('SELECT * FROM corp_members WHERE player_id = ? AND corp_id = ?', [$tid, $c['corp_id']])->fetch();
    if (!$t || (int)$t['role'] >= (int)$c['role']) fail('corp_rights', 403);
    q('DELETE FROM corp_members WHERE player_id = ?', [$tid]);
    corpMail($tid, 'Тебе виключено з корпорації', "Тебе виключено з корпорації «{$c['name']}». Бонуси корпорації більше не діють.");
    out(['ok' => true]);
  }
  case 'corp_leave': {
    $c = needCorp($pid); $cid = (int)$c['corp_id'];
    if ((int)$c['role'] === 4) {
      $n = (int)q('SELECT COUNT(*) FROM corp_members WHERE corp_id = ?', [$cid])->fetchColumn();
      if ($n > 1) fail('corp_transfer_first');
      q('DELETE FROM corps WHERE id = ?', [$cid]); out(['ok' => true, 'disbanded' => true]);
    }
    q('DELETE FROM corp_members WHERE player_id = ?', [$pid]);
    out(['ok' => true]);
  }

  // ---------- бюджет, споруди, бонус ×2, налаштування ----------
  case 'corp_donate': {
    rate('corpdon:' . $pid, 30, 60);
    $c = needCorp($pid); $cid = (int)$c['corp_id'];
    if ((int)$c['role'] < 3 && time() - (int)$c['joined'] < 3 * 86400) fail('corp_too_new');
    $b = max(0, (int)arg('bucks', 0)); $co = max(0, (float)arg('coins', 0)); if (!$b && !$co) fail('empty');
    $lim = donLimit($pid);
    if ($lim['usedB'] + $b > $lim['bucks']) fail('corp_limit_b');
    if ($lim['usedC'] + $co > $lim['coins']) fail('corp_limit_c');
    q('INSERT INTO corp_don (player_id, day, bucks, coins) VALUES (?, UTC_DATE(), ?, ?) ON DUPLICATE KEY UPDATE bucks = bucks + VALUES(bucks), coins = coins + VALUES(coins)', [$pid, $b, $co]);
    q('UPDATE corps SET bucks = bucks + ?, coins = coins + ? WHERE id = ?', [$b, $co, $cid]);
    q('UPDATE corp_members SET d_bucks = d_bucks + ?, d_coins = d_coins + ? WHERE player_id = ?', [$b, $co, $pid]);
    out(['ok' => true]);
  }
  case 'corp_upgrade': {
    $c = needCorp($pid); needRole($c, 4); $cid = (int)$c['corp_id'];
    $k = (string)arg('b'); if (!in_array($k, CORP_BLD, true)) fail('bad_bld');
    db()->beginTransaction();
    $row = q('SELECT * FROM corps WHERE id = ? FOR UPDATE', [$cid])->fetch(); finalizeBuild($row); $bld = bldOf($row); $b = buildOf($row);
    if (isset($b[$k])) { db()->rollBack(); fail('corp_building'); }
    if ($bld[$k] >= CORP_MAX) { db()->rollBack(); fail('corp_max'); }
    $cost = bldCost($bld[$k]);
    if ((isset($cost['bucks']) && $row['bucks'] < $cost['bucks']) || (isset($cost['coins']) && $row['coins'] < $cost['coins'])) { db()->rollBack(); fail('corp_budget'); }
    $b[$k] = [$bld[$k] + 1, time() + buildTime($bld[$k] + 1)];
    q('UPDATE corps SET build = ?, bucks = bucks - ?, coins = coins - ? WHERE id = ?', [json_encode($b), $cost['bucks'] ?? 0, $cost['coins'] ?? 0, $cid]);
    db()->commit();
    out(['ok' => true, 'to' => $b[$k][0], 'until' => $b[$k][1]]);
  }
  // прискорення будівництва з бюджету: 15 банкнот за кожні 5 хвилин, що лишились
  case 'corp_speed': {
    $c = needCorp($pid); needRole($c, 4); $cid = (int)$c['corp_id'];
    $k = (string)arg('b');
    db()->beginTransaction();
    $row = q('SELECT * FROM corps WHERE id = ? FOR UPDATE', [$cid])->fetch(); $b = buildOf($row);
    if (!isset($b[$k])) { db()->rollBack(); fail('not_found', 404); }
    $cost = (int)ceil(max(0, $b[$k][1] - time()) / 300) * 15;
    if ($row['bucks'] < $cost) { db()->rollBack(); fail('corp_budget'); }
    $b[$k][1] = time(); $row['build'] = json_encode($b);
    q('UPDATE corps SET build = ?, bucks = bucks - ? WHERE id = ?', [$row['build'], $cost, $cid]);
    finalizeBuild($row);
    db()->commit();
    out(['ok' => true, 'cost' => $cost]);
  }
  case 'corp_boost': {
    $c = needCorp($pid); needRole($c, 3); $cid = (int)$c['corp_id'];
    $k = (string)arg('b'); if (!in_array($k, BOOSTABLE, true)) fail('bad_bld');
    if ((bldOf($c)[$k] ?? 0) < 1) fail('corp_boost_zero');
    $bo = boostsOf($c); if (!empty($bo[$k])) fail('corp_boost_on');
    if ((int)$c['bucks'] < 1000) fail('corp_budget');
    $bo[$k] = time() + 86400;
    q('UPDATE corps SET boosts = ?, bucks = bucks - 1000 WHERE id = ?', [json_encode($bo), $cid]);
    out(['ok' => true, 'until' => $bo[$k]]);
  }
  case 'corp_emblem': {
    $c = needCorp($pid); needRole($c, 4); $cid = (int)$c['corp_id'];
    $em = (string)arg('emblem'); if (!preg_match('/^shield_\d{1,2}$/', $em)) fail('bad_emblem');
    if ((int)$c['bucks'] < 1000) fail('corp_budget');
    q('UPDATE corps SET emblem = ?, bucks = bucks - 1000 WHERE id = ?', [$em, $cid]);
    out(['ok' => true]);
  }
  case 'corp_rename': {
    $c = needCorp($pid); needRole($c, 4); $cid = (int)$c['corp_id'];
    $name = corpName(arg('name'), $cid);
    if ((int)$c['bucks'] < 5000) fail('corp_budget');
    q('UPDATE corps SET name = ?, name_lc = ?, bucks = bucks - 5000 WHERE id = ?', [$name, mb_strtolower($name), $cid]);
    out(['ok' => true]);
  }

  // ---------- спільні завдання ----------
  case 'corp_task': {
    $c = needCorp($pid); $cid = (int)$c['corp_id']; $L = corpLevel($c);
    $id = (string)arg('id'); $t = tasksOf($c, $L); $f = false;
    foreach ($t as $x) if (empty($x['done']) && in_array($pid, $x['who'], true)) fail('task_busy');
    foreach ($t as $x) if ($x['id'] === $id && !empty($x['done'])) fail('task_done');
    foreach ($t as &$x) if ($x['id'] === $id) { $f = true; if (!$x['taker']) { $x['taker'] = $pid; $x['exp'] = time() + CORP_TASK_TTL; } if (!in_array($pid, $x['who'], true)) $x['who'][] = $pid; }
    unset($x);
    if (!$f) fail('not_found', 404);
    q('UPDATE corps SET tasks = ? WHERE id = ?', [json_encode($t), $cid]);
    out(['ok' => true]);
  }
  // нагорода за виконане завдання для того, хто його взяв
  case 'corp_task_claim': {
    $c = needCorp($pid); $cid = (int)$c['corp_id'];
    db()->beginTransaction();
    $row = q('SELECT * FROM corps WHERE id = ? FOR UPDATE', [$cid])->fetch(); $t = tasksOf($row, corpLevel($row)); $f = false;
    foreach ($t as &$x) if ($x['id'] === (string)arg('id')) {
      if (empty($x['done']) || (int)$x['taker'] !== $pid) { db()->rollBack(); fail('corp_rights', 403); }
      if (!empty($x['claimed'])) { db()->rollBack(); fail('already_claimed'); }
      $x['claimed'] = 1; $f = true;
    }
    unset($x); if (!$f) { db()->rollBack(); fail('not_found', 404); }
    q('UPDATE corps SET tasks = ? WHERE id = ?', [json_encode($t), $cid]);
    db()->commit();
    out(['ok' => true, 'reward' => [['kind' => 'bucks', 'n' => 15], ['kind' => 'coins', 'n' => 30000]]]);
  }
  case 'corp_task_cancel': {
    $c = needCorp($pid); $cid = (int)$c['corp_id']; $L = corpLevel($c);
    $id = (string)arg('id'); $t = tasksOf($c, $L); $f = false;
    foreach ($t as &$x) if ($x['id'] === $id) {
      if (!empty($x['done'])) fail('task_done');
      if ((int)$c['role'] < 4 && (int)$x['taker'] !== $pid) fail('corp_rights', 403);
      $x['taker'] = null; $x['who'] = []; $x['p'] = 0; $f = true;
    }
    unset($x); if (!$f) fail('not_found', 404);
    q('UPDATE corps SET tasks = ? WHERE id = ?', [json_encode($t), $cid]);
    out(['ok' => true]);
  }
  // особисте завдання колекції: 12 год на виконання, відміна раз на 3 год, нагорода — предмет колекції + 5 банкнот, монети й досвід
  case 'corp_ctask': {
    $c = needCorp($pid); $op = (string)arg('op');
    $ct = json_decode((string)($c['ctask'] ?? ''), true); if ($ct && $ct['exp'] <= time()) $ct = null;
    $plv = (int)(q('SELECT level FROM player_stats WHERE player_id = ?', [$pid])->fetchColumn() ?: 1);
    if ($op === 'take') {
      if ($ct) fail('ctask_have');
      $co = collOf($c); if ($co['pos'] >= count($co['order'])) fail('coll_cycle_done');
      $k = array_rand(CTASK); $base = (array)arg('base', []);
      $ct = ['kind' => $k, 'n' => max(1, (int)round(CTASK[$k] * (1 + 0.08 * $plv))), 'base' => (float)($base[$k] ?? 0), 'exp' => time() + 12 * 3600];
      q('UPDATE corp_members SET ctask = ? WHERE player_id = ?', [json_encode($ct), $pid]);
      out(['ok' => true, 'ctask' => $ct]);
    }
    if ($op === 'cancel') {
      if (!$ct) fail('not_found', 404);
      if (time() - (int)$c['ctask_cancel'] < 3 * 3600) fail('ctask_cancel_wait');
      q('UPDATE corp_members SET ctask = NULL, ctask_cancel = ? WHERE player_id = ?', [time(), $pid]);
      out(['ok' => true]);
    }
    if ($op === 'done') {
      if (!$ct) fail('not_found', 404);
      if ((float)arg('cur', 0) - $ct['base'] < $ct['n']) fail('ctask_not_done');
      $cid = (int)$c['corp_id'];
      db()->beginTransaction();
      $row = q('SELECT * FROM corps WHERE id = ? FOR UPDATE', [$cid])->fetch(); $co = collOf($row); $aB = 0; $aC = 0.0;
      $done = collAdd($cid, $co, $aB, $aC);
      q('UPDATE corps SET coll = ?, bucks = bucks + ?, coins = coins + ?, sets = sets + ? WHERE id = ?', [json_encode($co), $aB, $aC, $done ? 1 : 0, $cid]);
      q('UPDATE corp_members SET ctask = NULL WHERE player_id = ?', [$pid]);
      db()->commit();
      out(['ok' => true, 'coll' => $done, 'reward' => [['kind' => 'bucks', 'n' => 5], ['kind' => 'coins', 'n' => 5000 * $plv], ['kind' => 'xp', 'n' => 150 * $plv]]]);
    }
    fail('bad_op');
  }
  case 'corp_chat_del': {
    $c = needCorp($pid); needRole($c, 2);
    q('DELETE FROM corp_chat WHERE id = ? AND corp_id = ?', [(int)arg('id'), $c['corp_id']]);
    out(['ok' => true]);
  }
  // прирости гри учасника: прогрес завдань + 20% його досвіду йде в досвід корпорації
  case 'corp_prog': {
    $c = myCorp($pid); if (!$c) out(['ok' => true]);
    rate('corpprog:' . $pid, 60, 60);
    $cid = (int)$c['corp_id'];
    $d = (array)arg('d', []);
    db()->beginTransaction();
    $row = q('SELECT * FROM corps WHERE id = ? FOR UPDATE', [$cid])->fetch(); $L = corpLevel($row);
    $t = tasksOf($row, $L); $done = []; $coll = collOf($row);
    foreach ($t as $i => &$x) {
      if (!empty($x['done']) || !in_array($pid, $x['who'], true)) continue;
      $n = max(0, (float)($d[$x['kind']] ?? 0)); if (!$n) continue;
      $x['p'] = min($x['n'], $x['p'] + $n);
      if ($x['p'] >= $x['n']) $done[] = $i;
    }
    unset($x);
    $addB = 0; $addC = 0.0; $collDone = null; $cupsGot = 0;
    foreach ($done as $i) {
      $x = $t[$i]; $addB += 25; $addC += 50000;
      // хто взяв — забирає нагороду прямо в завданнях; ті, хто допомагав, — отримують її поштою
      foreach ($x['who'] as $w) if ((int)$w !== (int)$x['taker']) mailReward((int)$w, 'Завдання корпорації виконано!', '«' . CORP_TASKS[$x['kind']][0] . '» — дякуємо за допомогу! Твоя нагорода:', [['kind' => 'bucks', 'n' => 15], ['kind' => 'coins', 'n' => 30000]]);
      clog($cid, $pid, 'task', CORP_TASKS[$x['kind']][0]);
      $cd = collAdd($cid, $coll, $addB, $addC); if ($cd) $collDone = $cd;
      $t[$i]['done'] = 1; $t[$i]['claimed'] = 0;
      if (tourActive()) { $cw = random_int(2, 5); tourAddCorp($cid, $cw); $t[$i]['cups'] = $cw; $cupsGot += $cw; } // випадкові кубки в турнір корпорацій $t[$i]['day'] = today(); $t[$i]['p'] = $t[$i]['n'];
    }
    // досвід корпорації: 20% від досвіду учасника
    $xp = max(0, (float)($d['xp'] ?? 0)) * 0.2; $cx = (float)$row['xp'] + $xp; $up = 0;
    while ($L < CORP_LMAX && $cx >= corpXpNeed($L)) { $cx = 0; $L++; $up++; } // після підвищення досвід починається з нуля
    if ($up) clog($cid, null, 'level', (string)$L);
    q('UPDATE corps SET tasks = ?, coll = ?, bucks = bucks + ?, coins = coins + ?, tasks_done = tasks_done + ?, xp = ?, lvl = ?, sets = sets + ? WHERE id = ?',
      [json_encode(array_values($t)), json_encode($coll), $addB, $addC, count($done), $cx, $L, $collDone ? 1 : 0, $cid]);
    if ($xp) q('UPDATE corp_members SET xp = xp + ? WHERE player_id = ?', [$xp, $pid]);
    db()->commit();
    $claim = count(array_filter($t, fn($x) => !empty($x['done']) && empty($x['claimed']) && (int)$x['taker'] === $pid));
    out(['ok' => true, 'done' => count($done), 'coll' => $collDone, 'up' => $up, 'lvl' => $L, 'claim' => $claim, 'cups' => $cupsGot, 'tasks' => array_values($t), 'xp' => $cx, 'need' => corpXpNeed($L)]);
  }

  // ---------- приватний чат корпорації ----------
  case 'corp_chat': {
    $c = needCorp($pid);
    out(['ok' => true, 'items' => array_reverse(q('SELECT h.id, h.body, UNIX_TIMESTAMP(h.created_at) t, p.id pid, p.nick, p.avatar, p.access is_admin, m.role, h.reply_to rid, COALESCE(rp.nick, IF(r.id IS NULL, NULL, "")) rnick, LEFT(r.body, 140) rbody FROM corp_chat h LEFT JOIN players p ON p.id = h.player_id
      LEFT JOIN corp_members m ON m.player_id = p.id AND m.corp_id = h.corp_id LEFT JOIN corp_chat r ON r.id = h.reply_to LEFT JOIN players rp ON rp.id = r.player_id WHERE h.corp_id = ? ORDER BY h.id DESC LIMIT 60', [$c['corp_id']])->fetchAll())]);
  }
  case 'corp_chat_send': {
    $c = needCorp($pid); restricted($me, 'chat'); rate('cchat:' . $pid, 8, 30);
    $text = cleanText(arg('text'), 500);
    $rid = (int)arg('reply', 0); if ($rid && !q('SELECT 1 FROM corp_chat WHERE id = ? AND corp_id = ?', [$rid, $c['corp_id']])->fetchColumn()) $rid = 0;
    q('INSERT INTO corp_chat (corp_id, player_id, body, reply_to) VALUES (?, ?, ?, ?)', [$c['corp_id'], $pid, $text, $rid ?: null]);
    if (pushOn()) // кожному учаснику; якщо за хвилину він зайде в гру — сповіщення не буде. Нові замінюють попереднє (без спаму)
      foreach (q('SELECT player_id FROM corp_members WHERE corp_id = ? AND player_id <> ?', [$c['corp_id'], $pid])->fetchAll(PDO::FETCH_COLUMN) as $to)
        pushTo((int)$to, 'cchat', ['corp' => $c['name'], 'nick' => $me['nick'], 'text' => $text], 'cchat', 'cchat');
    out(['ok' => true]);
  }

  // ---------- рейтинг корпорацій ----------
  case 'corp_top': {
    $per = 10; $page = max(0, (int)arg('page', 0));
    $total = (int)q('SELECT COUNT(*) FROM corps')->fetchColumn();
    $rows = q("SELECT c.id, c.name, c.emblem, c.lvl, c.xp, c.lvl_sum, c.tasks_done, c.sets, c.bld, (SELECT COUNT(*) FROM corp_members m WHERE m.corp_id = c.id) members, p.nick director
               FROM corps c JOIN players p ON p.id = c.director_id ORDER BY c.lvl DESC, c.xp DESC, c.lvl_sum DESC, c.id ASC LIMIT $per OFFSET " . ($page * $per))->fetchAll();
    foreach ($rows as &$r) { $r['cap'] = memberCap((int)$r['lvl']); $r['need'] = corpXpNeed((int)$r['lvl']); unset($r['bld']); }
    out(['ok' => true, 'total' => $total, 'per' => $per, 'items' => $rows]);
  }

  default: fail('unknown_action', 404);
}
