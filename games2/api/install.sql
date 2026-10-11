-- ============================================================
--  «Мій Хмарочос UA» — схема бази даних (MySQL 5.7+ / MariaDB 10.3+)
--  Імпортуй цей файл у базу game_ua1 (phpMyAdmin → Імпорт) або відкрий api/install.php
-- ============================================================
SET NAMES utf8mb4;

-- Гравці
CREATE TABLE IF NOT EXISTS players (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nick          VARCHAR(20)  NOT NULL,
  nick_lc       VARCHAR(20)  NOT NULL,
  pass_hash     VARCHAR(255) NULL,
  avatar        VARCHAR(16)  NOT NULL DEFAULT 'av_25',
  lang          VARCHAR(4)   NOT NULL DEFAULT 'uk',
  is_admin      TINYINT(1)   NOT NULL DEFAULT 0,
  banned        TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nick (nick_lc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сесії (токени входу; у базі зберігається лише хеш токена)
CREATE TABLE IF NOT EXISTS sessions (
  token_hash    CHAR(64)     NOT NULL,
  player_id     INT UNSIGNED NOT NULL,
  device        VARCHAR(120) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_hash),
  KEY ix_player (player_id),
  CONSTRAINT fk_sess_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Повний стан гри гравця (поверхи, баланси, жителі, завдання…), JSON
CREATE TABLE IF NOT EXISTS saves (
  player_id     INT UNSIGNED NOT NULL,
  rev           INT UNSIGNED NOT NULL DEFAULT 0,
  data          MEDIUMTEXT   NOT NULL,
  active_token  CHAR(64)     NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  CONSTRAINT fk_save_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ключові показники гравця окремими колонками (для рейтингів і аналітики без розбору JSON)
CREATE TABLE IF NOT EXISTS player_stats (
  player_id     INT UNSIGNED NOT NULL,
  level         INT UNSIGNED NOT NULL DEFAULT 1,
  xp            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  coins         DOUBLE       NOT NULL DEFAULT 0,
  bucks         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  floors        INT UNSIGNED NOT NULL DEFAULT 0,
  businesses    INT UNSIGNED NOT NULL DEFAULT 0,
  residents     INT UNSIGNED NOT NULL DEFAULT 0,
  cups          INT UNSIGNED NOT NULL DEFAULT 0,
  earned        DOUBLE       NOT NULL DEFAULT 0,
  rev_hour      DOUBLE       NOT NULL DEFAULT 0,
  maze_keys     INT UNSIGNED NOT NULL DEFAULT 0,
  lift_lvl      INT UNSIGNED NOT NULL DEFAULT 1,
  lobby_lvl     INT UNSIGNED NOT NULL DEFAULT 1,
  hotel_lvl     INT UNSIGNED NOT NULL DEFAULT 1,
  chests        INT UNSIGNED NOT NULL DEFAULT 0,
  achievements  INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  KEY ix_level (level), KEY ix_floors (floors), KEY ix_earned (earned), KEY ix_cups (cups),
  CONSTRAINT fk_stats_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Поверхи гравця (дзеркало стану гри для перегляду й аналітики в базі)
CREATE TABLE IF NOT EXISTS player_floors (
  player_id     INT UNSIGNED NOT NULL,
  floor_no      INT UNSIGNED NOT NULL,
  floor_id      INT UNSIGNED NOT NULL,
  type          VARCHAR(16)  NOT NULL,
  biz           VARCHAR(32)  NULL,
  stars         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  level         INT UNSIGNED NOT NULL DEFAULT 0,
  workers       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  slots         VARCHAR(64)  NULL,
  PRIMARY KEY (player_id, floor_no),
  CONSTRAINT fk_pf_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Жителі гравця
CREATE TABLE IF NOT EXISTS player_residents (
  player_id     INT UNSIGNED NOT NULL,
  res_id        INT UNSIGNED NOT NULL,
  name          VARCHAR(40)  NOT NULL,
  ch            VARCHAR(16)  NOT NULL,
  dream         VARCHAR(32)  NULL,
  job_floor     INT UNSIGNED NULL,
  rank_lvl      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  stars         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  happy         TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (player_id, res_id),
  CONSTRAINT fk_pr_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Історія подій гравця (будівництва, покупки, нагороди…)
CREATE TABLE IF NOT EXISTS history (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  player_id     INT UNSIGNED NOT NULL,
  kind          VARCHAR(32)  NOT NULL,
  info          VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_player_time (player_id, created_at),
  CONSTRAINT fk_hist_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Загальні новини від адміністрації
CREATE TABLE IF NOT EXISTS news (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  icon          VARCHAR(32)  NOT NULL DEFAULT 'megaphone2',
  title         VARCHAR(120) NOT NULL,
  body          TEXT         NOT NULL,
  published     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_pub (published, id)
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Особиста пошта (player_id NULL = лист для всіх гравців)
CREATE TABLE IF NOT EXISTS mail (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  player_id     INT UNSIGNED NULL,
  sender        VARCHAR(40)  NOT NULL DEFAULT 'Адміністрація',
  icon          VARCHAR(32)  NOT NULL DEFAULT 'mail2',
  title         VARCHAR(120) NOT NULL,
  body          TEXT         NOT NULL,
  reward        VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_player (player_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Які листи гравець уже отримав / забрав
CREATE TABLE IF NOT EXISTS mail_claims (
  mail_id       INT UNSIGNED NOT NULL,
  player_id     INT UNSIGNED NOT NULL,
  claimed_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (mail_id, player_id),
  CONSTRAINT fk_mc_mail FOREIGN KEY (mail_id) REFERENCES mail(id) ON DELETE CASCADE,
  CONSTRAINT fk_mc_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Загальний чат
CREATE TABLE IF NOT EXISTS chat_messages (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  player_id     INT UNSIGNED NOT NULL,
  body          VARCHAR(300) NOT NULL,
  reply_to      INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_player (player_id),
  CONSTRAINT fk_chat_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Форум: теми та повідомлення
CREATE TABLE IF NOT EXISTS forum_topics (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  player_id     INT UNSIGNED NOT NULL,
  title         VARCHAR(120) NOT NULL,
  posts         INT UNSIGNED NOT NULL DEFAULT 0,
  pinned        TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_post_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_order (pinned, last_post_at),
  CONSTRAINT fk_ft_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_posts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  topic_id      INT UNSIGNED NOT NULL,
  player_id     INT UNSIGNED NOT NULL,
  body          TEXT         NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_topic (topic_id, id),
  CONSTRAINT fk_fp_topic FOREIGN KEY (topic_id) REFERENCES forum_topics(id) ON DELETE CASCADE,
  CONSTRAINT fk_fp_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Обмеження частоти запитів (антиспам)
CREATE TABLE IF NOT EXISTS rate_limits (
  rkey          VARCHAR(80)  NOT NULL,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  window_start  INT UNSIGNED NOT NULL,
  PRIMARY KEY (rkey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Друзі (кого гравець додав у друзі)
CREATE TABLE IF NOT EXISTS friends (
  player_id     INT UNSIGNED NOT NULL,
  friend_id     INT UNSIGNED NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id, friend_id),
  KEY ix_friend (friend_id),
  CONSTRAINT fk_fr_p FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_fr_f FOREIGN KEY (friend_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Чорний список (заблоковані гравці не можуть писати й не видні в чаті)
CREATE TABLE IF NOT EXISTS player_blocks (
  player_id     INT UNSIGNED NOT NULL,
  blocked_id    INT UNSIGNED NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id, blocked_id),
  KEY ix_blocked (blocked_id),
  CONSTRAINT fk_bl_p FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_bl_b FOREIGN KEY (blocked_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Особисті повідомлення між гравцями
CREATE TABLE IF NOT EXISTS dm_messages (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_id       INT UNSIGNED NOT NULL,
  to_id         INT UNSIGNED NOT NULL,
  body          TEXT         NOT NULL,
  reply_to      INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at       DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_to (to_id, read_at),
  KEY ix_pair (from_id, to_id, id),
  CONSTRAINT fk_dm_f FOREIGN KEY (from_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_dm_t FOREIGN KEY (to_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== Адміністрування =====================
-- Права доступу: players.access = 1 — адміністратор, 0 — звичайний гравець (колонка додається автоматично)
-- Акції від адміністрації (банер у новинах + подарунок, який кожен гравець може забрати один раз)
CREATE TABLE IF NOT EXISTS promos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title         VARCHAR(120) NOT NULL,
  body          TEXT         NOT NULL,
  icon          VARCHAR(32)  NOT NULL DEFAULT 'gift_red',
  color         VARCHAR(16)  NOT NULL DEFAULT '#7a4fe0',
  rewards       TEXT         NULL,
  starts_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ends_at       DATETIME     NOT NULL,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_active (active, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promo_claims (
  promo_id      INT UNSIGNED NOT NULL,
  player_id     INT UNSIGNED NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (promo_id, player_id),
  CONSTRAINT fk_pc_promo FOREIGN KEY (promo_id) REFERENCES promos(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Знімки балансів гравців (не частіше разу на годину) — для графіків
CREATE TABLE IF NOT EXISTS balance_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  player_id     INT UNSIGNED NOT NULL,
  t             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  coins         DOUBLE       NOT NULL DEFAULT 0,
  bucks         BIGINT       NOT NULL DEFAULT 0,
  level         INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_pt (player_id, t),
  KEY ix_t (t),
  CONSTRAINT fk_bl_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Економіка гри по днях (загальні суми) — для графіків в адмін-панелі
CREATE TABLE IF NOT EXISTS econ_daily (
  day           DATE         NOT NULL,
  players       INT UNSIGNED NOT NULL DEFAULT 0,
  coins         DOUBLE       NOT NULL DEFAULT 0,
  bucks         BIGINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал дій адміністраторів
CREATE TABLE IF NOT EXISTS admin_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id      INT UNSIGNED NULL,
  action        VARCHAR(32)  NOT NULL,
  target        VARCHAR(64)  NULL,
  info          VARCHAR(500) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Отримані нагороди (кожна нагорода має унікальний ключ — отримати двічі неможливо)
CREATE TABLE IF NOT EXISTS reward_claims (
  player_id     INT UNSIGNED NOT NULL,
  ckey          VARCHAR(80)  NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id, ckey),
  CONSTRAINT fk_rc_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Подарунки між гравцями
CREATE TABLE IF NOT EXISTS gifts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_id       INT UNSIGNED NOT NULL,
  to_id         INT UNSIGNED NOT NULL,
  gift          VARCHAR(16)  NOT NULL,
  price         INT UNSIGNED NOT NULL DEFAULT 0,
  body          VARCHAR(300) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_to (to_id, id),
  CONSTRAINT fk_gf_from FOREIGN KEY (from_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_gf_to FOREIGN KEY (to_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Налаштування сервера (технічні роботи, оголошення)
CREATE TABLE IF NOT EXISTS settings (
  k             VARCHAR(32)  NOT NULL,
  v             TEXT         NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== Корпорації =====================
CREATE TABLE IF NOT EXISTS corps (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(24)  NOT NULL,
  name_lc       VARCHAR(24)  NOT NULL,
  emblem        VARCHAR(16)  NOT NULL DEFAULT 'shield_1',
  director_id   INT UNSIGNED NOT NULL,
  bucks         BIGINT       NOT NULL DEFAULT 0,
  coins         DOUBLE       NOT NULL DEFAULT 0,
  bld           TEXT         NULL,
  coll          TEXT         NULL,
  tasks         TEXT         NULL,
  sets          INT UNSIGNED NOT NULL DEFAULT 0,
  tasks_done    INT UNSIGNED NOT NULL DEFAULT 0,
  lvl_sum       INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_corp_name (name_lc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corp_members (
  player_id     INT UNSIGNED NOT NULL,
  corp_id       INT UNSIGNED NOT NULL,
  role          TINYINT      NOT NULL DEFAULT 0,
  joined_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  d_bucks       BIGINT       NOT NULL DEFAULT 0,
  d_coins       DOUBLE       NOT NULL DEFAULT 0,
  PRIMARY KEY (player_id),
  KEY ix_corp (corp_id),
  CONSTRAINT fk_cm_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_corp FOREIGN KEY (corp_id) REFERENCES corps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corp_invites (
  player_id     INT UNSIGNED NOT NULL,
  corp_id       INT UNSIGNED NOT NULL,
  from_id       INT UNSIGNED NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  CONSTRAINT fk_ci_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_ci_corp FOREIGN KEY (corp_id) REFERENCES corps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corp_don (
  player_id     INT UNSIGNED NOT NULL,
  day           DATE         NOT NULL,
  bucks         BIGINT       NOT NULL DEFAULT 0,
  coins         DOUBLE       NOT NULL DEFAULT 0,
  PRIMARY KEY (player_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corp_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id       INT UNSIGNED NOT NULL,
  player_id     INT UNSIGNED NULL,
  kind          VARCHAR(16)  NOT NULL,
  info          VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_corp (corp_id, id),
  CONSTRAINT fk_cl_corp FOREIGN KEY (corp_id) REFERENCES corps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Приватний чат корпорації
CREATE TABLE IF NOT EXISTS corp_chat (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id       INT UNSIGNED NOT NULL,
  player_id     INT UNSIGNED NOT NULL,
  body          VARCHAR(500) NOT NULL,
  reply_to      INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_corp (corp_id, id),
  CONSTRAINT fk_cc_corp FOREIGN KEY (corp_id) REFERENCES corps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Переговори з інвесторами: одна сесія на корпорацію, учасники — окремо
CREATE TABLE IF NOT EXISTS corp_invest (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id       INT UNSIGNED NOT NULL,
  starter_id    INT UNSIGNED NOT NULL,
  mode          VARCHAR(8)   NOT NULL DEFAULT 'easy',
  seed          INT UNSIGNED NOT NULL DEFAULT 1,
  lvl           INT UNSIGNED NOT NULL DEFAULT 1,
  state         TINYINT      NOT NULL DEFAULT 0,   -- 0 триває, 2 завершено
  started_at    INT UNSIGNED NOT NULL DEFAULT 0,
  begin_at      INT UNSIGNED NOT NULL DEFAULT 0,   -- кінець збору / перше питання
  ended_at      INT UNSIGNED NOT NULL DEFAULT 0,
  score         DOUBLE       NOT NULL DEFAULT 0,
  need          DOUBLE       NOT NULL DEFAULT 0,
  pct           DOUBLE       NOT NULL DEFAULT 0,
  ok            TINYINT      NOT NULL DEFAULT 0,
  r_bucks       BIGINT       NOT NULL DEFAULT 0,
  r_coins       DOUBLE       NOT NULL DEFAULT 0,
  r_xp          DOUBLE       NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_corp (corp_id, id),
  KEY ix_live (corp_id, state),
  CONSTRAINT fk_inv_corp FOREIGN KEY (corp_id) REFERENCES corps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corp_invest_p (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  inv_id        INT UNSIGNED NOT NULL,
  player_id     INT UNSIGNED NOT NULL,
  rev           DOUBLE       NOT NULL DEFAULT 0,   -- виручка за годину, зафіксована на вході
  mul           DOUBLE       NOT NULL DEFAULT 1,   -- очок за правильну відповідь
  score         DOUBLE       NOT NULL DEFAULT 0,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  answers       TINYINT      NOT NULL DEFAULT 0,
  last_round    INT          NOT NULL DEFAULT -1,
  share         DOUBLE       NOT NULL DEFAULT 0,
  joined_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_player (inv_id, player_id),
  KEY ix_player (player_id),
  CONSTRAINT fk_invp_inv FOREIGN KEY (inv_id) REFERENCES corp_invest(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заявки на донат: гравець обирає пакет → адміністратор після ручної оплати підтверджує або відхиляє.
-- Бонус акції фіксується в момент заявки, щоб гравець отримав саме те, що бачив.
CREATE TABLE IF NOT EXISTS donations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  player_id   INT UNSIGNED NOT NULL,
  pack        TINYINT UNSIGNED NOT NULL,
  bucks       INT UNSIGNED NOT NULL,
  uah         INT UNSIGNED NOT NULL,
  bonus_pct   INT UNSIGNED NOT NULL DEFAULT 0,
  bonus_rw    TEXT NULL,
  status      VARCHAR(10) NOT NULL DEFAULT 'pending',
  admin_id    INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  done_at     DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_status (status, id),
  KEY ix_player (player_id, id),
  CONSTRAINT fk_don_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Щотижневий турнір кубків (пн–сб за Києвом, неділя — відпочинок). week — дата понеділка.
CREATE TABLE IF NOT EXISTS tour_cups (
  week        DATE NOT NULL,
  player_id   INT UNSIGNED NOT NULL,
  cups        INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (week, player_id),
  KEY ix_rank (week, cups),
  CONSTRAINT fk_tc_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_corp (
  week        DATE NOT NULL,
  corp_id     INT UNSIGNED NOT NULL,
  cups        INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (week, corp_id),
  KEY ix_rank (week, cups),
  CONSTRAINT fk_tcc_corp FOREIGN KEY (corp_id) REFERENCES corps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кеш машинного перекладу повідомлень, новин і акцій (щоб кожен текст перекладався лише раз на мову)
CREATE TABLE IF NOT EXISTS tr_cache (
  h           CHAR(40) NOT NULL,
  lang        VARCHAR(5) NOT NULL,
  src         VARCHAR(8) NULL,
  out_text    TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (h)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Пуш-сповіщення (Firebase): токени пристроїв, черга, розсилки, лічильники
-- Бонус-коди від адміністрації: код латиницею, нагорода й ліміт активацій
CREATE TABLE IF NOT EXISTS bonus_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(24) CHARACTER SET ascii NOT NULL,
  reward      TEXT         NOT NULL,
  max_uses    INT UNSIGNED NOT NULL DEFAULT 0,   -- 0 — без обмежень
  used        INT UNSIGNED NOT NULL DEFAULT 0,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  note        VARCHAR(120) NULL,
  expires_at  INT UNSIGNED NOT NULL DEFAULT 0,   -- 0 — безстроково
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bonus_code_uses (
  code_id     INT UNSIGNED NOT NULL,
  player_id   INT UNSIGNED NOT NULL,
  used_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (code_id, player_id),
  KEY ix_player (player_id),
  CONSTRAINT fk_bcu_code FOREIGN KEY (code_id) REFERENCES bonus_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_tokens (
  token       VARCHAR(255) CHARACTER SET ascii NOT NULL,
  player_id   INT UNSIGNED NOT NULL,
  lang        VARCHAR(4) NOT NULL DEFAULT 'uk',
  tz          SMALLINT NOT NULL DEFAULT 120,
  prefs       VARCHAR(64) NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token),
  KEY ix_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Підписки браузера на сповіщення (Web Push). ep_hash — бо адреса буває довша за індекс
CREATE TABLE IF NOT EXISTS push_web (
  ep_hash     CHAR(40) CHARACTER SET ascii NOT NULL,
  endpoint    VARCHAR(500) NOT NULL,
  p256dh      VARCHAR(255) CHARACTER SET ascii NOT NULL,
  auth        VARCHAR(64)  CHARACTER SET ascii NOT NULL,
  player_id   INT UNSIGNED NOT NULL,
  lang        VARCHAR(4) NOT NULL DEFAULT 'uk',
  tz          SMALLINT NOT NULL DEFAULT 120,
  prefs       VARCHAR(64) NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ep_hash),
  KEY ix_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_queue (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  player_id   INT UNSIGNED NOT NULL,
  kind        VARCHAR(16) NOT NULL,
  data        TEXT NULL,
  send_after  DATETIME NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_after (send_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_bcast (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind        VARCHAR(16) NOT NULL,
  data        TEXT NULL,
  cursor_pid  INT UNSIGNED NOT NULL DEFAULT 0,
  done        TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_state (
  player_id   INT UNSIGNED NOT NULL,
  day         DATE NOT NULL DEFAULT '2000-01-01',
  n           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  idle_stage  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  idle_ref    INT UNSIGNED NOT NULL DEFAULT 0,
  cchat_at    INT UNSIGNED NOT NULL DEFAULT 0,
  gchat_at    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал сповіщень (для перевірки в адмінці; зберігається 7 днів)
CREATE TABLE IF NOT EXISTS push_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  player_id   INT UNSIGNED NOT NULL,
  kind        VARCHAR(16) NOT NULL,
  res         VARCHAR(12) NOT NULL,
  info        VARCHAR(160) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS online_log (
  t             INT UNSIGNED NOT NULL,
  n             INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (t)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
