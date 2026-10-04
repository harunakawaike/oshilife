<?php
/** logout.php の役割：公開URLの入口。実際の処理はpublic外のapi/auth/logout.phpへ委譲する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require APP_PRIVATE_ROOT . '/api/auth/logout.php';
