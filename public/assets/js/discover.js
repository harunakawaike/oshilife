/** discover.js の役割：公開予定をキーワード・推し・カテゴリ・日付で検索し、ページ単位で表示する。 */
'use strict';
let publicPage = 1;
let publicRequest = 0;
let publicQuery = new URLSearchParams();
/** 検索条件を固定し、追加ページも同じ条件で取得する。 */
async function loadPublicSchedules(append = false) {
    const request = ++publicRequest;
    const page = append ? publicPage + 1 : 1;
    const params = new URLSearchParams(publicQuery);
    params.set('page', page);
    const message = document.getElementById('discover-status');
    const more = document.getElementById('discover-more');
    more.disabled = true; message.textContent = '公開予定を探しています…';
    try {
        const data = await apiRequest(`api/schedules/public.php?${params}`);
        if (request !== publicRequest) return;
        const list = document.getElementById('public-schedules');
        if (!append) list.replaceChildren();
        data.schedules.forEach((item) => list.append(scheduleCard(publicScheduleView(item), { public: true })));
        if (!data.total) scheduleEmpty(list, '条件に合う公開予定はまだありません。');
        message.textContent = `${data.total}件の公開予定`;
        publicPage = page; more.hidden = !data.has_more;
    } catch (error) {
        if (request === publicRequest) { message.textContent = error.message; more.hidden = true; }
    } finally { if (request === publicRequest) more.disabled = false; }
}
const discoverForm = document.getElementById('discover-filters');
discoverForm.addEventListener('submit', (event) => { event.preventDefault(); publicQuery = new URLSearchParams(new FormData(discoverForm)); loadPublicSchedules(); });
discoverForm.addEventListener('reset', () => { publicQuery = new URLSearchParams(); loadPublicSchedules(); });
document.getElementById('discover-more').addEventListener('click', () => loadPublicSchedules(true));
loadPublicSchedules();
