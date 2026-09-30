<?php
/** approve.php の役割：投稿者本人が修正提案を承認し共有元を更新する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../../app/helpers/feedback_api.php';
$userId = startScheduleApi('POST');
$raw = readJson();
reviewCorrection($userId, scheduleId($raw['correction_id'] ?? null), true);
apiSuccess(['status' => 'approved']);
