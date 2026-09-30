/** theme.js の役割：色名・カラーピッカーから配色を試し、本人の設定APIへ保存する。 */
'use strict';
const themeForm = document.getElementById('theme-form');
const themeAccent = document.getElementById('theme-accent');
const themeBackground = document.getElementById('theme-background');
const themeMessage = document.getElementById('theme-message');
const themeSheet = document.getElementById('user-theme');
let savedTheme = { accent_color: themeAccent.value, theme_color: themeBackground.value };
let previewTimer;

/** 現在の入力を取得する。保存中に色を変えないようフォームをロックする。 */
function selectedTheme() {
    return { accent_color: themeAccent.value.toUpperCase(), theme_color: themeBackground.value.toUpperCase() };
}
/** 文字色の計算はPHPと共通にし、試着と保存後で見た目が変わらないようにする。 */
function previewTheme() {
    clearTimeout(previewTimer);
    const theme = selectedTheme();
    themeForm.querySelectorAll('[name=preset]').forEach((radio) => { radio.checked = radio.value === theme.accent_color; });
    themeForm.querySelectorAll('[data-background]').forEach((button) => { button.setAttribute('aria-pressed', String(button.dataset.background === theme.theme_color)); });
    themeMessage.textContent = 'プレビュー中です。「この色で保存」で確定します。';
    previewTimer = setTimeout(() => {
        const query = new URLSearchParams({ background: theme.theme_color, accent: theme.accent_color });
        themeSheet.href = appUrl(`theme.css.php?${query}`);
    }, 150);
}
/** 成功するまで保存済みの値を書き換えない。失敗時にも選んだ色は残して再試行できる。 */
async function saveTheme(event) {
    event.preventDefault();
    const theme = selectedTheme();
    const controls = [...themeForm.querySelectorAll('input,button')];
    controls.forEach((control) => { control.disabled = true; });
    themeMessage.textContent = '保存しています…';
    try {
        const data = await apiRequest('api/settings/theme.php', 'POST', theme);
        savedTheme = data.theme;
        clearTimeout(previewTimer);
        themeSheet.href = appUrl('theme.css.php');
        themeMessage.textContent = '保存しました。ホームやカレンダーにもこの配色が反映されます。';
    } catch (error) { themeMessage.textContent = error.message; }
    finally { controls.forEach((control) => { control.disabled = false; }); }
}
themeForm.addEventListener('submit', saveTheme);
themeForm.querySelectorAll('[name=preset]').forEach((radio) => radio.addEventListener('change', () => {
    themeAccent.value = radio.value; previewTheme();
}));
themeForm.querySelectorAll('[data-background]').forEach((button) => button.addEventListener('click', () => {
    themeBackground.value = button.dataset.background; previewTheme();
}));
themeAccent.addEventListener('input', previewTheme);
themeBackground.addEventListener('input', previewTheme);
themeSheet.addEventListener('error', () => { themeMessage.textContent = '配色を読み込めませんでした。接続を確認して画面を再読み込みしてください。'; });
document.getElementById('reset-theme').addEventListener('click', () => {
    themeAccent.value = savedTheme.accent_color; themeBackground.value = savedTheme.theme_color;
    previewTheme(); themeMessage.textContent = '保存した色に戻しました。';
});
// 色だけでなく選択状態も読み上げられるようにする。
themeForm.querySelectorAll('[data-background]').forEach((button) => {
    button.setAttribute('aria-pressed', String(button.dataset.background === themeBackground.value.toUpperCase()));
});
