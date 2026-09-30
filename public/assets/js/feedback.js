/** feedback.js の役割：公開予定詳細で感謝を切り替え、単一項目の修正提案を送信する。 */
'use strict';
const feedbackPanel = document.getElementById('schedule-feedback');
const feedbackScheduleId = Number(feedbackPanel.dataset.scheduleId);
/** 通信中は2ボタンとも無効化し、連打による意図しない追加→解除を防ぐ。 */
async function sendReaction(button) {
    const buttons = [...feedbackPanel.querySelectorAll('[data-reaction]')];
    const message = document.getElementById('reaction-message');
    buttons.forEach((item) => { item.disabled = true; });
    message.textContent = '送信しています…';
    try {
        const data = await apiRequest('api/schedules/reactions/toggle.php', 'POST', { schedule_id: feedbackScheduleId, reaction_type: button.dataset.reaction });
        button.setAttribute('aria-pressed', String(data.active));
        button.querySelector('[data-count]').textContent = data.count;
        message.textContent = data.active ? '気持ちを届けました。' : 'リアクションを解除しました。';
    } catch (error) {
        message.textContent = `${error.message} 状態を確認する場合は画面を再読み込みしてください。`;
    } finally { buttons.forEach((item) => { item.disabled = false; }); }
}
feedbackPanel.querySelectorAll('[data-reaction]').forEach((button) => button.addEventListener('click', () => sendReaction(button)));
const correctionForm = document.getElementById('correction-form');
/** 日付は日付選択、時刻は時刻選択、カテゴリは選択式にする。現在値はHTMLではなく文字として表示。 */
function showCorrectionField() {
    const select = document.getElementById('correction-field');
    const field = select.value;
    const option = select.selectedOptions[0];
    document.getElementById('correction-current').textContent = option.dataset.current;
    const input = document.getElementById('correction-value');
    input.type = field === 'schedule_date' ? 'date' : (['start_time','end_time'].includes(field) ? 'time' : 'text');
    input.value = option.dataset.value;
    document.getElementById('correction-note').value = option.dataset.value;
    if (field === 'category') document.getElementById('correction-category').value = option.dataset.value;
    document.getElementById('correction-input-label').hidden = ['note','category'].includes(field);
    document.getElementById('correction-note-label').hidden = field !== 'note';
    document.getElementById('correction-category-label').hidden = field !== 'category';
}
/** 提案内容だけを送る。old_value・本人ID・承認状態はサーバーが決める。 */
async function submitCorrection(event) {
    event.preventDefault();
    const field = document.getElementById('correction-field').value;
    const newValue = document.getElementById(field === 'note' ? 'correction-note' : field === 'category' ? 'correction-category' : 'correction-value').value;
    const controls = [...correctionForm.querySelectorAll('input,select,textarea,button')];
    const message = document.getElementById('correction-message');
    const input = { schedule_id: feedbackScheduleId, field_name: field, new_value: newValue, reason: correctionForm.elements.reason.value, source_url: correctionForm.elements.source_url.value };
    controls.forEach((control) => { control.disabled = true; });
    message.textContent = '送信しています…';
    try {
        await apiRequest('api/schedules/corrections/create.php', 'POST', input);
        message.textContent = '修正提案を送りました。投稿者の確認をお待ちください。';
        document.getElementById('pending-corrections').textContent = '確認待ちの修正提案があります。承認までは現在の予定を表示します。';
        correctionForm.elements.reason.value = '';
    } catch (error) { message.textContent = error.message; }
    finally { controls.forEach((control) => { control.disabled = false; }); }
}
if (correctionForm) {
    document.getElementById('correction-field').addEventListener('change', showCorrectionField);
    correctionForm.addEventListener('submit', submitCorrection);
    showCorrectionField();
}
