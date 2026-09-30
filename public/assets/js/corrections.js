/** corrections.js の役割：本人に届いた修正提案を安全な文字列表示で並べ、承認・却下と履歴を扱う。 */
'use strict';
let correctionPage = 1;
let correctionRequest = 0;
/** 投稿者の判断でのみ元予定を更新する。古い提案・権限エラーはカード内へ理由を表示する。 */
async function reviewCorrectionCard(card, request, approve) {
    if (!window.confirm(approve ? 'この提案を承認し、共有元の予定を変更しますか？同期中のユーザーにも反映されます。' : 'この提案を却下しますか？履歴は残ります。')) return;
    const buttons = card.querySelectorAll('button');
    buttons.forEach((button) => { button.disabled = true; });
    const message = card.querySelector('[role=alert]');
    message.textContent = '処理しています…';
    try {
        await apiRequest(`api/schedules/corrections/${approve ? 'approve' : 'reject'}.php`, 'POST', { correction_id: request.id });
        await loadCorrections();
    } catch (error) {
        message.textContent = error.message;
        buttons.forEach((button) => { button.disabled = false; });
    }
}
/** 未承認の値は比較欄だけに出し、予定表示には使わない。 */
function correctionCard(request) {
    const card = scheduleElement('article','card correction-card');
    const title = scheduleElement('a','schedule-title',request.schedule_title);
    title.href = appUrl(`schedule_detail.php?id=${request.schedule_id}`);
    card.append(title, scheduleElement('p','caption',`${request.requester_name}さんから · ${request.created_at} · ${request.status_label}`));
    card.append(scheduleElement('h2','',request.field_label));
    const comparison = scheduleElement('div','correction-comparison');
    for (const [label,value] of [['提案時の内容',request.old_display],['提案内容',request.new_display]]) {
        const part = scheduleElement('div','');
        part.append(scheduleElement('p','caption',label),scheduleElement('p','correction-value multiline',value)); comparison.append(part);
    }
    card.append(comparison, scheduleElement('h3','','修正理由'),scheduleElement('p','multiline',request.reason));
    if (request.source_url) {
        const source = scheduleElement('a','text-link','根拠を見る ↗');
        source.href = request.source_url; source.target = '_blank'; source.rel = 'noopener noreferrer'; card.append(source);
    }
    if (request.reviewed_at) card.append(scheduleElement('p','caption',`確認日時：${request.reviewed_at}`));
    if (request.status === 'pending') {
        const actions = scheduleElement('div','feedback-actions');
        if (request.can_approve) {
            const approve = scheduleElement('button','button primary small','承認して予定を更新');
            approve.type = 'button'; approve.addEventListener('click',()=>reviewCorrectionCard(card,request,true)); actions.append(approve);
        } else card.append(scheduleElement('p','caption','現在は非公開・中止・削除済みのため承認できません。履歴の確認と却下はできます。'));
        const reject = scheduleElement('button','button secondary small','却下');
        reject.type = 'button'; reject.addEventListener('click',()=>reviewCorrectionCard(card,request,false)); actions.append(reject);
        card.append(actions);
    }
    const message = scheduleElement('p','status-text'); message.setAttribute('role','alert'); card.append(message);
    return card;
}
/** 最新の条件の応答だけを採用し、20件ずつ履歴を読む。 */
async function loadCorrections(append = false) {
    const number = ++correctionRequest;
    const page = append ? correctionPage + 1 : 1;
    const status = document.getElementById('correction-status').value;
    const more = document.getElementById('correction-more');
    const message = document.getElementById('correction-list-message');
    more.disabled = true; message.textContent = '読み込んでいます…';
    try {
        const data = await apiRequest(`api/schedules/corrections/list.php?status=${status}&page=${page}`);
        if (number !== correctionRequest) return;
        const list = document.getElementById('correction-list');
        if (!append) list.replaceChildren();
        data.corrections.forEach((request)=>list.append(correctionCard(request)));
        if (!data.total) scheduleEmpty(list,'この条件の修正提案はありません。');
        correctionPage = page; more.hidden = !data.has_more; message.textContent = `${data.total}件の提案`;
    } catch (error) { if (number === correctionRequest) { message.textContent = error.message; more.hidden = true; } }
    finally { if (number === correctionRequest) more.disabled = false; }
}
document.getElementById('correction-status').addEventListener('change',()=>loadCorrections());
document.getElementById('correction-more').addEventListener('click',()=>loadCorrections(true));
loadCorrections();
