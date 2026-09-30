<?php
/** theme.php の役割：ログイン本人の配色をJSONで取得・保存する。グループやメンバーカラーは変更しない。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/api_bootstrap.php';
require_once PROJECT_ROOT . '/app/helpers/theme.php';
$user = requireAuth(true);
if ($_SERVER['REQUEST_METHOD'] === 'GET') apiSuccess(['theme' => userTheme((int) $user['id'])]);
requireMethod('POST');
requireCsrf();
$raw = readJson();
$theme = [];
foreach (['theme_color', 'accent_color'] as $key) {
    if (!validThemeColor($raw[$key] ?? null)) apiError('色を選び直してください。', 422);
    $theme[$key] = strtoupper($raw[$key]);
}
saveUserTheme((int) $user['id'], $theme);
apiSuccess(['theme' => $theme]);
