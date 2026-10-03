<?php
/** room_api.php の役割：連番ルームAPIの認証・CSRF・認可・JSON応答を統一し、旧検索招待は停止する。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_api.php';
require_once PROJECT_ROOT.'/app/services/room_link_service.php';

/** 固定APIファイルの操作だけを実行し、所有者や参加本人はセッションから確定する。 */
function runRoomApi(string $route): void
{
    // 古い画面から呼ばれても検索や招待・参加は行わない。既存招待履歴はDBに保持する。
    if (str_starts_with($route,'invitations/')) {
        requireAuth(true);
        apiError('招待方法が変更されました。ルームの招待リンクをご利用ください。',410);
    }
    $reads=['list','detail','events/list','events/available','members/list','links/list','links/preview'];
    $writes=['create','update','close','events/add','events/remove','members/leave','links/create','links/revoke','links/join'];
    if (!in_array($route,[...$reads,...$writes],true)) apiError('APIが見つかりません。',404);
    $read=in_array($route,$reads,true);
    $userId=startScheduleApi($read?'GET':'POST');$pdo=database();$raw=$read?$_GET:readJson();
    foreach(['owner_user_id','room_owner_user_id','inviter_user_id','invited_user_id','created_by_user_id','user_id','role','status'] as $key) {
        if (array_key_exists($key,$raw)) apiError('変更できない項目が指定されています。',422);
    }
    if ($route==='links/preview') apiSuccess(previewRoomInviteLink($pdo,$userId,$raw['token']??null));
    if ($route==='links/join') apiSuccess(joinRoomInviteLink($userId,$raw['token']??null));
    if ($route==='create') apiSuccess(['id'=>createRoom($userId,eventText($raw,'name','ルーム名',150,true))],201);
    if ($route==='list') apiSuccess(['rooms'=>listRooms($pdo,$userId)]);
    $roomId=scheduleId($raw['room_id']??null);
    if ($route==='detail') {
        $room=requireRoom($pdo,$userId,$roomId);
        apiSuccess(['room'=>['id'=>$room['id'],'name'=>$room['name'],'status'=>$room['status'],'my_role'=>$room['my_role']],
            'members'=>roomMembers($pdo,$userId,$roomId),'events'=>roomEvents($pdo,$userId,$roomId)]);
    }
    if ($route==='members/list') apiSuccess(['members'=>roomMembers($pdo,$userId,$roomId)]);
    if ($route==='events/list') apiSuccess(['events'=>roomEvents($pdo,$userId,$roomId)]);
    if ($route==='events/available') apiSuccess(['events'=>roomAvailableEvents($pdo,$userId,$roomId)]);
    if ($route==='links/list') apiSuccess(['links'=>roomInviteLinks($pdo,$userId,$roomId)]);
    if ($route==='links/create') apiSuccess(createRoomInviteLink($userId,$roomId),201);
    if ($route==='links/revoke') revokeRoomInviteLink($userId,$roomId,scheduleId($raw['id']??null));
    elseif ($route==='update' || $route==='close') changeRoom($userId,$roomId,$route,$route==='update'?eventText($raw,'name','ルーム名',150,true):null);
    elseif ($route==='members/leave') leaveRoom($userId,$roomId);
    elseif ($route==='events/add' || $route==='events/remove') changeRoomEvent($userId,$roomId,scheduleId($raw['event_id']??null),$route==='events/add');
    apiSuccess(['room_id'=>$roomId]);
}
