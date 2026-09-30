/** calendar.js の役割：月移動・推し絞り込み・日付選択と本人の実予定表示を行う。 */
'use strict';
const calendarToday = document.getElementById('calendar-app').dataset.today;
let calendarDate = calendarToday;
let calendarMonth = calendarToday.slice(0, 7);
let calendarItems = [];
let calendarRequest = 0;
/** 選択日の一覧と追加先の日付を揃える。 */
function showCalendarDay() {
    const list = document.getElementById('day-schedules');
    const items = calendarItems.filter((item) => item.date === calendarDate);
    document.getElementById('selected-day').textContent = `${calendarDate.replaceAll('-', '/')} の予定`;
    document.getElementById('day-count').textContent = `${items.length}件`;
    document.getElementById('calendar-add').href = appUrl(`schedule_form.php?date=${calendarDate}`);
    list.replaceChildren(...items.map((item) => scheduleCard(item)));
    if (!items.length) scheduleEmpty(list, 'この日の予定はまだありません。');
    document.querySelectorAll('.month-day').forEach((button) => {
        const selected = button.dataset.date === calendarDate;
        button.classList.toggle('selected', selected);
        button.setAttribute('aria-pressed', String(selected));
    });
}
/** 月初の曜日と日数から実際の月間カレンダーを作る。 */
function renderCalendarMonth() {
    const [year, month] = calendarMonth.split('-').map(Number);
    const grid = document.getElementById('month-days');
    document.getElementById('calendar-month').textContent = `${year}年 ${month}月`;
    grid.setAttribute('aria-label', `${year}年${month}月のカレンダー`);
    grid.replaceChildren();
    ['日', '月', '火', '水', '木', '金', '土'].forEach((day) => grid.append(scheduleElement('span', 'month-weekday', day)));
    const first = new Date(year, month - 1, 1).getDay();
    for (let blank = 0; blank < first; blank++) grid.append(scheduleElement('span', 'month-blank'));
    const days = new Date(year, month, 0).getDate();
    for (let day = 1; day <= days; day++) {
        const date = `${calendarMonth}-${String(day).padStart(2, '0')}`;
        const count = calendarItems.filter((item) => item.date === date).length;
        const button = scheduleElement('button', 'month-day', String(day));
        button.type = 'button'; button.dataset.date = date;
        button.setAttribute('aria-label', `${month}月${day}日、予定${count}件`);
        if (date === calendarToday) button.setAttribute('aria-current', 'date');
        if (count) button.append(scheduleElement('small', 'month-count', `${count}件`));
        button.addEventListener('click', () => { calendarDate = date; showCalendarDay(); });
        grid.append(button);
    }
    showCalendarDay();
}
/** 古い月の応答で新しい月を上書きしないようリクエスト番号を照合する。 */
async function loadCalendarMonth() {
    const request = ++calendarRequest;
    const [year, month] = calendarMonth.split('-');
    const message = document.getElementById('calendar-message');
    message.textContent = '予定を読み込んでいます…';
    try {
        const data = await apiRequest(`api/schedules/calendar.php?year=${year}&month=${month}&oshi_id=${document.getElementById('calendar-oshi').value}`);
        if (request !== calendarRequest) return;
        calendarItems = data.schedules;
        renderCalendarMonth();
        message.textContent = '共有予定の変更は、カレンダーを読み込んだときに反映されます。';
    } catch (error) {
        if (request !== calendarRequest) return;
        calendarItems = []; renderCalendarMonth(); message.textContent = error.message;
    }
}
/** 月末から移動しても飛び越さないよう、必ず1日を基準に次の月を作る。 */
function moveCalendarMonth(amount) {
    const [year, month] = calendarMonth.split('-').map(Number);
    const date = new Date(year, month - 1 + amount, 1);
    if (date.getFullYear() < 1000 || date.getFullYear() > 9999) return;
    calendarMonth = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
    calendarDate = calendarMonth + '-01';
    loadCalendarMonth();
}
document.getElementById('month-prev').addEventListener('click', () => moveCalendarMonth(-1));
document.getElementById('month-next').addEventListener('click', () => moveCalendarMonth(1));
document.getElementById('month-today').addEventListener('click', () => { calendarMonth = calendarToday.slice(0, 7); calendarDate = calendarToday; loadCalendarMonth(); });
document.getElementById('calendar-oshi').addEventListener('change', loadCalendarMonth);
loadCalendarMonth();
