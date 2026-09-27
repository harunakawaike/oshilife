<?php
/** logout.php の役割：CSRFと認証を確認してログアウトするJSON API。GETでは変更しない。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/api_bootstrap.php';
requireMethod('POST');
requireCsrf();
requireAuth(true);
logoutUser();
apiSuccess();
