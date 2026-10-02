<?php
/** restore.php の役割：同行者のrestore処理を認証付きAPIへ渡す。 */
declare(strict_types=1);
require_once __DIR__.'/../../../app/helpers/participant_api.php';
runParticipantApi('restore');
