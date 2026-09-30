<?php
/** calendar.php の役割：本人の月間予定を、同期/個人編集の表示日で取得する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('GET');
$year = positiveOshiId($_GET['year'] ?? null);
$rawMonth = $_GET['month'] ?? null;
// 月はIDではない。FILTER_VALIDATE_INTは「09」を拒否するため、
// 日付から取り出した01〜09も許可し、形式を確認してから10進数へ変換する。
$month = is_string($rawMonth) && preg_match('/^(?:0?[1-9]|1[0-2])$/D', $rawMonth)
    ? (int) $rawMonth
    : null;
if ($year === null || $year < 1000 || $year > 9999 || $month === null || $month > 12) apiError('年月を正しく指定してください。', 422);
$from = sprintf('%04d-%02d-01', $year, $month);
$to = (new DateTimeImmutable($from))->format('Y-m-t');
apiSuccess(['schedules' => findCalendarSchedules(database(), $userId, $from, $to, scheduleOshiFilter($_GET['oshi_id'] ?? null))]);
