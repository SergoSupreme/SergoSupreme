<?php
// ============================================================
//  Пуш-сповіщення через Firebase Cloud Messaging (HTTP v1).
//  Секретний ключ сервісного акаунта лежить у api/firebase-key.json (НЕ публікувати, НЕ надсилати в чати).
//  Без ключа все працює як раніше — сповіщення просто не надсилаються.
// ============================================================
const PUSH_KEY = __DIR__ . '/firebase-key.json';
const PUSH_CAP = 4;                       // несоціальних сповіщень на добу на гравця
const PUSH_NOW = ['dm', 'gift', 'cinv', 'cchat', 'reply', 'gchat', 'test'];  // ці не чекають ранку і не входять у денний ліміт
const PUSH_LATER = ['dm', 'cchat', 'reply', 'gchat'];  // чекають 1 хв: якщо гравець за цей час був у грі — він уже все бачив
const PUSH_WAIT = 60;
const PUSH_GROUP = ['dm' => 'msg', 'gift' => 'msg', 'cinv' => 'msg', 'cchat' => 'msg', 'reply' => 'chat', 'gchat' => 'chat', 'promo' => 'promo', 'news' => 'promo', 'give' => 'promo',
  'don' => 'promo', 'adm' => 'promo', 'tour' => 'tour', 'idle1' => 'tower', 'idle2' => 'tower', 'idle3' => 'tower'];
const PUSH_LANGS = ['uk', 'en', 'ru', 'de', 'fr', 'it', 'ja', 'ko', 'zh'];

function pushOn(): bool { return is_file(PUSH_KEY); }

