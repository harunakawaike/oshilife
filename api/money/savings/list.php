<?php
/** list.php の役割：savings/listのお金管理処理を認証付きで実行する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../../app/helpers/money_api.php';
runMoneyApi('savings/list');
