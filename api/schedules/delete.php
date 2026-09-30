<?php
/** delete.php の役割：作成者だけが予定を論理削除する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('POST');
$raw = readJson();
deleteSchedule($userId, scheduleId($raw['schedule_id'] ?? null));
apiSuccess();
