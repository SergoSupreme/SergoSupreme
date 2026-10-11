<?php
// Проста адмін-панель: новини, листи гравцям, бан. Вхід: /api/admin.php?key=ТВІЙ_ADMIN_KEY
require __DIR__ . '/db.php';
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if (!hash_equals((string)cfg('admin_key'), (string)$key)) { http_response_code(403); exit('403'); }
function ensureAccessCol() { if (!q("SHOW COLUMNS FROM players LIKE 'access'")->fetch()) db()->exec('ALTER TABLE players ADD COLUMN access TINYINT(1) NOT NULL DEFAULT 0'); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $do = $_POST['do'] ?? '';
  if ($do === 'news' && trim($_POST['title'] ?? '') !== '') { q('INSERT INTO news (icon, title, body) VALUES (?, ?, ?)', [$_POST['icon'] ?: 'megaphone2', $_POST['title'], $_POST['body']]); $msg = 'Новину опубліковано'; }
  if ($do === 'news_del') { q('UPDATE news SET published = 0 WHERE id = ?', [(int)$_POST['id']]); $msg = 'Новину приховано'; }
  if ($do === 'mail' && trim($_POST['title'] ?? '') !== '') {
    $pid = null;
    if (trim($_POST['nick'] ?? '') !== '') { $pid = q('SELECT id FROM players WHERE nick_lc = ?', [mb_strtolower(trim($_POST['nick']))])->fetchColumn(); if (!$pid) $msg = 'Гравця не знайдено'; }
    if (!$msg) {
      $reward = null;
      if (($_POST['rkind'] ?? '') !== '') $reward = json_encode(['kind' => $_POST['rkind'], 'n' => (int)$_POST['rn'], 'id' => $_POST['rid'] ?: 'blue']);
      q('INSERT INTO mail (player_id, sender, icon, title, body, reward) VALUES (?, ?, ?, ?, ?, ?)', [$pid ?: null, $_POST['sender'] ?: 'Адміністрація', $_POST['icon'] ?: 'mail2', $_POST['title'], $_POST['body'], $reward]);
      $msg = $pid ? 'Лист надіслано гравцю' : 'Лист надіслано всім гравцям';
    }
  }
  if ($do === 'access') { ensureAccessCol(); q('UPDATE players SET access = ? WHERE nick_lc = ?', [(int)$_POST['v'], mb_strtolower(trim($_POST['nick']))]); $msg = 'Права змінено'; }
  if ($do === 'ban') { q('UPDATE players SET banned = ? WHERE nick_lc = ?', [(int)$_POST['v'], mb_strtolower(trim($_POST['nick']))]); $msg = 'Готово'; }
}
$stats = q('SELECT (SELECT COUNT(*) FROM players) players, (SELECT COUNT(*) FROM players WHERE last_seen > NOW() - INTERVAL 1 DAY) dau, (SELECT COUNT(*) FROM chat_messages) chat, (SELECT COUNT(*) FROM forum_topics) topics')->fetch();
$news = q('SELECT id, title, created_at FROM news WHERE published = 1 ORDER BY id DESC LIMIT 20')->fetchAll();
?><!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Адмінка</title>
<style>body{font-family:system-ui;background:#0d1738;color:#e8eeff;max-width:760px;margin:auto;padding:16px}section{background:#18265e;border:1px solid #3a52a8;border-radius:12px;padding:12px;margin:12px 0}
input,textarea,select{width:100%;box-sizing:border-box;margin:4px 0;padding:8px;border-radius:8px;border:1px solid #3a52a8;background:#0a1236;color:#fff}button{padding:8px 16px;border-radius:8px;border:0;background:#ffc531;font-weight:700;cursor:pointer}.m{background:#1f9a37;padding:8px;border-radius:8px}</style>
<h1>«Мій Хмарочос UA» — адмінка</h1>
<?php if ($msg) echo '<p class="m">' . $h($msg) . '</p>'; ?>
<section>Гравців: <b><?= $stats['players'] ?></b> · активні за добу: <b><?= $stats['dau'] ?></b> · повідомлень у чаті: <b><?= $stats['chat'] ?></b> · тем на форумі: <b><?= $stats['topics'] ?></b></section>
<section><h3>Нова новина</h3><form method="post"><input type="hidden" name="key" value="<?= $h($key) ?>"><input type="hidden" name="do" value="news">
<input name="title" placeholder="Заголовок"><textarea name="body" rows="3" placeholder="Текст"></textarea><input name="icon" placeholder="Іконка (наприклад megaphone2, crane, cup_1)"><button>Опублікувати</button></form>
<?php foreach ($news as $n): ?><form method="post" style="display:flex;gap:8px;align-items:center"><input type="hidden" name="key" value="<?= $h($key) ?>"><input type="hidden" name="do" value="news_del"><input type="hidden" name="id" value="<?= $n['id'] ?>"><span style="flex:1">#<?= $n['id'] ?> <?= $h($n['title']) ?> <small><?= $n['created_at'] ?></small></span><button>Приховати</button></form><?php endforeach; ?></section>
<section><h3>Лист у пошту</h3><form method="post"><input type="hidden" name="key" value="<?= $h($key) ?>"><input type="hidden" name="do" value="mail">
<input name="nick" placeholder="Нік гравця (порожньо — всім)"><input name="sender" placeholder="Від кого (Адміністрація)"><input name="title" placeholder="Тема"><textarea name="body" rows="3" placeholder="Текст"></textarea>
<select name="rkind"><option value="">Без подарунка</option><option value="bucks">Банкноти</option><option value="coins">Монети</option><option value="keys">Ключі</option><option value="xp">Досвід</option><option value="chest">Скриня</option></select>
<input name="rn" type="number" placeholder="Кількість"><select name="rid"><option value="wood">Дерев'яна</option><option value="blue">Синя</option><option value="purple">Фіолетова</option><option value="red">Легендарна</option></select>
<input name="icon" placeholder="Іконка (mail2, gift_red…)"><button>Надіслати</button></form></section>
<section><h3>Права адміністратора (access)</h3><p>Адміністратор бачить у грі кнопку з короною біля пошти — повну адмін-панель.</p><form method="post"><input type="hidden" name="key" value="<?= $h($key) ?>"><input type="hidden" name="do" value="access"><input name="nick" placeholder="Нік гравця"><select name="v"><option value="1">access = 1 (адміністратор)</option><option value="0">access = 0 (гравець)</option></select><button>Застосувати</button></form></section>
<section><h3>Бан</h3><form method="post"><input type="hidden" name="key" value="<?= $h($key) ?>"><input type="hidden" name="do" value="ban"><input name="nick" placeholder="Нік"><select name="v"><option value="1">Заблокувати</option><option value="0">Розблокувати</option></select><button>Застосувати</button></form></section>
</html>
