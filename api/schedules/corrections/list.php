<?php
/** list.php の役割：本人の予定へ届いた提案と確認済み履歴を返す。 */
declare(strict_types=1);
require_once __DIR__ . '/../../../app/helpers/feedback_api.php';
$userId = startScheduleApi('GET');
$status = $_GET['status'] ?? 'pending';
$page = positiveOshiId($_GET['page'] ?? 1);
if (!is_string($status) || !in_array($status, ['pending','approved','rejected','all'], true) || $page === null || $page > 10000) apiError('一覧の条件を確認してください。', 422);
apiSuccess(listCorrectionRequests(database(), $userId, $status, $page));
