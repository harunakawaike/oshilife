<?php
/** live_service.php の役割：公演共有・本人の当落・TODO自動生成・遠征保存をまとめる。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_service.php';
require_once __DIR__.'/../repositories/live_repository.php';
require_once __DIR__.'/../validators/live_validator.php';
/** 複数テーブルの変更を一組にし、途中で失敗したら全部元に戻す。 */
function liveTransaction(callable $operation): mixed
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
function writeLiveRecord(PDO $pdo,string $table,array $values,?int $id=null): int
{
    $columns=array_keys($values);
    if ($id===null) {
        liveQuery($pdo,'INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));
        return (int)$pdo->lastInsertId();
    }
    liveQuery($pdo,'UPDATE '.$table.' SET '.implode(',',array_map(fn($column)=>$column.'=?',$columns)).' WHERE id=?',[...array_values($values),$id]);
    return $id;
}
/** 同じ推し・日・会場でタイトルが似た公演を候補として返す。強制拒否にはしない。 */
function liveDuplicates(PDO $pdo,int $userId,array $input): array
{
    $rows=liveRows($pdo,$userId,'s.oshi_id=:oshi AND s.schedule_date=:date AND l.venue_id=:venue',[
        'oshi'=>$input['schedule']['oshi_id'],'date'=>$input['schedule']['schedule_date'],'venue'=>$input['venue_id']]);
    return array_values(array_filter($rows,fn($row)=>similarScheduleTitles($row['title'],$input['schedule']['title'])));
}
/** 公演と公開予定を同時保存。既存のメンバーや情報元、個人編集済みコピーには触れない。 */
function saveLive(int $userId,array $raw,?int $id): int
{
    $input=validateLiveInput($raw);
    return liveTransaction(function(PDO $pdo) use($userId,$raw,$id,$input) {
        $existing=null;
        if ($id!==null) {
            $live=findLive($pdo,$userId,$id);
            $existing=requireScheduleOwner(lockSchedule($pdo,(int)$live['schedule_id']),$userId);
        }
        checkScheduleRelations($pdo,$userId,$input['schedule'],$existing);
        if (!liveQuery($pdo,'SELECT id FROM venues WHERE id=?',[$input['venue_id']])->fetch()) throw new ScheduleOperationException('会場を選び直してください。',422);
        if ($id===null && ($raw['allow_duplicate']??false)!==true) {
            $duplicates=liveDuplicates($pdo,$userId,$input);
            if ($duplicates) throw new ScheduleDuplicateException($duplicates);
        }
        if ($id===null) {
            $scheduleId=saveScheduleRecord($pdo,$userId,$input['schedule'],null);
            $id=writeLiveRecord($pdo,'live_events',['schedule_id'=>$scheduleId,'venue_id'=>$input['venue_id'],'open_time'=>$input['open_time'],'status'=>$input['status']]);
            liveQuery($pdo,"INSERT INTO user_live_status(user_id,live_event_id,note) VALUES(?,?,'')",[$userId,$id]);
        } else {
            $schedule=$input['schedule'];
            $values=array_intersect_key($schedule,array_flip(['oshi_id','title','schedule_date','start_time','end_time','note','status']));
            writeLiveRecord($pdo,'schedules',$values,(int)$existing['id']);
            if ((int)$existing['oshi_id']!==$schedule['oshi_id']) liveQuery($pdo,'DELETE FROM schedule_members WHERE schedule_id=?',[$existing['id']]);
            writeLiveRecord($pdo,'live_events',['venue_id'=>$input['venue_id'],'open_time'=>$input['open_time'],'status'=>$input['status']],$id);
        }
        return $id;
    });
}
/** 当選時だけ不足テンプレートを追加する。削除行も残すので再当選しても復活させない。 */
function saveLiveStatus(int $userId,int $liveId,array $raw): void
{
    $application = liveChoice($raw, 'application_status', APPLICATION_STATUSES);
    $lottery = liveChoice($raw, 'lottery_status', LOTTERY_STATUSES);
    $type = ($raw['trip_type'] ?? '') === '' ? null : ($raw['trip_type'] ?? null);
    if ($type !== null && (!is_string($type) || !isset(TRIP_TYPES[$type]))) {
        throw new ScheduleOperationException('移動区分を確認してください。', 422);
    }
    $note = liveText($raw, 'note', '個人メモ', 3000);

    liveTransaction(function (PDO $pdo) use ($userId, $liveId, $application, $lottery, $type, $note) {
        $live = findLive($pdo, $userId, $liveId);
        // 同じ公演への同時保存を順番に処理し、TODO生成まで一組で確定する。
        lockSchedule($pdo, (int) $live['schedule_id']);
        liveQuery($pdo,
            'INSERT INTO user_live_status
                (user_id,live_event_id,application_status,lottery_status,trip_type,note)
             VALUES(?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                application_status=VALUES(application_status),
                lottery_status=VALUES(lottery_status),
                trip_type=VALUES(trip_type),note=VALUES(note)',
            [$userId, $liveId, $application, $lottery, $type, $note]
        );

        if (!$live['is_owner']) {
            // すでに取り込んでいる場合は何も変更しない。個人編集による同期解除も維持する。
            liveQuery($pdo,
                'INSERT INTO user_schedules(user_id,schedule_id,sync_enabled)
                 VALUES(?,?,1) ON DUPLICATE KEY UPDATE id=id',
                [$userId, $live['schedule_id']]
            );
        }

        if ($lottery === 'won' && $type !== null) {
            $trip = liveQuery($pdo,
                'SELECT id FROM trips WHERE user_id=? AND live_event_id=?',
                [$userId, $liveId]
            )->fetchColumn();
            $templates = $type === 'trip' ? TRIP_TODOS : LOCAL_TODOS;
            foreach ($templates as $key => $title) {
                // 表示名を編集してもキーは同じ。削除済みの行も残すため再生成されない。
                liveQuery($pdo,
                    'INSERT INTO todos(user_id,live_event_id,trip_id,title,todo_type,template_key)
                     VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
                    [$userId, $liveId, $trip ?: null, $title, $type, $key]
                );
            }
        }
    });
}
/** TODOの所有者を確認し、削除は印を付けてテンプレートの再生成を防ぐ。 */
function changeLiveTodo(int $userId,string $action,array $raw): ?int
{
    return liveTransaction(function(PDO $pdo) use($userId,$action,$raw) {
        $id=$action==='create'?null:scheduleId($raw['id']??null);
        if ($id!==null) {
            $todo=liveQuery($pdo,'SELECT * FROM todos WHERE id=? AND user_id=? AND deleted_at IS NULL FOR UPDATE',[$id,$userId])->fetch();
            if (!$todo) throw new ScheduleOperationException('TODOが見つかりません。',404);
        }
        if ($action==='delete') { liveQuery($pdo,'UPDATE todos SET deleted_at=CURRENT_TIMESTAMP WHERE id=?',[$id]);return $id; }
        if ($action==='toggle') {
            if (!is_bool($raw['is_completed']??null)) throw new ScheduleOperationException('完了状態を指定してください。',422);
            liveQuery($pdo,'UPDATE todos SET is_completed=? WHERE id=?',[(int)$raw['is_completed'],$id]);return $id;
        }
        $values=['title'=>liveText($raw,'title','TODO',150,true),'due_date'=>liveDate($raw,'due_date',true)];
        if ($action==='create') {
            $liveId=scheduleId($raw['live_event_id']??null);
            $status=liveQuery($pdo,'SELECT id FROM user_live_status WHERE user_id=? AND live_event_id=? FOR UPDATE',[$userId,$liveId])->fetch();
            if (!$status) throw new ScheduleOperationException('先に自分の管理を保存してください。',409);
            $trip=liveQuery($pdo,'SELECT id FROM trips WHERE user_id=? AND live_event_id=?',[$userId,$liveId])->fetchColumn();
            $values+=['user_id'=>$userId,'live_event_id'=>$liveId,'trip_id'=>$trip?:null,'todo_type'=>'custom'];
        }
        return writeLiveRecord($pdo,'todos',$values,$id);
    });
}
/** 1人1公演につき遠征1件。既存TODOも同じ遠征へ結び付ける。 */
function saveTrip(int $userId,array $raw,?int $id): int
{
    $values=validateTripInput($raw);
    return liveTransaction(function(PDO $pdo) use($userId,$raw,$id,$values) {
        if ($id!==null) requireOwnTrip($pdo,$userId,$id,true);
        else {
            $liveId=scheduleId($raw['live_event_id']??null);
            $status=liveQuery($pdo,'SELECT * FROM user_live_status WHERE user_id=? AND live_event_id=? FOR UPDATE',[$userId,$liveId])->fetch();
            if (!$status || $status['trip_type']!=='trip') throw new ScheduleOperationException('先に自分の管理で「遠征」を選んで保存してください。',409);
            if (liveQuery($pdo,'SELECT id FROM trips WHERE user_id=? AND live_event_id=?',[$userId,$liveId])->fetch()) throw new ScheduleOperationException('この公演の遠征は作成済みです。',409);
            $values+=['user_id'=>$userId,'live_event_id'=>$liveId];
        }
        $saved=writeLiveRecord($pdo,'trips',$values,$id);
        if ($id===null) liveQuery($pdo,'UPDATE todos SET trip_id=? WHERE user_id=? AND live_event_id=?',[$saved,$userId,$liveId]);
        return $saved;
    });
}
/** 子データのIDだけを信用せず、所属する遠征とログイン本人を照合する。 */
function changeTravelItem(int $userId,string $kind,string $action,array $raw): ?int
{
    $table=$kind==='transport'?'transportations':'accommodations';
    return liveTransaction(function(PDO $pdo) use($userId,$kind,$action,$raw,$table) {
        $tripId=scheduleId($raw['trip_id']??null);requireOwnTrip($pdo,$userId,$tripId,true);
        $id=$action==='create'?null:scheduleId($raw['id']??null);
        if ($id!==null && !liveQuery($pdo,"SELECT id FROM $table WHERE id=? AND trip_id=?",[$id,$tripId])->fetch()) throw new ScheduleOperationException('予約情報が見つかりません。',404);
        if ($action==='delete') { liveQuery($pdo,"DELETE FROM $table WHERE id=?",[$id]);return $id; }
        return writeLiveRecord($pdo,$table,validateTravelItem($raw,$kind)+['trip_id'=>$tripId],$id);
    });
}
