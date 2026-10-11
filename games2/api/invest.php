<?php
// ============================================================
//  Інвестори в корпорацію: переговори.
//  Підключається з corp_api.php для дій corp_inv_* (усередині його try/catch).
//
//  Як це працює:
//   1. Директор призначає переговори — лише в Пн, Вт, Сб і Нд, не частіше разу на 24 години.
//      Він обирає режим (легкий / нормальний / важкий) і місце переговорів.
//   2. 5 хвилин триває збір: усім учасникам летить пуш і сповіщення в грі.
//      Коли учасник приєднується, його виручка за годину ФІКСУЄТЬСЯ — далі вона вже не впливає.
//   3. Далі інвестори по черзі ставлять питання. Номер поточного питання рахується
//      від часу початку, тож усі бачать те саме без жодного планувальника.
//   4. Кожна правильна відповідь дає (1 + бонус від зафіксованої виручки) очок.
//      Потрібно: питань x учасників x k (k залежить від режиму). Прогрес = очки / потрібно.
//   5. Наприкінці: персональні банкноти всім, хто дав команді хоча б 1% прогресу,
//      і нагорода в бюджет та досвід корпорації — якщо переговори успішні.
// ============================================================
if (!defined('MIN_CLIENT')) { http_response_code(403); exit; }
require_once __DIR__ . '/corp_core.php';

const INV_DAYS = [1, 2, 6, 7];       // Пн, Вт, Сб, Нд
const INV_LOBBY = 300;               // збір — 5 хвилин
const INV_COOLDOWN = 24 * 3600;      // одні переговори на добу
const INV_MIN_SHARE = 1.0;           // % прогресу команди для персональної нагороди
const INV_TZ = 'Europe/Kyiv';
const INV_GRACE = 3;                 // секунди запасу на мережу при відповіді
// id => [питань, секунд на питання, складність k, виручка для повного бонусу,
//        банкноти корпорації, монети й досвід за рівень, банкноти учаснику]
const INV_MODES = [
  'easy' => ['rounds' => 8,  'sec' => 22, 'k' => 1.00, 'rev' => 250000,  'bucks' => 500,  'per' => 45000,  'me' => 100],
  'norm' => ['rounds' => 10, 'sec' => 17, 'k' => 1.25, 'rev' => 800000,  'bucks' => 1800, 'per' => 85000,  'me' => 150],
  'hard' => ['rounds' => 12, 'sec' => 13, 'k' => 1.50, 'rev' => 2000000, 'bucks' => 4000, 'per' => 200000, 'me' => 250],
];
const INV_PLACES = [
  'hall' => [], 'bank' => ['ans' => 1.10], 'lux' => ['time' => 4], 'pent' => ['rev' => 1.15], 'park' => ['need' => 0.95],
];
// Правильні відповіді. Порядок збігається з INVEST.q у data.js; у гру ці числа не потрапляють.
const INV_ANS = [
  'easy' => [0,2,1,1,2,0,1,2,2,2,0,2,0,2,3,0,2,3,0,1,2,2,0,3],
  'norm' => [0,3,2,2,3,2,3,1,0,0,1,3,2,1,2,2,2,2,2,0,0,0,2,1],
  'hard' => [0,2,3,3,2,0,1,1,1,1,2,0,2,0,3,0,0,1,2,2,0,0,3,2],
];

