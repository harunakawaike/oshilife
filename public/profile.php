<?php
/** profile.php の役割：マイページ画面を表示する。共通middlewareでログインを確認し、Phase 2の仮UIを出す。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
$pageTitle = 'マイページ';
$pageStyle = 'profile';
$activePage = 'profile';
require PROJECT_ROOT . '/includes/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">MY OWN OSHI LIFE</p><h1>マイページ</h1></div></div>
<section class="profile-card"><div class="profile-avatar" aria-hidden="true">♡</div><h2><?= e($user['display_name']) ?></h2><p><?= e($user['email']) ?></p><span class="tag">わたしのOshilife</span></section>
<section class="section"><h2>自分らしく整える</h2><div class="card settings-list"><a class="settings-link" href="<?= e(appUrl('oshis.php')) ?>"><span>♡ 推し管理</span><span>登録・メンバー管理 →</span></a><div><span>◐ テーマカラー</span><span class="swatches" aria-label="ピンク・ラベンダー・ベージュの表示例"><i></i><i></i><i></i></span></div><?php foreach (['◌ お金管理', '✧ 推し活の足あと', '⚙ 設定'] as $label): ?><div><span><?= e($label) ?></span><span class="muted">準備中</span></div><?php endforeach; ?></div><p class="caption">テーマカラーの変更は今後対応します。</p></section>
<p id="logout-message" class="form-message" role="alert"></p><button id="logout-button" class="button secondary">ログアウト</button><p class="profile-version">oshilife v2 · Phase 2</p>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
