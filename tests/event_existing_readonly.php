<?php
/** event_existing_readonly.php の役割：既存イベント・本人管理・遠征・支払い元を変更せず、新名称の取得処理で読めるか確認する。 */
declare(strict_types=1);
require_once __DIR__.'/../config/app.php';
require_once PROJECT_ROOT.'/app/services/event_service.php';
require_once PROJECT_ROOT.'/app/repositories/money_repository.php';
if (env('DB_NAME') !== 'oshilife_v2') throw new RuntimeException('専用DBだけを検証してください。');
$pdo=database();$count=0;
foreach($pdo->query('SELECT * FROM user_event_status ORDER BY id')->fetchAll() as $management) {
    $userId=(int)$management['user_id'];
    $event=findEvent($pdo,$userId,(int)$management['event_id']);
    if ((int)$event['user_event_status_id'] !== (int)$management['id'] || !isset(EVENT_TYPES[$event['event_type']])) throw new RuntimeException('本人管理の対応が不正です。');
    findEventTodos($pdo,$userId,(int)$event['id']);
    if ($event['trip_id']) {
        $trip=findTripDetail($pdo,$userId,(int)$event['trip_id']);
        if ((int)$trip['event_id'] !== (int)$event['id']) throw new RuntimeException('遠征の対応が不正です。');
    }
    $source=moneySource($pdo,$userId,'live_ticket',(int)$management['id']);
    if ((int)$source['source_id'] !== (int)$management['id']) throw new RuntimeException('チケット反映元IDが不正です。');
    $count++;
}
echo "Existing data read-only PASS: $count personal event records; event/TODO/trip/ticket source readable.\n";
