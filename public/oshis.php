<?php
/** oshis.php の役割：自分の推し一覧、共有推し検索、新しい推し作成の画面を表示する。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
require_once PROJECT_ROOT . '/app/validators/oshi_validator.php';
$pageTitle = '推し管理';
$pageStyle = 'oshis';
$pageScripts = ['oshis'];
$activePage = 'profile';
require PROJECT_ROOT . '/includes/header.php';
?>
<a class="page-back" href="<?= e(appUrl('profile.php')) ?>">← マイページ</a>
<div class="page-heading"><div><p class="eyebrow">A LITTLE MORE ABOUT MY OSHI</p><h1>わたしの推し。</h1><p class="intro">好きな人、好きな音。あなたの毎日に集めよう。</p></div><span class="heading-flower" aria-hidden="true">♡</span></div>
<p id="oshi-message" class="status-text" role="status" tabindex="-1"></p>
<section class="section my-oshis-section">
    <div class="section-toolbar"><h2>自分の推し</h2><a class="text-link" href="#oshi-search">＋ 推しを探す</a></div>
    <div id="my-oshis" class="oshi-grid" aria-live="polite"><p>推しを読み込んでいます…</p></div>
</section>
<section class="section search-section">
    <div class="section-heading"><h2>推しを検索</h2><span class="tag lavender">みんなの共有マスタ</span></div>
    <p class="caption">すでに登録されている推しを検索して、自分の一覧に追加できます。</p>
    <form id="oshi-search" class="inline-form">
        <div><label class="form-label" for="oshi-query">推しの名前</label><input id="oshi-query" name="q" placeholder="SixTONES、King Gnu…" maxlength="100" required type="search"></div>
        <button class="button primary small" type="submit">検索</button>
    </form>
    <p id="search-status" class="status-text" role="status"></p>
    <div id="search-results" class="oshi-grid"></div>
    <button id="search-more" type="button" class="button secondary small" hidden>さらに表示</button>
    <div id="create-prompt" class="create-prompt" hidden><p>探している推しが見つからないときは</p><button id="show-create" class="button secondary small" type="button" aria-expanded="false" aria-controls="create-section">＋ 新しい推しを登録</button></div>
</section>
<section id="create-section" class="section card create-section" hidden>
    <p class="eyebrow">NEW OSHI</p><h2>新しい推しを登録</h2>
    <p class="caption">ここで作成した推しは、他のユーザーも検索・登録できます。自分の推し一覧にも追加されます。</p>
    <form id="oshi-create" novalidate>
        <div class="form-grid">
            <div><label class="form-label" for="oshi-name">推し名</label><input id="oshi-name" name="name" maxlength="100" required aria-describedby="oshi-error-name"><p class="field-error" id="oshi-error-name"></p></div>
            <div><label class="form-label" for="oshi-type">推し種別</label><select id="oshi-type" name="oshi_type" required aria-describedby="oshi-error-oshi_type">
                <?php foreach (OSHI_TYPES as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?>
            </select><p class="field-error" id="oshi-error-oshi_type"></p></div>
            <div><label class="form-label" for="oshi-emoji">推しの絵文字</label><input id="oshi-emoji" name="emoji" placeholder="💎 や 👑🐃" maxlength="64" required aria-describedby="emoji-help oshi-error-emoji"><p class="caption" id="emoji-help">複数の絵文字・記号を使えます（32文字以内）。</p><p class="field-error" id="oshi-error-emoji"></p></div>
            <div><label class="form-label" for="oshi-color">テーマカラー（HEX）</label><input id="oshi-color" name="theme_color" value="#986879" maxlength="7" pattern="#[0-9a-fA-F]{6}" required aria-describedby="oshi-error-theme_color"><p class="field-error" id="oshi-error-theme_color"></p></div>
        </div>
        <p class="form-message" id="create-message" role="alert" tabindex="-1"></p>
        <button class="button primary" type="submit">作成して自分の推しに追加</button>
    </form>
</section>
<noscript><p class="notice">推しの検索・登録にはJavaScriptを有効にしてください。</p></noscript>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