function invModeCfg(string $m): array { return INV_MODES[$m] ?? INV_MODES['easy']; }
function invPlaceCfg(string $p): array { return INV_PLACES[$p] ?? []; }
function invQCount(string $m): int { return count(INV_ANS[$m] ?? INV_ANS['easy']); }
// Який сьогодні день тижня в Києві (1 = понеділок)
function invWeekday(?int $t = null): int { return (int)(new DateTime('@' . ($t ?? time())))->setTimezone(new DateTimeZone(INV_TZ))->format('N'); }
function invDayOk(?int $t = null): bool { return in_array(invWeekday($t), INV_DAYS, true); }
// Наступне вікно переговорів (початок доби в Києві) — щоб показати гравцям, коли чекати
function invNextDay(): int {
  $d = (new DateTime('now', new DateTimeZone(INV_TZ)))->setTime(0, 0);
  for ($i = 1; $i <= 7; $i++) { $d->modify('+1 day'); if (in_array((int)$d->format('N'), INV_DAYS, true)) return $d->getTimestamp(); }
  return time();
}
// Бонус від зафіксованої виручки за годину: 0 (не вразили) .. 1 (повний)
function invRevBonus(float $rev, string $mode, int $lvl, float $placeMul = 1.0): float {
  $need = invModeCfg($mode)['rev'] * (1 + 0.25 * max(0, $lvl - 1));
  if ($need <= 0) return 1.0;
  return min(1.0, max(0.0, $rev / $need) * $placeMul);
}
// Скільки секунд на питання з урахуванням місця переговорів
function invSec(string $mode, string $place): int { return invModeCfg($mode)['sec'] + (int)(invPlaceCfg($place)['time'] ?? 0); }
// Поточний раунд: -1 поки триває збір, >= rounds — переговори завершено
function invRound(array $inv): int {
  $begin = (int)$inv['begin_at'];
  if (time() < $begin) return -1;
  return intdiv(time() - $begin, invSec((string)$inv['mode'], (string)$inv['place']));
}
function invEnded(array $inv): bool { return (int)$inv['state'] === 2 || invRound($inv) >= invModeCfg((string)$inv['mode'])['rounds']; }
function invActive(int $cid): ?array { $r = q('SELECT * FROM corp_invest WHERE corp_id = ? AND state < 2 ORDER BY id DESC LIMIT 1', [$cid])->fetch(); return $r ?: null; }
// Питання раунду: id питання та порядок варіантів — з насіння переговорів, однаково для всіх учасників
function invPlan(array $inv): array {
  $mode = (string)$inv['mode']; $n = invModeCfg($mode)['rounds']; $total = invQCount($mode);
  mt_srand((int)$inv['seed']);
  $pool = range(0, $total - 1);
  for ($i = $total - 1; $i > 0; $i--) { $j = mt_rand(0, $i); [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]]; }
  $plan = [];
  foreach (array_slice($pool, 0, $n) as $qi) {
    $ord = [0, 1, 2, 3];
    for ($i = 3; $i > 0; $i--) { $j = mt_rand(0, $i); [$ord[$i], $ord[$j]] = [$ord[$j], $ord[$i]]; }
    $plan[] = ['q' => $qi, 'ord' => $ord];
  }
  mt_srand();
  return $plan;
}
function invParts(int $id): array { return q('SELECT p.*, pl.nick FROM corp_invest_p p JOIN players pl ON pl.id = p.player_id WHERE p.inv_id = ? ORDER BY p.score DESC, p.id ASC', [$id])->fetchAll(); }
// Скільки очок треба набрати команді
// Рахуються лише ті, хто справді відповідає: хто зайшов і стоїть осторонь — команді не шкодить
function invActiveCount(array $parts): int {
  $n = 0; foreach ($parts as $p) if ((int)$p['last_round'] >= 0) $n++;
  return max(1, $n);
}
function invNeed(array $inv, int $parts): float {
  $m = invModeCfg((string)$inv['mode']);
  return max(1.0, $m['rounds'] * max(1, $parts) * $m['k'] * (float)(invPlaceCfg((string)$inv['place'])['need'] ?? 1.0));
}
// Коротка картка для серця гри (beat) і для списку
function invBrief(?array $inv): ?array {
  if (!$inv) return null;
  $m = invModeCfg((string)$inv['mode']); $r = invRound($inv);
  return ['id' => (int)$inv['id'], 'mode' => (string)$inv['mode'], 'place' => (string)$inv['place'],
    'st' => $r < 0 ? 'lobby' : 'run', 'begin' => (int)$inv['begin_at'],
    'ends' => (int)$inv['begin_at'] + $m['rounds'] * invSec((string)$inv['mode'], (string)$inv['place']),
    'n' => (int)q('SELECT COUNT(*) FROM corp_invest_p WHERE inv_id = ?', [(int)$inv['id']])->fetchColumn()];
}

