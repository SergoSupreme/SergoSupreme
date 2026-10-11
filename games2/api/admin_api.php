<?php
// ============================================================
//  API адмін-панелі (дії adm_*). Доступ лише гравцям з players.access = 1.
//  Підключається з index.php, працює всередині його try/catch.
// ============================================================
if (!defined('MIN_CLIENT')) { http_response_code(403); exit; }

$adm = me(); ensureSchema();
if ((int)($adm['access'] ?? 0) !== 1) fail('forbidden', 403);
rate('adm:' . $adm['id'], 240, 60);
// будь-яка зміна адміністратора (новини, акції, банер) одразу видна всім — скидаємо спільний кеш
if (!preg_match('/^adm_(stats|players|player|log|econ|list)/', (string)$a)) cacheDrop('beat_global');

function alog(string $action, $target = null, $info = null) {
  global $adm;
  q('INSERT INTO admin_log (admin_id, action, target, info) VALUES (?, ?, ?, ?)', [$adm['id'], $action, $target === null ? null : mb_substr((string)$target, 0, 64), $info === null ? null : mb_substr(is_string($info) ? $info : json_encode($info, JSON_UNESCAPED_UNICODE), 0, 500)]);
}
function target(): array { $id = (int)arg('id'); $p = $id ? q('SELECT * FROM players WHERE id = ?', [$id])->fetch() : null; if (!$p) fail('not_found', 404); return $p; }
// Дозволені подарунки
function cleanRewards($list): array {
  $out = [];
  foreach ((array)$list as $r) {
    if (!is_array($r)) continue;
    $k = (string)($r['kind'] ?? ''); $n = (int)($r['n'] ?? 0);
    if (in_array($k, ['coins', 'bucks', 'keys', 'xp'], true) && $n > 0) $out[] = ['kind' => $k, 'n' => min($n, 1000000000)];
    if ($k === 'chest' && in_array($r['id'] ?? '', ['wood', 'blue', 'purple', 'red'], true)) $out[] = ['kind' => 'chest', 'id' => $r['id'], 'n' => max(1, min(10, $n ?: 1))];
  }
  return array_slice($out, 0, 6);
}
// Ряд по днях за N днів (порожні дні = 0)
function series(array $rows, int $days): array {
  $m = []; foreach ($rows as $r) $m[$r['d']] = (float)$r['v'];
  $out = [];
  for ($i = $days - 1; $i >= 0; $i--) { $d = gmdate('Y-m-d', time() - $i * 86400); $out[] = ['d' => $d, 'v' => $m[$d] ?? 0]; }
  return $out;
}

