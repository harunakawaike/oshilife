<?php
/** status.php の役割：公開予定の感謝件数と本人の選択状態を返す。 */
declare(strict_types=1);
require_once __DIR__ . '/../../../app/helpers/feedback_api.php';
$userId = startScheduleApi('GET');
$id = scheduleId($_GET['schedule_id'] ?? null);
feedbackPublicSchedule($id, $userId);
apiSuccess(reactionStatus(database(), $id, $userId));
