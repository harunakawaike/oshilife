<?php
/** calendar.php の役割：月間カレンダーと日別予定をAPIで表示する。スマホ縦型・PC横並び。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
require_once PROJECT_ROOT . '/app/repositories/oshi_repository.php';
$myOshis = findMyOshis(database(), (int) $user['id']);
$pageTitle = 'カレンダー'; $pageStyle = 'schedules'; $pageScripts = ['schedules', 'calendar']; $activePage = 'calendar';
require PROJECT_ROOT . '/includes/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">DAYS WITH MY OSHI</p><h1>カレンダー</h1></div><a id="calendar-add" class="button primary small" href="<?= e(appUrl('schedule_form.php')) ?>">＋ 予定を登録</a></div>
<div class="schedule-toolbar"><label for="calendar-oshi">表示する推し</label><select id="calendar-oshi"><option value="all">すべての推し</option><?php foreach ($myOshis as $oshi): ?><option value="<?= (int) $oshi['id'] ?>"><?= e($oshi['emoji'] . ' ' . $oshi['name']) ?></option><?php endforeach; ?></select></div>
<p id="calendar-message" class="status-text" role="status"></p>
<div id="calendar-app" class="calendar-layout" data-today="<?= e(date('Y-m-d')) ?>">
    <section class="card month-panel"><div class="month-toolbar"><button id="month-prev" class="month-step" aria-label="前の月">‹</button><h2 id="calendar-month"></h2><button id="month-next" class="month-step" aria-label="次の月">›</button><button id="month-today" class="chip">今日</button></div><div id="month-days" class="month-grid" aria-label="月間カレンダー"></div></section>
    <section class="day-panel"><div class="section-heading"><h2 id="selected-day">今日の予定</h2><span id="day-count" class="tag"></span></div><div id="day-schedules" class="schedule-stack" aria-live="polite"><p>予定を読み込んでいます…</p></div></section>
</div>
<noscript><p class="notice">カレンダーの表示にはJavaScriptを有効にしてください。</p></noscript>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
