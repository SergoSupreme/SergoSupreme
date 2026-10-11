<?php
// ============================================================
//  Щотижневий турнір кубків: гравці (щоденні завдання) і корпорації (завдання корпорації).
//  Тиждень = понеділок–субота за Києвом; у неділю кубки не видаються, а турнір підсумовується
//  (лінива перевірка з beat/tour — без cron, під MySQL-блокуванням, тому нагороди лише один раз).
// ============================================================
if (!defined('MIN_CLIENT')) { http_response_code(403); exit; }

const TOUR_P = [1000, 900, 800, 700, 600, 500, 400, 300, 200, 100];        // гравцям, банкноти за 1–10 місце
const TOUR_C = [5000, 4500, 4000, 3500, 3000, 2500, 2000, 1500, 1000, 500]; // у бюджет корпорацій за 1–10 місце
const TOUR_DAILY = ['rides', 'stocked', 'movers', 'unload', 'tips', 'spend', 'cashed', 'liftcoins', 'xp', 'vips', 'coll', 'lotto', 'donate100', 'donate600', 'donate1000', 'evict', 'maze'];

function kyiv(string $t = 'now'): DateTime { return new DateTime($t, new DateTimeZone('Europe/Kyiv')); }
function tourWeek(?DateTime $d = null): string { $d = $d ? clone $d : kyiv(); $d->setTime(0, 0); $d->modify('-' . ((int)$d->format('N') - 1) . ' days'); return $d->format('Y-m-d'); }
function tourActive(): bool { return (int)kyiv()->format('N') <= 6; }
function tourEnd(string $w): int { return kyiv($w . ' 00:00')->modify('+6 days')->getTimestamp(); }   // неділя 00:00 — кінець турніру
function tourNext(string $w): int { return kyiv($w . ' 00:00')->modify('+7 days')->getTimestamp(); }  // наступний понеділок
// скільки кубків дає завдання (2–5) — однаково рахується на сервері й у грі
function tourCups(string $date, string $id): int { $h = 0; foreach (str_split("$date:$id") as $c) $h = ($h * 31 + ord($c)) % 1000003; return 2 + $h % 4; }
function tourPlaceP(string $w, int $pid): array {
  $me = q('SELECT cups, updated_at FROM tour_cups WHERE week = ? AND player_id = ?', [$w, $pid])->fetch();
  if (!$me || !(int)$me['cups']) return ['cups' => 0, 'place' => 0];
  $pl = 1 + (int)q('SELECT COUNT(*) FROM tour_cups WHERE week = ? AND (cups > ? OR (cups = ? AND (updated_at < ? OR (updated_at = ? AND player_id < ?))))', [$w, $me['cups'], $me['cups'], $me['updated_at'], $me['updated_at'], $pid])->fetchColumn();
  return ['cups' => (int)$me['cups'], 'place' => $pl];
}
function tourPlaceC(string $w, int $cid): array {
  $me = q('SELECT cups, updated_at FROM tour_corp WHERE week = ? AND corp_id = ?', [$w, $cid])->fetch();
  if (!$me || !(int)$me['cups']) return ['cups' => 0, 'place' => 0];
  $pl = 1 + (int)q('SELECT COUNT(*) FROM tour_corp WHERE week = ? AND (cups > ? OR (cups = ? AND (updated_at < ? OR (updated_at = ? AND corp_id < ?))))', [$w, $me['cups'], $me['cups'], $me['updated_at'], $me['updated_at'], $cid])->fetchColumn();
  return ['cups' => (int)$me['cups'], 'place' => $pl];
}
function tourAddCorp(int $cid, int $cups) {
  if ($cups <= 0 || !tourActive()) return;
  q('INSERT INTO tour_corp (week, corp_id, cups) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cups = cups + VALUES(cups), updated_at = NOW()', [tourWeek(), $cid, $cups]);
}
function cupsW(int $n): string { $m = $n % 10; $h = $n % 100; return $n . ' ' . ($m === 1 && $h !== 11 ? 'кубок' : ($m >= 2 && $m <= 4 && ($h < 12 || $h > 14) ? 'кубки' : 'кубків')); }
function tourRange(string $w): string { $a = kyiv($w); $b = kyiv($w)->modify('+5 days'); return $a->format('d.m') . '–' . $b->format('d.m'); }
function tourNewsList(array $rows, array $prize, string $what): string {
  $medal = ['🥇', '🥈', '🥉'];
  $h = '<div class="tn-list">';
  foreach (array_slice($rows, 0, 10) as $i => $r)
    $h .= '<div class="tn-row' . ($i < 3 ? ' top' : '') . '"><b class="tn-pl">' . ($medal[$i] ?? ($i + 1) . '.') . '</b><span class="tn-nick notr">' . htmlspecialchars((string)$r['name'], ENT_QUOTES) . '</span>'
       . '<span class="tn-cups">' . (int)$r['cups'] . ' <img class="tn-ic" src="images/icons/ach_cup.png" alt=""></span><span class="tn-prize">+' . number_format($prize[$i], 0, '.', ' ') . ' <img class="tn-ic" src="images/icons/cash.png" alt=""></span></div>';
  return $h . '</div><div class="tn-foot">' . $what . '</div>';
}
// підсумки одного тижня (усе в одній транзакції)
function tourSettle(string $w) {
  $range = tourRange($w);
  $P = q('SELECT t.player_id, t.cups, p.nick name FROM tour_cups t JOIN players p ON p.id = t.player_id WHERE t.week = ? AND t.cups > 0 ORDER BY t.cups DESC, t.updated_at ASC, t.player_id ASC', [$w])->fetchAll();
  foreach ($P as $i => $r) {
    $c = (int)$r['cups'];
    if ($i < 10) { $rw = [['kind' => 'bucks', 'n' => TOUR_P[$i]]]; $body = "Турнір кубків ($range) завершено! Ти зібрав " . cupsW($c) . " і посів " . ($i + 1) . ' місце. Вітаємо — ось твоя нагорода:'; }
    else { $rw = []; if (intdiv($c, 10) > 0) $rw[] = ['kind' => 'bucks', 'n' => intdiv($c, 10)]; $rw[] = ['kind' => 'coins', 'n' => $c * 250];
      $body = "Турнір кубків ($range) завершено! Ти зібрав " . cupsW($c) . " і посів " . ($i + 1) . ' місце. Дякуємо за участь — ось твоя нагорода (1 банкнота за кожні 10 кубків і 250 монет за кожен кубок):'; }
    q('INSERT INTO mail (player_id, sender, icon, title, body, reward) VALUES (?, ?, ?, ?, ?, ?)', [(int)$r['player_id'], 'Турнір', 'ach_cup', 'Турнір кубків завершено!', $body, json_encode(count($rw) === 1 ? $rw[0] : $rw)]);
    if (function_exists('pushTo')) pushTo((int)$r['player_id'], 'tour', ['place' => $i + 1], 'mail', 'tour');
  }
  if ($P) q('INSERT INTO news (icon, title, body) VALUES (?, ?, ?)', ['ach_cup', "🏆 Турнір кубків $range: переможці", 'Тиждень завершено! Найкращі гравці тижня та їхні нагороди:' . tourNewsList($P, TOUR_P, 'Учасники поза ТОП-10 отримали 1 банкноту за кожні 10 кубків і 250 монет за кожен кубок. Новий турнір — з понеділка!')]);
  $C = q('SELECT t.corp_id, t.cups, c.name FROM tour_corp t JOIN corps c ON c.id = t.corp_id WHERE t.week = ? AND t.cups > 0 ORDER BY t.cups DESC, t.updated_at ASC, t.corp_id ASC', [$w])->fetchAll();
  foreach ($C as $i => $r) {
    $c = (int)$r['cups']; $b = ($i < 10 ? TOUR_C[$i] : 0) + 3 * $c; $m = 5000 * $c;
    q('UPDATE corps SET bucks = bucks + ?, coins = coins + ? WHERE id = ?', [$b, $m, (int)$r['corp_id']]);
    $msg = "🏆 Турнір корпорацій ($range) завершено! Ми зібрали " . cupsW($c) . " і посіли " . ($i + 1) . ' місце' . ($i < 10 ? ' — призове!' : '.') . ' У бюджет нараховано ' . number_format($b, 0, '.', ' ') . ' банкнот і ' . number_format($m, 0, '.', ' ') . ' монет.';
    q('INSERT INTO corp_chat (corp_id, player_id, body) VALUES (?, 0, ?)', [(int)$r['corp_id'], $msg]);
    q('INSERT INTO corp_log (corp_id, player_id, kind, info) VALUES (?, NULL, ?, ?)', [(int)$r['corp_id'], 'tour', mb_substr(cupsW($c) . " · " . ($i + 1) . " місце · +$b банкнот", 0, 255)]);
  }
  if ($C) q('INSERT INTO news (icon, title, body) VALUES (?, ?, ?)', ['bz_office', "🏆 Турнір корпорацій $range: переможці", 'Найкращі корпорації тижня та призи в бюджет:' . tourNewsList($C, TOUR_C, 'Додатково кожна корпорація-учасниця отримала в бюджет 3 банкноти та 5 000 монет за кожен кубок. Новий турнір — з понеділка!')]);
}
// лінива перевірка: чи є тиждень, що вже завершився, але ще не підсумований
function tourFinalize() {
  $now = kyiv(); $cur = tourWeek($now);
  $target = (int)$now->format('N') === 7 ? $cur : kyiv($cur)->modify('-7 days')->format('Y-m-d');
  $done = (string)(q("SELECT v FROM settings WHERE k = 'tour_done'")->fetchColumn() ?: '');
  if ($done !== '' && $done >= $target) return;
  if (!(int)q("SELECT GET_LOCK('mx_tour', 0)")->fetchColumn()) return;
  try {
    $done = (string)(q("SELECT v FROM settings WHERE k = 'tour_done'")->fetchColumn() ?: '');
    if ($done !== '' && $done >= $target) return;
    $weeks = q('SELECT week FROM (SELECT DISTINCT week FROM tour_cups UNION SELECT DISTINCT week FROM tour_corp) w WHERE week > ? AND week <= ? ORDER BY week', [$done === '' ? '2000-01-01' : $done, $target])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($weeks as $w) {
      db()->beginTransaction();
      tourSettle((string)$w);
      q("INSERT INTO settings (k, v) VALUES ('tour_done', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [(string)$w]);
      db()->commit();
    }
    q("INSERT INTO settings (k, v) VALUES ('tour_done', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$target]);
  } finally { q("SELECT RELEASE_LOCK('mx_tour')"); }
}
function tourTop(string $table, string $w): array {
  if ($table === 'p') return q('SELECT p.id, p.nick name, p.avatar, t.cups FROM tour_cups t JOIN players p ON p.id = t.player_id WHERE t.week = ? AND t.cups > 0 ORDER BY t.cups DESC, t.updated_at ASC, t.player_id ASC LIMIT 10', [$w])->fetchAll();
  return q('SELECT c.id, c.name, c.emblem, c.lvl, t.cups FROM tour_corp t JOIN corps c ON c.id = t.corp_id WHERE t.week = ? AND t.cups > 0 ORDER BY t.cups DESC, t.updated_at ASC, t.corp_id ASC LIMIT 10', [$w])->fetchAll();
}

switch ($a) {
  case 'tour': {
    $p = me(); ensureSchema(); tourFinalize();
    $w = tourWeek(); $pid = (int)$p['id'];
    $cm = q('SELECT corp_id FROM corp_members WHERE player_id = ?', [$pid])->fetchColumn();
    $res = ['ok' => true, 'week' => $w, 'date' => kyiv()->format('Y-m-d'), 'active' => tourActive(), 'end' => tourEnd($w), 'next' => tourNext($w),
      'me' => tourPlaceP($w, $pid), 'top' => tourTop('p', $w), 'n' => (int)q('SELECT COUNT(*) FROM tour_cups WHERE week = ? AND cups > 0', [$w])->fetchColumn(),
      'prizeP' => TOUR_P, 'prizeC' => TOUR_C];
    if (arg('corp')) { $res['ctop'] = tourTop('c', $w); $res['cn'] = (int)q('SELECT COUNT(*) FROM tour_corp WHERE week = ? AND cups > 0', [$w])->fetchColumn(); }
    if ($cm) $res['corp'] = tourPlaceC($w, (int)$cm);
    out($res);
  }
  // кубки за виконане щоденне завдання (один раз на завдання на день)
  case 'tour_cup': {
    $p = me(); ensureSchema(); rate('tcup:' . $p['id'], 40, 60); tourFinalize();
    if (!tourActive()) fail('tour_rest');
    $id = (string)arg('id'); if (!in_array($id, TOUR_DAILY, true)) fail('bad_task');
    $date = kyiv()->format('Y-m-d');
    $ins = q('INSERT IGNORE INTO reward_claims (player_id, ckey) VALUES (?, ?)', [$p['id'], "tcup:$date:$id"]);
    if (!$ins->rowCount()) fail('tour_got');
    $n = random_int(2, 5); $w = tourWeek(); // кількість кубків випадкова, як і ключі
    q('INSERT INTO tour_cups (week, player_id, cups) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cups = cups + VALUES(cups), updated_at = NOW()', [$w, $p['id'], $n]);
    out(['ok' => true, 'cups' => $n] + ['me' => tourPlaceP($w, (int)$p['id'])]);
  }
}
