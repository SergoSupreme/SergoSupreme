<?php
// ============================================================
//  Керування корпораціями з адмін-панелі: бюджет, рівень, споруди,
//  звання учасників, передача посади Директора й виключення.
//  Підключається з admin_api.php (доступ уже перевірено там).
// ============================================================
if (!defined('MIN_CLIENT')) { http_response_code(403); exit; }
require_once __DIR__ . '/corp_core.php';

const ADM_CORP_BLD = ['rev', 'xp', 'liftxp', 'liftrev', 'lobby', 'tips'];
const ADM_CORP_BMAX = 30;
const ADM_CORP_ROLES = ['Новачок', 'Менеджер', 'Адміністратор', 'Зам. директора', 'Директор'];

function admCorpRow(int $id): array {
  $c = q('SELECT * FROM corps WHERE id = ?', [$id])->fetch();
  if (!$c) fail('not_found', 404);
  return $c;
}
function admCorpBld(array $c): array {
  $b = json_decode((string)($c['bld'] ?? ''), true) ?: [];
  $o = []; foreach (ADM_CORP_BLD as $k) $o[$k] = max(0, min(ADM_CORP_BMAX, (int)($b[$k] ?? 0)));
  return $o;
}
// Повна картка корпорації для адміністратора
function admCorpCard(int $id): array {
  $c = admCorpRow($id);
  $L = max(1, (int)$c['lvl']);
  $members = q('SELECT m.player_id id, m.role, m.xp, m.d_bucks, m.d_coins, UNIX_TIMESTAMP(m.joined_at) joined,
                       p.nick, p.avatar, p.banned, UNIX_TIMESTAMP(p.last_seen) seen, COALESCE(s.level, 1) level
                FROM corp_members m JOIN players p ON p.id = m.player_id
                LEFT JOIN player_stats s ON s.player_id = m.player_id
                WHERE m.corp_id = ? ORDER BY m.role DESC, m.xp DESC', [$id])->fetchAll();
  $log = q('SELECT l.kind, l.info, UNIX_TIMESTAMP(l.created_at) t, p.nick
            FROM corp_log l LEFT JOIN players p ON p.id = l.player_id
            WHERE l.corp_id = ? ORDER BY l.id DESC LIMIT 20', [$id])->fetchAll();
  $inv = q('SELECT id, mode, pct, ok, UNIX_TIMESTAMP(FROM_UNIXTIME(started_at)) t FROM corp_invest WHERE corp_id = ? AND state = 2 ORDER BY id DESC LIMIT 5', [$id])->fetchAll();
  return ['corp' => [
    'id' => (int)$c['id'], 'name' => $c['name'], 'emblem' => $c['emblem'], 'lvl' => $L, 'xp' => (float)$c['xp'],
    'need' => corpXpNeed($L), 'max' => CORP_LMAX, 'bucks' => (float)$c['bucks'], 'coins' => (float)$c['coins'],
    'bld' => admCorpBld($c), 'bmax' => ADM_CORP_BMAX, 'director' => (int)$c['director_id'],
    'tasks_done' => (int)$c['tasks_done'], 'sets' => (int)$c['sets'], 'created' => strtotime($c['created_at'] . ' UTC'),
    'cap' => 20 + 2 * ($L - 1),
  ], 'members' => $members, 'log' => $log, 'invest' => $inv, 'roles' => ADM_CORP_ROLES, 'blds' => ADM_CORP_BLD];
}
// Зберегти зміни: назва, бюджет, рівень, досвід, споруди
function admCorpSave(int $id): array {
  $c = admCorpRow($id); $ch = [];
  $set = []; $args = [];
  $name = trim((string)arg('name', ''));
  if ($name !== '' && $name !== $c['name']) {
    if (mb_strlen($name) < 3 || mb_strlen($name) > 24) fail('corp_name_len');
    if (q('SELECT 1 FROM corps WHERE name_lc = ? AND id <> ?', [mb_strtolower($name), $id])->fetchColumn()) fail('corp_name_taken');
    $set[] = 'name = ?'; $args[] = $name; $set[] = 'name_lc = ?'; $args[] = mb_strtolower($name);
    $ch[] = 'назва: ' . $c['name'] . ' → ' . $name;
  }
  $em = trim((string)arg('emblem', ''));
  if ($em !== '' && $em !== $c['emblem'] && preg_match('/^shield_\d{1,2}$/', $em)) { $set[] = 'emblem = ?'; $args[] = $em; $ch[] = 'герб: ' . $em; }

  foreach ([['bucks', 'банкноти', 1e12], ['coins', 'монети', 1e15]] as [$k, $n, $cap]) {
    $v = arg($k); if ($v === null || $v === '') continue;
    $v = max(0, min($cap, (float)$v));
    if (abs($v - (float)$c[$k]) < 0.5) continue;
    $set[] = "$k = ?"; $args[] = $v; $ch[] = "$n: " . number_format((float)$c[$k], 0, '.', ' ') . ' → ' . number_format($v, 0, '.', ' ');
  }
  $lv = arg('lvl');
  if ($lv !== null && $lv !== '') {
    $lv = max(1, min(CORP_LMAX, (int)$lv));
    if ($lv !== (int)$c['lvl']) { $set[] = 'lvl = ?'; $args[] = $lv; $ch[] = 'рівень: ' . $c['lvl'] . ' → ' . $lv; }
  }
  $xp = arg('xp');
  if ($xp !== null && $xp !== '') {
    $xp = max(0, (float)$xp);
    if (abs($xp - (float)$c['xp']) >= 0.5) { $set[] = 'xp = ?'; $args[] = $xp; $ch[] = 'досвід: ' . number_format($xp, 0, '.', ' '); }
  }
  // споруди
  $bld = admCorpBld($c); $want = (array)arg('bld', []); $bch = [];
  foreach (ADM_CORP_BLD as $k) {
    if (!array_key_exists($k, $want)) continue;
    $v = max(0, min(ADM_CORP_BMAX, (int)$want[$k]));
    if ($v !== $bld[$k]) { $bch[] = "$k " . $bld[$k] . '→' . $v; $bld[$k] = $v; }
  }
  if ($bch) {
    $set[] = 'bld = ?'; $args[] = json_encode($bld);
    $set[] = 'lvl_sum = ?'; $args[] = array_sum($bld);
    $set[] = 'build = ?'; $args[] = null;                       // незавершене будівництво скидаємо
    $ch[] = 'споруди: ' . implode(', ', $bch);
  }
  if (!$set) return ['ok' => true, 'changes' => []];
  $args[] = $id;
  q('UPDATE corps SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
  alog('corp_edit', $c['name'], implode('; ', $ch));
  clog($id, null, 'admin', mb_substr(implode('; ', $ch), 0, 255));
  return ['ok' => true, 'changes' => $ch];
}
// Учасники: звання, передача посади Директора, виключення
function admCorpMember(int $id, int $pid, string $op, int $role): array {
  $c = admCorpRow($id);
  $m = q('SELECT m.*, p.nick FROM corp_members m JOIN players p ON p.id = m.player_id WHERE m.corp_id = ? AND m.player_id = ?', [$id, $pid])->fetch();
  if (!$m) fail('not_found', 404);

  if ($op === 'role') {
    $role = max(0, min(3, $role));                               // 4 — лише через передачу посади
    if ((int)$c['director_id'] === $pid) fail('corp_transfer_first');
    q('UPDATE corp_members SET role = ? WHERE corp_id = ? AND player_id = ?', [$role, $id, $pid]);
    alog('corp_member', $c['name'], $m['nick'] . ' → ' . ADM_CORP_ROLES[$role]);
    clog($id, $pid, 'role', ADM_CORP_ROLES[$role]);
    return ['ok' => true];
  }
  if ($op === 'director') {
    if ((int)$c['director_id'] === $pid) return ['ok' => true];
    db()->beginTransaction();
    q('UPDATE corp_members SET role = 3 WHERE corp_id = ? AND role = 4', [$id]);   // колишній стає замом
    q('UPDATE corp_members SET role = 4 WHERE corp_id = ? AND player_id = ?', [$id, $pid]);
    q('UPDATE corps SET director_id = ? WHERE id = ?', [$pid, $id]);
    db()->commit();
    alog('corp_member', $c['name'], 'новий Директор: ' . $m['nick']);
    clog($id, $pid, 'director', $m['nick']);
    return ['ok' => true];
  }
  if ($op === 'kick') {
    if ((int)$c['director_id'] === $pid) fail('corp_transfer_first');
    q('DELETE FROM corp_members WHERE corp_id = ? AND player_id = ?', [$id, $pid]);
    alog('corp_member', $c['name'], 'виключено: ' . $m['nick']);
    clog($id, $pid, 'kick', $m['nick']);
    return ['ok' => true];
  }
  fail('bad_op');
}
