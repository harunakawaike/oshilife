<?php
/** corrections.php の役割：自分の予定へ届いた修正提案を確認し、承認・却下・履歴表示を行う画面。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
require_once PROJECT_ROOT . '/config/feedback.php';
$pageTitle = '届いた修正提案'; $pageStyle = 'feedback'; $pageScripts = ['schedules','corrections']; $activePage = 'profile';
require PROJECT_ROOT . '/includes/header.php';
?>
<a class="page-back" href="<?= e(appUrl('profile.php')) ?>">← マイページ</a>
<h1>届いた修正提案</h1>
<p class="intro">あなたが登録した予定への提案です。根拠と最新の予定を確認してから判断してください。</p>
<label class="form-label" for="correction-status">表示する提案</label>
<select id="correction-status"><?php foreach (CORRECTION_STATUSES as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?><option value="all">すべての履歴</option></select>
<p id="correction-list-message" class="status-text" role="status"></p>
<div id="correction-list" class="correction-list" aria-live="polite"></div>
<button id="correction-more" type="button" class="button secondary small" hidden>さらに表示</button>
<noscript><p>一覧の取得にはJavaScriptを有効にしてください。</p></noscript>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
