<?php
/** read.php の役割：本人が見た通知を表示済み・既読へ更新する。CSRF保護されたPOSTのみ。 */
declare(strict_types=1);
require_once __DIR__.'/../../app/helpers/schedule_api.php';
require_once PROJECT_ROOT.'/app/repositories/notification_repository.php';
$userId=startScheduleApi('POST');
$raw=readJson();$ids=$raw['ids']??null;$mode=$raw['mode']??'read';
if (!is_array($ids) || !array_is_list($ids) || count($ids)<1 || count($ids)>30 || !in_array($mode,['read','shown'],true)) apiError('通知の指定が正しくありません。',422);
foreach ($ids as &$id) $id=scheduleId($id);
unset($id);
acknowledgeNotifications(database(),$userId,array_values(array_unique($ids)),$mode);
apiSuccess();
