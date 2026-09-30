<?php
/** profile.php の役割：マイページ画面を表示する。共通middlewareでログインを確認し、本人の配色設定とアカウント情報を出す。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
require_once PROJECT_ROOT . '/app/helpers/theme.php';
$theme = userTheme((int) $user['id']);
$pageScripts = ['theme'];
$pageTitle = 'マイページ';
$pageStyle = 'profile';
$activePage = 'profile';
require PROJECT_ROOT . '/includes/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">MY OWN OSHI LIFE</p><h1>マイページ</h1></div></div>
<section class="profile-card"><div class="profile-avatar" aria-hidden="true">♡</div><h2><?= e($user['display_name']) ?></h2><p><?= e($user['email']) ?></p><span class="tag">わたしのOshilife</span></section>
<section class="section"><h2>自分らしく整える</h2><div class="card settings-list"><a class="settings-link" href="<?= e(appUrl('oshis.php')) ?>"><span>♡ 推し管理</span><span>登録・メンバー管理 →</span></a><a class="settings-link" href="#theme-settings"><span>◐ 画面の色</span><span>好きな色を選ぶ →</span></a><?php foreach (['◌ お金管理', '✧ 推し活の足あと', '⚙ 設定'] as $label): ?><div><span><?= e($label) ?></span><span class="muted">準備中</span></div><?php endforeach; ?></div></section>

<section id="theme-settings" class="section card theme-settings" aria-labelledby="theme-heading">
<h2 id="theme-heading">画面の色を、自分らしく</h2>
<p>ボタンやナビの色と、背景色を選べます。選んだ色をこの画面で試してから保存してください。</p>
<form id="theme-form">
<fieldset><legend>メインカラー</legend><div class="theme-presets">
<?php foreach (THEME_PRESETS as $label => $color): ?>
<label><input type="radio" name="preset" value="<?= e($color) ?>" <?= $theme['accent_color'] === $color ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
<?php endforeach; ?>
</div><label class="theme-color-field">好きな色を自由に選ぶ<input id="theme-accent" type="color" value="<?= e($theme['accent_color']) ?>"></label></fieldset>
<fieldset><legend>背景色</legend><div class="theme-backgrounds">
<button class="button secondary small" type="button" data-background="#F4F5F7">ライト</button>
<button class="button secondary small" type="button" data-background="#15171C">ダーク</button>
</div><label class="theme-color-field">背景も好きな色にする<input id="theme-background" type="color" value="<?= e($theme['theme_color']) ?>"></label></fieldset>
<p class="caption">文字色は読みやすい白・黒に自動調整します。保存した配色は自分の画面だけに反映されます。</p>
<div class="theme-actions"><button id="save-theme" type="submit" class="button primary small">この色で保存</button><button id="reset-theme" type="button" class="button secondary small">保存した色に戻す</button></div>
<p id="theme-message" class="status-text" role="status" aria-live="polite"></p>
</form></section>
<p id="logout-message" class="form-message" role="alert"></p><button id="logout-button" class="button secondary">ログアウト</button><p class="profile-version">oshilife v2</p>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
