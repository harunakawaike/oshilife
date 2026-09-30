<?php
/** toggle.php の役割：本人の助かった・ありがとうを追加または解除する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../../app/helpers/feedback_api.php';
$userId = startScheduleApi('POST');
$raw = readJson();
apiSuccess(toggleReaction($userId, scheduleId($raw['schedule_id'] ?? null), inputString($raw, 'reaction_type')));
