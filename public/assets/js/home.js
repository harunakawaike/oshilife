/** home.js の役割：DBから取得した推しの選択を画面へ反映する。予定の絞り込みは次Phaseで追加する。 */
'use strict';
/** 実際の予定はまだ絞り込まないため、選択状態と今の対応範囲を正しく案内する。 */
function updateOshiSelection() {
    const select = document.getElementById('home-oshi');
    document.getElementById('home-filter-status').textContent = `${select.selectedOptions[0].textContent}を選択中。予定などの絞り込みは今後対応します。`;
}
document.getElementById('home-oshi').addEventListener('change', updateOshiSelection);
