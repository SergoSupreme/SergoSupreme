<?php
// Одноразове створення таблиць. Відкрий у браузері: /api/install.php?key=ТВІЙ_ADMIN_KEY
require __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== cfg('admin_key')) { http_response_code(403); exit("Невірний ключ\n"); }
$sql = file_get_contents(__DIR__ . '/install.sql');
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
  $lines = array_filter(explode("\n", $stmt), fn($l) => !str_starts_with(trim($l), '--'));
  $stmt = trim(implode("\n", $lines)); if ($stmt === '') continue;
  db()->exec($stmt);
}
echo "Готово: таблиці створено.\n";
