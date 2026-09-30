<?php
/** unadded.php の役割：公開予定を絞り込む。ホーム新着では自分の推しと未追加条件も付ける。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('GET');
apiSuccess(findPublicSchedules(database(), $userId, scheduleFilters(), true));
