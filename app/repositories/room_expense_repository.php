<?php
/** room_expense_repository.php の役割：ルームの共同支出と負担内訳を取得する。個人expensesは参照・更新しない。 */
declare(strict_types=1);
require_once __DIR__.'/room_repository.php';
require_once __DIR__.'/../../config/room_expenses.php';

/** 支払・負担候補は同じルームの参加中ユーザーだけ。内部IDは選択値に使い、画面の名前には表示しない。 */
function roomExpenseCandidates(PDO $pdo, int $userId, int $roomId): array
{
    requireRoom($pdo,$userId,$roomId);
    return eventQuery($pdo,"SELECT m.id AS member_id,m.user_id,u.display_name FROM room_members m
        JOIN users u ON u.id=m.user_id WHERE m.room_id=? AND m.status='active' AND u.deleted_at IS NULL ORDER BY m.id",[$roomId])->fetchAll();
}

/** 支出ID単独では検索しない。別ルームのIDに書き換えても取得できないよう必ずroom_idも照合する。 */
function roomExpenseRecord(PDO $pdo, int $roomId, int $id, bool $lock = false): array
{
    return eventQuery($pdo,'SELECT * FROM room_expenses WHERE room_id=? AND id=?'.($lock?' FOR UPDATE':''),[$roomId,$id])->fetch()
        ?: throw new ScheduleOperationException('共同支出が見つかりません。',404);
}

/** 表示名は保存時の写しを返すため、改名・退出後も当時の支払者や負担者を判別できる。 */
function roomExpenseShares(PDO $pdo, int $roomId, int $id): array
{
    return eventQuery($pdo,"SELECT s.id,s.user_id,s.display_name_snapshot,s.share_amount,m.id AS member_id,
        (m.status='active' AND u.deleted_at IS NULL) AS selectable
        FROM room_expense_members s JOIN room_members m ON m.room_id=s.room_id AND m.user_id=s.user_id
        JOIN users u ON u.id=m.user_id WHERE s.room_id=? AND s.room_expense_id=? ORDER BY m.id",[$roomId,$id])->fetchAll();
}

/** 登録者でも退出後は閲覧・編集不可。終了ルームや取消済みの支出は履歴の閲覧だけにする。 */
function canChangeRoomExpense(array $room, array $expense, int $userId): bool
{
    return $room['status']==='active' && $expense['status']==='active'
        && ($room['my_role']==='owner' || (int)$expense['created_by_user_id']===$userId);
}

/** 詳細を返す前にactiveメンバーを確認し、内訳と本人の編集可否を付ける。 */
function findRoomExpense(PDO $pdo, int $userId, int $roomId, int $id): array
{
    $room=requireRoom($pdo,$userId,$roomId);$expense=roomExpenseRecord($pdo,$roomId,$id);
    $expense['shares']=roomExpenseShares($pdo,$roomId,$id);
    $expense['can_edit']=canChangeRoomExpense($room,$expense,$userId);
    return $expense;
}

/** 一覧も認可してから取得する。取消は明示的に「履歴を含める」を選んだ時だけ表示する。 */
function listRoomExpenses(PDO $pdo, int $userId, int $roomId, bool $includeCancelled = false): array
{
    $room=requireRoom($pdo,$userId,$roomId);
    $rows=eventQuery($pdo,"SELECT * FROM room_expenses WHERE room_id=?".($includeCancelled?'':" AND status='active'").' ORDER BY expense_date DESC,id DESC',[$roomId])->fetchAll();
    foreach($rows as &$expense) {
        $expense['shares']=roomExpenseShares($pdo,$roomId,(int)$expense['id']);
        $expense['can_edit']=canChangeRoomExpense($room,$expense,$userId);
    }
    return $rows;
}
