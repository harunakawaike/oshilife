<?php
/** calendar.php の役割：カレンダー画面を表示する。共通middlewareでログインを確認し、Phase 1の仮UIを出す。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
$pageTitle = 'カレンダー';
$pageStyle = 'calendar';
$activePage = 'calendar';
require PROJECT_ROOT . '/includes/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">DAYS WITH MY OSHI</p><h1>カレンダー</h1></div><span class="heading-flower" aria-hidden="true">▦</span></div>
<div class="oshi-selector"><span>💎</span><strong>すべての推し</strong><button class="chip" disabled>切替 · 準備中</button></div>
<p class="demo-note">表示例 · 2026年10月10日を選択しています。</p>
<section class="calendar-card card"><div class="section-heading"><button class="icon-button" disabled aria-label="前の月（準備中）">‹</button><h2>2026年 <strong>10月</strong></h2><button class="icon-button" disabled aria-label="次の月（準備中）">›</button></div><div class="calendar-grid" aria-label="2026年10月の月間カレンダー（表示例）">
<?php foreach (['日', '月', '火', '水', '木', '金', '土'] as $day): ?><span class="weekday"><?= e($day) ?></span><?php endforeach; ?>
<?php for ($blank = 0; $blank < 4; $blank++): ?><span></span><?php endfor; ?>
<?php for ($day = 1; $day <= 31; $day++): ?><span class="calendar-day <?= $day === 10 ? 'selected' : '' ?>"><?= $day ?><?php if (in_array($day, [3, 10, 17, 24], true)): ?><span class="calendar-dot"></span><?php endif; ?></span><?php endfor; ?>
</div></section>
<section class="section"><div class="section-heading"><h2>10月10日 <span class="muted">土曜日</span></h2><span class="tag">3件 · 表示例</span></div><div class="card schedule-list"><p>💎🩷 <time>19:00</time> TV出演</p><p>👑🐃 <time>20:00</time> 新曲配信</p><p>💎🩷🖤 <time>22:00</time> YouTube</p></div></section>
<button class="button primary" disabled>＋ 予定を追加 · 準備中</button>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
