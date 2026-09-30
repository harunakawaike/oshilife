<?php
/** list.php の役割：本人の指定日の予定を返す。未指定なら日本時間の今日。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('GET');
$date = $_GET['date'] ?? date('Y-m-d');
if (!is_string($date) || !validScheduleDate($date)) apiError('日付を正しく指定してください。', 422);
apiSuccess(['schedules' => findCalendarSchedules(database(), $userId, $date, $date, scheduleOshiFilter($_GET['oshi_id'] ?? null))]);
