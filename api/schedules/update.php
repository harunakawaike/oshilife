<?php
/** update.php の役割：入力と所有者を確認し、予定本体・メンバー・情報元をまとめて保存する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('POST');
$raw = readJson();
[$input, $errors] = validateScheduleInput($raw);
if ($errors) apiError('入力内容を確認してください。', 422, $errors);
$id = saveSchedule($userId, $input, scheduleId($raw['schedule_id'] ?? null), ($raw['allow_duplicate'] ?? false) === true);
apiSuccess(['schedule' => findScheduleDetail(database(), $id, $userId)], 200);
