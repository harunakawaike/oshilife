<?php
/** update.php の役割：イベント・遠征のtrips/accommodation/update処理を認証付きで実行する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../../app/helpers/event_api.php';
runEventApi('trips/accommodation/update');