switch ($a) {

  // ---------- огляд ----------
  case 'adm_overview': {
    // знімок економіки за сьогодні (для графіка «економіка по днях»)
    q('INSERT INTO econ_daily (day, players, coins, bucks) SELECT UTC_DATE(), COUNT(*), COALESCE(SUM(coins), 0), COALESCE(SUM(bucks), 0) FROM player_stats
       ON DUPLICATE KEY UPDATE players = VALUES(players), coins = VALUES(coins), bucks = VALUES(bucks)');
    $k = q('SELECT (SELECT COUNT(*) FROM players) players,
                   (SELECT COUNT(*) FROM players WHERE last_seen > NOW() - INTERVAL 10 MINUTE) online,
                   (SELECT COUNT(*) FROM players WHERE last_seen > NOW() - INTERVAL 1 DAY) dau,
                   (SELECT COUNT(*) FROM players WHERE created_at > NOW() - INTERVAL 1 DAY) new24,
                   (SELECT COUNT(*) FROM players WHERE banned = 1) banned,
                   (SELECT COUNT(*) FROM players WHERE (chat_until > NOW()) OR (dm_until > NOW())) muted,
                   (SELECT COALESCE(SUM(coins), 0) FROM player_stats) coins,
                   (SELECT COALESCE(SUM(bucks), 0) FROM player_stats) bucks,
                   (SELECT COALESCE(AVG(level), 0) FROM player_stats) avglvl,
                   (SELECT COUNT(*) FROM chat_messages WHERE created_at > NOW() - INTERVAL 1 DAY) chat24,
                   (SELECT COUNT(*) FROM dm_messages WHERE created_at > NOW() - INTERVAL 1 DAY) dm24,
                   (SELECT COUNT(*) FROM promos WHERE active = 1 AND ends_at > NOW()) promos,
                   (SELECT COUNT(*) FROM players WHERE last_seen > NOW() - INTERVAL 7 DAY) wau,
                   (SELECT COUNT(*) FROM corps) corps,
                   (SELECT COUNT(DISTINCT player_id) FROM (SELECT player_id FROM push_tokens UNION SELECT player_id FROM push_web) pt) apps,
                   (SELECT COUNT(*) FROM donations WHERE status = "pending") dpend,
                   (SELECT COALESCE(SUM(uah), 0) FROM donations WHERE status = "done" AND created_at > NOW() - INTERVAL 30 DAY) uah30')->fetch();
    $days = 30;
    $hours = q('SELECT HOUR(t) h, COUNT(DISTINCT player_id) v FROM balance_log WHERE t > NOW() - INTERVAL 7 DAY GROUP BY h')->fetchAll();
    $ret = q('SELECT SUM(created_at < NOW() - INTERVAL 1 DAY) c1, SUM(created_at < NOW() - INTERVAL 1 DAY AND last_seen >= created_at + INTERVAL 1 DAY) r1,
                     SUM(created_at < NOW() - INTERVAL 7 DAY) c7, SUM(created_at < NOW() - INTERVAL 7 DAY AND last_seen >= created_at + INTERVAL 7 DAY) r7 FROM players WHERE created_at > NOW() - INTERVAL 90 DAY')->fetch();
    $onl = q('SELECT t, n FROM online_log WHERE t > ? ORDER BY t', [time() - 86400])->fetchAll();
    $reg = series(q('SELECT DATE(created_at) d, COUNT(*) v FROM players WHERE created_at > NOW() - INTERVAL 30 DAY GROUP BY d')->fetchAll(), $days);
    $act = series(q('SELECT DATE(t) d, COUNT(DISTINCT player_id) v FROM balance_log WHERE t > NOW() - INTERVAL 30 DAY GROUP BY d')->fetchAll(), $days);
    $eco = q('SELECT day d, coins, bucks FROM econ_daily WHERE day > UTC_DATE() - INTERVAL 30 DAY ORDER BY day')->fetchAll();
    $lv = q('SELECT CASE WHEN level < 5 THEN "1–4" WHEN level < 10 THEN "5–9" WHEN level < 20 THEN "10–19" WHEN level < 30 THEN "20–29" WHEN level < 50 THEN "30–49" ELSE "50+" END b, COUNT(*) n
             FROM player_stats GROUP BY b')->fetchAll();
    $order = ['1–4', '5–9', '10–19', '20–29', '30–49', '50+']; $lvm = array_column($lv, 'n', 'b');
    $top = q('SELECT p.id, p.nick, p.avatar, s.level, s.bucks, s.coins FROM player_stats s JOIN players p ON p.id = s.player_id ORDER BY s.bucks DESC LIMIT 8')->fetchAll();
    out(['ok' => true, 'kpi' => $k, 'reg' => $reg, 'active' => $act, 'hours' => $hours, 'ret' => $ret, 'onl' => $onl,
      'eco' => array_map(fn($r) => ['d' => $r['d'], 'coins' => (float)$r['coins'], 'bucks' => (float)$r['bucks']], $eco),
      'levels' => array_map(fn($b) => ['b' => $b, 'n' => (int)($lvm[$b] ?? 0)], $order), 'top' => $top]);
  }

  // ---------- гравці ----------
  case 'adm_players': {
    $qs = trim((string)arg('q', '')); $page = max(0, (int)arg('page', 0)); $per = 30;
    $sort = ['seen' => 'p.last_seen DESC', 'new' => 'p.id DESC', 'level' => 's.level DESC', 'bucks' => 's.bucks DESC', 'coins' => 's.coins DESC'][(string)arg('sort', 'seen')] ?? 'p.last_seen DESC';
    $flt = (string)arg('f', 'all');
    $w = []; $pr = [];
    if ($qs !== '') { if (ctype_digit($qs)) { $w[] = '(p.id = ? OR p.nick_lc LIKE ?)'; $pr[] = (int)$qs; } else $w[] = 'p.nick_lc LIKE ?'; $pr[] = '%' . mb_strtolower($qs) . '%'; }
    if ($flt === 'online') $w[] = 'p.last_seen > NOW() - INTERVAL 10 MINUTE';
    if ($flt === 'banned') $w[] = 'p.banned = 1';
    if ($flt === 'muted') $w[] = '(p.chat_until > NOW() OR p.dm_until > NOW())';
    if ($flt === 'admins') $w[] = 'p.access = 1';
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $total = (int)q("SELECT COUNT(*) FROM players p $where", $pr)->fetchColumn();
    $rows = q("SELECT p.id, p.nick, p.avatar, p.access, p.banned, p.chat_until > NOW() chatban, p.dm_until > NOW() dmban, UNIX_TIMESTAMP(p.last_seen) seen, UNIX_TIMESTAMP(p.created_at) created,
                      COALESCE(s.level, 1) level, COALESCE(s.coins, 0) coins, COALESCE(s.bucks, 0) bucks, COALESCE(s.floors, 0) floors
               FROM players p LEFT JOIN player_stats s ON s.player_id = p.id $where ORDER BY $sort LIMIT $per OFFSET " . ($page * $per), $pr)->fetchAll();
    out(['ok' => true, 'total' => $total, 'per' => $per, 'items' => $rows]);
  }
  case 'adm_player': {
    $p = target(); $id = (int)$p['id'];
    $st = q('SELECT * FROM player_stats WHERE player_id = ?', [$id])->fetch() ?: [];
    $sv = q('SELECT rev, data, UNIX_TIMESTAMP(updated_at) t FROM saves WHERE player_id = ?', [$id])->fetch();
    $d = $sv ? json_decode($sv['data'], true) : [];
    $bal = q('SELECT UNIX_TIMESTAMP(t) t, coins, bucks, level FROM balance_log WHERE player_id = ? AND t > NOW() - INTERVAL 30 DAY ORDER BY t', [$id])->fetchAll();
    out(['ok' => true, 'player' => [
      'id' => $id, 'nick' => $p['nick'], 'avatar' => $p['avatar'], 'access' => (int)$p['access'], 'banned' => (int)$p['banned'],
      'chat_until' => $p['chat_until'] && strtotime($p['chat_until'] . ' UTC') > time() ? strtotime($p['chat_until'] . ' UTC') : null, 'chat_reason' => $p['chat_reason'],
      'dm_until' => $p['dm_until'] && strtotime($p['dm_until'] . ' UTC') > time() ? strtotime($p['dm_until'] . ' UTC') : null, 'dm_reason' => $p['dm_reason'],
      'ban_until' => $p['ban_until'] ? strtotime($p['ban_until'] . ' UTC') : null, 'ban_reason' => $p['ban_reason'], 'lang' => $p['lang'],
      'created' => strtotime($p['created_at'] . ' UTC'), 'seen' => strtotime($p['last_seen'] . ' UTC'), 'hasPassword' => !empty($p['pass_hash']),
      'sessions' => (int)q('SELECT COUNT(*) FROM sessions WHERE player_id = ?', [$id])->fetchColumn(),
    ], 'save' => [
      'rev' => $sv ? (int)$sv['rev'] : 0, 't' => $sv ? (int)$sv['t'] : 0,
      'coins' => (float)($d['coins'] ?? 0), 'bucks' => (int)($d['bucks'] ?? 0), 'keys' => (int)($d['maze']['keys'] ?? 0), 'xp' => (int)($d['xp'] ?? 0), 'level' => (int)($d['level'] ?? 1),
      'floors' => max(0, count($d['floors'] ?? []) - 1), 'residents' => count($d['residents'] ?? []), 'cups' => count($d['cups'] ?? []),
      'earned' => (float)($d['stats']['earned'] ?? 0),
    ], 'stats' => $st, 'balance' => array_map(fn($r) => ['t' => (int)$r['t'], 'coins' => (float)$r['coins'], 'bucks' => (int)$r['bucks'], 'level' => (int)$r['level']], $bal),
      'friends' => (int)q('SELECT COUNT(*) FROM friends WHERE player_id = ?', [$id])->fetchColumn(),
      'hist' => q('SELECT kind, info, UNIX_TIMESTAMP(created_at) t FROM history WHERE player_id = ? ORDER BY id DESC LIMIT 25', [$id])->fetchAll(),
      'gifts' => (int)q('SELECT COUNT(*) FROM gifts WHERE to_id = ?', [$id])->fetchColumn(),
      'dons' => q('SELECT COUNT(*) n, COALESCE(SUM(uah), 0) uah FROM donations WHERE player_id = ? AND status = "done"', [$id])->fetch(),
      'corp' => (string)(q('SELECT c.name FROM corp_members m JOIN corps c ON c.id = m.corp_id WHERE m.player_id = ?', [$id])->fetchColumn() ?: '')]);
  }
  case 'adm_player_save': {
    $p = target(); $id = (int)$p['id']; $ch = [];
    // обліковий запис
    $nick = arg('nick');
    if ($nick !== null && $nick !== $p['nick']) {
      $nick = cleanNick($nick);
      if (q('SELECT 1 FROM players WHERE nick_lc = ? AND id <> ?', [mb_strtolower($nick), $id])->fetchColumn()) fail('nick_taken');
      q('UPDATE players SET nick = ?, nick_lc = ? WHERE id = ?', [$nick, mb_strtolower($nick), $id]); $ch[] = "нік: {$p['nick']} → $nick";
    }
    $pass = (string)arg('password', '');
    if ($pass !== '') { if (mb_strlen($pass) < 6) fail('password_short'); q('UPDATE players SET pass_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]); q('DELETE FROM sessions WHERE player_id = ?', [$id]); $ch[] = 'новий пароль'; }
    $acc = arg('access');
    if ($acc !== null && (int)$acc !== (int)$p['access']) { if ($id === (int)$adm['id'] && (int)$acc !== 1) fail('self_access'); q('UPDATE players SET access = ? WHERE id = ?', [(int)$acc ? 1 : 0, $id]); $ch[] = 'access=' . ((int)$acc ? 1 : 0); }
    // баланси в збереженні гри
    $fields = ['coins' => 'coins', 'bucks' => 'bucks', 'xp' => 'xp', 'level' => 'level', 'keys' => 'keys'];
    $bal = [];
    foreach ($fields as $f) { $v = arg($f); if ($v !== null && $v !== '') $bal[$f] = max(0, (float)$v); }
    if ($bal) {
      db()->beginTransaction();
      $sv = q('SELECT rev, data FROM saves WHERE player_id = ? FOR UPDATE', [$id])->fetch();
      // декодуємо як об'єкти, щоб порожні {} у збереженні не перетворились на [] (інакше гра губить дані)
      $d = $sv ? json_decode($sv['data']) : null;
      if (!is_object($d) || empty($d->floors)) { db()->rollBack(); fail('no_save'); }
      if (!isset($d->maze) || !is_object($d->maze)) $d->maze = (object)['keys' => 0];
      foreach ($bal as $f => $v) {
        $old = $f === 'keys' ? ($d->maze->keys ?? 0) : ($d->$f ?? 0);
        if ((float)$old == $v) continue;
        if ($f === 'keys') $d->maze->keys = (int)$v; elseif ($f === 'coins') $d->coins = $v; else $d->$f = (int)max($f === 'level' ? 1 : 0, $v);
        $ch[] = "$f: " . (0 + $old) . ' → ' . (0 + $v);
      }
      if ($nick !== null && isset($d->profile) && is_object($d->profile)) $d->profile->name = $nick;
      // новий rev — гра гравця сама підтягне змінений стан з бази
      q('UPDATE saves SET rev = rev + 1, data = ? WHERE player_id = ?', [json_encode($d, JSON_UNESCAPED_UNICODE), $id]);
      q('UPDATE player_stats SET coins = ?, bucks = ?, xp = ?, level = ?, maze_keys = ? WHERE player_id = ?', [(float)($d->coins ?? 0), (int)($d->bucks ?? 0), (int)($d->xp ?? 0), (int)($d->level ?? 1), (int)($d->maze->keys ?? 0), $id]);
      q('INSERT INTO balance_log (player_id, coins, bucks, level) VALUES (?, ?, ?, ?)', [$id, (float)($d->coins ?? 0), (int)($d->bucks ?? 0), (int)($d->level ?? 1)]);
      db()->commit();
    }
    if ($ch) { alog('edit', $p['nick'], implode('; ', $ch)); q('INSERT INTO history (player_id, kind, info) VALUES (?, "admin", ?)', [$id, mb_substr(implode('; ', $ch), 0, 255)]); }
    out(['ok' => true, 'changes' => $ch]);
  }
  case 'adm_ban': {
    $p = target(); if ((int)$p['id'] === (int)$adm['id']) fail('self');
    $v = (int)arg('v', 1); $hours = (int)arg('hours', 0); $reason = mb_substr(trim((string)arg('reason', '')), 0, 200);
    q('UPDATE players SET banned = ?, ban_until = ?, ban_reason = ? WHERE id = ?', [$v ? 1 : 0, $v && $hours > 0 ? gmdate('Y-m-d H:i:s', time() + $hours * 3600) : null, $v ? ($reason ?: null) : null, $p['id']]);
    alog($v ? 'ban' : 'unban', $p['nick'], $v ? (($hours ? "$hours год" : 'назавжди') . ($reason ? " · $reason" : '')) : null);
    out(['ok' => true]);
  }
  // Санкції: block — блокування акаунта, chat — бан у чаті й на форумі, dm — бан в особистих повідомленнях
  case 'adm_sanction': {
    $p = target(); if ((int)$p['id'] === (int)$adm['id']) fail('self');
    $kind = (string)arg('kind'); if (!in_array($kind, ['block', 'chat', 'dm'], true)) fail('bad_kind');
    $hours = (int)arg('hours', 0); if (!in_array($hours, [0, 1, 6, 12, 24, 72, 168, 720], true)) fail('bad_term');
    $reason = mb_substr(trim((string)arg('reason', '')), 0, 200); if (mb_strlen($reason) < 3) fail('need_reason');
    $until = $hours ? gmdate('Y-m-d H:i:s', time() + $hours * 3600) : null;
    if ($kind === 'block') q('UPDATE players SET banned = 1, ban_until = ?, ban_reason = ? WHERE id = ?', [$until, $reason, $p['id']]);
    else q("UPDATE players SET {$kind}_until = ?, {$kind}_reason = ? WHERE id = ?", [$until ?? '9999-12-31 00:00:00', $reason, $p['id']]);
    $term = $hours ? ($hours < 24 ? "$hours год" : ($hours / 24) . ' дн') : 'назавжди';
    $what = ['block' => 'Ваш акаунт заблоковано', 'chat' => 'Вам заборонено писати в загальний чат і на форум', 'dm' => 'Вам заборонено писати особисті повідомлення'][$kind];
    $till = '';
    q('INSERT INTO mail (player_id, sender, icon, title, body) VALUES (?, ?, ?, ?, ?)', [$p['id'], 'Система', 'lock',
      ['block' => 'Блокування акаунта', 'chat' => 'Бан у чаті', 'dm' => 'Бан в особистих повідомленнях'][$kind], "$what на термін: $term$till.\nПричина: $reason"]);
    alog($kind === 'block' ? 'ban' : ($kind === 'chat' ? 'mute' : 'dmban'), $p['nick'], "$term · $reason");
    out(['ok' => true]);
  }
  case 'adm_unsanction': {
    $p = target(); $kind = (string)arg('kind');
    if ($kind === 'block') q('UPDATE players SET banned = 0, ban_until = NULL, ban_reason = NULL WHERE id = ?', [$p['id']]);
    elseif (in_array($kind, ['chat', 'dm'], true)) q("UPDATE players SET {$kind}_until = NULL, {$kind}_reason = NULL WHERE id = ?", [$p['id']]);
    else fail('bad_kind');
    q('INSERT INTO mail (player_id, sender, icon, title, body) VALUES (?, ?, ?, ?, ?)', [$p['id'], 'Система', 'lock',
      ['block' => 'Блокування знято', 'chat' => 'Бан у чаті знято', 'dm' => 'Бан в особистих знято'][$kind], 'Адміністрація зняла обмеження. Дякуємо, що дотримуєтесь правил!']);
    alog($kind === 'block' ? 'unban' : 'unmute', $p['nick'], $kind);
    out(['ok' => true]);
  }
  case 'adm_mute': {
    $p = target(); $v = (int)arg('v', 1);
    q('UPDATE players SET muted = ? WHERE id = ?', [$v ? 1 : 0, $p['id']]);
    alog($v ? 'mute' : 'unmute', $p['nick']); out(['ok' => true]);
  }
  case 'adm_kick': { $p = target(); q('DELETE FROM sessions WHERE player_id = ?', [$p['id']]); alog('kick', $p['nick']); out(['ok' => true]); }
  case 'adm_reset': {
    $p = target();
    q('UPDATE saves SET rev = rev + 1, data = "{}" WHERE player_id = ?', [$p['id']]);
    q('UPDATE player_stats SET level = 1, xp = 0, coins = 0, bucks = 0, floors = 0, businesses = 0, residents = 0, cups = 0, earned = 0 WHERE player_id = ?', [$p['id']]);
    alog('reset', $p['nick']); out(['ok' => true]);
  }
  case 'adm_delete': {
    $p = target(); if ((int)$p['id'] === (int)$adm['id']) fail('self');
    q('DELETE FROM players WHERE id = ?', [$p['id']]);
    alog('delete', $p['nick'], 'id ' . $p['id']); out(['ok' => true]);
  }

  // ---------- роздачі ----------
  case 'adm_give': {
    $rw = cleanRewards(arg('rewards', [])); if (!$rw) fail('no_rewards');
    $title = mb_substr(trim((string)arg('title', '')), 0, 120) ?: 'Подарунок від адміністрації';
    $body = mb_substr(trim((string)arg('text', '')), 0, 1000) ?: 'Тримай подарунок!';
    $to = trim((string)arg('to', ''));
    $pid = null;
    if ($to !== '') { $pid = q('SELECT id FROM players WHERE nick_lc = ? OR id = ?', [mb_strtolower($to), ctype_digit($to) ? (int)$to : 0])->fetchColumn(); if (!$pid) fail('not_found', 404); }
    q('INSERT INTO mail (player_id, sender, icon, title, body, reward) VALUES (?, ?, ?, ?, ?, ?)', [$pid ?: null, 'Адміністрація', 'gift_red', $title, $body, json_encode(count($rw) === 1 ? $rw[0] : $rw)]);
    // щоб роздачу «всім» отримали й ті, хто зареєструвався раніше (лист для всіх видно лише з дати створення акаунта) — дата листа = зараз, тож видно всім існуючим
    if ((int)arg('push', 1)) { if ($pid) pushTo((int)$pid, 'give', [], 'mail', 'give'); else pushAll('give', [], 'mail'); }
    alog('give', $pid ? $to : 'всім', $rw); out(['ok' => true]);
  }
  // Історія — суто роздачі адміністрації. Нагороди корпорацій, турнірів, донатів і подарунки гравців сюди не потрапляють
  case 'adm_gives': {
    out(['ok' => true, 'items' => q("SELECT m.id, m.title, m.reward, UNIX_TIMESTAMP(m.created_at) t, p.nick, (SELECT COUNT(*) FROM mail_claims c WHERE c.mail_id = m.id) claims
                                     FROM mail m LEFT JOIN players p ON p.id = m.player_id
                                     WHERE m.reward IS NOT NULL AND m.sender NOT IN ('Корпорація', 'Магазин', 'Турнір', 'Система')
                                       AND m.reward NOT LIKE '%corpinv%' ORDER BY m.id DESC LIMIT 30")->fetchAll()]);
  }

  // ---------- корпорації ----------
  case 'adm_corps': {
    $qs = trim((string)arg('q', '')); $page = max(0, (int)arg('page', 0)); $per = 20;
    $w = ''; $pr = [];
    if ($qs !== '') { $w = 'WHERE c.name_lc LIKE ? OR c.id = ?'; $pr = ['%' . mb_strtolower($qs) . '%', ctype_digit($qs) ? (int)$qs : 0]; }
    $total = (int)q("SELECT COUNT(*) FROM corps c $w", $pr)->fetchColumn();
    $rows = q("SELECT c.id, c.name, c.emblem, c.lvl, c.xp, c.bucks, c.coins, c.tasks_done, c.sets, UNIX_TIMESTAMP(c.created_at) t,
                      p.nick director, p.id director_id, (SELECT COUNT(*) FROM corp_members m WHERE m.corp_id = c.id) members
               FROM corps c LEFT JOIN players p ON p.id = c.director_id $w ORDER BY c.lvl DESC, c.id ASC LIMIT $per OFFSET " . ($page * $per), $pr)->fetchAll();
    out(['ok' => true, 'total' => $total, 'per' => $per, 'items' => $rows]);
  }
  case 'adm_corp': {
    require_once __DIR__ . '/corp_api_admin.php';
    out(['ok' => true] + admCorpCard((int)arg('id')));
  }
  case 'adm_corp_save': {
    require_once __DIR__ . '/corp_api_admin.php';
    out(admCorpSave((int)arg('id')));
  }
  case 'adm_corp_member': {
    require_once __DIR__ . '/corp_api_admin.php';
    out(admCorpMember((int)arg('id'), (int)arg('pid'), (string)arg('op'), (int)arg('role', 0)));
  }
  case 'adm_corp_del': {
    $id = (int)arg('id'); $n = (string)q('SELECT name FROM corps WHERE id = ?', [$id])->fetchColumn();
    if ($n === '') fail('not_found', 404);
    q('DELETE FROM corps WHERE id = ?', [$id]); alog('corp_del', $n);
    out(['ok' => true]);
  }

  // ---------- бонус-коди ----------
  case 'adm_codes': {
    require_once __DIR__ . '/codes.php';
    out(['ok' => true, 'items' => array_map('codeRow', q('SELECT * FROM bonus_codes ORDER BY active DESC, id DESC LIMIT 100')->fetchAll())]);
  }
  case 'adm_code_save': {
    require_once __DIR__ . '/codes.php';
    $id = (int)arg('id', 0);
    $code = codeNorm((string)arg('code', ''));
    if (!codeValid($code)) fail('code_chars');
    $rw = cleanRewards(arg('rewards', [])); if (!$rw) fail('no_rewards');
    $max = max(0, min(1000000, (int)arg('max', 0)));
    $act = (int)arg('active', 1) ? 1 : 0;
    $note = mb_substr(trim((string)arg('note', '')), 0, 120);
    $exp = max(0, (int)arg('expires', 0));
    // код не має збігатися з чужим бонус-кодом або з кодом запрошення гравця
    if (q('SELECT 1 FROM bonus_codes WHERE code = ? AND id <> ?', [$code, $id])->fetchColumn()) fail('code_exists');
    if (q('SELECT 1 FROM players WHERE invite_code = ?', [$code])->fetchColumn()) fail('code_exists');
    if ($id) {
      q('UPDATE bonus_codes SET code = ?, reward = ?, max_uses = ?, active = ?, note = ?, expires_at = ? WHERE id = ?',
        [$code, json_encode($rw, JSON_UNESCAPED_UNICODE), $max, $act, $note ?: null, $exp, $id]);
    } else {
      q('INSERT INTO bonus_codes (code, reward, max_uses, active, note, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$code, json_encode($rw, JSON_UNESCAPED_UNICODE), $max, $act, $note ?: null, $exp]);
      $id = (int)db()->lastInsertId();
    }
    alog('code', $code, $rw);
    out(['ok' => true, 'id' => $id]);
  }
  case 'adm_code_act': {
    $id = (int)arg('id'); q('UPDATE bonus_codes SET active = ? WHERE id = ?', [(int)arg('v', 0) ? 1 : 0, $id]);
    alog((int)arg('v', 0) ? 'code_on' : 'code_off', (string)q('SELECT code FROM bonus_codes WHERE id = ?', [$id])->fetchColumn());
    out(['ok' => true]);
  }
  case 'adm_code_del': {
    $id = (int)arg('id'); $c = (string)q('SELECT code FROM bonus_codes WHERE id = ?', [$id])->fetchColumn();
    q('DELETE FROM bonus_codes WHERE id = ?', [$id]); alog('code_del', $c);
    out(['ok' => true]);
  }

  // ---------- акції ----------
  case 'adm_promos': {
    out(['ok' => true, 'items' => array_map(fn($r) => $r + ['rewards_list' => json_decode((string)$r['rewards'], true) ?: []],
      q('SELECT pr.id, pr.title, pr.body, pr.icon, pr.color, pr.rewards, pr.active, pr.type, pr.cfg, UNIX_TIMESTAMP(pr.starts_at) s, UNIX_TIMESTAMP(pr.ends_at) e,
                (SELECT COUNT(*) FROM promo_claims c WHERE c.promo_id = pr.id) claims FROM promos pr ORDER BY pr.id DESC LIMIT 50')->fetchAll())]);
  }
  case 'adm_promo_save': {
    $id = (int)arg('id', 0);
    $title = mb_substr(trim((string)arg('title', '')), 0, 120); if ($title === '') fail('empty');
    $body = mb_substr(trim((string)arg('text', '')), 0, 2000);
    $icon = preg_replace('/[^a-z0-9_]/', '', (string)arg('icon', 'gift_red')) ?: 'gift_red';
    $color = preg_match('/^#[0-9a-f]{6}$/i', (string)arg('color', '')) ? arg('color') : '#7a4fe0';
    $rw = cleanRewards(arg('rewards', []));
    $type = arg('type') === 'donate' ? 'donate' : 'discount';
    $pct = max(1, min($type === 'donate' ? 500 : 90, (int)arg('pct', 10)));
    $cats = array_values(array_intersect((array)arg('cats', []), ['build', 'upgrade', 'tech', 'services', 'staff', 'chests', 'keys', 'lotto', 'skip', 'pass']));
    if ($type === 'discount' && !$cats) fail('no_cats');
    $cfg = json_encode($type === 'donate' ? ['pct' => $pct, 'pack' => max(-1, min(5, (int)arg('pack', -1)))] : ['pct' => $pct, 'cats' => $cats]);
    if ($type === 'discount') $rw = [];
    $start = (int)arg('start', time()); $end = (int)arg('end', time() + 86400 * 3); if ($end <= $start) fail('bad_dates');
    $vals = [$title, $body, $icon, $color, json_encode($rw), gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end), (int)arg('active', 1) ? 1 : 0, $type, $cfg];
    if ($id) q('UPDATE promos SET title = ?, body = ?, icon = ?, color = ?, rewards = ?, starts_at = ?, ends_at = ?, active = ?, type = ?, cfg = ? WHERE id = ?', [...$vals, $id]);
    else { q('INSERT INTO promos (title, body, icon, color, rewards, starts_at, ends_at, active, type, cfg) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $vals); $id = (int)db()->lastInsertId(); }
    if ((int)arg('push', 0) && (int)arg('active', 1)) pushAll('promo', ['title' => $title], 'promo');
    alog('promo', $title, $cfg); out(['ok' => true, 'id' => $id]);
  }
  case 'adm_promo_del': { $id = (int)arg('id'); q('DELETE FROM promos WHERE id = ?', [$id]); alog('promo_del', $id); out(['ok' => true]); }

  // ---------- новини ----------
  case 'adm_news': {
    out(['ok' => true, 'items' => q('SELECT id, icon, title, body, published, UNIX_TIMESTAMP(created_at) t FROM news ORDER BY id DESC LIMIT 50')->fetchAll()]);
  }
  case 'adm_news_save': {
    $id = (int)arg('id', 0); $title = mb_substr(trim((string)arg('title', '')), 0, 160); if ($title === '') fail('empty');
    $body = mb_substr(trim((string)arg('text', '')), 0, 4000); $icon = preg_replace('/[^a-z0-9_]/', '', (string)arg('icon', 'megaphone2')) ?: 'megaphone2';
    if ($id) q('UPDATE news SET title = ?, body = ?, icon = ?, published = ? WHERE id = ?', [$title, $body, $icon, (int)arg('published', 1), $id]);
    else q('INSERT INTO news (icon, title, body) VALUES (?, ?, ?)', [$icon, $title, $body]);
    if ((int)arg('push', 0)) pushAll('news', ['title' => $title], 'news');
    alog('news', $title); out(['ok' => true]);
  }
  case 'adm_news_del': { q('DELETE FROM news WHERE id = ?', [(int)arg('id')]); alog('news_del', (int)arg('id')); out(['ok' => true]); }

  // ---------- модерація чату ----------
  case 'adm_chat': {
    out(['ok' => true, 'items' => q('SELECT c.id, c.body, UNIX_TIMESTAMP(c.created_at) t, p.id pid, p.nick, p.avatar, p.chat_until > NOW() muted FROM chat_messages c JOIN players p ON p.id = c.player_id ORDER BY c.id DESC LIMIT 60')->fetchAll()]);
  }
  case 'adm_chat_del': { q('DELETE FROM chat_messages WHERE id = ?', [(int)arg('id')]); alog('chat_del', (int)arg('id')); out(['ok' => true]); }

  // ---------- онлайн ----------
  case 'adm_online': {
    $hist = q('SELECT t, n FROM online_log WHERE t > ? ORDER BY t', [time() - 86400])->fetchAll();
    $peak = q('SELECT MAX(n) FROM online_log WHERE t > ?', [time() - 86400])->fetchColumn();
    $peak7 = q('SELECT MAX(n) FROM online_log WHERE t > ?', [time() - 7 * 86400])->fetchColumn();
    out(['ok' => true, 'hist' => $hist, 'peak' => (int)$peak, 'peak7' => (int)$peak7, 'dau' => (int)q('SELECT COUNT(*) FROM players WHERE last_seen > NOW() - INTERVAL 1 DAY')->fetchColumn(), 'items' => q('SELECT p.id, p.nick, p.avatar, p.access, UNIX_TIMESTAMP(p.last_seen) seen, COALESCE(s.level, 1) level, COALESCE(s.bucks, 0) bucks, COALESCE(s.coins, 0) coins, COALESCE(s.floors, 0) floors
      FROM players p LEFT JOIN player_stats s ON s.player_id = p.id WHERE p.last_seen > NOW() - INTERVAL 10 MINUTE ORDER BY p.last_seen DESC LIMIT 100')->fetchAll()]);
  }
  // ---------- подарунки ----------
  case 'adm_gifts': {
    $per = 10; $page = max(0, (int)arg('page', 0));
    $st = q('SELECT COUNT(*) n, COALESCE(SUM(price), 0) sum, COUNT(DISTINCT from_id) senders FROM gifts')->fetch();
    $top = q('SELECT gift, COUNT(*) n FROM gifts GROUP BY gift ORDER BY n DESC LIMIT 6')->fetchAll();
    out(['ok' => true, 'stats' => $st, 'top' => $top, 'total' => (int)$st['n'], 'per' => $per, 'items' => q("SELECT g.id, g.gift, g.price, g.body, UNIX_TIMESTAMP(g.created_at) t, f.id fid, f.nick fnick, f.avatar favatar, r.id tid, r.nick tnick, r.avatar tavatar
      FROM gifts g JOIN players f ON f.id = g.from_id JOIN players r ON r.id = g.to_id ORDER BY g.id DESC LIMIT $per OFFSET " . ($page * $per))->fetchAll()]);
  }
  // ---------- донат: заявки ----------
  case 'adm_dons': {
    $per = 10; $page = max(0, (int)arg('page', 0)); $st = arg('st') === 'all' ? 'all' : 'pending';
    $w = $st === 'all' ? '1' : "d.status = 'pending'";
    $total = (int)q("SELECT COUNT(*) FROM donations d WHERE $w")->fetchColumn();
    $sum = q("SELECT SUM(status = 'pending') pending, COALESCE(SUM(IF(status = 'done', bucks, 0)), 0) bucks, COALESCE(SUM(IF(status = 'done', uah, 0)), 0) uah, SUM(status = 'done') done FROM donations")->fetch();
    $sum += q("SELECT COALESCE(SUM(IF(status = 'done' AND done_at > NOW() - INTERVAL 1 DAY, uah, 0)), 0) uah24, COALESCE(SUM(IF(status = 'done' AND done_at > NOW() - INTERVAL 30 DAY, uah, 0)), 0) uah30,
      COUNT(DISTINCT IF(status = 'done', player_id, NULL)) donors, SUM(status = 'rejected') rejected FROM donations")->fetch();
    $ch = series(q("SELECT DATE(done_at) d, SUM(uah) v FROM donations WHERE status = 'done' AND done_at > NOW() - INTERVAL 30 DAY GROUP BY d")->fetchAll(), 30);
    $cn = series(q("SELECT DATE(created_at) d, COUNT(*) v FROM donations WHERE created_at > NOW() - INTERVAL 30 DAY GROUP BY d")->fetchAll(), 30);
    $packs = q("SELECT pack, COUNT(*) n, SUM(uah) uah FROM donations WHERE status = 'done' GROUP BY pack ORDER BY pack")->fetchAll();
    $top = q("SELECT p.id, p.nick, p.avatar, SUM(d.uah) uah, COUNT(*) n FROM donations d JOIN players p ON p.id = d.player_id WHERE d.status = 'done' GROUP BY p.id ORDER BY uah DESC LIMIT 5")->fetchAll();
    out(['ok' => true, 'total' => $total, 'per' => $per, 'stats' => $sum, 'chart' => $ch, 'cnt' => $cn, 'packs' => $packs, 'top' => $top, 'items' => q("SELECT d.id, d.pack, d.bucks, d.uah, d.bonus_pct, d.bonus_rw, d.status, UNIX_TIMESTAMP(d.created_at) t, UNIX_TIMESTAMP(d.done_at) dt,
      p.id pid, p.nick, p.avatar FROM donations d JOIN players p ON p.id = d.player_id WHERE $w ORDER BY d.id DESC LIMIT $per OFFSET " . ($page * $per))->fetchAll()]);
  }
  case 'adm_don_set': {
    $id = (int)arg('id'); $ok = (bool)arg('ok', false);
    db()->beginTransaction();
    $d = q('SELECT d.*, p.nick FROM donations d JOIN players p ON p.id = d.player_id WHERE d.id = ? FOR UPDATE', [$id])->fetch();
    if (!$d) { db()->rollBack(); fail('not_found', 404); }
    if ($d['status'] !== 'pending') { db()->rollBack(); fail('don_done'); }
    q('UPDATE donations SET status = ?, admin_id = ?, done_at = NOW() WHERE id = ?', [$ok ? 'done' : 'rejected', $adm['id'], $id]);
    $pid = (int)$d['player_id']; $b = (int)$d['bucks'];
    $mail = fn($icon, $title, $body, $rw) => q('INSERT INTO mail (player_id, sender, icon, title, body, reward) VALUES (?, ?, ?, ?, ?, ?)', [$pid, 'Магазин', $icon, $title, $body, $rw === null ? null : json_encode($rw)]);
    if ($ok) {
      $mail('don_3', 'Банкноти нараховано!', "Дякуємо за підтримку гри! Оплату заявки №$id підтверджено — забери свої " . number_format($b, 0, '.', ' ') . ' банкнот.', ['kind' => 'bucks', 'n' => $b, 'don' => 1]);
      $extra = []; $pct = (int)$d['bonus_pct'];
      if ($pct > 0) $extra[] = ['kind' => 'bucks', 'n' => (int)round($b * $pct / 100)];
      foreach (json_decode((string)$d['bonus_rw'], true) ?: [] as $r) $extra[] = $r;
      if ($extra) $mail('fireworks', 'Бонус донат-акції!', "До заявки №$id діяла акція" . ($pct ? " +$pct% банкнот" : '') . '. Ось твої додаткові нагороди:', $extra);
      q("INSERT INTO history (player_id, kind, info) VALUES (?, 'donate', ?)", [$pid, (string)$b]);
      pushTo($pid, 'don', [], 'mail', 'don');
      alog('donate_ok', $d['nick'], "№$id · $b банкнот · {$d['uah']} ₴" . ($pct ? " · +$pct%" : ''));
    } else {
      $mail('mail2', 'Заявку на донат відхилено', "Заявку №$id на " . number_format($b, 0, '.', ' ') . ' банкнот відхилено. Якщо ти вже оплатив — напиши адміністратору в особисті повідомлення.', null);
      alog('donate_no', $d['nick'], "№$id · $b банкнот · {$d['uah']} ₴");
    }
    db()->commit();
    out(['ok' => true]);
  }
  case 'adm_gift_del': { q('DELETE FROM gifts WHERE id = ?', [(int)arg('id')]); alog('gift_del', (int)arg('id')); out(['ok' => true]); }
  // ---------- сервер: технічні роботи й оголошення ----------
  case 'adm_settings': {
    if (arg('save')) {
      foreach (['banner' => 300, 'maint_msg' => 500] as $k => $max) q('REPLACE INTO settings (k, v) VALUES (?, ?)', [$k, mb_substr(trim((string)arg($k, '')), 0, $max)]);
      q('REPLACE INTO settings (k, v) VALUES ("maint", ?)', [(int)arg('maint', 0) ? '1' : '0']);
      alog('settings', null, ['maint' => (int)arg('maint', 0), 'banner' => mb_substr((string)arg('banner', ''), 0, 80)]);
    }
    $c = []; foreach (q('SELECT k, v FROM settings')->fetchAll() as $r) $c[$r['k']] = $r['v'];
    $db = q('SELECT (SELECT COUNT(*) FROM players) players, (SELECT COUNT(*) FROM saves) saves, (SELECT COUNT(*) FROM sessions) sessions, (SELECT COUNT(*) FROM chat_messages) chat, (SELECT COUNT(*) FROM dm_messages) dm, (SELECT COUNT(*) FROM gifts) gifts, (SELECT COUNT(*) FROM mail) mail, (SELECT COUNT(*) FROM corps) corps')->fetch();
    $size = q('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    $tables = q('SELECT table_name n, table_rows r, data_length + index_length b FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY b DESC LIMIT 8')->fetchAll();
    $cronF = sys_get_temp_dir() . '/mx_cron_' . md5(cfg('db_name'));
    $srv = ['php' => PHP_VERSION, 'mysql' => (string)q('SELECT VERSION()')->fetchColumn(), 'size' => (int)$size, 'tables' => $tables, 'time' => time(), 'tz' => date_default_timezone_get(),
      'min_client' => defined('MIN_CLIENT') ? MIN_CLIENT : '', 'schema' => is_file(schemaFlag()), 'cron_at' => is_file($cronF) ? filemtime($cronF) : 0,
      'mem' => (int)round(memory_get_peak_usage(true) / 1048576), 'fpm' => function_exists('fastcgi_finish_request'), 'disk' => @disk_free_space(__DIR__) ?: 0,
      'rate' => (int)q('SELECT COUNT(*) FROM rate_limits')->fetchColumn(), 'trc' => (int)q('SELECT COUNT(*) FROM tr_cache')->fetchColumn(),
      'online' => (int)q('SELECT COUNT(*) FROM players WHERE last_seen > NOW() - INTERVAL 10 MINUTE')->fetchColumn()];
    $ps = q("SELECT SUM(res = 'sent') sent, SUM(res IN ('err','gone')) bad, COUNT(*) n FROM push_log WHERE created_at > NOW() - INTERVAL 1 DAY")->fetch();
    out(['ok' => true, 'cfg' => ['banner' => $c['banner'] ?? '', 'maint' => (int)($c['maint'] ?? 0), 'maint_msg' => $c['maint_msg'] ?? ''], 'db' => $db, 'srv' => $srv, 'php' => PHP_VERSION, 'time' => time(), 'pstat' => $ps,
      'push' => ['on' => pushOn(), 'web' => wpOn(), 'tokens' => (int)q('SELECT COUNT(*) FROM push_tokens')->fetchColumn() + (int)q('SELECT COUNT(*) FROM push_web')->fetchColumn(), 'queue' => (int)q('SELECT COUNT(*) FROM push_queue')->fetchColumn(), 'cron' => cronKey(),
        'mine' => (int)q('SELECT COUNT(*) FROM push_tokens WHERE player_id = ?', [$adm['id']])->fetchColumn() + (int)q('SELECT COUNT(*) FROM push_web WHERE player_id = ?', [$adm['id']])->fetchColumn(),
        'log' => q('SELECT l.kind, l.res, l.info, UNIX_TIMESTAMP(l.created_at) t, p.nick, p.id pid FROM push_log l LEFT JOIN players p ON p.id = l.player_id ORDER BY l.id DESC LIMIT 40')->fetchAll()]]);
  }
  case 'adm_push_test': { // тестове сповіщення на власні пристрої адміністратора — одразу, без затримок
    if (!pushOn()) fail('push_off');
    if (!q('SELECT 1 FROM ' . PUSH_ANY . ' t WHERE player_id = ? LIMIT 1', [$adm['id']])->fetchColumn()) fail('push_no_device');
    pushTo((int)$adm['id'], 'test', [], 'mail'); out(['ok' => true]);
  }
  case 'adm_export': { // вивантаження гравців у таблицю (CSV)
    $rows = q('SELECT p.id, p.nick, COALESCE(s.level, 1) level, COALESCE(s.floors, 0) floors, COALESCE(s.bucks, 0) bucks, ROUND(COALESCE(s.coins, 0)) coins, p.access, p.banned, p.created_at, p.last_seen
      FROM players p LEFT JOIN player_stats s ON s.player_id = p.id ORDER BY p.id LIMIT 20000')->fetchAll();
    alog('tool', 'export', count($rows) . ' гравців'); out(['ok' => true, 'rows' => $rows]);
  }
  case 'adm_push_send': { // власне сповіщення: усім або одному гравцю
    if (!pushOn()) fail('push_off');
    $title = mb_substr(trim((string)arg('title', '')), 0, 80); $text = mb_substr(trim((string)arg('text', '')), 0, 240);
    if ($title === '' || $text === '') fail('empty');
    $open = (string)arg('open', ''); if (!in_array($open, ['', 'mail', 'news', 'promo', 'chat', 'corp', 'tour', 'gifts'], true)) $open = '';
    $to = trim((string)arg('to', ''));
    if ($to !== '') {
      $p = q('SELECT id, nick FROM players WHERE id = ? OR nick = ? LIMIT 1', [ctype_digit($to) ? (int)$to : 0, $to])->fetch(); if (!$p) fail('not_found');
      if (!q('SELECT 1 FROM ' . PUSH_ANY . ' t WHERE player_id = ? LIMIT 1', [$p['id']])->fetchColumn()) fail('push_no_device');
      pushTo((int)$p['id'], 'adm', ['title' => $title, 'text' => $text], $open); alog('push', $p['nick'], $title . ' — ' . $text);
      out(['ok' => true, 'n' => 1]);
    }
    pushAll('adm', ['title' => $title, 'text' => $text], $open); alog('push', 'всім', $title . ' — ' . $text);
    out(['ok' => true, 'n' => (int)q('SELECT COUNT(DISTINCT player_id) FROM (SELECT player_id FROM push_tokens UNION SELECT player_id FROM push_web) pt')->fetchColumn()]);
  }
  case 'adm_tool': { // сервісні інструменти
    $what = (string)arg('what'); $res = '';
    if ($what === 'schema') { @unlink(schemaFlag()); ensureSchema(true); $res = 'Структуру бази перевірено й оновлено'; }
    elseif ($what === 'rate') { $res = 'Знято обмежень: ' . q('DELETE FROM rate_limits')->rowCount(); }
    elseif ($what === 'trcache') { $res = 'Очищено перекладів: ' . q('DELETE FROM tr_cache')->rowCount(); }
    elseif ($what === 'pushq') { if (!pushOn()) fail('push_off'); pushRun(300); $res = 'Черга оброблена, лишилось: ' . (int)q('SELECT COUNT(*) FROM push_queue')->fetchColumn(); }
    elseif ($what === 'pushclear') { $res = 'Очищено з черги: ' . q('DELETE FROM push_queue')->rowCount(); q('UPDATE push_bcast SET done = 1 WHERE done = 0'); }
    elseif ($what === 'pushlog') { $res = 'Очищено записів: ' . q('DELETE FROM push_log')->rowCount(); }
    elseif ($what === 'optimize') { foreach (['chat_messages', 'dm_messages', 'sessions', 'mail', 'push_queue', 'push_log', 'balance_log', 'tr_cache'] as $t) { try { q("OPTIMIZE TABLE `$t`")->fetchAll(); } catch (Throwable $e) {} } $res = 'Таблиці оптимізовано'; }
    else fail('bad_request');
    alog('tool', $what, $res); out(['ok' => true, 'msg' => $res]);
  }
  case 'adm_cleanup': {
    $what = (string)arg('what'); $n = 0;
    if ($what === 'chat') $n = q('DELETE FROM chat_messages WHERE created_at < NOW() - INTERVAL 7 DAY')->rowCount();
    if ($what === 'sessions') $n = q('DELETE FROM sessions WHERE last_used < NOW() - INTERVAL 30 DAY')->rowCount();
    if ($what === 'mail') $n = q('DELETE FROM mail WHERE created_at < NOW() - INTERVAL 30 DAY')->rowCount();
    if ($what === 'dm') $n = q('DELETE FROM dm_messages WHERE created_at < NOW() - INTERVAL 90 DAY')->rowCount();
    if ($what === 'balance') $n = q('DELETE FROM balance_log WHERE t < NOW() - INTERVAL 60 DAY')->rowCount();
    if ($what === 'log') $n = q('DELETE FROM admin_log WHERE created_at < NOW() - INTERVAL 90 DAY')->rowCount();
    alog('cleanup', $what, $n . ' записів'); out(['ok' => true, 'n' => $n]);
  }

  // ---------- журнал ----------
  case 'adm_log': {
    $per = 10; $page = max(0, (int)arg('page', 0)); $total = (int)q('SELECT COUNT(*) FROM admin_log')->fetchColumn();
    out(['ok' => true, 'total' => $total, 'per' => $per, 'items' => q("SELECT l.id, l.action, l.target, l.info, UNIX_TIMESTAMP(l.created_at) t, p.nick, p.avatar, tp.id tid, tp.avatar tavatar
      FROM admin_log l LEFT JOIN players p ON p.id = l.admin_id LEFT JOIN players tp ON tp.nick_lc = LOWER(l.target)
      ORDER BY l.id DESC LIMIT $per OFFSET " . ($page * $per))->fetchAll()]);
  }

  default: fail('unknown_action', 404);
}
