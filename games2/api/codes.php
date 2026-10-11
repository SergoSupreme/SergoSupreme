<?php
// ============================================================
//  Коди: бонус-коди від адміністрації та код запрошення між гравцями.
//
//   • Бонус-код створює адміністрація: сам код латиницею, нагорода й ліміт активацій.
//     Один гравець може активувати кожен код лише раз.
//   • Код запрошення є в кожного гравця. З 15 рівня можна один раз увести код того,
//     хто покликав тебе в гру — і обидва отримують по 100 банкнот.
//
//  Підключається з index.php для дій code_* (усередині його try/catch).
// ============================================================
if (!defined('MIN_CLIENT')) { http_response_code(403); exit; }
require_once __DIR__ . '/corp_core.php'; // mailReward — лист із нагородою

const CODE_LEVEL = 15;        // з якого рівня можна ввести код запрошення
const CODE_BONUS = 100;       // банкнот кожному — і тому, хто ввів, і тому, хто запросив
const CODE_ABC = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // без схожих символів (0/O, 1/I)

function codeNorm(string $c): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)); }
function codeValid(string $c): bool { return (bool)preg_match('/^[A-Z0-9]{4,24}$/', $c); }
// Код запрошення гравця: створюється при першому відкритті розділу й більше не змінюється
function codeMine(array $p): string {
  $cur = codeNorm((string)($p['invite_code'] ?? ''));
  if ($cur !== '') return $cur;
  for ($i = 0; $i < 12; $i++) {
    $c = ''; for ($j = 0; $j < 7; $j++) $c .= CODE_ABC[random_int(0, strlen(CODE_ABC) - 1)];
    if (q('SELECT 1 FROM players WHERE invite_code = ?', [$c])->fetchColumn()) continue;
    if (q('SELECT 1 FROM bonus_codes WHERE code = ?', [$c])->fetchColumn()) continue;
    q('UPDATE players SET invite_code = ? WHERE id = ? AND (invite_code IS NULL OR invite_code = "")', [$c, $p['id']]);
    return codeNorm((string)q('SELECT invite_code FROM players WHERE id = ?', [$p['id']])->fetchColumn());
  }
  return '';
}
function codeReward(array $rw): array { return $rw; }
// Чи може цей бонус-код ще спрацювати
function codeLive(array $r): bool {
  if (!(int)$r['active']) return false;
  if ((int)$r['expires_at'] > 0 && (int)$r['expires_at'] < time()) return false;
  if ((int)$r['max_uses'] > 0 && (int)$r['used'] >= (int)$r['max_uses']) return false;
  return true;
}
function codeRow(array $r): array {
  return ['id' => (int)$r['id'], 'code' => $r['code'], 'reward' => json_decode((string)$r['reward'], true) ?: [],
    'max' => (int)$r['max_uses'], 'used' => (int)$r['used'], 'active' => (int)$r['active'],
    'note' => (string)($r['note'] ?? ''), 'expires' => (int)$r['expires_at'], 'live' => codeLive($r),
    't' => strtotime($r['created_at'] . ' UTC')];
}

function codeDispatch(string $a, array $p) {
  $pid = (int)$p['id'];
  switch ($a) {

    // Мій код запрошення й стан: чи можу ще ввести чужий
    case 'code_info': {
      $lvl = (int)(q('SELECT level FROM player_stats WHERE player_id = ?', [$p['id']])->fetchColumn() ?: 1);
      $by = (int)($p['invited_by'] ?? 0);
      out(['ok' => true, 'code' => codeMine($p), 'level' => $lvl, 'need' => CODE_LEVEL, 'bonus' => CODE_BONUS,
        'used' => (int)($p['invite_used'] ?? 0) === 1,
        'by' => $by ? (string)q('SELECT nick FROM players WHERE id = ?', [$by])->fetchColumn() : '',
        'invited' => (int)q('SELECT COUNT(*) FROM players WHERE invited_by = ?', [$p['id']])->fetchColumn()]);
    }

    // Активація: спершу шукаємо серед бонус-кодів, потім серед кодів запрошення
    case 'code_use': {
      rate('code:' . $pid, 12, 300);
      $c = codeNorm((string)arg('code', ''));
      if (!codeValid($c)) fail('code_bad');

      $row = q('SELECT * FROM bonus_codes WHERE code = ?', [$c])->fetch();
      if ($row) {
        if (q('SELECT 1 FROM bonus_code_uses WHERE code_id = ? AND player_id = ?', [(int)$row['id'], $pid])->fetchColumn()) fail('code_used');
        if (!(int)$row['active']) fail('code_off');
        if ((int)$row['expires_at'] > 0 && (int)$row['expires_at'] < time()) fail('code_off');
        db()->beginTransaction();
        $row = q('SELECT * FROM bonus_codes WHERE id = ? FOR UPDATE', [(int)$row['id']])->fetch();
        if ((int)$row['max_uses'] > 0 && (int)$row['used'] >= (int)$row['max_uses']) { db()->rollBack(); fail('code_over'); }
        try { q('INSERT INTO bonus_code_uses (code_id, player_id) VALUES (?, ?)', [(int)$row['id'], $pid]); }
        catch (Throwable $e) { db()->rollBack(); fail('code_used'); }
        q('UPDATE bonus_codes SET used = used + 1 WHERE id = ?', [(int)$row['id']]);
        db()->commit();
        $rw = json_decode((string)$row['reward'], true) ?: [];
        q('INSERT INTO history (player_id, kind, info) VALUES (?, "code", ?)', [$pid, $c]);
        out(['ok' => true, 'kind' => 'bonus', 'code' => $c, 'reward' => $rw]);
      }

      // код запрошення
      $host = q('SELECT id, nick FROM players WHERE invite_code = ?', [$c])->fetch();
      if (!$host) fail('code_bad');
      if ((int)$host['id'] === $pid) fail('code_self');
      if ((int)($p['invite_used'] ?? 0) === 1) fail('code_done');
      $lvl = (int)(q('SELECT level FROM player_stats WHERE player_id = ?', [$pid])->fetchColumn() ?: 1);
      if ($lvl < CODE_LEVEL) fail('code_level');
      $n = q('UPDATE players SET invited_by = ?, invite_used = 1 WHERE id = ? AND invite_used = 0', [(int)$host['id'], $pid])->rowCount();
      if (!$n) fail('code_done');
      $rw = [['kind' => 'bucks', 'n' => CODE_BONUS]];
      mailReward((int)$host['id'], 'Твій код запрошення спрацював!',
        'Гравець ' . $p['nick'] . ' увів твій код запрошення. Дякуємо, що кличеш друзів у гру — тримай нагороду:', $rw);
      q('INSERT INTO history (player_id, kind, info) VALUES (?, "invite", ?)', [$pid, $host['nick']]);
      pushTo((int)$host['id'], 'gift', ['nick' => $p['nick']], 'mail', 'invite');
      out(['ok' => true, 'kind' => 'invite', 'code' => $c, 'nick' => $host['nick'], 'reward' => $rw]);
    }
  }
  fail('bad_op');
}
if (isset($a) && strncmp($a, 'code_', 5) === 0) { $p0 = me(); ensureSchema(); codeDispatch($a, $p0); }
