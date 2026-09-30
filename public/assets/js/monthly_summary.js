/** monthly_summary.js の役割：本人が今月受け取った感謝とカレンダー追加を、順位なしでホームに表示する。 */
'use strict';
let monthlyRequest = 0;
/** 推し切替と連動する。予定の開催月ではなく、共有・追加・リアクションが行われた月で集計する。 */
async function loadMonthlyThanks() {
    const number = ++monthlyRequest;
    const panel = document.getElementById('monthly-thanks');
    const oshi = document.getElementById('home-oshi').value || 'all';
    panel.replaceChildren(scheduleElement('p','','今月の共有状況を読み込んでいます…'));
    try {
        const data = await apiRequest(`api/reflections/monthly-summary.php?year=${panel.dataset.year}&month=${panel.dataset.month}&oshi_id=${oshi}`);
        if (number !== monthlyRequest) return;
        panel.replaceChildren(scheduleElement('p','caption',`${data.year}年${data.month}月 · 選択中の推しの共有状況`));
        if (!data.calendar_added_count && !data.helped_count && !data.thanks_count) {
            panel.append(scheduleElement('p','','今月はまだ反応がありません。予定を共有すると、ここに「ありがとう」が届きます。'));
        } else {
            panel.append(scheduleElement('p','',`あなたの共有した予定が ${data.calendar_added_user_count}人のカレンダーに ${data.calendar_added_count}件追加されました。`));
            panel.append(scheduleElement('p','',`🙌 助かった！ ${data.helped_count}件`), scheduleElement('p','',`♡ ありがとう！ ${data.thanks_count}件`));
        }
        panel.append(scheduleElement('p','caption','現在残っている追加・リアクションの集計です。'));
    } catch (error) {
        if (number !== monthlyRequest) return;
        panel.replaceChildren(scheduleElement('p','',error.message));
        const retry = scheduleElement('button','button secondary small','再読み込み'); retry.type='button'; retry.addEventListener('click',loadMonthlyThanks); panel.append(retry);
    }
}
document.getElementById('home-oshi').addEventListener('change',loadMonthlyThanks);
loadMonthlyThanks();
