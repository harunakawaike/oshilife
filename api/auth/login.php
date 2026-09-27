<?php
/** login.php の役割：メールアドレスとパスワードを照合し、ログイン状態を作るJSON API。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/api_bootstrap.php';
requireMethod('POST');
requireCsrf();
limitAuthRequests();
$input = readJson();
$email = trim(inputString($input, 'email'));
$password = inputString($input, 'password');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
    apiError('メールアドレスまたはパスワードが正しくありません。', 401);
}
if (!loginUser($email, $password)) {
    apiError('メールアドレスまたはパスワードが正しくありません。', 401);
}
apiSuccess(['user' => currentUser(), 'csrf_token' => csrfToken()]);
