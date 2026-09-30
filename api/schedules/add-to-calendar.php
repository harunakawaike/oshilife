<?php
/** add-to-calendar.php の役割：有効な公開予定への本人の関連を追加する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('POST');
$raw = readJson();
addScheduleToCalendar($userId, scheduleId($raw['schedule_id'] ?? null));
apiSuccess();
