<?php
/** live.php の役割：旧ブックマークをイベント画面へ案内する互換入口。 */
declare(strict_types=1);
require_once __DIR__.'/../middleware/auth.php';
requireAuth();
$query = http_build_query($_GET);
header('Location: ' . appUrl('events.php') . ($query === '' ? '' : '?' . $query), true, 302);
exit;
