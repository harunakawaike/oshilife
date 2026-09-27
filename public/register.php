<?php
/** register.php の役割：はじめましての認証フォームを表示する。送信はauth.jsからJSON APIへ行う。 */
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
if (isset($_SESSION['user_id'])) {
    redirectTo('home.php');
}
$pageTitle = '新規登録';
$pageStyle = 'auth';
$isAuthPage = true;
require PROJECT_ROOT . '/includes/header.php';
?>
<div class="auth-decoration" aria-hidden="true"><span>✧</span><span>♡</span><span>✧</span></div>
<p class="eyebrow">YOUR LITTLE OSHI SPACE</p>
<h1>はじめまして</h1>
<p class="intro">あなたの「好き」を、ここから。</p>
<form id="auth-form" data-action="register" class="auth-form">
    <label for="display_name">表示名 <span class="field-note">50文字以内</span></label>
    <input id="display_name" name="display_name" autocomplete="nickname" maxlength="50" placeholder="はるな 💎" required aria-describedby="error-display_name">
    <p class="field-error" id="error-display_name"></p>
    <label for="email">メールアドレス</label>
    <input id="email" name="email" type="email" autocomplete="email" maxlength="254" placeholder="you@example.com" required aria-describedby="error-email">
    <p class="field-error" id="error-email"></p>
    <label for="password">パスワード</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="10" aria-describedby="password-help error-password">
    <p id="password-help" class="field-note">10文字以上・72バイト以内。日本語は1文字約3バイトです。</p>
    <p class="field-error" id="error-password"></p>
    <label for="password_confirmation">パスワード（確認）</label>
    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required aria-describedby="error-password_confirmation">
    <p class="field-error" id="error-password_confirmation"></p>
    <p id="form-message" class="form-message" role="alert" tabindex="-1"></p>
    <button type="submit" class="button primary">アカウントを作成</button>
</form>
<noscript><p class="notice">登録・ログインにはJavaScriptを有効にしてください。</p></noscript>
<p class="auth-switch">すでにアカウントをお持ちの方は <a href="<?= e(appUrl('login.php')) ?>">ログイン</a></p>
<p class="auth-footnote">好きなことを、もっと心地よく。</p>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
