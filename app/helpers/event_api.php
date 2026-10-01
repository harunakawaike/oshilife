<?php
/** event_api.php の役割：イベント系APIの認証・CSRF・応答をまとめる。画面と処理を分け再利用する。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_api.php';
require_once PROJECT_ROOT.'/app/services/event_service.php';
/** 各APIファイルに固定した経路のみを実行する。user_idは入力値を使わずセッションから取得する。 */
function runEventApi(string $route): void
{
    $read=in_array($route,['events/list','events/detail','events/duplicate-check','events/status','events/todos/list','events/next','venues/list','trips/detail'],true);
    $userId=startScheduleApi($read?'GET':'POST');$pdo=database();$raw=$read?$_GET:readJson();
    // 旧入口だけ旧ID名を受け付ける。認証と保存処理は新APIと同じ。
    if (defined('LEGACY_EVENT_API') && !array_key_exists('event_id',$raw) && array_key_exists('live_event_id',$raw)) $raw['event_id']=$raw['live_event_id'];
    if ($route==='events/list' || $route==='events/next') {
        $where='1=1';$values=[];$oshi=scheduleOshiFilter($raw['oshi_id']??null);
        if ($oshi!==null) { $where.=' AND s.oshi_id=:oshi';$values['oshi']=$oshi; }
        $q=eventText($raw,'q','検索語',150);
        if ($q!=='') { $where.=' AND s.title LIKE :q';$values['q']='%'.$q.'%'; }
        $period=$raw['period']??'all';
        if (!in_array($period,['all','upcoming','past'],true)) throw new ScheduleOperationException('期間が正しくありません。',422);
        if ($period==='upcoming') $where.=' AND s.schedule_date>=CURRENT_DATE';
        if ($period==='past') $where.=' AND s.schedule_date<CURRENT_DATE';
        // ホームには本人が不参加・取消にしたイベントを出さない。検討中も日付順で残す。共有の公演情報や一覧は残す。
        if ($route==='events/next') $where.=" AND u.id IS NOT NULL AND u.participation_status NOT IN ('not_attending','cancelled') AND s.schedule_date>=CURRENT_DATE AND l.status IN ('scheduled','postponed')";
        $page=positiveOshiId($raw['page']??1);
        if ($page===null || $page>10000) throw new ScheduleOperationException('ページ番号が正しくありません。',422);
        $rows=eventRows($pdo,$userId,$where,$values,$route==='events/next'?1:31,($page-1)*30);
        apiSuccess($route==='events/next'?['event'=>$rows[0]??null]:['events'=>array_slice($rows,0,30),'has_more'=>count($rows)>30]);
    }
    if ($route==='venues/list') apiSuccess(['venues'=>eventQuery($pdo,'SELECT * FROM venues ORDER BY name')->fetchAll()]);
    if ($route==='venues/create') {
        $values=['name'=>eventText($raw,'name','会場名',150,true),'prefecture'=>eventText($raw,'prefecture','都道府県',20,true),'address'=>eventText($raw,'address','住所',255)];
        foreach (['latitude'=>90,'longitude'=>180] as $key=>$max) {
            $value=$raw[$key]??null;
            if ($value==='') $value=null;
            if ($value!==null && ((!is_string($value)&&!is_numeric($value)) || !is_numeric($value) || abs((float)$value)>$max)) throw new ScheduleOperationException('緯度・経度が正しくありません。',422);
            $values[$key]=$value;
        }
        $existing=eventQuery($pdo,'SELECT id FROM venues WHERE name=? AND prefecture=? AND address=?',array_slice(array_values($values),0,3))->fetchColumn();
        apiSuccess(['id'=>$existing?:writeEventRecord($pdo,'venues',$values)],201);
    }
    if ($route==='events/create' || $route==='events/update') {
        $id=saveEvent($userId,$raw,$route==='events/create'?null:scheduleId($raw['id']??null));
        apiSuccess(['event'=>findEvent($pdo,$userId,$id)],$route==='events/create'?201:200);
    }
    if ($route==='events/duplicate-check') {
        $input=['schedule'=>['title'=>eventText($raw,'title','タイトル',150,true),'oshi_id'=>scheduleId($raw['oshi_id']??null),'schedule_date'=>eventDate($raw,'event_date')],'venue_id'=>scheduleId($raw['venue_id']??null)];
        apiSuccess(['duplicates'=>eventDuplicates($pdo,$userId,$input)]);
    }
    if ($route==='events/detail') apiSuccess(['event'=>findEvent($pdo,$userId,scheduleId($raw['id']??null))]);
    if ($route==='events/status' || $route==='events/status/update') {
        $id=scheduleId($raw['event_id']??null);
        if (!$read) saveEventStatus($userId,$id,$raw);
        findEvent($pdo,$userId,$id);
        $status=eventQuery($pdo,'SELECT * FROM user_event_status WHERE user_id=? AND event_id=?',[$userId,$id])->fetch()?:null;
        apiSuccess(['status'=>$status]);
    }
    if ($route==='events/todos/list') apiSuccess(['todos'=>findEventTodos($pdo,$userId,scheduleId($raw['event_id']??null))]);
    if (str_starts_with($route,'events/todos/')) apiSuccess(['id'=>changeEventTodo($userId,basename($route),$raw)]);
    if ($route==='trips/detail') apiSuccess(['trip'=>findTripDetail($pdo,$userId,scheduleId($raw['id']??null))]);
    if ($route==='trips/create' || $route==='trips/update') apiSuccess(['id'=>saveTrip($userId,$raw,$route==='trips/create'?null:scheduleId($raw['id']??null))]);
    $parts=explode('/',$route);
    if ($parts[0]==='trips' && in_array($parts[1],['transport','accommodation'],true)) apiSuccess(['id'=>changeTravelItem($userId,$parts[1],$parts[2],$raw)]);
    apiError('APIが見つかりません。',404);
}
