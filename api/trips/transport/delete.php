<?php
/** delete.php の役割：ライブ・遠征のtrips/transport/delete処理を認証付きで実行する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../../app/helpers/live_api.php';
runLiveApi('trips/transport/delete');
