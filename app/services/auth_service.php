<?php
/** auth_service.php の役割：登録・ログイン・ログアウトの手順をまとめる。APIとDBの橋渡しをする。 */
declare(strict_types=1);
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/app/repositories/user_repository.php';

/** 平文のパスワードを保存せず、復元困難なハッシュへ変換して登録する。 */
function registerUser(array $input): int
{
    // password_hashはランダムなsaltも自動管理する。同じパスワードでも保存値は異なる。
    $hash = password_hash($input['password'], PASSWORD_DEFAULT);
    return createUser(database(), trim($input['display_name']), strtolower(trim($input['email'])), $hash);
}

/** パスワードが一致した場合だけセッションに本人のIDを記録する。 */
function loginUser(string $email, string $password): bool
{
    $user = findUserByEmail(database(), strtolower(trim($email)));
    // ユーザーが存在しない場合もハッシュ照合し、存在の有無による時間差を小さくする。
    $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    // password_verifyは入力と保存済みハッシュの対応を調べる。ハッシュ文字列の直接比較はしない。
    $verified = password_verify($password, $hash);
    if (!$user || !$verified) {
        return false;
    }
    // ログイン前のIDを捨て、攻撃者が既知のIDを使い回すセッション固定攻撃を防ぐ。
    session_regenerate_id(true);
    $_SESSION = [
        'user_id' => (int) $user['id'],
        'last_activity' => time(),
        'csrf_token' => bin2hex(random_bytes(32)),
    ];
    return true;
}

/** ログイン済みで、DB上も有効なユーザーを返す。パスワードハッシュは返さない。 */
function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $user = findUserById(database(), (int) $_SESSION['user_id']);
    if ($user === null) {
        unset($_SESSION['user_id']);
    }
    return $user;
}

/** サーバーのセッション内容・保存ファイル・ブラウザーのCookieを破棄する。 */
function logoutUser(): void
{
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $params['path'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    session_destroy();
}
