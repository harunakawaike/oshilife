<?php
/** room_message_api.php の役割：トークAPIの認証・CSRF・カーソル検証を共通化する。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_api.php';
require_once PROJECT_ROOT.'/app/services/room_message_service.php';

/** URLを書き換えても、各サービスでルームのactive memberか再確認する。 */
function runRoomMessageApi(string $action): void
{
    $read=$action==='list';
    $userId=startScheduleApi($read?'GET':'POST');$raw=$read?$_GET:readJson();
    // 投稿者名やIDはDB・セッションから決め、偽装入力は無視せず拒否する。
    foreach(['user_id','display_name_snapshot','status','created_at'] as $key) if (array_key_exists($key,$raw)) apiError('指定できない項目です。',422);
    $roomId=scheduleId($raw['room_id']??null);
    if ($action==='send') apiSuccess(['id'=>sendRoomMessage($userId,$roomId,$raw['message']??null)],201);
    if ($action==='delete') {$id=scheduleId($raw['id']??null);deleteRoomMessage($userId,$roomId,$id);apiSuccess(['id'=>$id]);}
    $after=null;
    if (isset($raw['after_id'])) {
        if (is_string($raw['after_id']) && $raw['after_id']==='0') $after=0;
        else $after=scheduleId($raw['after_id']);
    }
    $known=$raw['known_ids']??'';
    if (!is_string($known)) apiError('メッセージIDが不正です。',422);
    $ids=$known===''?[]:explode(',',$known);
    if (count($ids)>100) apiError('メッセージIDが多すぎます。',422);
    $ids=array_map('scheduleId',$ids);
    apiSuccess(listRoomMessages(database(),$userId,$roomId,$after,$ids));
}
