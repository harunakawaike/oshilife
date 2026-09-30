<?php
/** live_repository.php の役割：共有公演と「ログイン本人だけ」の管理情報をDBから取得する。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_repository.php';
require_once __DIR__.'/../../config/lives.php';
/** NULLを含む値もプリペアドステートメントで渡し、SQLへの入力値の埋め込みを防ぐ。 */
function liveQuery(PDO $pdo, string $sql, array $values=[]): PDOStatement
{
    $statement=$pdo->prepare($sql);
    $statement->execute($values);
    return $statement;
}
/** 公演の日時・タイトルはschedulesから読むので、修正提案の承認も即座に反映される。 */
function liveRows(PDO $pdo, int $userId, string $where='1=1', array $values=[], int $limit=200, int $offset=0): array
{
    $sql="SELECT l.*,s.title,s.oshi_id,s.schedule_date AS event_date,s.start_time,s.end_time,s.note,s.created_by_user_id,
        o.name AS oshi_name,o.emoji AS oshi_emoji,v.name AS venue_name,v.prefecture,v.address,v.latitude,v.longitude,
        u.application_status,u.lottery_status,u.trip_type,u.note AS personal_note,t.id AS trip_id,
        (SELECT COUNT(*) FROM todos td WHERE td.user_id=viewer.id AND td.live_event_id=l.id AND td.deleted_at IS NULL AND td.is_completed=0) AS incomplete_todos
        FROM live_events l JOIN schedules s ON s.id=l.schedule_id JOIN oshis o ON o.id=s.oshi_id
        JOIN venues v ON v.id=l.venue_id CROSS JOIN (SELECT :viewer AS id) viewer
        LEFT JOIN user_live_status u ON u.live_event_id=l.id AND u.user_id=viewer.id
        LEFT JOIN trips t ON t.live_event_id=l.id AND t.user_id=viewer.id
        WHERE $where ORDER BY s.schedule_date,s.start_time,l.id LIMIT ".(int)$limit.' OFFSET '.(int)$offset;
    $rows=liveQuery($pdo,$sql,['viewer'=>$userId]+$values)->fetchAll();
    foreach ($rows as &$row) {
        $row['status_label']=LIVE_STATUSES[$row['status']];
        $row['application_label']=APPLICATION_STATUSES[$row['application_status']]??null;
        $row['lottery_label']=LOTTERY_STATUSES[$row['lottery_status']]??null;
        $row['trip_label']=TRIP_TYPES[$row['trip_type']]??null;
        $row['is_owner']=(int)$row['created_by_user_id']===$userId;
        $row['days_until']=(int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable($row['event_date']))->format('%r%a');
        foreach (['open_time','start_time','end_time'] as $key) $row[$key]=$row[$key]===null?null:substr($row[$key],0,5);
    }
    return $rows;
}
/** 共有公演を取得する。本人の管理列以外の個人情報はJOINしない。 */
function findLive(PDO $pdo,int $userId,int $id): array
{
    return liveRows($pdo,$userId,'l.id=:id',['id'=>$id],1)[0]??throw new ScheduleOperationException('ライブが見つかりません。',404);
}
/** 予測できるIDでも他人の遠征にはアクセスさせない。 */
function requireOwnTrip(PDO $pdo,int $userId,int $id,bool $lock=false): array
{
    return liveQuery($pdo,'SELECT * FROM trips WHERE id=:id AND user_id=:user'.($lock?' FOR UPDATE':''),['id'=>$id,'user'=>$userId])->fetch()?:throw new ScheduleOperationException('遠征が見つかりません。',404);
}
/** TODOは本人分かつ削除されていない行だけを返す。 */
function findLiveTodos(PDO $pdo,int $userId,int $liveId): array
{
    return liveQuery($pdo,'SELECT * FROM todos WHERE user_id=:user AND live_event_id=:live AND deleted_at IS NULL ORDER BY is_completed,due_date IS NULL,due_date,id',['user'=>$userId,'live'=>$liveId])->fetchAll();
}
/** 遠征の所有者を先に確認してから、子データをまとめて取得する。 */
function findTripDetail(PDO $pdo,int $userId,int $id): array
{
    $trip=requireOwnTrip($pdo,$userId,$id);
    $trip['live']=findLive($pdo,$userId,(int)$trip['live_event_id']);
    $trip['transportations']=liveQuery($pdo,'SELECT * FROM transportations WHERE trip_id=? ORDER BY departure_at,id',[$id])->fetchAll();
    $trip['accommodations']=liveQuery($pdo,'SELECT * FROM accommodations WHERE trip_id=? ORDER BY check_in_at,id',[$id])->fetchAll();
    $trip['todos']=findLiveTodos($pdo,$userId,(int)$trip['live_event_id']);
    return $trip;
}
