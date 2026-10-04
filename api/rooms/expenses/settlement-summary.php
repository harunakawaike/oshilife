<?php
/** settlement-summary.php の役割：参加中メンバーにだけ、保存しない精算サマリーをJSONで返す。 */
declare(strict_types=1);
require_once __DIR__.'/../../../app/helpers/schedule_api.php';
require_once PROJECT_ROOT.'/app/services/room_settlement_service.php';
$userId=startScheduleApi('GET');$roomId=scheduleId($_GET['room_id']??null);
try { apiSuccess(roomSettlementSummary(database(),$userId,$roomId)); }
catch (RoomSettlementDataException $error) { apiError('共同支出の整合性を確認できないため、精算サマリーを表示できません。',503); }
