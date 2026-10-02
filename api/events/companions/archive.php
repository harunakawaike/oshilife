<?php
/** archive.php の役割：同行者のarchive処理を認証付きAPIへ渡す。 */
declare(strict_types=1);
require_once __DIR__.'/../../../app/helpers/participant_api.php';
runParticipantApi('archive');
