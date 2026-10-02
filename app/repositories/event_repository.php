<?php
/** event_repository.php の役割：共有公演と「ログイン本人だけ」の管理情報をDBから取得する。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_repository.php';
require_once __DIR__.'/../../config/events.php';
/** NULLを含む値もプリペアドステートメントで渡し、SQLへの入力値の埋め込みを防ぐ。 */
function eventQuery(PDO $pdo, string $sql, array $values=[]): PDOStatement
{
    $statement=$pdo->prepare($sql);
    $statement->execute($values);
    return $statement;
}
/** 公演の日時・タイトルはschedulesから読むので、修正提案の承認も即座に反映される。 */
function eventRows(PDO $pdo, int $userId, string $where='1=1', array $values=[], int $limit=200, int $offset=0): array
{
    $sql="SELECT l.*,s.title,s.oshi_id,s.schedule_date AS event_date,s.start_time,s.end_time,s.note,s.created_by_user_id,
        o.name AS oshi_name,o.emoji AS oshi_emoji,v.name AS venue_name,v.prefecture,v.address,v.latitude,v.longitude,
        u.entry_method,u.application_status,u.lottery_status,u.participation_status,u.sales_type,u.trip_type,u.note AS personal_note,t.id AS trip_id,
        u.id AS user_event_status_id,u.ticket_amount,u.ticket_payment_status,u.ticket_paid_date,u.ticket_expense_id,
        (SELECT COUNT(*) FROM todos td WHERE td.user_id=viewer.id AND td.event_id=l.id AND td.deleted_at IS NULL AND td.is_completed=0 AND NOT (COALESCE(td.template_key,'')='payment' AND COALESCE(u.ticket_amount,-1)=0)) AS incomplete_todos
        FROM events l JOIN schedules s ON s.id=l.schedule_id JOIN oshis o ON o.id=s.oshi_id
        JOIN venues v ON v.id=l.venue_id CROSS JOIN (SELECT :viewer AS id) viewer
        LEFT JOIN user_event_status u ON u.event_id=l.id AND u.user_id=viewer.id
        LEFT JOIN trips t ON t.event_id=l.id AND t.user_id=viewer.id
        WHERE $where ORDER BY s.schedule_date,s.start_time,l.id LIMIT ".(int)$limit.' OFFSET '.(int)$offset;
    $rows=eventQuery($pdo,$sql,['viewer'=>$userId]+$values)->fetchAll();
    foreach ($rows as &$row) {
        $row['event_type_label']=EVENT_TYPES[$row['event_type']] ?? $row['event_type'];
        $row['status_label']=EVENT_STATUSES[$row['status']];
        $row['entry_label']=($row['entry_method']==='lottery' && $row['sales_type']==='fanclub') ? ENTRY_METHODS['fanclub_presale'] : (ENTRY_METHODS[$row['entry_method']]??null);
        $row['participation_label']=PARTICIPATION_STATUSES[$row['participation_status']]??null;
        $row['sales_label']=SALES_TYPES[$row['sales_type']]??null;
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
function findEvent(PDO $pdo,int $userId,int $id): array
{
    return eventRows($pdo,$userId,'l.id=:id',['id'=>$id],1)[0]??throw new ScheduleOperationException('イベントが見つかりません。',404);
}
/** 予測できるIDでも他人の遠征にはアクセスさせない。 */
function requireOwnTrip(PDO $pdo,int $userId,int $id,bool $lock=false): array
{
    return eventQuery($pdo,'SELECT * FROM trips WHERE id=:id AND user_id=:user'.($lock?' FOR UPDATE':''),['id'=>$id,'user'=>$userId])->fetch()?:throw new ScheduleOperationException('遠征が見つかりません。',404);
}
/** TODOは本人分かつ削除されていない行だけを返す。 */
function findEventTodos(PDO $pdo,int $userId,int $eventId): array
{
    return eventQuery($pdo,'SELECT * FROM todos WHERE user_id=:user AND event_id=:event AND deleted_at IS NULL ORDER BY is_completed,due_date IS NULL,due_date,id',['user'=>$userId,'event'=>$eventId])->fetchAll();
}
/** 遠征の所有者を先に確認してから、子データをまとめて取得する。 */
function findTripDetail(PDO $pdo,int $userId,int $id): array
{
    $trip=requireOwnTrip($pdo,$userId,$id);
    $trip['event']=findEvent($pdo,$userId,(int)$trip['event_id']);
    $trip['transportations']=eventQuery($pdo,'SELECT * FROM transportations WHERE trip_id=? ORDER BY departure_at,id',[$id])->fetchAll();
    $trip['accommodations']=eventQuery($pdo,'SELECT * FROM accommodations WHERE trip_id=? ORDER BY check_in_at,id',[$id])->fetchAll();
    $trip['todos']=findEventTodos($pdo,$userId,(int)$trip['event_id']);
    return $trip;
}
