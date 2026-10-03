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
// 復帰先はURL文字列を受け付けず、招待ページで保存した形式確認済みトークンから固定パスを作る。
$inviteToken = $_SESSION['room_join_token'] ?? null;
if (!loginUser($email, $password)) {
    apiError('メールアドレスまたはパスワードが正しくありません。', 401);
}
// loginUserはセッションを作り直すため、成功時だけ復帰先を一度返す。
$redirect = is_string($inviteToken) && preg_match('/\A[a-f0-9]{64}\z/', $inviteToken)
    ? 'rooms/join.php?token='.$inviteToken : 'home.php';
apiSuccess(['user' => currentUser(), 'csrf_token' => csrfToken(), 'redirect' => $redirect]);
