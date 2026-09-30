<?php
/** live_api.php の役割：ライブ系APIの認証・CSRF・応答をまとめる。画面と処理を分け再利用する。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_api.php';
require_once PROJECT_ROOT.'/app/services/live_service.php';
/** 各APIファイルに固定した経路のみを実行する。user_idは入力値を使わずセッションから取得する。 */
function runLiveApi(string $route): void
{
    $read=in_array($route,['lives/list','lives/detail','lives/duplicate-check','lives/status','lives/todos/list','lives/next','venues/list','trips/detail'],true);
    $userId=startScheduleApi($read?'GET':'POST');$pdo=database();$raw=$read?$_GET:readJson();
    if ($route==='lives/list' || $route==='lives/next') {
        $where='1=1';$values=[];$oshi=scheduleOshiFilter($raw['oshi_id']??null);
        if ($oshi!==null) { $where.=' AND s.oshi_id=:oshi';$values['oshi']=$oshi; }
        $q=liveText($raw,'q','検索語',150);
        if ($q!=='') { $where.=' AND s.title LIKE :q';$values['q']='%'.$q.'%'; }
        $period=$raw['period']??'all';
        if (!in_array($period,['all','upcoming','past'],true)) throw new ScheduleOperationException('期間が正しくありません。',422);
        if ($period==='upcoming') $where.=' AND s.schedule_date>=CURRENT_DATE';
        if ($period==='past') $where.=' AND s.schedule_date<CURRENT_DATE';
        if ($route==='lives/next') $where.=" AND u.id IS NOT NULL AND s.schedule_date>=CURRENT_DATE AND l.status IN ('scheduled','postponed')";
        $page=positiveOshiId($raw['page']??1);
        if ($page===null || $page>10000) throw new ScheduleOperationException('ページ番号が正しくありません。',422);
        $rows=liveRows($pdo,$userId,$where,$values,$route==='lives/next'?1:31,($page-1)*30);
        apiSuccess($route==='lives/next'?['live'=>$rows[0]??null]:['lives'=>array_slice($rows,0,30),'has_more'=>count($rows)>30]);
    }
    if ($route==='venues/list') apiSuccess(['venues'=>liveQuery($pdo,'SELECT * FROM venues ORDER BY name')->fetchAll()]);
    if ($route==='venues/create') {
        $values=['name'=>liveText($raw,'name','会場名',150,true),'prefecture'=>liveText($raw,'prefecture','都道府県',20,true),'address'=>liveText($raw,'address','住所',255)];
        foreach (['latitude'=>90,'longitude'=>180] as $key=>$max) {
            $value=$raw[$key]??null;
            if ($value==='') $value=null;
            if ($value!==null && ((!is_string($value)&&!is_numeric($value)) || !is_numeric($value) || abs((float)$value)>$max)) throw new ScheduleOperationException('緯度・経度が正しくありません。',422);
            $values[$key]=$value;
        }
        $existing=liveQuery($pdo,'SELECT id FROM venues WHERE name=? AND prefecture=? AND address=?',array_slice(array_values($values),0,3))->fetchColumn();
        apiSuccess(['id'=>$existing?:writeLiveRecord($pdo,'venues',$values)],201);
    }
    if ($route==='lives/create' || $route==='lives/update') {
        $id=saveLive($userId,$raw,$route==='lives/create'?null:scheduleId($raw['id']??null));
        apiSuccess(['live'=>findLive($pdo,$userId,$id)],$route==='lives/create'?201:200);
    }
    if ($route==='lives/duplicate-check') {
        $input=['schedule'=>['title'=>liveText($raw,'title','タイトル',150,true),'oshi_id'=>scheduleId($raw['oshi_id']??null),'schedule_date'=>liveDate($raw,'event_date')],'venue_id'=>scheduleId($raw['venue_id']??null)];
        apiSuccess(['duplicates'=>liveDuplicates($pdo,$userId,$input)]);
    }
    if ($route==='lives/detail') apiSuccess(['live'=>findLive($pdo,$userId,scheduleId($raw['id']??null))]);
    if ($route==='lives/status' || $route==='lives/status/update') {
        $id=scheduleId($raw['live_event_id']??null);
        if (!$read) saveLiveStatus($userId,$id,$raw);
        findLive($pdo,$userId,$id);
        $status=liveQuery($pdo,'SELECT * FROM user_live_status WHERE user_id=? AND live_event_id=?',[$userId,$id])->fetch()?:null;
        apiSuccess(['status'=>$status]);
    }
    if ($route==='lives/todos/list') apiSuccess(['todos'=>findLiveTodos($pdo,$userId,scheduleId($raw['live_event_id']??null))]);
    if (str_starts_with($route,'lives/todos/')) apiSuccess(['id'=>changeLiveTodo($userId,basename($route),$raw)]);
    if ($route==='trips/detail') apiSuccess(['trip'=>findTripDetail($pdo,$userId,scheduleId($raw['id']??null))]);
    if ($route==='trips/create' || $route==='trips/update') apiSuccess(['id'=>saveTrip($userId,$raw,$route==='trips/create'?null:scheduleId($raw['id']??null))]);
    $parts=explode('/',$route);
    if ($parts[0]==='trips' && in_array($parts[1],['transport','accommodation'],true)) apiSuccess(['id'=>changeTravelItem($userId,$parts[1],$parts[2],$raw)]);
    apiError('APIが見つかりません。',404);
}
