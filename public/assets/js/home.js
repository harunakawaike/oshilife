/** home.js の役割：今日の予定と、公開予定／イベントに分けた未追加の新着情報を表示する。 */
'use strict';
let homeDayRequest = 0;
const homeFeeds = {
    schedule: {list: 'home-unadded', count: 'unadded-count', more: 'home-more', message: 'home-schedule-message', label: '公開予定', page: 1, request: 0},
    event: {list: 'home-unadded-events', count: 'unadded-event-count', more: 'home-event-more', message: 'home-event-message', label: 'イベント情報', page: 1, request: 0},
};
/** 推しIDを共通で取得し、URLの値として安全に渡す。 */
function homeOshiFilter() {
    return encodeURIComponent(document.getElementById('home-oshi').value || 'all');
}
/** 今日の予定は公開・非公開・イベントを含めて従来どおり表示する。 */
async function loadHomeDay() {
    const request = ++homeDayRequest;
    const list = document.getElementById('home-today');
    try {
        const data = await apiRequest(`api/schedules/list.php?date=${list.dataset.today}&oshi_id=${homeOshiFilter()}`);
        if (request !== homeDayRequest) return;
        list.replaceChildren(...data.schedules.map(item => scheduleCard(item, {onRemoved: () => loadHomeSchedules()})));
        if (!data.schedules.length) scheduleEmpty(list, '今日の予定はまだありません。');
    } catch (error) {
        if (request === homeDayRequest) scheduleEmpty(list, error.message);
    }
}
/** 分類ごとにページ番号・応答番号を分離する。追加読込や推し切替で他方の欄を上書きしない。 */
async function loadHomeFeed(kind, append = false) {
    const feed = homeFeeds[kind];
    const request = ++feed.request;
    const page = append ? feed.page + 1 : 1;
    const list = document.getElementById(feed.list);
    const more = document.getElementById(feed.more);
    const message = document.getElementById(feed.message);
    const count = document.getElementById(feed.count);
    more.disabled = true;
    message.textContent = '読み込んでいます…';
    if (!append) {
        list.replaceChildren();
        count.hidden = true;
        more.hidden = true;
    }
    try {
        const fresh = await apiRequest(`api/schedules/unadded.php?oshi_id=${homeOshiFilter()}&kind=${kind}&page=${page}`);
        if (request !== feed.request) return;
        fresh.schedules.forEach(item => list.append(scheduleCard(publicScheduleView(item), {
            public: true,
            onAdded: () => loadHomeSchedules(),
        })));
        feed.page = page;
        count.textContent = `${fresh.total}件`;
        count.hidden = fresh.total === 0;
        if (!fresh.total) scheduleEmpty(list, `未追加の${feed.label}はありません。`);
        more.hidden = !fresh.has_more;
        message.textContent = '';
    } catch (error) {
        if (request === feed.request) message.textContent = error.message;
    } finally {
        if (request === feed.request) more.disabled = false;
    }
}
/** 推し変更とカレンダー追加後は全欄を更新する。片方が失敗しても他方は表示できる。 */
async function loadHomeSchedules() {
    const select = document.getElementById('home-oshi');
    document.getElementById('home-filter-status').textContent = `${select.selectedOptions[0].textContent}の予定・イベント情報を表示しています。`;
    await Promise.all([loadHomeDay(), loadHomeFeed('schedule'), loadHomeFeed('event')]);
}
document.getElementById('home-oshi').addEventListener('change', () => loadHomeSchedules());
for (const [kind, feed] of Object.entries(homeFeeds)) {
    document.getElementById(feed.more).addEventListener('click', () => loadHomeFeed(kind, true));
}
loadHomeSchedules();
