/** home.js の役割：本人の今日の予定と、登録済み推しの未追加公開予定を実データで表示する。 */
'use strict';
let homeRequest = 0;
let homePage = 1;
let homeTotal = 0;
/** 今日の予定と新着を同じ推し選択で読み込み、古い応答は破棄する。 */
async function loadHomeSchedules(append = false) {
    const request = ++homeRequest;
    const select = document.getElementById('home-oshi');
    const filter = select.value || 'all';
    const page = append ? homePage + 1 : 1;
    const today = document.getElementById('home-today').dataset.today;
    const status = document.getElementById('home-filter-status');
    const more = document.getElementById('home-more');
    status.textContent = '予定を読み込んでいます…'; more.disabled = true;
    try {
        const [day, fresh] = await Promise.all([
            apiRequest(`api/schedules/list.php?date=${today}&oshi_id=${filter}`),
            apiRequest(`api/schedules/unadded.php?oshi_id=${filter}&page=${page}`),
        ]);
        if (request !== homeRequest) return;
        const dayList = document.getElementById('home-today');
        dayList.replaceChildren(...day.schedules.map((item) => scheduleCard(item, { onRemoved: () => loadHomeSchedules() })));
        if (!day.schedules.length) scheduleEmpty(dayList, '今日の予定はまだありません。');
        const list = document.getElementById('home-unadded');
        if (!append) list.replaceChildren();
        fresh.schedules.forEach((item) => list.append(scheduleCard(publicScheduleView(item), { public: true, onAdded: (card) => {
            card.remove();
            // NOT EXISTSで除外される件数を取り直す。全ページ再読込は不要。
            loadHomeSchedules();
        } })));
        homePage = page; homeTotal = fresh.total;
        document.getElementById('unadded-count').textContent = `${homeTotal}件`;
        if (!homeTotal) scheduleEmpty(list, '未追加の公開予定はありません。');
        more.hidden = !fresh.has_more;
        status.textContent = `${select.selectedOptions[0].textContent}の予定を表示しています。`;
    } catch (error) {
        if (request === homeRequest) { status.textContent = error.message; more.hidden = true; }
    } finally { if (request === homeRequest) more.disabled = false; }
}
document.getElementById('home-oshi').addEventListener('change', () => loadHomeSchedules());
document.getElementById('home-more').addEventListener('click', () => loadHomeSchedules(true));
loadHomeSchedules();
