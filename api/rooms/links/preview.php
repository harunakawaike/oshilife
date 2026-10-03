<?php
/** preview.php の役割：招待リンクの固定操作を認証付きAPIで実行する。 */
declare(strict_types=1);
require_once __DIR__.'/../../../app/helpers/room_api.php';
runRoomApi('links/preview');
