<?php
/** live_form.php の役割：旧ブックマークをイベント画面へ案内する互換入口。 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once APP_PRIVATE_ROOT . '/middleware/auth.php';
requireAuth();
$query = http_build_query($_GET);
header('Location: ' . appUrl('event_form.php') . ($query === '' ? '' : '?' . $query), true, 302);
exit;
