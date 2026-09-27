<?php
/** me.php の役割：現在のユーザーとCSRFトークンを返すJSON API。未ログインならuserはnull。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/api_bootstrap.php';
requireMethod('GET');
apiSuccess(['user' => currentUser(), 'csrf_token' => csrfToken()]);
