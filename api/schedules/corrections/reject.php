<?php
/** reject.php の役割：投稿者本人が修正提案を却下する。履歴は残す。 */
declare(strict_types=1);
require_once __DIR__ . '/../../../app/helpers/feedback_api.php';
$userId = startScheduleApi('POST');
$raw = readJson();
reviewCorrection($userId, scheduleId($raw['correction_id'] ?? null), false);
apiSuccess(['status' => 'rejected']);
