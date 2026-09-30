<?php
/** money_api.php の役割：お金APIの認証・CSRFと共通応答をまとめ、画面から保存処理を分離する。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_api.php';
require_once PROJECT_ROOT.'/app/services/money_service.php';
/** APIファイルの固定ルートだけを処理し、user_idをリクエストから受け取らない。 */
function runMoneyApi(string $route): void
{
    $parts = explode('/',$route);
    $action = end($parts);
    $read = !in_array($action,['create','update','delete'],true);
    $userId = startScheduleApi($read?'GET':'POST');
    $raw = $read?$_GET:readJson();
    $pdo = database();
    if ($route==='source') {
        $source = moneySource($pdo,$userId,liveChoice($raw,'source_type',['live_ticket'=>true,'transportation'=>true,'accommodation'=>true]),scheduleId($raw['source_id']??null));
        apiSuccess(['source'=>$source]);
    }
    if (in_array($parts[0],['savings','expenses'],true)) {
        $table = $parts[0];
        if ($action==='detail') apiSuccess(['record'=>findMoneyRecord($pdo,$table,$userId,scheduleId($raw['id']??null))]);
        if ($action==='list') {
            [$year,$oshiId,$page] = moneyFilters($userId,$raw);
            apiSuccess(listMoneyRecords($pdo,$table,$userId,$year,$oshiId,$page));
        }
        $id = $action==='create'?null:scheduleId($raw['id']??null);
        if ($action==='delete') {
            deleteMoneyRecord($userId,$table,$id);
            apiSuccess(['deleted'=>true]);
        }
        $id = saveMoneyRecord($userId,$table,$raw,$id);
        apiSuccess(['record'=>findMoneyRecord($pdo,$table,$userId,$id)],$action==='create'?201:200);
    }
    [$year,$oshiId] = moneyFilters($userId,$raw);
    if (in_array($route,['monthly','categories','by-oshi'],true)) apiSuccess(['items'=>moneyBreakdown($pdo,$userId,$year,$oshiId,$route)]);
    // 同じリクエスト内の年間・内訳は同じDB時点を読む。
    $data = liveTransaction(function(PDO $pdo) use($userId,$year,$oshiId,$route) {
        $summary = moneyDashboard($pdo,$userId,$year,$oshiId);
        if ($route==='special-effects') return moneySpecialEffects($summary['special_effect_eligible']);
        if ($route!=='dashboard') throw new ScheduleOperationException('APIが見つかりません。',404);
        return $summary + [
            'monthly'=>moneyBreakdown($pdo,$userId,$year,$oshiId,'monthly'),
            'categories'=>moneyBreakdown($pdo,$userId,$year,$oshiId,'categories'),
            'by_oshi'=>$oshiId===null?moneyBreakdown($pdo,$userId,$year,null,'by-oshi'):[],
            'special_effects'=>moneySpecialEffects($summary['special_effect_eligible']),
        ];
    });
    apiSuccess($data);
}
