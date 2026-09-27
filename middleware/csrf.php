<?php
/** csrf.php の役割：第三者のサイトから本人の意図しない更新を行うCSRF攻撃を防ぐ。 */
declare(strict_types=1);

/** セッションごとに推測困難な合言葉（トークン）を用意する。 */
function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** 画面が送ったトークンとサーバーの値を比較する。ログイン前のフォームも保護する。 */
function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    // Cookieだけでは別サイトからの送信と区別できないため、追加のトークンを照合する。
    if ($token === '' || !hash_equals(csrfToken(), $token)) {
        apiError('画面の有効期限が切れました。再読み込みして、もう一度お試しください。', 419);
    }
}
