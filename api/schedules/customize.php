<?php
/** customize.php の役割：本人の取り込みを自分用に編集し、日時などの自動同期を解除する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('POST');
$raw = readJson();
[$input, $errors] = validateScheduleInput($raw, true);
if ($errors) apiError('入力内容を確認してください。', 422, $errors);
$id = scheduleId($raw['schedule_id'] ?? null);
customizeSchedule($userId, $id, $input);
apiSuccess(['schedule' => findScheduleDetail(database(), $id, $userId)]);