// Повний стан переговорів для конкретного гравця.
// Текст питань лежить у data.js, сюди йде лише номер питання й порядок варіантів —
// правильна відповідь не залишає сервер.
function invState(array $inv, int $pid): array {
  $mode = (string)$inv['mode']; $m = invModeCfg($mode); $sec = invSec($mode, (string)$inv['place']);
  $r = invRound($inv); $begin = (int)$inv['begin_at'];
  $parts = invParts((int)$inv['id']); $need = invNeed($inv, invActiveCount($parts));
  $score = 0.0; $mine = null;
  foreach ($parts as $p) { $score += (float)$p['score']; if ((int)$p['player_id'] === $pid) $mine = $p; }
  $plan = invPlan($inv);
  $st = $r < 0 ? 'lobby' : ($r >= $m['rounds'] ? 'done' : 'run');
  $o = ['id' => (int)$inv['id'], 'mode' => $mode, 'place' => (string)$inv['place'], 'lvl' => (int)$inv['lvl'],
    'st' => $st, 'begin' => $begin, 'round' => $r, 'rounds' => $m['rounds'], 'sec' => $sec,
    'r_ends' => $begin + ($r + 1) * $sec, 'ends' => $begin + $m['rounds'] * $sec,
    'need' => round($need, 2), 'score' => round($score, 2), 'pct' => round($need > 0 ? $score / $need * 100 : 0, 1),
    'starter' => (int)$inv['starter_id'], 'now' => time(),
    'qs' => array_map(fn($x) => (int)$x['q'], $plan),
    'parts' => array_map(fn($p) => ['id' => (int)$p['player_id'], 'nick' => $p['nick'], 'rev' => (float)$p['rev'],
      'mul' => round((float)$p['mul'], 2), 'nn' => (int)$p['hits'], 'score' => round((float)$p['score'], 2),
      'share' => round($need > 0 ? (float)$p['score'] / $need * 100 : 0, 1)], $parts)];
  $o['me'] = $mine ? ['in' => 1, 'rev' => (float)$mine['rev'], 'mul' => round((float)$mine['mul'], 2), 'nn' => (int)$mine['hits'],
    'score' => round((float)$mine['score'], 2), 'share' => round($need > 0 ? (float)$mine['score'] / $need * 100 : 0, 1),
    'done' => ((int)$mine['last_round'] === $r && (int)$mine['answers'] > 0) ? 1 : 0] : ['in' => 0];
  if ($st === 'run' && isset($plan[$r])) $o['q'] = ['i' => (int)$plan[$r]['q'], 'ord' => array_map('intval', $plan[$r]['ord'])];
  return $o;
}

// ---------- підсумки ----------
// Рахуємо прогрес, роздаємо нагороди, закриваємо переговори. Безпечно викликати будь-кому: усе під блокуванням рядка.
function invFinish(int $id): ?array {
  db()->beginTransaction();
  $inv = q('SELECT * FROM corp_invest WHERE id = ? FOR UPDATE', [$id])->fetch();
  if (!$inv || (int)$inv['state'] === 2) { db()->rollBack(); return $inv ? invResult($inv) : null; }
  if (!invEnded($inv)) { db()->rollBack(); return null; }
  $cid = (int)$inv['corp_id']; $mode = (string)$inv['mode']; $m = invModeCfg($mode);
  $parts = q('SELECT * FROM corp_invest_p WHERE inv_id = ?', [$id])->fetchAll();
  $need = invNeed($inv, invActiveCount($parts));
  $score = 0.0; foreach ($parts as $p) $score += (float)$p['score'];
  $pct = $need > 0 ? $score / $need * 100 : 0;
  $ok = $pct >= 100 ? 1 : 0;
  // персональні нагороди — усім, хто дав команді хоча б 1% прогресу
  $paid = 0;
  foreach ($parts as $p) {
    $share = $need > 0 ? (float)$p['score'] / $need * 100 : 0;
    q('UPDATE corp_invest_p SET share = ? WHERE id = ?', [$share, (int)$p['id']]);
    if ($share + 1e-9 < INV_MIN_SHARE) continue;
    $paid++;
    mailReward((int)$p['player_id'], 'Переговори з інвесторами',
      ($ok ? 'Інвестори задоволені!' : 'Інвестори пішли думати.') . ' Твій внесок у переговори — ' . number_format($share, 1, ',', ' ') . '%. Нагорода за участь:',
      [['kind' => 'bucks', 'n' => $m['me']]]);
  }
  // нагорода корпорації — лише за успішні переговори
  $addB = 0; $addC = 0.0; $addX = 0.0; $up = 0; $lvl = 0;
  $row = q('SELECT * FROM corps WHERE id = ? FOR UPDATE', [$cid])->fetch();
  if ($row) {
    $lvl = max(1, (int)$row['lvl']);
    if ($ok) {
      $addB = $m['bucks']; $addC = (float)$m['per'] * $lvl; $addX = (float)$m['per'] * $lvl;
      $cx = (float)$row['xp'] + $addX; $L = $lvl;
      while ($L < CORP_LMAX && $cx >= corpXpNeed($L)) { $cx = 0; $L++; $up++; }
      q('UPDATE corps SET bucks = bucks + ?, coins = coins + ?, xp = ?, lvl = ? WHERE id = ?', [$addB, $addC, $cx, $L, $cid]);
      if ($up) clog($cid, null, 'level', (string)$L);
      $lvl = $L;
    }
  }
  q('UPDATE corp_invest SET state = 2, score = ?, need = ?, pct = ?, ok = ?, r_bucks = ?, r_coins = ?, r_xp = ?, ended_at = ? WHERE id = ?',
    [$score, $need, $pct, $ok, $addB, $addC, $addX, time(), $id]);
  clog($cid, (int)$inv['starter_id'], 'invest', $mode . ':' . round($pct) . ':' . $ok);
  db()->commit();
  return invResult(q('SELECT * FROM corp_invest WHERE id = ?', [$id])->fetch()) + ['paid' => $paid, 'up' => $up, 'lvl' => $lvl];
}
function invResult(array $inv): array {
  return ['id' => (int)$inv['id'], 'mode' => (string)$inv['mode'], 'place' => (string)$inv['place'],
    'pct' => round((float)$inv['pct'], 1), 'ok' => (int)$inv['ok'], 'at' => (int)$inv['started_at'],
    'bucks' => (int)$inv['r_bucks'], 'coins' => (float)$inv['r_coins'], 'xp' => (float)$inv['r_xp'],
    'parts' => array_map(fn($p) => ['id' => (int)$p['player_id'], 'nick' => $p['nick'], 'nn' => (int)$p['hits'],
      'share' => round((float)$p['share'], 1), 'rev' => (float)$p['rev']], invParts((int)$inv['id']))];
}
// Закриває всі переговори, час яких вийшов — для cron, якщо в грі нікого не лишилось
function invFinalizeAll(): int {
  $n = 0;
  foreach (q('SELECT * FROM corp_invest WHERE state < 2 LIMIT 50')->fetchAll() as $inv)
    if (invEnded($inv)) { invFinish((int)$inv['id']); $n++; }
  return $n;
}
// Викликається із серця гри: якщо переговори щойно скінчилися — підбиваємо підсумки
function invSweep(int $cid): ?array {
  $inv = invActive($cid); if (!$inv) return null;
  if (invEnded($inv)) { invFinish((int)$inv['id']); return null; }
  return invBrief($inv);
}

