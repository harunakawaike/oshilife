<?php
/** room_personal_money_service.php の役割：精算済み共同支出の本人負担だけを、確認後に個人支出へ反映する。 */
declare(strict_types=1);
require_once __DIR__.'/room_settlement_state_service.php';
require_once __DIR__.'/money_service.php';

/** 既存カテゴリだけへ対応付ける。「その他」は内容不明のため特効対象外のその他遠征にする。 */
function roomPersonalCategory(string $category): string
{
    return ['ticket'=>'live_ticket','transportation'=>'transportation','accommodation'=>'accommodation',
        'food'=>'food','goods'=>'goods','sightseeing'=>'sightseeing','other'=>'other_trip'][$category];
}

/** 確認画面と保存で同じ本人データを使う。他人の個人支出や反映状況は返さない。 */
function roomPersonalPreview(PDO $pdo, int $userId, int $roomId, int $id): array
{
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        try {$result=roomPersonalPreview($pdo,$userId,$roomId,$id);$pdo->commit();return $result;}
        catch (Throwable $error) {$pdo->rollBack();throw $error;}
    }
    // 支出変更・精算確定と同じ親行からロックし、確認中の状態を一貫させる。
    $room=requireRoom($pdo,$userId,$roomId,false,false,true);
    $source=roomExpenseRecord($pdo,$roomId,$id);
    $share=eventQuery($pdo,'SELECT share_amount FROM room_expense_members WHERE room_id=? AND room_expense_id=? AND user_id=?',[$roomId,$id,$userId])->fetchColumn();
    $existing=eventQuery($pdo,"SELECT * FROM expenses WHERE user_id=? AND source_type='room_expense' AND source_id=? FOR UPDATE",[$userId,$id])->fetch() ?: null;
    $state=roomSettlementState($pdo,$roomId);
    $eventId=null;$oshiId=null;
    if ($existing) {$eventId=$existing['event_id'];$oshiId=$existing['oshi_id'];}
    else {
        $events=eventQuery($pdo,'SELECT event_id FROM room_events WHERE room_id=?',[$roomId])->fetchAll(PDO::FETCH_COLUMN);
        if (count($events)===1) {
            // 1件だけでも、非公開イベントや未登録の推しを個人会計へ勝手に関連付けない。
            $event=eventQuery($pdo,"SELECT e.id,s.oshi_id FROM events e JOIN schedules s ON s.id=e.schedule_id
                JOIN user_oshis u ON u.oshi_id=s.oshi_id AND u.user_id=?
                WHERE e.id=? AND s.visibility='public' AND s.status<>'deleted'",[$userId,$events[0]])->fetch();
            if ($event) {$eventId=$event['id'];$oshiId=$event['oshi_id'];}
        }
    }
    $category=roomPersonalCategory($source['category']);
    $values=['user_id'=>$userId,'title'=>$source['title'],'amount'=>moneyDecimal((int)$share*100),
        'category'=>$category,'expense_date'=>$source['expense_date'],'special_effect_eligible'=>(int)MONEY_CATEGORIES[$category]['eligible'],
        'oshi_id'=>$oshiId,'event_id'=>$eventId,'trip_id'=>null,'source_type'=>'room_expense','source_id'=>$id,'room_expense_id'=>null,
        'note'=>$existing['note']??('連番ルーム：'.$room['name'])];
    $differences=[];
    if ($existing) foreach(['title','amount','category','expense_date','special_effect_eligible'] as $key) {
        if ((string)$existing[$key] !== (string)$values[$key]) $differences[]=$key;
    }
    if ($existing && $source['status']==='cancelled') $differences[]='cancelled';
    if ($existing && $share===false) $differences[]='share_removed';
    $ready=$source['status']==='active' && $share!==false && (int)$share>0 && $state['settlement_status']==='settled';
    return ['category_label'=>MONEY_CATEGORIES[$category]['label'],'values'=>$values,'existing'=>$existing,'has_share'=>$share!==false,'share_amount'=>$share===false?null:(int)$share,
        'source_status'=>$source['status'],'settlement_status'=>$state['settlement_status'],'differences'=>$differences,
        'can_create'=>$ready && !$existing,'can_update'=>$ready && $existing && (bool)$differences,'can_delete'=>(bool)$existing,
        // 確認後の共同支出・本人会計の更新も検知し、古い画面から上書きしない。
        'confirmation_token'=>hash('sha256',json_encode([$userId,$roomId,$source['version'],$values,$existing,$state],JSON_THROW_ON_ERROR))];
}

/** 本人の明示操作だけで保存・更新・削除する。精算状態と共同支出には一切書き込まない。 */
function reflectRoomPersonal(int $userId, int $roomId, int $id, string $action, mixed $token): ?int
{
    return eventTransaction(function(PDO $pdo) use($userId,$roomId,$id,$action,$token): ?int {
        $preview=roomPersonalPreview($pdo,$userId,$roomId,$id);
        requireSettlementToken($preview['confirmation_token'],$token);
        if (!in_array($action,['create','update','delete'],true) || !$preview['can_'.$action]) {
            throw new ScheduleOperationException('現在の状態では反映できません。精算状態・本人負担額・反映済みの内容を確認してください。',409);
        }
        $existing=$preview['existing'];
        if ($action==='delete') {
            eventQuery($pdo,"DELETE FROM expenses WHERE id=? AND user_id=? AND source_type='room_expense' AND source_id=?",[$existing['id'],$userId,$id]);
            return null;
        }
        $values=$preview['values'];
        validateMoneyInput($values,'expenses');
        // UNIQUE(user_id,source_type,source_id)でも同時・二重登録を止める。
        return writeEventRecord($pdo,'expenses',$values,$existing?(int)$existing['id']:null);
    });
}
