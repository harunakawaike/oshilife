<?php
/** home.php の役割：ホーム画面を表示する。共通middlewareでログインを確認し、実データの推し切替と予定などの表示例を出す。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
require_once PROJECT_ROOT . '/app/repositories/oshi_repository.php';
$myOshis = findMyOshis(database(), (int) $user['id']);
$pageScripts = ['home'];
$pageTitle = 'ホーム';
$pageStyle = 'home';
$activePage = 'home';
require PROJECT_ROOT . '/includes/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">MY LITTLE HAPPINESS</p><h1>好きがある、毎日。</h1></div><span class="heading-flower" aria-hidden="true">✳</span></div>
<p class="greeting"><?= e($user['display_name']) ?>さん、こんにちは。</p>
<div class="oshi-selector"><span class="oshi-avatar" aria-hidden="true">♡</span><div class="oshi-picker"><label for="home-oshi">表示する推し</label><select id="home-oshi"><option value="">すべての推し</option><?php foreach ($myOshis as $oshi): ?><option value="<?= (int) $oshi['id'] ?>"><?= e($oshi['emoji'] . ' ' . $oshi['name']) ?></option><?php endforeach; ?></select></div><a class="chip" href="<?= e(appUrl('oshis.php')) ?>">推し管理</a></div>
<?php if ($myOshis === []): ?><p class="caption">まずは<a href="<?= e(appUrl('oshis.php')) ?>">推しを登録</a>して、あなたの好きなものを集めましょう。</p><?php endif; ?>
<p id="home-filter-status" class="caption" aria-live="polite">すべての推しを選択中。予定などの絞り込みは今後対応します。</p>
<p class="demo-note">以下は表示例です。予定や金額は保存されません。</p>
<div class="dashboard-grid">
<section class="today-card"><div class="section-heading"><h2>今日の予定</h2><span class="tag">表示例</span></div><p class="date-label"><?= e(date('n月j日')) ?></p><div class="event-row"><span class="event-time">19:00</span><div><span class="mini-label">TV / MEDIA</span><h3>💎🩷 音楽番組に出演</h3><p>夜の楽しみを、ひとつ。</p></div></div></section>
<section class="section"><div class="section-heading"><h2>新着の公開予定</h2><a href="<?= e(appUrl('discover.php')) ?>">見つける →</a></div><article class="card"><div class="card-meta"><span class="tag lavender">配信</span><span>表示例 · 10/03</span></div><h3>💎🩷🖤 22:00 YouTube公開</h3><p>週末のスペシャルコンテンツ</p><button class="text-button" disabled>＋ カレンダーに追加 · 準備中</button></article><p class="caption">今後は、カレンダーに追加していない公開予定だけが並びます。</p></section>
<section class="section"><div class="section-heading"><h2>次のライブ</h2><a href="<?= e(appUrl('live.php')) ?>">一覧 →</a></div><article class="live-preview"><div class="ticket-date"><strong>10</strong><span>OCT</span></div><div><span class="mini-label">表示例 / LIVE</span><h3>Autumn live 2026</h3><p>東京 · 開演 18:00</p></div><span aria-hidden="true">♫</span></article></section>
<div class="two-cards"><section class="card funds"><span class="small-icon">◌</span><h2>推し活資金</h2><strong>¥30,000</strong><p>次の楽しみへ · 表示例</p></section><section class="card thanks"><span class="small-icon">♡</span><h2>最近のありがとう</h2><strong>12<span> 件</span></strong><p>好きでつながる · 表示例</p></section></div>
</div>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
