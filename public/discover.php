<?php
/** discover.php の役割：公開中の予定を検索し、本人のカレンダーへ追加する。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
require_once PROJECT_ROOT . '/app/repositories/oshi_repository.php';
require_once PROJECT_ROOT . '/config/schedules.php';
$myOshis = findMyOshis(database(), (int) $user['id']);
$pageTitle = '見つける'; $pageStyle = 'schedules'; $pageScripts = ['schedules', 'discover']; $activePage = 'discover';
require PROJECT_ROOT . '/includes/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">FIND YOUR NEXT JOY</p><h1>みんなの公開予定</h1><p class="intro">気になる予定を、自分のカレンダーへ。</p></div><a class="button secondary small" href="<?= e(appUrl('schedule_form.php')) ?>">＋ 予定を共有</a></div>
<form id="discover-filters" class="card schedule-filter-form">
<label>キーワード<input type="search" name="q" placeholder="番組名・イベント名など" maxlength="150"></label>
<label>推し<select name="oshi_id"><option value="all">すべての推し</option><?php foreach ($myOshis as $oshi): ?><option value="<?= (int) $oshi['id'] ?>"><?= e($oshi['emoji'] . ' ' . $oshi['name']) ?></option><?php endforeach; ?></select></label>
<label>カテゴリ<select name="category"><option value="">すべて</option><?php foreach (SCHEDULE_CATEGORIES as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>日付<input type="date" name="date"></label><button class="button primary small" type="submit">検索する</button><button class="button secondary small" type="reset">条件をクリア</button>
</form>
<p class="caption">推しの選択肢は自分の登録済み一覧です。「すべて」では未登録の推しの公開予定も表示します。</p>
<p id="discover-status" class="status-text" role="status"></p><div id="public-schedules" class="public-schedule-grid" aria-live="polite"></div><button id="discover-more" class="button secondary small" type="button" hidden>さらに表示</button>
<noscript><p class="notice">公開予定の表示にはJavaScriptを有効にしてください。</p></noscript>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
