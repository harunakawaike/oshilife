<?php
/** auth.php の役割：ログイン必須の画面・APIで共通の認証チェックをするmiddleware（処理前の門番）。 */
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once PROJECT_ROOT . '/app/services/auth_service.php';

/** 非ログインなら画面はログインへ、APIはJSONで401を返す。チェック漏れと重複を防ぐ。 */
function requireAuth(bool $isApi = false): array
{
    $user = currentUser();
    if ($user !== null) {
        return $user;
    }
    if ($isApi) {
        apiError('ログインしてください。', 401);
    }
    redirectTo('login.php');
}
