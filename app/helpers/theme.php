<?php
/** theme.php の役割：本人の配色設定を読み書きし、読みやすい文字色と共通CSS変数を作る。 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
const DEFAULT_THEME = ['theme_color' => '#F4F5F7', 'accent_color' => '#2563EB'];
const THEME_PRESETS = [
    'ブルー' => '#2563EB', 'グリーン' => '#00875A', 'オレンジ' => '#EA580C',
    'レッド' => '#DC2626', 'パープル' => '#7C3AED', 'ピンク' => '#DB2777',
    'イエロー' => '#FACC15', 'モノトーン' => '#252525',
];
/** CSSへの不正な文字列混入を防ぐため、色だけを厳密に許可する。 */
function validThemeColor(mixed $value): bool
{
    return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1;
}
/** ログイン本人の設定のみ取得。設定がない場合はブルーとライト背景を使う。 */
function userTheme(?int $userId): array
{
    if ($userId === null) return DEFAULT_THEME;
    $statement = database()->prepare('SELECT theme_color,accent_color FROM user_settings WHERE user_id=:id');
    $statement->execute(['id' => $userId]);
    $theme = $statement->fetch();
    if (!$theme || !validThemeColor($theme['theme_color']) || !validThemeColor($theme['accent_color'])) return DEFAULT_THEME;
    return array_map('strtoupper', $theme);
}
/** PDOで値を別送し、SQLインジェクションを防ぐ。本人IDはAPIがセッションから渡す。 */
function saveUserTheme(int $userId, array $theme): void
{
    $statement = database()->prepare('INSERT INTO user_settings (user_id,theme_color,accent_color) VALUES (:id,:background,:accent)
        ON DUPLICATE KEY UPDATE theme_color=VALUES(theme_color),accent_color=VALUES(accent_color)');
    $statement->execute(['id' => $userId, 'background' => $theme['theme_color'], 'accent' => $theme['accent_color']]);
}
/** 色の各成分を取り出す。入力は検証済みの色に限定する。 */
function themeRgb(string $hex): array
{
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}
/** 2色を混ぜて、背景に合うカード・枠線を作る。 */
function themeMix(string $a, string $b, float $amount): string
{
    $left = themeRgb($a); $right = themeRgb($b); $rgb = [];
    foreach ($left as $i => $value) $rgb[] = (int) round($value * (1 - $amount) + $right[$i] * $amount);
    return sprintf('#%02X%02X%02X', ...$rgb);
}
/** 相対輝度は色の明るさ。明暗の比から文字が読めるかを判定する。 */
function themeLuminance(string $hex): float
{
    $linear = array_map(function (int $value): float {
        $value /= 255;
        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, themeRgb($hex));
    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
}
/** 白・黒のうちコントラストが高い方を文字色にする。黄色や黒のボタンでも文字が消えない。 */
function themeInk(string $background): string
{
    return themeLuminance($background) > 0.179 ? '#000000' : '#FFFFFF';
}
/** 色のコントラスト比を比較する。リンクは複数の背景に置くため両方で確認する。 */
function themeContrast(string $a, string $b): float
{
    $a = themeLuminance($a); $b = themeLuminance($b);
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}
/** 保存色から画面全体の変数を生成。色の役割をまとめ、ページごとの固定ピンクを置き換える。 */
function themeVariables(array $theme): array
{
    $bg = $theme['theme_color']; $accent = $theme['accent_color'];
    $ink = themeInk($bg);
    $surface = themeMix($bg, $ink === '#000000' ? '#FFFFFF' : '#101216', 0.94);
    $soft = themeMix($surface, $accent, 0.10);
    if (themeContrast($ink, $soft) < 4.5) $soft = $surface;
    // 好きなボタン色はそのまま。細いリンク文字だけは背景で読める色に調整する。
    $link = $accent;
    if (min(themeContrast($link, $bg), themeContrast($link, $surface), themeContrast($link, $soft)) < 4.5) $link = $ink;
    return ['color-scheme'=>$ink === '#000000' ? 'light' : 'dark', 'bg'=>$bg, 'surface'=>$surface, 'ink'=>$ink, 'muted'=>$ink,
        'accent'=>$accent, 'accent-text'=>$link, 'on-accent'=>themeInk($accent),
        'pink'=>$soft, 'lavender'=>$soft, 'border'=>themeMix($surface, $ink, 0.24),
        'shadow'=>'0 4px 18px #0000000D'];
}
