<?php
/** home.php の役割：ホーム画面を表示する。共通middlewareでログインを確認し、実データの推し切替と今日の予定・未追加の公開予定を出す。 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once APP_PRIVATE_ROOT . '/middleware/auth.php';
$user = requireAuth();
require_once PROJECT_ROOT . '/app/repositories/oshi_repository.php';
$myOshis = findMyOshis(database(), (int) $user['id']);
$pageScripts = ['schedules', 'home', 'monthly_summary', 'events', 'money'];
$extraStyles = ['schedules', 'feedback', 'events', 'money'];
$pageTitle = 'ホーム';
$pageStyle = 'home';
$activePage = 'home';
require PROJECT_ROOT . '/includes/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">MY LITTLE HAPPINESS</p><h1>好きがある、毎日。</h1></div><span class="heading-flower" aria-hidden="true">✳</span></div>
<p class="greeting"><?= e($user['display_name']) ?>さん、こんにちは。</p>
<div class="oshi-selector"><span class="oshi-avatar" aria-hidden="true">♡</span><div class="oshi-picker"><label for="home-oshi">表示する推し</label><select id="home-oshi"><option value="">すべての推し</option><?php foreach ($myOshis as $oshi): ?><option value="<?= (int) $oshi['id'] ?>"><?= e($oshi['emoji'] . ' ' . $oshi['name']) ?></option><?php endforeach; ?></select></div><a class="chip" href="<?= e(appUrl('oshis.php')) ?>">推し管理</a></div>
<?php if ($myOshis === []): ?><p class="caption">まずは<a href="<?= e(appUrl('oshis.php')) ?>">推しを登録</a>して、あなたの好きなものを集めましょう。</p><?php endif; ?>
<p id="home-filter-status" class="caption" aria-live="polite">すべての推しの予定を表示します。</p>
<div class="dashboard-grid">
<section class="today-card"><div class="section-heading"><h2>今日の予定</h2><a href="<?= e(appUrl('calendar.php')) ?>">カレンダー →</a></div><p class="date-label"><?= e(date('n月j日')) ?></p><div id="home-today" class="schedule-stack" data-today="<?= e(date('Y-m-d')) ?>" aria-live="polite"><p>予定を読み込んでいます…</p></div><a class="text-button" href="<?= e(appUrl('schedule_form.php')) ?>">＋ 予定を登録</a></section>
<div class="home-discovery">
<section class="section"><div class="section-heading"><h2>新着の公開予定 <span id="unadded-count" class="tag" hidden></span></h2><a href="<?= e(appUrl('discover.php')) ?>">見つける →</a></div><div id="home-unadded" class="schedule-stack" aria-live="polite"><p>公開予定を読み込んでいます…</p></div><p id="home-schedule-message" class="caption" role="status"></p><button id="home-more" class="button secondary small" type="button" hidden>さらに表示</button><p class="caption">自分の推しの公開予定のうち、イベント以外で未追加のものが並びます。</p></section>
<section class="section"><div class="section-heading"><h2>新着のイベント情報 <span id="unadded-event-count" class="tag" hidden></span></h2><a href="<?= e(appUrl('events.php')) ?>">イベント一覧 →</a></div><div id="home-unadded-events" class="schedule-stack" aria-live="polite"><p>イベント情報を読み込んでいます…</p></div><p id="home-event-message" class="caption" role="status"></p><button id="home-event-more" class="button secondary small" type="button" hidden>さらに表示</button><p class="caption">自分の推しのイベント情報のうち、まだカレンダーに追加していないものが並びます。</p></section>
</div>
<section class="section"><div class="section-heading"><h2>次のイベント</h2><a href="<?= e(appUrl('events.php')) ?>">一覧 →</a></div><div id="home-next-event" aria-live="polite"><p>読み込んでいます…</p></div></section>
<div class="two-cards"><section class="card funds"><span class="small-icon" aria-hidden="true">💰</span><h2>推し活資金</h2><div id="home-money" data-year="<?= e(date('Y')) ?>" aria-live="polite"><p>資金を読み込んでいます…</p></div></section><section class="card thanks"><span class="small-icon" aria-hidden="true">♡</span><h2>最近のありがとう</h2><div id="monthly-thanks" class="thanks-summary" data-year="<?= e(date('Y')) ?>" data-month="<?= e(date('m')) ?>" aria-live="polite"><p>今月の共有状況を読み込んでいます…</p></div><a class="text-button" href="<?= e(appUrl('corrections.php')) ?>">届いた修正提案を見る →</a></section></div>
</div>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
