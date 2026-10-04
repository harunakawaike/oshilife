<?php
/** delete.php の役割：トークのdelete操作を認証付き共通APIへ渡す。 */
declare(strict_types=1);
require_once __DIR__.'/../../../app/helpers/room_message_api.php';
runRoomMessageApi('delete');
