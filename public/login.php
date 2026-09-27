<?php
/** login.php の役割：おかえりなさいの認証フォームを表示する。送信はauth.jsからJSON APIへ行う。 */
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
if (isset($_SESSION['user_id'])) {
    redirectTo('home.php');
}
$pageTitle = 'ログイン';
$pageStyle = 'auth';
$isAuthPage = true;
require PROJECT_ROOT . '/includes/header.php';
?>
<div class="auth-decoration" aria-hidden="true"><span>✧</span><span>♡</span><span>✧</span></div>
<p class="eyebrow">YOUR LITTLE OSHI SPACE</p>
<h1>おかえりなさい</h1>
<p class="intro">今日も、好きからはじまる一日を。</p>
<?php if (isset($_GET['registered'])): ?>
<p class="notice success">登録が完了しました。ログインしてはじめましょう。</p>
<?php endif; ?>
<form id="auth-form" data-action="login" class="auth-form">
    <label for="email">メールアドレス</label>
    <input id="email" name="email" type="email" autocomplete="email" maxlength="254" placeholder="you@example.com" required aria-describedby="error-email">
    <p class="field-error" id="error-email"></p>
    <label for="password">パスワード</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required  aria-describedby="password-help error-password">
    <p id="password-help" class="field-note">登録したパスワードを入力してください。</p>
    <p class="field-error" id="error-password"></p>
    <p id="form-message" class="form-message" role="alert" tabindex="-1"></p>
    <button type="submit" class="button primary">ログイン</button>
</form>
<noscript><p class="notice">登録・ログインにはJavaScriptを有効にしてください。</p></noscript>
<p class="auth-switch">はじめての方は <a href="<?= e(appUrl('register.php')) ?>">新規登録</a></p>
<p class="auth-footnote">好きなことを、もっと心地よく。</p>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
