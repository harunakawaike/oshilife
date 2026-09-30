<?php
/** feedback_api.php の役割：Phase 4 APIへ既存の認証・CSRF・例外処理と新しいサービスを読み込む。 */
declare(strict_types=1);
require_once __DIR__ . '/schedule_api.php';
require_once PROJECT_ROOT . '/app/services/feedback_service.php';

/** 予定の閲覧制御を通してから件数やリアクション状態を返す。 */
function feedbackPublicSchedule(int $id, int $userId): array
{
    $schedule = findScheduleDetail(database(), $id, $userId);
    return requireFeedbackSchedule($schedule);
}
