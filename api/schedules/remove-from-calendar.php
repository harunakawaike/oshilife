<?php
/** remove-from-calendar.php の役割：本人の取り込みだけを解除する。元予定は削除しない。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('POST');
$raw = readJson();
removeScheduleFromCalendar($userId, scheduleId($raw['schedule_id'] ?? null));
apiSuccess();
