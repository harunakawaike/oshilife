<?php
/** bottom_nav.php の役割：5画面の共通ナビ。スマホでは下部固定、PCではヘッダー内にCSSで切り替える。現在のページを色とaria-currentで示す。 */
$navigation = [
    ['home', 'ホーム', '⌂'],
    ['calendar', 'カレンダー', '▦'],
    ['discover', '見つける', '⌕'],
    ['events', 'イベント', '▣'],
    ['profile', 'マイページ', '♙'],
];
?>
<nav class="bottom-nav" aria-label="メインナビゲーション">
<?php foreach ($navigation as [$key, $label, $icon]): ?>
    <a href="<?= e(appUrl($key . '.php')) ?>" <?= $activePage === $key ? 'aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true"><?= e($icon) ?></span><span><?= e($label) ?></span></a>
<?php endforeach; ?>
</nav>
