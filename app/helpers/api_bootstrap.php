<?php
/** api_bootstrap.php の役割：APIの共通起動と例外処理。画面とAPIを分け、将来のスマホアプリでも処理を再利用しやすくする。 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/response.php';
// SQL、接続情報、ファイルパスなどの内部事情は利用者のJSONへ出さない。
set_exception_handler(function (Throwable $exception): void {
    error_log('API error: ' . get_class($exception) . ' code=' . $exception->getCode());
    apiError('処理に失敗しました。時間をおいてお試しください。続く場合は管理者へご連絡ください。', 500);
});
require_once __DIR__ . '/../../config/app.php';
require_once PROJECT_ROOT . '/middleware/csrf.php';
require_once PROJECT_ROOT . '/middleware/auth.php';
require_once PROJECT_ROOT . '/app/validators/auth_validator.php';
require_once PROJECT_ROOT . '/app/helpers/rate_limit.php';
