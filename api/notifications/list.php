<?php
/** list.php の役割：本人の通知一覧・未読件数・未表示ポップアップを返す。GETでは既読にしない。 */
declare(strict_types=1);
require_once __DIR__.'/../../app/helpers/schedule_api.php';
require_once PROJECT_ROOT.'/app/repositories/notification_repository.php';
$userId=startScheduleApi('GET');
$pdo=database();
$count=(int)scheduleQuery($pdo,'SELECT COUNT(*) FROM notifications n JOIN schedules s ON s.id=n.schedule_id WHERE '.notificationWhere().' AND n.read_at IS NULL',['user'=>$userId])->fetchColumn();
apiSuccess(['notifications'=>notificationRows($pdo,$userId,false),'popups'=>notificationRows($pdo,$userId,true),'unread_count'=>$count]);
