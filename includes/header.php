<?php
/** header.php の役割：全ページ共通のheadとヘッダー。CSRFとアプリURLをJavaScriptへ渡す。 */
require_once PROJECT_ROOT . '/middleware/csrf.php';
$isAuthPage = $isAuthPage ?? false;
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="Oshilife v2 — 推しとの毎日を、自分らしく。">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <meta name="app-base-path" content="<?= e(APP_BASE_PATH) ?>">
    <title><?= e($pageTitle) ?> | Oshilife v2</title>
    <link rel="stylesheet" href="<?= e(assetUrl('assets/css/common.css')) ?>">
    <link rel="stylesheet" href="<?= e(assetUrl('assets/css/' . $pageStyle . '.css')) ?>">
    <?php foreach (($extraStyles ?? []) as $style): ?>
    <link rel="stylesheet" href="<?= e(assetUrl('assets/css/' . $style . '.css')) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?= e(assetUrl('assets/css/theme.css')) ?>">
    <link id="user-theme" rel="stylesheet" href="<?= e(appUrl('theme.css.php')) ?>">
    <script src="<?= e(assetUrl('assets/js/common.js')) ?>" defer></script>
    <?php if ($isAuthPage): ?>
    <script src="<?= e(assetUrl('assets/js/auth.js')) ?>" defer></script>
    <?php endif; ?>
    <?php foreach (($pageScripts ?? []) as $script): ?>
    <script src="<?= e(assetUrl('assets/js/' . $script . '.js')) ?>" defer></script>
    <?php endforeach; ?>
</head>
<body class="<?= $isAuthPage ? 'auth-layout' : 'app-layout' ?>">
<a class="skip-link" href="#main">本文へスキップ</a>
<div class="app-shell">
<header class="app-header">
    <a class="brand" href="<?= e(appUrl('index.php')) ?>" aria-label="Oshilife ホーム"><span class="brand-mark" aria-hidden="true">✳</span> oshilife<span class="version">v2</span></a>
    <span class="header-note"><?= $isAuthPage ? '推しとの毎日を、自分らしく。' : 'MY OSHI, MY LIFE' ?></span>
<?php if (!$isAuthPage): ?>
<?php require PROJECT_ROOT . '/includes/bottom_nav.php'; ?>
<?php endif; ?>
</header>
<main id="main" class="<?= $isAuthPage ? 'auth-main' : 'page-main' ?>">
