<?php
/** money.php の役割：年間の残高・積立・支出、月別内訳と特効換算を本人だけに表示する。 */
require_once __DIR__.'/../app/helpers/money_view.php';
[$year,$oshiId] = moneyFilters((int)$user['id'],$_GET);
$options = moneyOshiOptions((int)$user['id']);
$options[''] = 'すべての推し';
require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('profile.php')) ?>">← マイページ</a>
<div class="page-heading"><div><p class="eyebrow">MY YEAR, MY FANDOM</p><h1>お金管理</h1></div><div class="event-actions"><a class="button primary" href="<?= e(appUrl('saving_form.php')) ?>">＋ 積立</a><a class="button secondary" href="<?= e(appUrl('expense_form.php')) ?>">＋ 支出</a></div></div>
<form id="money-filter" class="money-filter">
<label>対象年<input type="number" name="year" min="1000" max="9998" step="1" required value="<?= $year ?>"></label>
<?php eventSelect('oshi_id','対象の推し',$options,$oshiId??''); ?>
<button class="button secondary" type="submit">表示する</button>
</form>
<p id="money-status" role="status"></p>
<div id="money-dashboard" class="money-grid" aria-live="polite">
<section class="card money-balance"><h2>現在残高</h2><strong id="money-balance">—</strong><p id="money-scope"></p><p class="caption">選択年の前年繰越＋年間積立−年間支出。登録した日付で集計します。</p></section>
<section class="card"><h2>年間サマリー</h2><dl id="money-summary" class="money-metrics"></dl><p id="money-common" class="caption"></p></section>
<section class="card"><h2>月別の支出</h2><div id="money-monthly" class="money-bars"></div></section>
<section class="card"><h2>カテゴリ別の支出</h2><div id="money-categories" class="money-bars"></div></section>
<section id="money-oshi-section" class="card"><h2>推し別の支出</h2><p class="caption">自分用の内訳です。金額による順位ではありません。</p><div id="money-by-oshi" class="money-bars"></div></section>
<section class="card money-effects"><h2>特効に換算してみる</h2><p>推し活そのものに使った対象額 <strong id="money-eligible">—</strong></p><div id="money-effect-tabs" class="money-tabs" role="tablist" aria-label="特効の種類"></div><div id="money-effect-panel" role="tabpanel" tabindex="0"></div><details><summary>換算について</summary><p id="money-effect-notice" class="caption"></p></details></section>
</div>
<div class="money-grid money-records">
<?php foreach (['savings'=>'積立の記録','expenses'=>'支出の記録'] as $kind=>$label): ?>
<section class="card"><h2><?= e($label) ?></h2><div id="money-<?= e($kind) ?>" class="money-list"></div><p id="money-<?= e($kind) ?>-message" role="status"></p><button id="money-<?= e($kind) ?>-more" class="button secondary small" hidden>さらに表示</button></section>
<?php endforeach; ?>
</div>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
