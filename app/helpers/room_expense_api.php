<?php
/** room_expense_api.php の役割：共同支出APIの認証・CSRF・ID検証とJSON応答をまとめる。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_api.php';
require_once PROJECT_ROOT.'/app/services/room_expense_service.php';

/** 閲覧にもログインとルーム認可を要求する。更新時のCSRFは他サイトから勝手に保存されるのを防ぐ。 */
function runRoomExpenseApi(string $action): void
{
    if (!in_array($action,['list','detail','create','update','cancel','members'],true)) apiError('APIが見つかりません。',404);
    $read=in_array($action,['list','detail','members'],true);
    $userId=startScheduleApi($read?'GET':'POST');$raw=$read?$_GET:readJson();
    foreach(['created_by_user_id','created_by_name_snapshot','paid_by_name_snapshot','status','user_id','owner_user_id','role'] as $key) {
        if (array_key_exists($key,$raw)) apiError('変更できない項目が指定されています。',422);
    }
    $roomId=scheduleId($raw['room_id']??null);$pdo=database();
    if ($action==='members') apiSuccess(['members'=>roomExpenseCandidates($pdo,$userId,$roomId)]);
    if ($action==='list') {
        $include=$raw['include_cancelled']??'0';
        if (!in_array($include,['0','1'],true)) apiError('表示条件が正しくありません。',422);
        apiSuccess(['expenses'=>listRoomExpenses($pdo,$userId,$roomId,$include==='1')]);
    }
    if ($action==='create') apiSuccess(['id'=>saveRoomExpense($userId,$roomId,$raw)],201);
    $id=scheduleId($raw['id']??null);
    if ($action==='detail') apiSuccess(['expense'=>findRoomExpense($pdo,$userId,$roomId,$id)]);
    if ($action==='update') saveRoomExpense($userId,$roomId,$raw,$id);
    if ($action==='cancel') cancelRoomExpense($userId,$roomId,$id,$raw['version']??null,$raw['settlement_token']??null);
    apiSuccess(['id'=>$id]);
}
