<?php
/** list.php の役割：共同支出の固定操作を認証付きAPIで実行する。 */
declare(strict_types=1);
require_once __DIR__.'/../../../app/helpers/room_expense_api.php';
runRoomExpenseApi('list');
