/** monthly_summary.js の役割：今月のカレンダー追加と感謝を、数字中心の小さなダッシュボードで表示する。 */
'use strict';
let monthlyRequest = 0;
/** 1つの数値をアイコン・大きな数字・短いラベルで表し、装飾は読み上げ対象から外す。 */
function monthlyMetric(icon, label, value, unit) {
    const metric=scheduleElement('div','thanks-metric');
    const symbol=scheduleElement('span','thanks-metric-icon',icon);symbol.setAttribute('aria-hidden','true');
    const number=scheduleElement('dd','thanks-metric-value',String(value));
    number.append(scheduleElement('small','',unit));
    metric.append(symbol,scheduleElement('dt','thanks-metric-label',label),number);
    return metric;
}
/** 推し切替の最新応答だけを採用し、0件でも同じ3つの数値を表示する。 */
async function loadMonthlyThanks() {
    const number = ++monthlyRequest;
    const panel = document.getElementById('monthly-thanks');
    const oshi = document.getElementById('home-oshi').value || 'all';
    panel.replaceChildren(scheduleElement('p','','読み込んでいます…'));
    try {
        const data = await apiRequest(`api/reflections/monthly-summary.php?year=${panel.dataset.year}&month=${panel.dataset.month}&oshi_id=${oshi}`);
        if (number !== monthlyRequest) return;
        const period=scheduleElement('div','thanks-period');
        period.append(scheduleElement('span','',`${data.year}年${data.month}月`),scheduleElement('span','thanks-period-label','今月の共有'));
        const metrics=scheduleElement('dl','thanks-metrics');
        metrics.append(monthlyMetric('▦','カレンダー追加',data.calendar_added_count,'件'),monthlyMetric('🙌','助かった！',data.helped_count,'件'),monthlyMetric('♡','ありがとう！',data.thanks_count,'件'));
        panel.replaceChildren(period,metrics);
        const total=data.calendar_added_count+data.helped_count+data.thanks_count;
        panel.append(scheduleElement('p','thanks-note',total?'あなたの共有が、誰かの役に立っています。':'予定を共有すると、ここに反応が届きます。'));
    } catch (error) {
        if (number !== monthlyRequest) return;
        panel.replaceChildren(scheduleElement('p','',error.message));
        const retry=scheduleElement('button','button secondary small','再読み込み');retry.type='button';retry.addEventListener('click',loadMonthlyThanks);panel.append(retry);
    }
}
document.getElementById('home-oshi').addEventListener('change',loadMonthlyThanks);
loadMonthlyThanks();