// Тексти сповіщень мовою гравця: [заголовок, текст]; {nick} {corp} {text} {title} {place} підставляються
function pushTexts(): array {
  return [
    'adm' => array_fill_keys(PUSH_LANGS, ['{title}', '{text}']), // власне сповіщення від адміністратора
    'dm' => ['uk' => ['{nick}', '{text}'], 'en' => ['{nick}', '{text}'], 'ru' => ['{nick}', '{text}'], 'de' => ['{nick}', '{text}'], 'fr' => ['{nick}', '{text}'], 'it' => ['{nick}', '{text}'], 'ja' => ['{nick}', '{text}'], 'ko' => ['{nick}', '{text}'], 'zh' => ['{nick}', '{text}']],
    'gift' => [
      'uk' => ['🎁 Тобі подарунок!', '{nick} надсилає тобі подарунок — подивись у профілі'],
      'en' => ['🎁 You got a gift!', '{nick} sent you a gift — check your profile'],
      'ru' => ['🎁 Тебе подарок!', '{nick} отправляет тебе подарок — посмотри в профиле'],
      'de' => ['🎁 Ein Geschenk für dich!', '{nick} hat dir ein Geschenk geschickt — schau in dein Profil'],
      'fr' => ['🎁 Un cadeau pour toi !', '{nick} t’a envoyé un cadeau — regarde ton profil'],
      'it' => ['🎁 Un regalo per te!', '{nick} ti ha inviato un regalo — guarda il tuo profilo'],
      'ja' => ['🎁 プレゼントが届きました!', '{nick}さんからプレゼント — プロフィールを見てね'],
      'ko' => ['🎁 선물이 도착했어요!', '{nick}님이 선물을 보냈어요 — 프로필을 확인하세요'],
      'zh' => ['🎁 你收到一份礼物!', '{nick} 给你送了礼物——去个人资料看看吧']],
    'cinv' => [
      'uk' => ['🏢 Запрошення в корпорацію', '{nick} кличе тебе в «{corp}»'],
      'en' => ['🏢 Corporation invite', '{nick} invites you to «{corp}»'],
      'ru' => ['🏢 Приглашение в корпорацию', '{nick} зовёт тебя в «{corp}»'],
      'de' => ['🏢 Konzern-Einladung', '{nick} lädt dich zu «{corp}» ein'],
      'fr' => ['🏢 Invitation de corporation', '{nick} t’invite dans «{corp}»'],
      'it' => ['🏢 Invito in corporazione', '{nick} ti invita in «{corp}»'],
      'ja' => ['🏢 企業への招待', '{nick}さんが「{corp}」に招待しています'],
      'ko' => ['🏢 기업 초대', '{nick}님이 «{corp}»에 초대했어요'],
      'zh' => ['🏢 公司邀请', '{nick} 邀请你加入「{corp}」']],
    'cchat' => [
      'uk' => ['💬 {corp}', '{nick}: {text}'], 'en' => ['💬 {corp}', '{nick}: {text}'], 'ru' => ['💬 {corp}', '{nick}: {text}'],
      'de' => ['💬 {corp}', '{nick}: {text}'], 'fr' => ['💬 {corp}', '{nick}: {text}'], 'it' => ['💬 {corp}', '{nick}: {text}'],
      'ja' => ['💬 {corp}', '{nick}: {text}'], 'ko' => ['💬 {corp}', '{nick}: {text}'], 'zh' => ['💬 {corp}', '{nick}: {text}']],
    'test' => [
      'uk' => ['🔔 Тестове сповіщення', 'Якщо ти це бачиш — сповіщення працюють!'], 'en' => ['🔔 Test notification', 'If you can see this, notifications work!'],
      'ru' => ['🔔 Тестовое уведомление', 'Если ты это видишь — уведомления работают!'], 'de' => ['🔔 Test-Benachrichtigung', 'Wenn du das siehst, funktionieren Benachrichtigungen!'],
      'fr' => ['🔔 Notification de test', 'Si tu vois ceci, les notifications fonctionnent !'], 'it' => ['🔔 Notifica di prova', 'Se vedi questo, le notifiche funzionano!'],
      'ja' => ['🔔 テスト通知', 'これが見えたら通知は正常です!'], 'ko' => ['🔔 테스트 알림', '이게 보이면 알림이 작동하는 거예요!'], 'zh' => ['🔔 测试通知', '如果你看到这条,通知正常!']],
    'gchat' => [
      'uk' => ['💬 Загальний чат', '{nick}: {text}'], 'en' => ['💬 Global chat', '{nick}: {text}'], 'ru' => ['💬 Общий чат', '{nick}: {text}'],
      'de' => ['💬 Allgemeiner Chat', '{nick}: {text}'], 'fr' => ['💬 Chat général', '{nick}: {text}'], 'it' => ['💬 Chat generale', '{nick}: {text}'],
      'ja' => ['💬 全体チャット', '{nick}: {text}'], 'ko' => ['💬 전체 채팅', '{nick}: {text}'], 'zh' => ['💬 公共聊天', '{nick}: {text}']],
    'reply' => [
      'uk' => ['↩ {nick} відповідає тобі', '{text}'], 'en' => ['↩ {nick} replied to you', '{text}'], 'ru' => ['↩ {nick} отвечает тебе', '{text}'],
      'de' => ['↩ {nick} hat dir geantwortet', '{text}'], 'fr' => ['↩ {nick} t’a répondu', '{text}'], 'it' => ['↩ {nick} ti ha risposto', '{text}'],
      'ja' => ['↩ {nick}さんから返信', '{text}'], 'ko' => ['↩ {nick}님의 답장', '{text}'], 'zh' => ['↩ {nick} 回复了你', '{text}']],
    'promo' => [
      'uk' => ['🔥 {title}', 'Нова акція в грі — заходь, поки діє!'],
      'en' => ['🔥 {title}', 'A new promo is live — come in while it lasts!'],
      'ru' => ['🔥 {title}', 'Новая акция в игре — заходи, пока действует!'],
      'de' => ['🔥 {title}', 'Neue Aktion im Spiel — schau vorbei, solange sie läuft!'],
      'fr' => ['🔥 {title}', 'Nouvelle promo en jeu — viens vite tant qu’elle dure !'],
      'it' => ['🔥 {title}', 'Nuova promo nel gioco — entra finché dura!'],
      'ja' => ['🔥 {title}', '新しいキャンペーン開催中 — お見逃しなく!'],
      'ko' => ['🔥 {title}', '새 프로모션 진행 중 — 끝나기 전에 들어오세요!'],
      'zh' => ['🔥 {title}', '新活动上线——趁还有效快来吧!']],
    'news' => [
      'uk' => ['📰 {title}', 'Нова новина в грі — заходь почитати'],
      'en' => ['📰 {title}', 'There’s news in the game — come and read'],
      'ru' => ['📰 {title}', 'Новая новость в игре — заходи почитать'],
      'de' => ['📰 {title}', 'Neuigkeiten im Spiel — schau rein'],
      'fr' => ['📰 {title}', 'Du nouveau dans le jeu — viens lire'],
      'it' => ['📰 {title}', 'Novità nel gioco — vieni a leggere'],
      'ja' => ['📰 {title}', 'ゲームに新しいお知らせがあります'],
      'ko' => ['📰 {title}', '게임에 새 소식이 있어요'],
      'zh' => ['📰 {title}', '游戏有新消息——快来看看']],
    'give' => [
      'uk' => ['🎁 Подарунок від адміністрації', 'Забери нагороду в пошті!'],
      'en' => ['🎁 A gift from the team', 'Claim your reward in the mail!'],
      'ru' => ['🎁 Подарок от администрации', 'Забери награду на почте!'],
      'de' => ['🎁 Ein Geschenk vom Team', 'Hol dir deine Belohnung in der Post!'],
      'fr' => ['🎁 Un cadeau de l’équipe', 'Récupère ta récompense dans le courrier !'],
      'it' => ['🎁 Un regalo dal team', 'Ritira la ricompensa nella posta!'],
      'ja' => ['🎁 運営からのプレゼント', 'メールで報酬を受け取ろう!'],
      'ko' => ['🎁 운영진의 선물', '우편함에서 보상을 받으세요!'],
      'zh' => ['🎁 来自官方的礼物', '去邮箱领取奖励吧!']],
    'don' => [
      'uk' => ['✅ Банкноти зараховано', 'Покупку підтверджено — забери банкноти в пошті'],
      'en' => ['✅ Banknotes credited', 'Your purchase is confirmed — collect the banknotes in the mail'],
      'ru' => ['✅ Банкноты зачислены', 'Покупка подтверждена — забери банкноты на почте'],
      'de' => ['✅ Banknoten gutgeschrieben', 'Kauf bestätigt — hol die Banknoten in der Post ab'],
      'fr' => ['✅ Billets crédités', 'Achat confirmé — récupère tes billets dans le courrier'],
      'it' => ['✅ Banconote accreditate', 'Acquisto confermato — ritira le banconote nella posta'],
      'ja' => ['✅ 紙幣を付与しました', '購入が確認されました — メールで受け取ってね'],
      'ko' => ['✅ 지폐 지급 완료', '구매가 확인됐어요 — 우편함에서 받으세요'],
      'zh' => ['✅ 钞票已到账', '购买已确认——去邮箱领取钞票']],
    'tour' => [
      'uk' => ['🏆 Турнір завершено!', 'Ти на {place} місці — нагорода вже в пошті'],
      'en' => ['🏆 Tournament over!', 'You placed #{place} — your reward is in the mail'],
      'ru' => ['🏆 Турнир завершён!', 'Ты на {place} месте — награда уже на почте'],
      'de' => ['🏆 Turnier beendet!', 'Du bist auf Platz {place} — die Belohnung ist in der Post'],
      'fr' => ['🏆 Tournoi terminé !', 'Tu es {place}e — ta récompense t’attend dans le courrier'],
      'it' => ['🏆 Torneo concluso!', 'Sei al {place}° posto — la ricompensa è nella posta'],
      'ja' => ['🏆 トーナメント終了!', 'あなたは{place}位 — 報酬はメールに届いています'],
      'ko' => ['🏆 토너먼트 종료!', '{place}위를 했어요 — 보상이 우편함에 있어요'],
      'zh' => ['🏆 锦标赛结束!', '你获得第{place}名——奖励已发到邮箱']],
    'idle1' => [
      'uk' => ['Давно не бачились! 💰', 'Заходь та забирай дохід — вежа вже заробила'],
      'en' => ['Long time no see! 💰', 'Come in and collect your income — your tower has been earning'],
      'ru' => ['Давно не виделись! 💰', 'Заходи и забирай доход — башня уже заработала'],
      'de' => ['Lange nicht gesehen! 💰', 'Komm vorbei und hol deine Einnahmen — dein Turm hat verdient'],
      'fr' => ['Ça fait longtemps ! 💰', 'Viens récupérer tes revenus — ta tour a bien travaillé'],
      'it' => ['Da quanto tempo! 💰', 'Entra e ritira gli incassi — la tua torre ha guadagnato'],
      'ja' => ['お久しぶり! 💰', '収益を受け取りに来てね — タワーが稼いでいます'],
      'ko' => ['오랜만이에요! 💰', '들어와서 수익을 챙기세요 — 타워가 돈을 벌었어요'],
      'zh' => ['好久不见!💰', '快来领取收入——你的大楼一直在赚钱']],
    'idle2' => [
      'uk' => ['Твої жителі сумують 🏙', 'Вежа чекає на тебе — повертайся, на тебе чекають завдання й бонуси'],
      'en' => ['Your residents miss you 🏙', 'Your tower is waiting — tasks and bonuses are ready'],
      'ru' => ['Твои жители скучают 🏙', 'Башня ждёт тебя — возвращайся, тебя ждут задания и бонусы'],
      'de' => ['Deine Bewohner vermissen dich 🏙', 'Dein Turm wartet — Aufgaben und Boni sind bereit'],
      'fr' => ['Tes résidents s’ennuient 🏙', 'Ta tour t’attend — tâches et bonus sont prêts'],
      'it' => ['I tuoi residenti ti aspettano 🏙', 'La tua torre ti aspetta — compiti e bonus sono pronti'],
      'ja' => ['住人たちが寂しがっています 🏙', 'タワーが待っています — タスクとボーナスが準備OK'],
      'ko' => ['주민들이 보고 싶어 해요 🏙', '타워가 기다려요 — 과제와 보너스가 준비됐어요'],
      'zh' => ['居民们想你了 🏙', '大楼在等你——任务和奖励都准备好了']],
    'idle3' => [
      'uk' => ['Ми скучили! 🎁', 'Повертайся до свого хмарочоса — тебе чекає щоденний бонус'],
      'en' => ['We miss you! 🎁', 'Come back to your skyscraper — a daily bonus is waiting'],
      'ru' => ['Мы соскучились! 🎁', 'Возвращайся в свой небоскрёб — тебя ждёт ежедневный бонус'],
      'de' => ['Wir vermissen dich! 🎁', 'Komm zurück zu deinem Wolkenkratzer — ein Tagesbonus wartet'],
      'fr' => ['Tu nous manques ! 🎁', 'Reviens dans ton gratte-ciel — un bonus quotidien t’attend'],
      'it' => ['Ci manchi! 🎁', 'Torna al tuo grattacielo — ti aspetta un bonus giornaliero'],
      'ja' => ['会いたいな! 🎁', 'ビルに戻ってきて — デイリーボーナスが待っています'],
      'ko' => ['보고 싶어요! 🎁', '빌딩으로 돌아오세요 — 일일 보너스가 기다려요'],
      'zh' => ['我们想你了!🎁', '回到你的摩天大楼吧——每日奖励在等你']],
  ];
}
// Короткий текст, перекладений машинно (для назв акцій/новин, які пише адміністратор українською)
function pushMt(string $t, string $lang): string {
  if ($t === '' || $lang === 'uk' || !function_exists('mtTranslate')) return $t;
  $h = sha1($lang . "\n" . $t);
  $c = q('SELECT out_text FROM tr_cache WHERE h = ?', [$h])->fetchColumn(); if ($c !== false) return (string)$c;
  $o = mtTranslate($t, $lang); if ($o === null) return $t;
  q('INSERT IGNORE INTO tr_cache (h, lang, src, out_text) VALUES (?, ?, ?, ?)', [$h, $lang, $o[1], $o[0]]);
  return $o[0];
}
function pushText(string $kind, string $lang, array $v): array {
  $T = pushTexts(); $row = $T[$kind][$lang] ?? $T[$kind]['uk'] ?? ['Мій Хмарочос', ''];
  if (isset($v['title'])) $v['title'] = pushMt((string)$v['title'], $lang);
  if ($kind === 'adm' && isset($v['text'])) $v['text'] = pushMt((string)$v['text'], $lang);
  $rep = []; foreach ($v as $k => $x) $rep['{' . $k . '}'] = mb_substr(preg_replace('/:e\d{1,2}:/', '🙂', (string)$x), 0, 160);
  return [strtr($row[0], $rep), strtr($row[1], $rep)];
}

