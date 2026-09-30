<?php
/** detail.php の役割：privateや削除済みも含め閲覧権限を確認してから詳細を返す。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('GET');
$schedule = findScheduleDetail(database(), scheduleId($_GET['id'] ?? null), $userId);
if (!$schedule) apiError('予定が見つからないか、閲覧できません。', 404);
apiSuccess(['schedule' => $schedule]);
