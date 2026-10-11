<?php
// ============================================================
//  Налаштування сервера «Мій Хмарочос UA».
//  Цей файл лежить ТІЛЬКИ на сервері і ніколи не потрапляє в браузер.
// ============================================================
return [
  'db_host' => getenv('GAME_DB_HOST') ?: 'localhost',
  'db_name' => getenv('GAME_DB_NAME') ?: 'game_ua1',
  'db_user' => getenv('GAME_DB_USER') ?: 'game_ua1_usr',
  'db_pass' => getenv('GAME_DB_PASS') ?: 'f?Y.LsSZl@x~d.[@',
  // Ключ адміністратора для admin.php (зміни на свій довгий випадковий рядок!)
  'admin_key' => getenv('GAME_ADMIN_KEY') ?: 'zminy-mene-na-dovgyi-sekretnyi-kliuch',
  // Звідки дозволені запити (домен гри). '*' — з будь-якого.
  'cors_origin' => '*',
];
