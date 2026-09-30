<?php
/** view.php の役割：HTML表示とアプリ内URLを安全に共通化する。 */
declare(strict_types=1);

/** ユーザー入力をHTMLの文字として表示し、タグ・スクリプトの実行（XSS）を防ぐ。 */
function e(?string $value): string
{
    // htmlspecialchars は < や引用符を変換する。DB保存時ではなくHTML出力時に使う。
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** サブディレクトリ配置にも対応したアプリ内URLを作る。 */
function appUrl(string $path): string
{
    return APP_BASE_PATH . '/' . ltrim($path, '/');
}

/** ファイルの内容が変わったらURLも変え、ブラウザーに古いCSS/JSを使わせない。 */
function assetUrl(string $path): string
{
    // 呼び出し元は共通ヘッダー内の固定パス。テーマなど本人別の動的CSSには使わない。
    $version = substr(hash_file('sha256', PROJECT_ROOT . '/public/' . $path), 0, 12);
    return appUrl($path) . '?v=' . $version;
}

/** 固定のアプリ内ページへ移動し、後続処理を止める。 */
function redirectTo(string $path): never
{
    header('Location: ' . appUrl($path));
    exit;
}
