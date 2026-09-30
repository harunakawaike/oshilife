<?php
/** notification_repository.php の役割：受信通知・表示済み・既読を本人ごとに保存し、取り消された反応は除外する。 */
declare(strict_types=1);
require_once __DIR__ . '/schedule_repository.php';
/** 同じ感謝を押し直しても通知を乱発しない。イベントのUNIQUE制約で1回だけ保存する。 */
function createFeedbackNotification(PDO $pdo, int $recipient, int $schedule, int $actor, string $kind, ?int $correction = null): void
{
    $event = $correction === null ? "reaction:$schedule:$actor:$kind" : "correction:$correction";
    $statement = $pdo->prepare('INSERT INTO notifications (recipient_user_id,schedule_id,actor_user_id,kind,event_key,correction_id) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
    $statement->execute([$recipient,$schedule,$actor,$kind,$event,$correction]);
}
/** 通知先本人だけに返す。感謝の解除後・非公開化後は、その感謝通知を表示しない。 */
function notificationWhere(): string
{
    return "n.recipient_user_id=:user AND s.created_by_user_id=n.recipient_user_id AND
        (n.kind='correction' OR (s.visibility='public' AND s.status<>'deleted' AND EXISTS
        (SELECT 1 FROM reactions r WHERE r.schedule_id=n.schedule_id AND r.user_id=n.actor_user_id AND r.reaction_type=n.kind)))";
}
/** 通知文はサーバーで種類を決め、予定名は表示時にHTMLではなく文字として扱う。 */
function notificationRows(PDO $pdo, int $userId, bool $popups): array
{
    $sql = 'SELECT n.id,n.kind,n.schedule_id,n.created_at,n.read_at,s.title FROM notifications n JOIN schedules s ON s.id=n.schedule_id WHERE ' . notificationWhere();
    if ($popups) $sql .= ' AND n.shown_at IS NULL AND n.read_at IS NULL';
    $rows = scheduleQuery($pdo, $sql . ' ORDER BY n.id DESC LIMIT ' . ($popups ? '3' : '30'), ['user'=>$userId])->fetchAll();
    $labels = ['helped'=>'「助かった！」が届きました','thanks'=>'「ありがとう！」が届きました','correction'=>'修正提案が届きました'];
    foreach ($rows as &$row) {
        $row['id']=(int)$row['id']; $row['schedule_id']=(int)$row['schedule_id'];
        $row['message']=$labels[$row['kind']];
        $row['unread']=$row['read_at']===null; unset($row['read_at']);
        $row['path']=$row['kind']==='correction' ? 'corrections.php' : 'schedule_detail.php?id='.$row['schedule_id'];
    }
    return $rows;
}
/** ポップアップ表示済みと既読を分ける。一瞬の表示だけで未読通知を消さない。 */
function acknowledgeNotifications(PDO $pdo, int $userId, array $ids, string $mode): void
{
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $set=$mode==='read' ? 'read_at=COALESCE(read_at,CURRENT_TIMESTAMP),shown_at=COALESCE(shown_at,CURRENT_TIMESTAMP)' : 'shown_at=COALESCE(shown_at,CURRENT_TIMESTAMP)';
    // 本人条件をUPDATEにも必ず付ける。他人の通知IDを混ぜても更新されない。
    $statement=$pdo->prepare('UPDATE notifications SET '.$set.' WHERE recipient_user_id=? AND id IN ('.$marks.')');
    $statement->execute([$userId,...$ids]);
}
