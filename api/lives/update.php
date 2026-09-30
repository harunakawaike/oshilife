<?php
/** update.php の役割：ライブ・遠征のlives/update処理を認証付きで実行する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/live_api.php';
runLiveApi('lives/update');
