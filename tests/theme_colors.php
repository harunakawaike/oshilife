<?php
/** theme_colors.php の役割：明暗の極端な配色でも、本文・リンク・ボタン文字に十分な明暗差があるか検証する。 */
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers/theme.php';
mt_srand(20260930);
$colors = ['#000000', '#FFFFFF', '#FACC15', '#808080', '#FF0000', '#0000FF'];
for ($i = 0; $i < 500; $i++) $colors[] = sprintf('#%06X', mt_rand(0, 16777215));
foreach ($colors as $background) {
    foreach (['#000000', '#FFFFFF', '#FACC15', '#2563EB'] as $accent) {
        $v = themeVariables(['theme_color'=>$background, 'accent_color'=>$accent]);
        foreach (['bg','surface','pink'] as $area) {
            if (themeContrast($v['ink'], $v[$area]) < 4.5 || themeContrast($v['accent-text'], $v[$area]) < 4.5) {
                throw new RuntimeException('本文・リンクの明暗差不足: ' . $background);
            }
        }
        if (themeContrast($v['accent'], $v['on-accent']) < 4.5) throw new RuntimeException('ボタン文字の明暗差不足');
    }
}
echo "2024 color combinations: contrast checks passed.\n";
