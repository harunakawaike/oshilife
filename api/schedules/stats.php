<?php
/** stats.php の役割：投稿者本人だけに公開予定の利用件数を返す。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/feedback_api.php';
$userId = startScheduleApi('GET');
$id = scheduleId($_GET['schedule_id'] ?? null);
$schedule = findScheduleDetail(database(), $id, $userId);
if (!$schedule || !$schedule['is_owner'] || $schedule['visibility'] !== 'public') apiError('共有状況を確認できません。', 404);
apiSuccess(scheduleFeedbackStats(database(), $id));
