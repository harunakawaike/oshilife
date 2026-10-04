<?php
/** room_message_service.php の役割：ルームのテキスト投稿・差分取得・本人の論理削除を管理する。会計処理は呼ばない。 */
declare(strict_types=1);
require_once __DIR__.'/event_service.php';
require_once __DIR__.'/../repositories/room_repository.php';

/** 日本語・絵文字を文字として数え、改行は保持する。HTMLはテキストのまま保存し、表示側で無害化する。 */
function validateRoomMessage(mixed $message): string
{
    if (!is_string($message) || !mb_check_encoding($message,'UTF-8')) throw new ScheduleOperationException('メッセージを入力してください。',422);
    $message=str_replace(["\r\n","\r"],"\n",$message);
    if (mb_strlen($message,'UTF-8')>1000 || !preg_match('/[^\s\p{Z}\p{C}]/u',$message) || str_contains($message,"\0")) {
        throw new ScheduleOperationException('メッセージは空白だけにせず、1000文字以内で入力してください。',422);
    }
    return $message;
}

/** 投稿と退出・終了の競合を防ぐためルームをロックする。操作者はセッション由来のIDだけを使う。 */
function sendRoomMessage(int $userId, int $roomId, mixed $raw): int
{
    $message=validateRoomMessage($raw);
    return eventTransaction(function(PDO $pdo) use($userId,$roomId,$message): int {
        requireRoom($pdo,$userId,$roomId,false,true,true);
        // 同じユーザーの複数タブ・別ルームからの同時投稿も直列にし、連投制限のすり抜けを防ぐ。
        $name=eventQuery($pdo,'SELECT display_name FROM users WHERE id=? FOR UPDATE',[$userId])->fetchColumn();
        $count=(int)eventQuery($pdo,'SELECT COUNT(*) FROM room_messages WHERE user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 30 SECOND) FOR UPDATE',[$userId])->fetchColumn();
        if ($count>=20) throw new ScheduleOperationException('送信が続いています。少し待ってから再送してください。',429);
        // prepare + executeに値を渡すことで、本文をSQL命令として解釈させない。
        eventQuery($pdo,'INSERT INTO room_messages(room_id,user_id,display_name_snapshot,message) VALUES(?,?,?,?)',[$roomId,$userId,$name,$message]);
        return (int)$pdo->lastInsertId();
    });
}

/** 本文は残す論理削除。ownerでも他人の投稿は削除できない。終了ルームは閲覧専用にする。 */
function deleteRoomMessage(int $userId, int $roomId, int $id): void
{
    eventTransaction(function(PDO $pdo) use($userId,$roomId,$id): void {
        requireRoom($pdo,$userId,$roomId,false,true,true);
        $message=eventQuery($pdo,'SELECT user_id FROM room_messages WHERE room_id=? AND id=?',[$roomId,$id])->fetch();
        if (!$message) throw new ScheduleOperationException('メッセージが見つかりません。',404);
        if ((int)$message['user_id']!==$userId) throw new ScheduleOperationException('自分のメッセージだけ削除できます。',403);
        eventQuery($pdo,"UPDATE room_messages SET status='deleted' WHERE room_id=? AND id=? AND status='active'",[$roomId,$id]);
    });
}

/** 初回は直近100件、以後はIDの差分だけ返す。削除本文はAPIにも含めない。 */
function listRoomMessages(PDO $pdo, int $userId, int $roomId, ?int $afterId, array $knownIds): array
{
    requireRoom($pdo,$userId,$roomId);
    $columns="id,user_id,display_name_snapshot,CASE WHEN status='active' THEN message ELSE NULL END AS message,status,created_at";
    if ($afterId===null) {
        $messages=eventQuery($pdo,"SELECT $columns FROM room_messages WHERE room_id=? ORDER BY id DESC LIMIT 100",[$roomId])->fetchAll();
        $messages=array_reverse($messages);
    } else {
        $messages=eventQuery($pdo,"SELECT $columns FROM room_messages WHERE room_id=? AND id>? ORDER BY id LIMIT 100",[$roomId,$afterId])->fetchAll();
    }
    foreach($messages as &$message) {$message['is_me']=(int)$message['user_id']===$userId;unset($message['user_id']);}
    unset($message);
    $deleted=[];
    if ($knownIds) {
        // 新着IDだけでは過去の削除を検知できないため、表示中の最大100件の削除状態だけを確認する。
        $slots=implode(',',array_fill(0,count($knownIds),'?'));
        $deleted=eventQuery($pdo,"SELECT id FROM room_messages WHERE room_id=? AND status='deleted' AND id IN ($slots)",[$roomId,...$knownIds])->fetchAll(PDO::FETCH_COLUMN);
    }
    $room=roomRecord($pdo,$roomId);
    return ['messages'=>$messages,'deleted_ids'=>$deleted,'room_status'=>$room['status'],'has_more'=>count($messages)===100];
}
