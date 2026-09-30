<?php
/** theme.css.php の役割：本人の配色を外部CSSとして返す。インラインCSSを許可せず既存CSPを維持する。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
require_once PROJECT_ROOT . '/app/helpers/theme.php';
$user = currentUser();
$theme = userTheme($user ? (int) $user['id'] : null);
// マイページの試着用。GETはDBを変更せず、有効な2色がある時だけ一時表示する。
if ($user && validThemeColor($_GET['background'] ?? null) && validThemeColor($_GET['accent'] ?? null)) {
    $theme = ['theme_color' => $_GET['background'], 'accent_color' => $_GET['accent']];
}
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: private, no-store');
echo ':root {';
foreach (themeVariables($theme) as $name => $value) echo '--' . $name . ':' . $value . ';';
echo '}';
