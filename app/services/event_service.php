<?php
/** event_service.php の役割：公演共有・本人の当落・TODO自動生成・遠征保存をまとめる。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_service.php';
require_once __DIR__.'/../repositories/event_repository.php';
require_once __DIR__.'/../validators/event_validator.php';
/** 複数テーブルの変更を一組にし、途中で失敗したら全部元に戻す。 */
function eventTransaction(callable $operation): mixed
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $result = $operation($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
/** 固定の内部テーブル・検証済み項目だけを使う。クライアントから列名は受け取らない。 */
function writeEventRecord(PDO $pdo,string $table,array $values,?int $id=null): int
{
    $columns=array_keys($values);
    if ($id===null) {
        eventQuery($pdo,'INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));
        return (int)$pdo->lastInsertId();
    }
    eventQuery($pdo,'UPDATE '.$table.' SET '.implode(',',array_map(fn($column)=>$column.'=?',$columns)).' WHERE id=?',[...array_values($values),$id]);
    return $id;
}
/** 同じ推し・日・会場でタイトルが似た公演を候補として返す。強制拒否にはしない。 */
function eventDuplicates(PDO $pdo,int $userId,array $input): array
{
    $rows=eventRows($pdo,$userId,'s.oshi_id=:oshi AND s.schedule_date=:date AND l.venue_id=:venue',[
        'oshi'=>$input['schedule']['oshi_id'],'date'=>$input['schedule']['schedule_date'],'venue'=>$input['venue_id']]);
    return array_values(array_filter($rows,fn($row)=>similarScheduleTitles($row['title'],$input['schedule']['title'])));
}
/** 公演と公開予定を同時保存。既存のメンバーや情報元、個人編集済みコピーには触れない。 */
function saveEvent(int $userId,array $raw,?int $id): int
{
    $input=validateEventInput($raw);
    return eventTransaction(function(PDO $pdo) use($userId,$raw,$id,$input) {
        $existing=null;
        if ($id!==null) {
            $event=findEvent($pdo,$userId,$id);
            $existing=requireScheduleOwner(lockSchedule($pdo,(int)$event['schedule_id']),$userId);
        }
        checkScheduleRelations($pdo,$userId,$input['schedule'],$existing);
        if (!eventQuery($pdo,'SELECT id FROM venues WHERE id=?',[$input['venue_id']])->fetch()) throw new ScheduleOperationException('会場を選び直してください。',422);
        if ($id===null && ($raw['allow_duplicate']??false)!==true) {
            $duplicates=eventDuplicates($pdo,$userId,$input);
            if ($duplicates) throw new ScheduleDuplicateException($duplicates);
        }
        if ($id===null) {
            $scheduleId=saveScheduleRecord($pdo,$userId,$input['schedule'],null);
            $id=writeEventRecord($pdo,'events',['schedule_id'=>$scheduleId,'event_type'=>$input['event_type']??'live','venue_id'=>$input['venue_id'],'open_time'=>$input['open_time'],'status'=>$input['status']]);
            eventQuery($pdo,"INSERT INTO user_event_status(user_id,event_id,note) VALUES(?,?,'')",[$userId,$id]);
        } else {
            $schedule=$input['schedule'];
            $values=array_intersect_key($schedule,array_flip(['oshi_id','title','schedule_date','start_time','end_time','note','status']));
            writeEventRecord($pdo,'schedules',$values,(int)$existing['id']);
            if ((int)$existing['oshi_id']!==$schedule['oshi_id']) eventQuery($pdo,'DELETE FROM schedule_members WHERE schedule_id=?',[$existing['id']]);
            writeEventRecord($pdo,'events',['event_type'=>$input['event_type']??$event['event_type'],'venue_id'=>$input['venue_id'],'open_time'=>$input['open_time'],'status'=>$input['status']],$id);
        }
        return $id;
    });
}
/** 参加確定時に不足TODOを生成する。本人管理→TODOの順にロックし、支払い反映との競合を防ぐ。 */
function saveEventStatus(int $userId,int $eventId,array $raw): void
{
    eventTransaction(function (PDO $pdo) use ($userId,$eventId,$raw) {
        $event=findEvent($pdo,$userId,$eventId);
        lockSchedule($pdo,(int)$event['schedule_id']);
        $existing=eventQuery($pdo,'SELECT * FROM user_event_status WHERE user_id=? AND event_id=? FOR UPDATE',[$userId,$eventId])->fetch() ?: [];
        $values=validateEventManagement($raw,$existing);
        $values+=['user_id'=>$userId,'event_id'=>$eventId];
        writeEventRecord($pdo,'user_event_status',$values,$existing ? (int)$existing['id'] : null);
        if (!$event['is_owner']) {
            // 個別編集済みのカレンダーを共有内容で上書きしない。
            eventQuery($pdo,'INSERT INTO user_schedules(user_id,schedule_id,sync_enabled) VALUES(?,?,1) ON DUPLICATE KEY UPDATE id=id',[$userId,$event['schedule_id']]);
        }
        if ($values['participation_status']==='confirmed') {
            $trip=eventQuery($pdo,'SELECT id FROM trips WHERE user_id=? AND event_id=?',[$userId,$eventId])->fetchColumn();
            $type=$values['trip_type']??'local';
            $templates=$type==='trip' ? TRIP_TODOS : LOCAL_TODOS;
            // 0円は支払い不要。既存の支払TODOは削除せず、画面側で不要な行を隠す。
            if ($values['ticket_amount']!==null && (float)$values['ticket_amount']==0.0) unset($templates['payment']);
            foreach($templates as $key=>$title) {
                eventQuery($pdo,'INSERT INTO todos(user_id,event_id,trip_id,title,todo_type,template_key,is_completed) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[$userId,$eventId,$trip?:null,$title,$type,$key,(int)($key==='payment' && $values['ticket_payment_status']==='paid')]);
            }
        }
        // 支払いを直接変更した場合も入金TODOへ同期。支出は自動作成・変更しない。
        if (array_key_exists('ticket_payment_status',$raw)) {
            eventQuery($pdo,"UPDATE todos SET is_completed=? WHERE user_id=? AND event_id=? AND template_key='payment' AND deleted_at IS NULL",[(int)($values['ticket_payment_status']==='paid'),$userId,$eventId]);
        }
    });
}
/** TODOの所有者を確認し、削除は印を付けてテンプレートの再生成を防ぐ。 */
function changeEventTodo(int $userId,string $action,array $raw): ?int
{
    return eventTransaction(function(PDO $pdo) use($userId,$action,$raw) {
        $id=$action==='create'?null:scheduleId($raw['id']??null);
        if ($id!==null) {
            $todo=eventQuery($pdo,'SELECT * FROM todos WHERE id=? AND user_id=? AND deleted_at IS NULL',[$id,$userId])->fetch();
            if (!$todo) throw new ScheduleOperationException('TODOが見つかりません。',404);
            // チケット反映と同じ「個人管理→TODO」の順でロックし、完了取消との競合を防ぐ。
            eventQuery($pdo,'SELECT id FROM user_event_status WHERE user_id=? AND event_id=? FOR UPDATE',[$userId,$todo['event_id']]);
            $todo=eventQuery($pdo,'SELECT * FROM todos WHERE id=? AND user_id=? AND deleted_at IS NULL FOR UPDATE',[$id,$userId])->fetch();
            if (!$todo) throw new ScheduleOperationException('TODOが見つかりません。',404);
        }
        if ($action==='delete') {
            eventQuery($pdo,'UPDATE todos SET deleted_at=CURRENT_TIMESTAMP WHERE id=?',[$id]);
            syncTicketPaymentFromTodo($pdo,$userId,$todo,false);
            return $id;
        }
        if ($action==='toggle') {
            if (!is_bool($raw['is_completed']??null)) throw new ScheduleOperationException('完了状態を指定してください。',422);
            eventQuery($pdo,'UPDATE todos SET is_completed=? WHERE id=?',[(int)$raw['is_completed'],$id]);
            syncTicketPaymentFromTodo($pdo,$userId,$todo,$raw['is_completed']);
            return $id;
        }
        $values=['title'=>eventText($raw,'title','TODO',150,true),'due_date'=>eventDate($raw,'due_date',true)];
        if ($action==='create') {
            $eventId=scheduleId($raw['event_id']??null);
            $status=eventQuery($pdo,'SELECT id FROM user_event_status WHERE user_id=? AND event_id=? FOR UPDATE',[$userId,$eventId])->fetch();
            if (!$status) throw new ScheduleOperationException('先に自分の管理を保存してください。',409);
            $trip=eventQuery($pdo,'SELECT id FROM trips WHERE user_id=? AND event_id=?',[$userId,$eventId])->fetchColumn();
            $values+=['user_id'=>$userId,'event_id'=>$eventId,'trip_id'=>$trip?:null,'todo_type'=>'custom'];
        }
        return writeEventRecord($pdo,'todos',$values,$id);
    });
}
/** 1人1公演につき遠征1件。既存TODOも同じ遠征へ結び付ける。 */
function saveTrip(int $userId,array $raw,?int $id): int
{
    $values=validateTripInput($raw);
    return eventTransaction(function(PDO $pdo) use($userId,$raw,$id,$values) {
        if ($id!==null) requireOwnTrip($pdo,$userId,$id,true);
        else {
            $eventId=scheduleId($raw['event_id']??null);
            $status=eventQuery($pdo,'SELECT * FROM user_event_status WHERE user_id=? AND event_id=? FOR UPDATE',[$userId,$eventId])->fetch();
            if (!$status || $status['participation_status']!=='confirmed' || $status['trip_type']!=='trip') throw new ScheduleOperationException('先に自分の管理で「参加確定」と「遠征」を選んで保存してください。',409);
            if (eventQuery($pdo,'SELECT id FROM trips WHERE user_id=? AND event_id=?',[$userId,$eventId])->fetch()) throw new ScheduleOperationException('この公演の遠征は作成済みです。',409);
            $values+=['user_id'=>$userId,'event_id'=>$eventId];
        }
        $saved=writeEventRecord($pdo,'trips',$values,$id);
        if ($id===null) eventQuery($pdo,'UPDATE todos SET trip_id=? WHERE user_id=? AND event_id=?',[$saved,$userId,$eventId]);
        return $saved;
    });
}
/** 子データのIDだけを信用せず、所属する遠征とログイン本人を照合する。 */
function changeTravelItem(int $userId,string $kind,string $action,array $raw): ?int
{
    $table=$kind==='transport'?'transportations':'accommodations';
    return eventTransaction(function(PDO $pdo) use($userId,$kind,$action,$raw,$table) {
        $tripId=scheduleId($raw['trip_id']??null);requireOwnTrip($pdo,$userId,$tripId,true);
        $id=$action==='create'?null:scheduleId($raw['id']??null);
        $existing=$id===null?null:eventQuery($pdo,"SELECT * FROM $table WHERE id=? AND trip_id=? FOR UPDATE",[$id,$tripId])->fetch();
        if ($id!==null && !$existing) throw new ScheduleOperationException('予約情報が見つかりません。',404);
        if ($action==='delete') { eventQuery($pdo,"DELETE FROM $table WHERE id=?",[$id]);return $id; }
        $payment = array_key_exists('payment_status',$raw)
            ? eventChoice($raw,'payment_status',PAYMENT_STATUSES)
            : ($existing['payment_status']??'unpaid');
        // 支払い済みに初めて変えた日を保存。支出日は確認画面で変更できる。
        $paidDate = $payment==='paid' ? ($existing['paid_date']??date('Y-m-d')) : null;
        $values = validateTravelItem($raw,$kind) + ['trip_id'=>$tripId,'payment_status'=>$payment,'paid_date'=>$paidDate];
        return writeEventRecord($pdo,$table,$values,$id);
    });
}

/** 固定キーpaymentのTODOだけをチケット支払いに対応させる。expensesへのINSERTは行わない。 */
function syncTicketPaymentFromTodo(PDO $pdo,int $userId,array $todo,bool $completed): void
{
    if ($todo['template_key'] !== 'payment') return;
    $paidDate = $completed ? date('Y-m-d') : null;
    $sql = $completed
        ? "UPDATE user_event_status SET ticket_payment_status='paid',ticket_paid_date=COALESCE(ticket_paid_date,?) WHERE user_id=? AND event_id=?"
        : "UPDATE user_event_status SET ticket_payment_status='unpaid',ticket_paid_date=? WHERE user_id=? AND event_id=?";
    eventQuery($pdo,$sql,[$paidDate,$userId,$todo['event_id']]);
}
