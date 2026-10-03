<?php
/** invitations/cancel の役割：固定したルーム操作を認証付きAPIに渡す。 */
declare(strict_types=1);
require_once __DIR__.'/../../../app/helpers/room_api.php';
runRoomApi('invitations/cancel');
