<?php
/** bootstrap.php の役割：公開PHPから非公開のアプリ本体を探し、ローカルと本番の配置差を吸収する。 */
declare(strict_types=1);

// ローカルでは public/ の親にアプリ本体がある。本番では www/ と同じ親の
// oshilife_private/ に置く。Webから渡されるパスは使わない。
$localRoot = dirname(__DIR__);
$privateRoot = realpath($localRoot . '/public') === __DIR__ && is_file($localRoot . '/config/app.php')
    ? $localRoot
    : dirname($localRoot) . '/oshilife_private';
$resolvedRoot = realpath($privateRoot);
if ($resolvedRoot === false || !is_file($resolvedRoot . '/config/app.php')) {
    // config/app.phpより前に失敗するので、PHP標準のエラー画面へサーバーパスを出さない。
    http_response_code(503);
    exit('アプリの配置を確認してください。');
}

define('APP_PRIVATE_ROOT', $resolvedRoot);
define('APP_PUBLIC_ROOT', __DIR__);
