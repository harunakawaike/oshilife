<?php
/** create.php の役割：修正提案を保存する。元の予定はまだ変更しない。 */
declare(strict_types=1);
require_once __DIR__ . '/../../../app/helpers/feedback_api.php';
$userId = startScheduleApi('POST');
$raw = readJson();
$id = createCorrection($userId, scheduleId($raw['schedule_id'] ?? null), $raw);
apiSuccess(['correction_id' => $id], 201);