// ---------- дії гри ----------
function invDispatch(string $a, int $pid, array $me) {
  switch ($a) {

    // Стан переговорів для моєї корпорації: активні або підсумок останніх
    case 'corp_inv_info': {
      $c = needCorp($pid); $cid = (int)$c['corp_id']; $L = corpLevel($c);
      $inv = invActive($cid);
      if ($inv && invEnded($inv)) { invFinish((int)$inv['id']); $inv = null; }
      $last = q('SELECT * FROM corp_invest WHERE corp_id = ? AND state = 2 ORDER BY id DESC LIMIT 1', [$cid])->fetch();
      $lastAt = (int)q('SELECT COALESCE(MAX(started_at), 0) FROM corp_invest WHERE corp_id = ?', [$cid])->fetchColumn();
      $res = ['ok' => true, 'lvl' => $L, 'role' => (int)$c['role'], 'members' => (int)q('SELECT COUNT(*) FROM corp_members WHERE corp_id = ?', [$cid])->fetchColumn(),
        'day_ok' => invDayOk(), 'next_day' => invNextDay(), 'next_dow' => invWeekday(invNextDay()), 'cool' => max(0, $lastAt + INV_COOLDOWN - time()),
        'last' => $last ? invResult($last) : null, 'now' => time()];
      $res['inv'] = $inv ? invState($inv, $pid) : null;
      out($res);
    }

    // Директор призначає переговори
    case 'corp_inv_start': {
      $c = needCorp($pid); needRole($c, 4); $cid = (int)$c['corp_id'];
      $mode = (string)arg('mode', 'easy'); if (!isset(INV_MODES[$mode])) fail('bad_op');
      $place = (string)arg('place', 'hall'); if (!isset(INV_PLACES[$place])) fail('bad_op');
      if (!invDayOk()) fail('inv_day');
      db()->beginTransaction();
      q('SELECT id FROM corps WHERE id = ? FOR UPDATE', [$cid])->fetchColumn();
      if (invActive($cid)) { db()->rollBack(); fail('inv_on'); }
      $lastAt = (int)q('SELECT COALESCE(MAX(started_at), 0) FROM corp_invest WHERE corp_id = ?', [$cid])->fetchColumn();
      if (time() - $lastAt < INV_COOLDOWN) { db()->rollBack(); fail('inv_cool'); }
      $now = time();
      q('INSERT INTO corp_invest (corp_id, starter_id, mode, place, seed, state, started_at, begin_at, lvl) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)',
        [$cid, $pid, $mode, $place, random_int(1, 2000000000), $now, $now + INV_LOBBY, corpLevel($c)]);
      $id = (int)db()->lastInsertId();
      db()->commit();
      clog($cid, $pid, 'invstart', $mode);
      // сповіщаємо всіх, крім ініціатора: пуш на телефон і банер у грі (через beat)
      $cname = (string)$c['name'];
      foreach (q('SELECT player_id FROM corp_members WHERE corp_id = ? AND player_id <> ?', [$cid, $pid])->fetchAll(PDO::FETCH_COLUMN) as $m)
        pushTo((int)$m, 'inv', ['corp' => $cname, 'nick' => (string)$me['nick']], 'inv', 'inv' . $id);
      out(['ok' => true, 'inv' => invState(q('SELECT * FROM corp_invest WHERE id = ?', [$id])->fetch(), $pid)]);
    }

    // Приєднатися: саме тут фіксується виручка учасника за годину
    case 'corp_inv_join': {
      $c = needCorp($pid); $cid = (int)$c['corp_id'];
      $inv = invActive($cid); if (!$inv) fail('inv_none');
      if (invEnded($inv)) { invFinish((int)$inv['id']); fail('inv_over'); }
      if (invRound($inv) >= 1) fail('inv_late');   // після першого питання приєднатися вже не можна
      // виручку беремо з серверної статистики; значення від клієнта може її тільки занизити, але не завищити
      $stat = max(0.0, (float)q('SELECT COALESCE(rev_hour, 0) FROM player_stats WHERE player_id = ?', [$pid])->fetchColumn());
      $sent = max(0.0, (float)arg('rev', 0));
      $rev = $sent > 0 ? min($sent, $stat) : $stat;
      $mul = 1.0 + invRevBonus($rev, (string)$inv['mode'], (int)$inv['lvl'], (float)(invPlaceCfg((string)$inv['place'])['rev'] ?? 1.0));
      q('INSERT INTO corp_invest_p (inv_id, player_id, rev, mul) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rev = VALUES(rev), mul = VALUES(mul)', [(int)$inv['id'], $pid, $rev, $mul]);
      out(['ok' => true, 'inv' => invState($inv, $pid)]);
    }

    // Відповідь на питання поточного раунду
    case 'corp_inv_answer': {
      $c = needCorp($pid); $cid = (int)$c['corp_id'];
      $inv = invActive($cid); if (!$inv) fail('inv_none');
      $r = invRound($inv); $m = invModeCfg((string)$inv['mode']);
      if ($r < 0 || $r >= $m['rounds']) fail('inv_over');
      // відповідь мусить стосуватися того самого питання, яке гравець бачив: інакше через затримку
      // мережі вона зарахувалася б наступному питанню, якого він ще не читав
      $sawRound = (int)arg('round', $r);
      if ($sawRound !== $r) fail('inv_round');
      $me_p = q('SELECT * FROM corp_invest_p WHERE inv_id = ? AND player_id = ?', [(int)$inv['id'], $pid])->fetch();
      if (!$me_p) fail('inv_not_in');
      if ((int)$me_p['last_round'] > $r || ((int)$me_p['last_round'] === $r && (int)$me_p['answers'] > 0)) fail('inv_answered');
      $opt = (int)arg('opt', -1); if ($opt < 0 || $opt > 3) fail('bad_op');
      $plan = invPlan($inv); $step = $plan[$r] ?? null; if (!$step) fail('inv_over');
      $correct = INV_ANS[(string)$inv['mode']][$step['q']] ?? 0;
      $hit = ((int)$step['ord'][$opt] === (int)$correct) ? 1 : 0;      // opt — місце в показаному списку
      $gain = 0.0;
      if ($hit) $gain = (float)$me_p['mul'] * (float)(invPlaceCfg((string)$inv['place'])['ans'] ?? 1.0);
      q('UPDATE corp_invest_p SET score = score + ?, hits = hits + ?, answers = 1, last_round = ? WHERE id = ?', [$gain, $hit, $r, (int)$me_p['id']]);
      out(['ok' => true, 'hit' => $hit, 'right' => (int)array_search($correct, $step['ord'], true), 'gain' => round($gain, 3), 'inv' => invState($inv, $pid)]);
    }

    // Коротке оновлення під час переговорів (раз на секунду з відкритого розділу)
    case 'corp_inv_state': {
      $c = needCorp($pid); $cid = (int)$c['corp_id'];
      $inv = invActive($cid);
      if ($inv && invEnded($inv)) { $r = invFinish((int)$inv['id']); out(['ok' => true, 'inv' => null, 'done' => $r]); }
      out(['ok' => true, 'inv' => $inv ? invState($inv, $pid) : null, 'now' => time()]);
    }
  }
  fail('bad_op');
}
if (isset($a) && strncmp($a, 'corp_inv_', 9) === 0) invDispatch($a, (int)$pid, (array)$me);
