/** calendar.js の役割：月移動・推し絞り込み・日付選択と本人の実予定表示を行う。 */
'use strict';
const calendarToday = document.getElementById('calendar-app').dataset.today;
let calendarDate = calendarToday;
let calendarMonth = calendarToday.slice(0, 7);
let calendarItems = [];
let calendarRequest = 0;
let calendarLoadFailed = false;
/** 選択日の一覧と追加先の日付を揃える。 */
function showCalendarDay() {
    const list = document.getElementById('day-schedules');
    const items = calendarItems.filter((item) => item.date === calendarDate);
    document.getElementById('selected-day').textContent = `${calendarDate.replaceAll('-', '/')} の予定`;
    const dayCount = document.getElementById('day-count');
    dayCount.textContent = !calendarLoadFailed && items.length ? `${items.length}件` : '';
    dayCount.hidden = calendarLoadFailed || items.length === 0;
    document.getElementById('calendar-add').href = appUrl(`schedule_form.php?date=${calendarDate}`);
    list.replaceChildren(...items.map((item) => scheduleCard(item, { onRemoved: loadCalendarMonth })));
    // 通信エラーと「本当に0件」を分け、登録済みの予定が消えたように見せない。
    if (calendarLoadFailed) scheduleEmpty(list, '予定を読み込めませんでした。「今日」または月の切替で再試行してください。');
    else if (!items.length) scheduleEmpty(list, 'この日の予定はまだありません。');
    document.querySelectorAll('.month-cell').forEach((cell) => {
        const selected = cell.dataset.date === calendarDate;
        cell.classList.toggle('selected', selected);
    });
    document.querySelectorAll('.month-view').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.date === calendarDate));
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
        // 日付と一覧を別の操作にし、登録へ直接進んでも既存予定の確認手段を残す。
        const cell = scheduleElement('div', 'month-cell');
        // 日付と件数をまとめた外側へ選択色を付ける。各操作は独立したままにする。
        cell.dataset.date = date;
        cell.dataset.today = String(date === calendarToday);
        const dateLink = scheduleElement('a', 'month-day', String(day));
        dateLink.dataset.date = date;
        dateLink.href = appUrl(`schedule_form.php?date=${date}`);
        dateLink.setAttribute('aria-label', `${month}月${day}日に予定を登録`);
        if (date === calendarToday) dateLink.setAttribute('aria-current', 'date');
        const viewButton = scheduleElement('button', 'month-view', calendarLoadFailed ? '再読込' : (count ? `${count}件` : ''));
        // 0件の日は数字だけにする。予定がある日の件数から一覧を開ける。
        viewButton.hidden = !calendarLoadFailed && count === 0;
        viewButton.type = 'button'; viewButton.dataset.date = date;
        viewButton.setAttribute('aria-label', `${month}月${day}日の予定一覧${calendarLoadFailed ? 'を再読み込み' : `、${count}件`}`);
        viewButton.addEventListener('click', () => {
            calendarDate = date;
            if (calendarLoadFailed) loadCalendarMonth();
            else showCalendarDay();
        });
        cell.append(dateLink, viewButton);
        grid.append(cell);
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
        calendarLoadFailed = false;
        calendarItems = data.schedules;
        renderCalendarMonth();
        message.textContent = '共有予定の変更は、カレンダーを読み込んだときに反映されます。';
    } catch (error) {
        if (request !== calendarRequest) return;
        calendarLoadFailed = true;
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
