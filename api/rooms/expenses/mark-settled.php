<?php
/** mark-settled.php の役割：認証・CSRF確認後、ownerによるルーム全体の精算完了を記録する。 */
declare(strict_types=1);
require_once __DIR__.'/../../../app/helpers/schedule_api.php';
require_once PROJECT_ROOT.'/app/services/room_settlement_state_service.php';
$userId=startScheduleApi('POST');$raw=readJson();$roomId=scheduleId($raw['room_id']??null);
try {
    markRoomSettled($userId,$roomId,$raw['confirmation_token']??null);
    apiSuccess(['room_id'=>$roomId]);
} catch (RoomSettlementDataException $error) {
    apiError('共同支出の整合性を確認できないため、精算を確定できません。',503);
}
