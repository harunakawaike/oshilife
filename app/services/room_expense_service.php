<?php
/** room_expense_service.php の役割：共同支出本体と内訳を一括保存する。変更成功時に精算済み状態を解除する。個人expensesには反映しない。 */
declare(strict_types=1);
require_once __DIR__.'/event_service.php';
require_once __DIR__.'/room_settlement_state_service.php';
require_once __DIR__.'/../repositories/room_expense_repository.php';
require_once __DIR__.'/../validators/room_expense_validator.php';

/** 画面を開いた後の他の人の更新を、古い値で上書きしないため更新番号を照合する。 */
function requireRoomExpenseVersion(array $expense, mixed $version): void
{
    if (positiveOshiId($version)!==(int)$expense['version']) throw new ScheduleOperationException('他の操作で更新されています。画面を再読み込みして内容を確認してください。',409);
}

/** 共同データの変更はactiveな登録者本人またはownerだけ。URLやPOSTを書き換えても権限は増えない。 */
function requireRoomExpenseEditor(array $room, array $expense, int $userId): void
{
    if ($room['my_role']!=='owner' && (int)$expense['created_by_user_id']!==$userId) throw new ScheduleOperationException('編集・取消は登録者またはルームのownerだけが行えます。',403);
    if ($expense['status']!=='active') throw new ScheduleOperationException('取消済みの共同支出は変更できません。',409);
}

/** 本体と複数の負担行（1対多）を一組として保存する。失敗するとeventTransactionが全処理をROLLBACKする。 */
function saveRoomExpense(int $userId, int $roomId, array $raw, ?int $id = null): int
{
    return eventTransaction(function(PDO $pdo) use($userId,$roomId,$raw,$id): int {
        // 退出・ルーム終了・他の保存と同じ順でロックし、検証後に参加状態が変わる競合を防ぐ。
        $room=requireRoom($pdo,$userId,$roomId,false,true,true);
        $existing=$id===null?null:roomExpenseRecord($pdo,$roomId,$id,true);
        if ($existing) {
            requireRoomExpenseEditor($room,$existing,$userId);
            requireRoomExpenseVersion($existing,$raw['version']??null);
        }
        requireSettlementChangeConfirmation($pdo,$userId,$roomId,$raw['settlement_token']??null);
        $input=validateRoomExpenseInput($raw);$shares=$input['shares'];unset($input['shares']);
        $candidates=[];
        foreach(roomExpenseCandidates($pdo,$userId,$roomId) as $member) $candidates[(int)$member['user_id']]=$member;
        $oldShares=[];
        if ($existing) foreach(roomExpenseShares($pdo,$roomId,$id) as $share) $oldShares[(int)$share['user_id']]=$share;
        $payer=$input['paid_by_user_id'];
        if (!isset($candidates[$payer]) && (!$existing || $payer!==(int)$existing['paid_by_user_id'])) throw new ScheduleOperationException('支払者は同じルームの参加中メンバーから選択してください。',422);
        // 退出者を過去の記録から消したり、同意なく負担だけ増減したりしない。既存額の保持だけ許可する。
        foreach($oldShares as $uid=>$old) {
            if (!isset($candidates[$uid]) && (!isset($shares[$uid]) || $shares[$uid]['share_amount']!==(int)$old['share_amount'])) throw new ScheduleOperationException('退出済みメンバーの過去の負担額は保持してください。',422);
        }
        foreach($shares as $uid=>&$share) {
            if (!isset($candidates[$uid]) && !isset($oldShares[$uid])) throw new ScheduleOperationException('負担者は同じルームの参加中メンバーから選択してください。',422);
            $share['display_name_snapshot']=$oldShares[$uid]['display_name_snapshot']??$candidates[$uid]['display_name'];
        }
        unset($share);
        $input['paid_by_name_snapshot']=$existing && $payer===(int)$existing['paid_by_user_id'] ? $existing['paid_by_name_snapshot'] : $candidates[$payer]['display_name'];
        if ($existing) {
            $input['version']=(int)$existing['version']+1;
            writeEventRecord($pdo,'room_expenses',$input,$id);
        } else {
            $input+=['room_id'=>$roomId,'created_by_user_id'=>$userId,'created_by_name_snapshot'=>$candidates[$userId]['display_name']];
            $id=writeEventRecord($pdo,'room_expenses',$input);
        }
        // 編集中に選択を外したactiveメンバーの内訳だけ除く。取消では内訳を削除しない。
        foreach($oldShares as $uid=>$old) if (!isset($shares[$uid])) eventQuery($pdo,'DELETE FROM room_expense_members WHERE id=?',[$old['id']]);
        foreach($shares as $uid=>$share) {
            if (isset($oldShares[$uid])) {
                writeEventRecord($pdo,'room_expense_members',['share_amount'=>$share['share_amount']],(int)$oldShares[$uid]['id']);
            } else {
                writeEventRecord($pdo,'room_expense_members',['room_expense_id'=>$id,'room_id'=>$roomId,...$share]);
            }
        }
        // ブラウザーの計算を信用せず、保存後の実際のSUMも検査して不整合なら本体ごと戻す。
        $sum=(int)eventQuery($pdo,'SELECT SUM(share_amount) FROM room_expense_members WHERE room_expense_id=?',[$id])->fetchColumn();
        if ($sum!==$input['total_amount']) throw new ScheduleOperationException('負担額の合計が支出総額と一致していません',422);
        resetRoomSettlement($pdo,$roomId);
        return $id;
    });
}

/** 取消は履歴を残す状態変更。精算や返金・個人会計の記録は作らない。 */
function cancelRoomExpense(int $userId, int $roomId, int $id, mixed $version, mixed $settlementToken = null): void
{
    eventTransaction(function(PDO $pdo) use($userId,$roomId,$id,$version,$settlementToken): void {
        $room=requireRoom($pdo,$userId,$roomId,false,true,true);
        $expense=roomExpenseRecord($pdo,$roomId,$id,true);
        requireRoomExpenseEditor($room,$expense,$userId);requireRoomExpenseVersion($expense,$version);
        requireSettlementChangeConfirmation($pdo,$userId,$roomId,$settlementToken);
        eventQuery($pdo,"UPDATE room_expenses SET status='cancelled',version=version+1 WHERE id=?",[$id]);
        resetRoomSettlement($pdo,$roomId);
    });
}
