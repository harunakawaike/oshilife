<?php
/** monthly-summary.php の役割：本人の公開予定について指定月の共有・取り込み・感謝を集計する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/feedback_api.php';
$userId = startScheduleApi('GET');
$year = positiveOshiId($_GET['year'] ?? date('Y'));
$rawMonth = $_GET['month'] ?? date('m');
$month = is_string($rawMonth) && preg_match('/^(?:0?[1-9]|1[0-2])$/D', $rawMonth) ? (int) $rawMonth : null;
if ($year === null || $year < 1000 || $year > 9998 || $month === null) apiError('年月を正しく指定してください。', 422);
apiSuccess(monthlyFeedbackSummary(database(), $userId, $year, $month, scheduleOshiFilter($_GET['oshi_id'] ?? null)));
