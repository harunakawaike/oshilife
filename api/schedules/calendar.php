<?php
/** calendar.php の役割：本人の月間予定を、同期/個人編集の表示日で取得する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('GET');
$year = positiveOshiId($_GET['year'] ?? null);
$month = positiveOshiId($_GET['month'] ?? null);
if ($year === null || $year < 1000 || $year > 9999 || $month === null || $month > 12) apiError('年月を正しく指定してください。', 422);
$from = sprintf('%04d-%02d-01', $year, $month);
$to = (new DateTimeImmutable($from))->format('Y-m-t');
apiSuccess(['schedules' => findCalendarSchedules(database(), $userId, $from, $to, scheduleOshiFilter($_GET['oshi_id'] ?? null))]);