// ---------- постановка в чергу ----------
function pushKickLater() {
  static $set = false; if ($set) return; $set = true;
  register_shutdown_function(function () {
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); // відповідь уже пішла гравцю — надсилаємо у фоні
    ignore_user_abort(true);
    try { pushRun(function_exists('fastcgi_finish_request') ? 60 : 15); pushWaitLoop(); } catch (Throwable $e) { error_log('[mx] push ' . $e->getMessage()); }
  });
}
// Без cron: після відповіді гравцю один фоновий процес дочікується відкладених сповіщень (до 3 хв) і надсилає їх вчасно
function pushWaitLoop() {
  if (!function_exists('fastcgi_finish_request')) return; // лише коли відповідь уже віддано (PHP-FPM)
  if (!(int)q('SELECT GET_LOCK(?, 0)', ['mx_pushwait_' . cfg('db_name')])->fetchColumn()) return; // уже хтось чекає
  try {
    @set_time_limit(240); $end = time() + 180;
    while (time() < $end) {
      $next = q('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), MIN(send_after)) FROM push_queue WHERE send_after <= UTC_TIMESTAMP() + INTERVAL 90 SECOND')->fetchColumn();
      if ($next === null || $next === false) break;
      if ((int)$next > 0) sleep(min((int)$next, 90));
      pushRun(60);
    }
  } finally { q('SELECT RELEASE_LOCK(?)', ['mx_pushwait_' . cfg('db_name')]); }
}
function pushLog(int $pid, string $kind, string $res, string $info = '') {
  try { q('INSERT INTO push_log (player_id, kind, res, info) VALUES (?, ?, ?, ?)', [$pid, $kind, $res, mb_substr($info, 0, 160)]); } catch (Throwable $e) {}
}
function pushTo(int $pid, string $kind, array $v = [], string $open = '', string $tag = '', array $extra = []) {
  if (!pushOn() || $pid <= 0) return;
  try {
    if (!q('SELECT 1 FROM push_tokens WHERE player_id = ? LIMIT 1', [$pid])->fetchColumn()) return; // немає застосунку — нікому надсилати
    // чекаємо лише якщо гравець щойно був у грі: рівно до моменту, коли мине хвилина без активності. Неактивному — одразу
    $wait = 0;
    if (in_array($kind, PUSH_LATER, true)) $wait = max(0, (int)q('SELECT UNIX_TIMESTAMP(last_seen) FROM players WHERE id = ?', [$pid])->fetchColumn() + PUSH_WAIT - time());
    q('INSERT INTO push_queue (player_id, kind, data, send_after) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND)',
      [$pid, $kind, json_encode(['v' => $v, 'open' => $open, 'tag' => $tag, 'at' => time()] + $extra, JSON_UNESCAPED_UNICODE), $wait]);
    pushKickLater();
  } catch (Throwable $e) { error_log('[mx] pushTo ' . $e->getMessage()); }
}
// усім гравцям із застосунком (розсилка йде частинами, щоб не вантажити сервер)
function pushAll(string $kind, array $v = [], string $open = '') {
  if (!pushOn()) return;
  try { q('INSERT INTO push_bcast (kind, data) VALUES (?, ?)', [$kind, json_encode(['v' => $v, 'open' => $open], JSON_UNESCAPED_UNICODE)]); pushKickLater(); }
  catch (Throwable $e) { error_log('[mx] pushAll ' . $e->getMessage()); }
}
// повідомлення в загальному чаті — усім, хто має застосунок (кожному не частіше разу на 15 хв)
function pushChatAll(int $from, string $nick, string $text, int $skip = 0) {
  if (!pushOn()) return;
  try {
    $ids = q('SELECT DISTINCT t.player_id FROM push_tokens t LEFT JOIN push_state s ON s.player_id = t.player_id
              WHERE t.player_id <> ? AND t.player_id <> ? AND COALESCE(s.gchat_at, 0) < ? AND t.player_id NOT IN (SELECT player_id FROM player_blocks WHERE blocked_id = ?)', [$from, $skip, time() - 900, $from])->fetchAll(PDO::FETCH_COLUMN);
    $data = json_encode(['v' => ['nick' => $nick, 'text' => $text], 'open' => 'chat', 'tag' => 'gchat', 'at' => time()], JSON_UNESCAPED_UNICODE);
    foreach (array_chunk($ids, 200) as $ch) {
      $q = []; $a = []; $st = []; $sa = [];
      foreach ($ch as $pid) { $q[] = '(?, "gchat", ?, UTC_TIMESTAMP() + INTERVAL ' . PUSH_WAIT . ' SECOND)'; array_push($a, (int)$pid, $data); $st[] = '(?, ?)'; array_push($sa, (int)$pid, time()); }
      q('INSERT INTO push_queue (player_id, kind, data, send_after) VALUES ' . implode(',', $q), $a);
      q('INSERT INTO push_state (player_id, gchat_at) VALUES ' . implode(',', $st) . ' ON DUPLICATE KEY UPDATE gchat_at = VALUES(gchat_at)', $sa);
    }
  } catch (Throwable $e) { error_log('[mx] pushChatAll ' . $e->getMessage()); }
}
// запуск обробки не частіше ніж раз на 30 с (з запитів гравців, якщо на хостингу немає cron)
function pushTick() {
  if (!pushOn()) return;
  $f = sys_get_temp_dir() . '/mx_push_tick_' . md5(cfg('db_name'));
  if (is_file($f) && filemtime($f) > time() - 30) return;
  @touch($f); pushKickLater();
}

// ---------- Firebase: токен доступу (JWT, RS256) ----------
function fcmCreds(): ?array { static $c = null; if ($c === null) { $c = json_decode((string)@file_get_contents(PUSH_KEY), true) ?: []; } return !empty($c['private_key']) ? $c : null; }
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function fcmAccess(): ?string {
  $cr = fcmCreds(); if (!$cr) return null;
  $f = sys_get_temp_dir() . '/mx_fcm_tok_' . md5($cr['client_email']);
  $c = @json_decode((string)@file_get_contents($f), true);
  if ($c && ($c['exp'] ?? 0) > time() + 120) return $c['tok'];
  $now = time();
  $jwt = b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . b64u(json_encode(['iss' => $cr['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
    'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
  if (!openssl_sign($jwt, $sig, $cr['private_key'], 'sha256WithRSAEncryption')) { error_log('[mx] fcm sign failed'); return null; }
  $jwt .= '.' . b64u($sig);
  $ch = curl_init(cfg('fcm_oauth') ?: (getenv('MX_FCM_OAUTH') ?: 'https://oauth2.googleapis.com/token'));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 8,
    CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt])]);
  $r = json_decode((string)curl_exec($ch), true); curl_close($ch);
  if (empty($r['access_token'])) { error_log('[mx] fcm token: ' . json_encode($r)); return null; }
  @file_put_contents($f, json_encode(['tok' => $r['access_token'], 'exp' => $now + (int)($r['expires_in'] ?? 3600)]), LOCK_EX);
  return $r['access_token'];
}
// надсилання пачкою паралельно; повертає [індекс => 'ok' | 'gone' | 'err']
function fcmSendMany(array $msgs): array {
  $res = []; if (!$msgs) return $res;
  $tok = fcmAccess(); if (!$tok) return array_fill_keys(array_keys($msgs), 'err');
  $url = (cfg('fcm_base') ?: (getenv('MX_FCM_BASE') ?: 'https://fcm.googleapis.com')) . '/v1/projects/' . fcmCreds()['project_id'] . '/messages:send';
  foreach (array_chunk($msgs, 20, true) as $chunk) {
    $mh = curl_multi_init(); $hs = [];
    foreach ($chunk as $i => $m) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json; charset=utf-8'], CURLOPT_POSTFIELDS => json_encode(['message' => $m], JSON_UNESCAPED_UNICODE)]);
      curl_multi_add_handle($mh, $ch); $hs[$i] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
    foreach ($hs as $i => $ch) {
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $body = (string)curl_multi_getcontent($ch);
      $res[$i] = $code === 200 ? 'ok' : (($code === 404 || strpos($body, 'UNREGISTERED') !== false || ($code === 400 && strpos($body, 'registration token') !== false)) ? 'gone' : 'err');
      if ($res[$i] === 'err') error_log('[mx] fcm ' . $code . ' ' . mb_substr($body, 0, 300));
      curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
  }
  return $res;
}
function fcmMessage(string $token, string $title, string $body, string $kind, string $open, string $tag): array {
  // канали з власним звуком гри (застосунок 1.2+); у старих версіях Android візьме стандартний канал
  $n = ['channel_id' => (PUSH_GROUP[$kind] ?? 'main') . '_s', 'icon' => 'ic_stat_notify', 'color' => '#F3A600', 'sound' => 'notify'];
  if ($tag !== '' && !in_array($kind, ['dm', 'cchat', 'gchat', 'reply'], true)) $n['tag'] = $tag; // повідомлення не замінюють одне одного — кожне зі звуком // нове сповіщення з тим самим тегом замінює старе (без «спаму» від одного гравця)
  return ['token' => $token, 'notification' => ['title' => $title, 'body' => $body],
    'android' => ['priority' => 'high', 'notification' => $n],
    'data' => ['kind' => $kind, 'open' => $open]];
}

// ---------- обробник черги ----------
function pushRun(int $limit = 60) {
  if (!pushOn()) return;
  if (!(int)q("SELECT GET_LOCK(?, 0)", ['mx_push_' . cfg('db_name')])->fetchColumn()) return; // інший процес уже надсилає
  try {
    pushFanout(); pushIdleScan();
    $rows = q('SELECT id, player_id, kind, data FROM push_queue WHERE send_after <= UTC_TIMESTAMP() ORDER BY id LIMIT ' . (int)$limit)->fetchAll();
    if (!$rows) return;
    q('DELETE FROM push_queue WHERE id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')');
    $msgs = []; $meta = [];
    foreach ($rows as $r) {
      $pid = (int)$r['player_id']; $kind = $r['kind']; $d = json_decode((string)$r['data'], true) ?: [];
      $toks = q('SELECT token, lang, tz, prefs FROM push_tokens WHERE player_id = ?', [$pid])->fetchAll(); if (!$toks) { pushLog($pid, $kind, 'notoken'); continue; }
      $prefs = array_filter(explode(',', (string)$toks[0]['prefs']));
      if ($kind !== 'test' && (in_array('all', $prefs, true) || in_array(PUSH_GROUP[$kind] ?? '', $prefs, true))) { pushLog($pid, $kind, 'off'); continue; } // гравець вимкнув цю групу
      $tz = (int)$toks[0]['tz']; $loc = time() + $tz * 60; $h = (int)gmdate('G', $loc);
      $now = in_array($kind, PUSH_NOW, true);
      if ($kind === 'gchat' && ($h >= 23 || $h < 8)) { pushLog($pid, $kind, 'night'); continue; } // загальний чат уночі не турбує (і зранку вже неактуальний)
      if (!$now && ($h >= 23 || $h < 8)) { // тихі години — переносимо на ранок за часом гравця
        $wake = gmmktime(8, 0, 0, (int)gmdate('n', $loc), (int)gmdate('j', $loc) + ($h >= 23 ? 1 : 0), (int)gmdate('Y', $loc)) - $tz * 60 + random_int(0, 1800);
        q('INSERT INTO push_queue (player_id, kind, data, send_after) VALUES (?, ?, ?, FROM_UNIXTIME(?))', [$pid, $kind, $r['data'], $wake]); pushLog($pid, $kind, 'morning'); continue;
      }
      if (in_array($kind, PUSH_LATER, true)) { // за хвилину очікування гравець заходив у гру — він уже все бачив
        if ((int)q('SELECT UNIX_TIMESTAMP(last_seen) FROM players WHERE id = ?', [$pid])->fetchColumn() >= (int)($d['at'] ?? 0) + 3) { pushLog($pid, $kind, 'ingame'); continue; }
        if ($kind === 'dm' && !empty($d['mid']) && q('SELECT read_at IS NOT NULL FROM dm_messages WHERE id = ?', [(int)$d['mid']])->fetchColumn()) { pushLog($pid, $kind, 'read'); continue; }
      }
      $day = gmdate('Y-m-d', $loc);
      $st = q('SELECT day, n FROM push_state WHERE player_id = ?', [$pid])->fetch();
      $n = ($st && $st['day'] === $day) ? (int)$st['n'] : 0;
      if (!$now && $kind !== 'adm' && $n >= PUSH_CAP) { pushLog($pid, $kind, 'limit'); continue; } // ліміт на день — не набридаємо
      if (!$now) q('INSERT INTO push_state (player_id, day, n) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE n = IF(day = VALUES(day), n + 1, 1), day = VALUES(day)', [$pid, $day]);
      foreach ($toks as $t) {
        $lang = in_array($t['lang'], PUSH_LANGS, true) ? $t['lang'] : 'uk';
        [$ti, $bo] = pushText($kind, $lang, (array)($d['v'] ?? []));
        $msgs[] = fcmMessage($t['token'], $ti, $bo, $kind, (string)($d['open'] ?? ''), (string)($d['tag'] ?? '')); $meta[] = [$t['token'], $pid, $kind, $bo];
      }
    }
    foreach (fcmSendMany($msgs) as $i => $res) {
      [$tok, $lp, $lk, $lb] = $meta[$i];
      if ($res === 'gone') q('DELETE FROM push_tokens WHERE token = ?', [$tok]); // застосунок видалено — токен більше не дійсний
      pushLog($lp, $lk, $res === 'ok' ? 'sent' : $res, $lb);
    }
  } finally { q("SELECT RELEASE_LOCK(?)", ['mx_push_' . cfg('db_name')]); }
}
// розсилки «всім» → у чергу по 500 гравців за раз
function pushFanout() {
  foreach (q('SELECT id, kind, data, cursor_pid FROM push_bcast WHERE done = 0 ORDER BY id LIMIT 3')->fetchAll() as $b) {
    $d = json_decode((string)$b['data'], true) ?: [];
    $ids = q('SELECT DISTINCT player_id FROM push_tokens WHERE player_id > ? ORDER BY player_id LIMIT 500', [(int)$b['cursor_pid']])->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_chunk($ids, 100) as $ch) {
      $vals = []; $args = [];
      foreach ($ch as $pid) { $vals[] = '(?, ?, ?, UTC_TIMESTAMP())'; array_push($args, (int)$pid, $b['kind'], json_encode(['v' => $d['v'] ?? [], 'open' => $d['open'] ?? '', 'tag' => $b['kind'] . $b['id']], JSON_UNESCAPED_UNICODE)); }
      q('INSERT INTO push_queue (player_id, kind, data, send_after) VALUES ' . implode(',', $vals), $args);
    }
    q('UPDATE push_bcast SET cursor_pid = ?, done = ? WHERE id = ?', [$ids ? (int)end($ids) : (int)$b['cursor_pid'], count($ids) < 500 ? 1 : 0, (int)$b['id']]);
  }
}
// «давно не бачились»: 12 годин → 3 дні → 7 днів (кожне — один раз за відсутність)
function pushIdleScan() {
  $f = sys_get_temp_dir() . '/mx_push_idle_' . md5(cfg('db_name'));
  if (is_file($f) && filemtime($f) > time() - 600) return; @touch($f);
  q('DELETE FROM push_log WHERE created_at < NOW() - INTERVAL 7 DAY');
  $rows = q('SELECT p.id, UNIX_TIMESTAMP(p.last_seen) seen, s.idle_stage, s.idle_ref FROM (SELECT DISTINCT player_id FROM push_tokens) t
             JOIN players p ON p.id = t.player_id LEFT JOIN push_state s ON s.player_id = p.id
             WHERE p.last_seen < UTC_TIMESTAMP() - INTERVAL 12 HOUR AND p.last_seen > UTC_TIMESTAMP() - INTERVAL 30 DAY LIMIT 2000')->fetchAll();
  foreach ($rows as $r) {
    $seen = (int)$r['seen']; $stage = (int)$r['idle_ref'] === $seen ? (int)$r['idle_stage'] : 0; // гравець заходив після останнього нагадування — рахуємо заново
    $away = time() - $seen; $want = $away >= 7 * 86400 ? 3 : ($away >= 3 * 86400 ? 2 : 1);
    if ($stage >= $want) continue;
    q('INSERT INTO push_state (player_id, day, n, idle_stage, idle_ref) VALUES (?, "2000-01-01", 0, ?, ?) ON DUPLICATE KEY UPDATE idle_stage = VALUES(idle_stage), idle_ref = VALUES(idle_ref)', [(int)$r['id'], $want, $seen]);
    q('INSERT INTO push_queue (player_id, kind, data, send_after) VALUES (?, ?, ?, UTC_TIMESTAMP())', [(int)$r['id'], 'idle' . $want, json_encode(['v' => [], 'open' => '', 'tag' => 'idle'])]);
  }
}

// ---------- дії гри ----------
if (isset($a) && strncmp($a, 'push_', 5) === 0) {
  switch ($a) {
    case 'push_reg': {
      $p = me(); ensureSchema();
      $tok = trim((string)arg('token', '')); if ($tok === '' || strlen($tok) > 255) fail('bad_token');
      $lang = in_array(arg('lang'), PUSH_LANGS, true) ? arg('lang') : 'uk';
      $tz = max(-720, min(840, (int)arg('tz', 120)));
      $prefs = implode(',', array_intersect((array)arg('off', []), ['all', 'msg', 'chat', 'promo', 'tour', 'tower']));
      q('INSERT INTO push_tokens (token, player_id, lang, tz, prefs) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE player_id = VALUES(player_id), lang = VALUES(lang), tz = VALUES(tz), prefs = VALUES(prefs), updated_at = NOW()',
        [$tok, $p['id'], $lang, $tz, $prefs]);
      out(['ok' => true, 'on' => pushOn()]);
    }
    case 'push_unreg': {
      me(); $tok = trim((string)arg('token', '')); if ($tok !== '') q('DELETE FROM push_tokens WHERE token = ?', [$tok]);
      out(['ok' => true]);
    }
  }
}
