<?php
/** app.php の役割：全画面・APIの起動処理。環境変数、共通関数、セッションを用意する。 */
declare(strict_types=1);

const PROJECT_ROOT = __DIR__ . '/..';
require_once PROJECT_ROOT . '/app/helpers/env.php';
require_once PROJECT_ROOT . '/app/helpers/view.php';
// ページの障害でも内部情報を表示せず、利用者には次の行動を日本語で案内する。
if (!defined('API_REQUEST')) {
    set_exception_handler(function (Throwable $exception): void {
        error_log('Page error: ' . get_class($exception) . ' code=' . $exception->getCode());
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>接続を確認してください</title><h1>ただいまページを表示できません</h1><p>時間をおいて再読み込みしてください。初回設定中の場合は、READMEのDB接続設定をご確認ください。</p></html>';
    });
}
loadEnv(PROJECT_ROOT . '/.env');
date_default_timezone_set('Asia/Tokyo');
// APP_URLを1か所変えると画面・CSS・JS・API・Cookieのパスが一緒に変わる。
// URLのホスト名は設定値から取得し、利用者が送るHostヘッダーを信用して生成しない。
$appUrl = rtrim(env('APP_URL'), '/');
if ($appUrl !== '') {
    $parts = parse_url($appUrl);
    if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('APP_URLはhttp://localhost/oshilife-v2/publicのように指定してください。');
    }
    $basePath = rtrim($parts['path'] ?? '', '/');
} else {
    // Phase 1の設定とも互換性を保つ。新規セットアップではAPP_URLを推奨する。
    $basePath = rtrim(env('APP_BASE_PATH'), '/');
}
if ($basePath !== '' && !preg_match('#^(/[a-zA-Z0-9_-]+)+$#', $basePath)) {
    throw new RuntimeException('公開URLのパスには英数字・ハイフン・アンダースコアを使用してください。');
}
define('APP_BASE_PATH', $basePath);
define('APP_URL', $appUrl);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', PROJECT_ROOT . '/storage/logs/php-error.log');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store');

// セッションはサーバーに保存する情報。ブラウザーには内容ではなく照合用IDだけを渡す。
// 既存OshilifeのCookieと混ざらないよう、独立した名前と保存先を使う。
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '7200');
session_name('OSHILIFE_V2_SESSION');
session_save_path(PROJECT_ROOT . '/storage/sessions');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => APP_BASE_PATH . '/',
    'secure' => env('APP_ENV', 'local') === 'production' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!session_start()) {
        throw new RuntimeException('セッション保存先のアクセス権を確認してください。');
    }
}
// 最後のアクセスから2時間経ったログイン情報は破棄する。
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 7200) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();
