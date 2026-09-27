<?php
/** live.php の役割：ライブ画面を表示する。共通middlewareでログインを確認し、Phase 1の仮UIを出す。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
$pageTitle = 'ライブ';
$pageStyle = 'live';
$activePage = 'live';
require PROJECT_ROOT . '/includes/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">SEE YOU AT THE LIVE</p><h1>会える日のこと。</h1></div><span class="heading-flower" aria-hidden="true">♫</span></div><p class="intro">待ち遠しい日を、ひとつずつ。</p><p class="demo-note">ライブ情報・申込状況は表示例です。</p>
<section class="section"><h2>これからのライブ</h2><div class="responsive-cards"><article class="card concert-card"><div class="concert-cover"><span>💎</span><small>AUTUMN LIVE</small><strong>好きな音に、<br>会いにいこう。</strong><span class="cover-year">2026</span></div><div class="card-meta"><span class="tag lavender">当選 · 表示例</span><span>10/10（土）</span></div><h3>Autumn live 2026</h3><p>東京 / 開場 17:00 · 開演 18:00</p><div class="live-checklist"><span>申込状況</span><span>遠征</span><span>TODO</span></div><p class="caption">ライブ詳細・当落・遠征・連番ルームは準備中です。</p></article><article class="card"><div class="card-meta"><span class="tag">申込前 · 表示例</span><span>11/21（土）</span></div><h3>👑🐃 Winter session</h3><p>大阪 / 開演 18:30</p></article></div></section>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
