<?php
/** discover.php の役割：見つける画面を表示する。共通middlewareでログインを確認し、Phase 1の仮UIを出す。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
$pageTitle = '見つける';
$pageStyle = 'discover';
$activePage = 'discover';
require PROJECT_ROOT . '/includes/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">FIND YOUR NEXT JOY</p><h1>好き、を見つける。</h1></div><span class="heading-flower" aria-hidden="true">⌕</span></div>
<label class="search-label" for="search">推し・予定・共有情報を検索</label><input id="search" type="search" placeholder="検索機能は準備中です" disabled>
<p class="demo-note">公開予定の表示例です。検索・追加は今後対応します。</p>
<div class="filter-chips"><span class="chip active">公開予定</span><span class="chip">推し</span><span class="chip">共有情報</span></div>
<section class="section"><h2>みんなの公開予定</h2><div class="responsive-cards"><article class="card discovery-card"><div class="discovery-art lavender-art" aria-hidden="true">💎 <span>ON AIR</span> ✧</div><div class="card-meta"><span class="tag lavender">YouTube</span><span>表示例 · 10/03 22:00</span></div><h3>💎🩷🖤 週末のスペシャル配信</h3><p>画面の向こうで会える、特別な時間。</p><button class="text-button" disabled>＋ カレンダーに追加 · 準備中</button></article><article class="card discovery-card"><div class="card-meta"><span class="tag">新曲</span><span>表示例 · 10/10 20:00</span></div><h3>👑🐃 新曲配信スタート</h3><p>新しい一曲を、毎日のおともに。</p><button class="text-button" disabled>＋ カレンダーに追加 · 準備中</button></article></div></section>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
