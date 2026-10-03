<?php
/** room_link_service.php の役割：推測困難な招待リンクの発行・検証・無効化・本人参加を管理する。 */
declare(strict_types=1);
require_once __DIR__.'/room_service.php';

/** 256bitの乱数を64桁の文字列にする。DB流出時に招待URLを復元されないようSHA-256だけ保存する。 */
function createRoomInviteLink(int $userId, int $roomId): array
{
    return eventTransaction(function(PDO $pdo) use($userId,$roomId): array {
        requireRoom($pdo,$userId,$roomId,true,true,true);
        $token=bin2hex(random_bytes(32));
        $expires=date('Y-m-d H:i:s',time()+7*86400);
        $id=writeEventRecord($pdo,'room_invite_links',[
            'room_id'=>$roomId,'created_by_user_id'=>$userId,'token_hash'=>hash('sha256',$token),'expires_at'=>$expires,
        ]);
        // Hostヘッダー由来のURLは使わない。APP_URL未設定時は画面側で同一オリジンの絶対URLにする。
        $path='rooms/join.php?token='.$token;
        return ['id'=>$id,'url'=>APP_URL!=='' ? APP_URL.'/'.$path : appUrl($path),'expires_at'=>$expires];
    });
}

/** 一覧に生トークンもハッシュも返さない。期限切れは時刻で判定し、Schedulerは不要。 */
function roomInviteLinks(PDO $pdo, int $userId, int $roomId): array
{
    requireRoom($pdo,$userId,$roomId,true);
    return eventQuery($pdo,"SELECT id,created_at,expires_at,max_uses,used_count,status,
        CASE WHEN status='active' AND expires_at<=CURRENT_TIMESTAMP THEN 'expired' ELSE status END AS effective_status
        FROM room_invite_links WHERE room_id=? ORDER BY id DESC",[$roomId])->fetchAll();
}

/** 終了ルームでもownerは無効化可能。ルーム→リンクのロック順を参加処理と揃えて競合を防ぐ。 */
function revokeRoomInviteLink(int $userId, int $roomId, int $id): void
{
    eventTransaction(function(PDO $pdo) use($userId,$roomId,$id): void {
        requireRoom($pdo,$userId,$roomId,true,false,true);
        $link=eventQuery($pdo,'SELECT id FROM room_invite_links WHERE id=? AND room_id=? FOR UPDATE',[$id,$roomId])->fetch();
        if (!$link) throw new ScheduleOperationException('招待リンクが見つかりません。',404);
        eventQuery($pdo,"UPDATE room_invite_links SET status='revoked' WHERE id=?",[$id]);
    });
}

/** トークン形式を制限し、SQLへ直接埋め込まずprepare/executeでハッシュ値を照合する。 */
function findRoomInviteLink(PDO $pdo, mixed $token): array
{
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/',$token)) throw new ScheduleOperationException('この招待リンクは無効です',404);
    return eventQuery($pdo,'SELECT * FROM room_invite_links WHERE token_hash=?',[hash('sha256',$token)])->fetch()
        ?: throw new ScheduleOperationException('この招待リンクは無効です',404);
}

/** 確認画面と参加APIで同じ失効条件を使う。古い画面を開いたままでも参加時に再判定する。 */
function validateRoomInviteLink(array $link, array $room): void
{
    if ($room['status']==='closed') throw new ScheduleOperationException('このルームは終了しています',409);
    if ($link['status']==='revoked') throw new ScheduleOperationException('この招待リンクは無効です',410);
    if ($link['status']==='expired' || strtotime($link['expires_at'])<=time()) throw new ScheduleOperationException('この招待リンクは期限切れです',410);
    if ($link['max_uses']!==null && (int)$link['used_count']>=(int)$link['max_uses']) throw new ScheduleOperationException('この招待リンクは利用上限に達しています',410);
}

/** 確認に必要なルーム名とowner表示名だけ公開する。未参加者へメンバー一覧や個人情報を渡さない。 */
function previewRoomInviteLink(PDO $pdo, int $userId, mixed $token): array
{
    $link=findRoomInviteLink($pdo,$token);$room=roomRecord($pdo,(int)$link['room_id']);
    validateRoomInviteLink($link,$room);
    $owner=eventQuery($pdo,"SELECT CASE WHEN deleted_at IS NULL THEN display_name ELSE '退会済みユーザー' END AS name FROM users WHERE id=?",[$room['owner_user_id']])->fetch();
    $member=eventQuery($pdo,"SELECT id FROM room_members WHERE room_id=? AND user_id=? AND status='active'",[$room['id'],$userId])->fetch();
    return ['room_id'=>(int)$room['id'],'room_name'=>$room['name'],'owner_name'=>$owner['name'],'already_joined'=>(bool)$member];
}

/** GETでは呼ばない。CSRF検証済みPOSTの本人だけを参加させ、退出行はIDを変えず再利用する。 */
function joinRoomInviteLink(int $userId, mixed $token): array
{
    return eventTransaction(function(PDO $pdo) use($userId,$token): array {
        $hint=findRoomInviteLink($pdo,$token);
        $room=roomRecord($pdo,(int)$hint['room_id'],true);
        // ルームロック取得後に最新の失効状態を読む。参加と無効化・終了のすれ違いを防ぐ。
        $link=eventQuery($pdo,'SELECT * FROM room_invite_links WHERE id=? FOR UPDATE',[$hint['id']])->fetch();
        validateRoomInviteLink($link,$room);
        $member=eventQuery($pdo,'SELECT id,status FROM room_members WHERE room_id=? AND user_id=? FOR UPDATE',[$room['id'],$userId])->fetch();
        if ($member && $member['status']==='active') return ['room_id'=>(int)$room['id'],'already_joined'=>true];
        if ($member) {
            eventQuery($pdo,"UPDATE room_members SET status='active',joined_at=CURRENT_TIMESTAMP WHERE id=?",[$member['id']]);
        } else {
            writeEventRecord($pdo,'room_members',['room_id'=>$room['id'],'user_id'=>$userId,'room_owner_user_id'=>$room['owner_user_id'],'role'=>'member']);
        }
        // 新規参加・再参加の成立回数。同じ参加済みユーザーの再送では増やさない。
        eventQuery($pdo,'UPDATE room_invite_links SET used_count=used_count+1 WHERE id=?',[$link['id']]);
        return ['room_id'=>(int)$room['id'],'already_joined'=>false];
    });
}
