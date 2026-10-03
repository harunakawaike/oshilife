<?php
/** room_service.php の役割：ルーム・招待・参加を一括保存する。個人のイベント管理や会計には書き込まない。 */
declare(strict_types=1);
require_once __DIR__.'/event_service.php';
require_once __DIR__.'/../repositories/room_repository.php';

/** ルームとowner参加を同じトランザクションで作り、owner不在のルームを残さない。 */
function createRoom(int $userId, string $name): int
{
    return eventTransaction(function(PDO $pdo) use($userId,$name): int {
        $id = writeEventRecord($pdo,'rooms',['owner_user_id'=>$userId,'name'=>$name]);
        writeEventRecord($pdo,'room_members',['room_id'=>$id,'user_id'=>$userId,'room_owner_user_id'=>$userId,'role'=>'owner']);
        return $id;
    });
}

/** 名前変更・終了はownerだけ。終了時にはリンクを無効化し、新たな参加を止める。旧招待履歴は変更しない。 */
function changeRoom(int $userId, int $roomId, string $action, ?string $name = null): void
{
    eventTransaction(function(PDO $pdo) use($userId,$roomId,$action,$name): void {
        $room=requireRoom($pdo,$userId,$roomId,true,false,true);
        if ($action==='close') {
            if ($room['status']==='closed') return;
            eventQuery($pdo,"UPDATE rooms SET status='closed' WHERE id=?",[$roomId]);
            eventQuery($pdo,"UPDATE room_invite_links SET status='revoked' WHERE room_id=? AND status='active'",[$roomId]);
        } else {
            if ($room['status']!=='active') throw new ScheduleOperationException('終了したルームは変更できません。',409);
            eventQuery($pdo,'UPDATE rooms SET name=? WHERE id=?',[$name,$roomId]);
        }
    });
}

/** メンバー退出は履歴を残す。owner移譲が未実装なのでownerは退出不可。 */
function leaveRoom(int $userId, int $roomId): void
{
    eventTransaction(function(PDO $pdo) use($userId,$roomId): void {
        $room=requireRoom($pdo,$userId,$roomId,false,false,true);
        if ($room['my_role']==='owner') throw new ScheduleOperationException('ownerは退出できません。必要ならルームを終了してください。',409);
        eventQuery($pdo,"UPDATE room_members SET status='left' WHERE room_id=? AND user_id=?",[$roomId,$userId]);
    });
}

/** 中間テーブルだけを変更し、イベント本体・カレンダー・参加状況を変更しない。 */
function changeRoomEvent(int $userId, int $roomId, int $eventId, bool $add): void
{
    eventTransaction(function(PDO $pdo) use($userId,$roomId,$eventId,$add): void {
        requireRoom($pdo,$userId,$roomId,true,true,true);
        if ($add) {
            $event=eventQuery($pdo,"SELECT e.id FROM events e JOIN schedules s ON s.id=e.schedule_id
                JOIN user_event_status u ON u.event_id=e.id AND u.user_id=?
                WHERE e.id=? AND s.visibility='public' AND s.status<>'deleted'",[$userId,$eventId])->fetch();
            if (!$event) throw new ScheduleOperationException('自分が管理している公開イベントを選んでください。',422);
            if (eventQuery($pdo,'SELECT id FROM room_events WHERE room_id=? AND event_id=?',[$roomId,$eventId])->fetch()) throw new ScheduleOperationException('このイベントは追加済みです。',409);
            writeEventRecord($pdo,'room_events',['room_id'=>$roomId,'event_id'=>$eventId]);
        } else {
            $statement=eventQuery($pdo,'DELETE FROM room_events WHERE room_id=? AND event_id=?',[$roomId,$eventId]);
            if ($statement->rowCount()===0) throw new ScheduleOperationException('紐付けイベントが見つかりません。',404);
        }
    });
}
