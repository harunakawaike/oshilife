/** schedule_detail.js の役割：詳細画面から公開予定追加・取り込み解除・論理削除を実行する。 */
'use strict';
/** 確認が必要な操作を確認し、成功後は最新の同期状態を再表示する。 */
async function performScheduleAction(button, action, confirmation) {
    if (confirmation && !window.confirm(confirmation)) return;
    button.disabled = true;
    const message = document.getElementById('schedule-detail-message');
    message.textContent = '';
    try {
        await apiRequest(`api/schedules/${action}.php`, 'POST', { schedule_id: Number(document.getElementById('schedule-detail').dataset.id) });
        if (action === 'delete' || action === 'remove-from-calendar') window.location.assign(appUrl('calendar.php'));
        else window.location.reload();
    } catch (error) { message.textContent = error.message; button.disabled = false; }
}
const scheduleActions = [
    ['add-schedule', 'add-to-calendar', ''],
    ['remove-schedule', 'remove-from-calendar', 'この予定を自分のカレンダーから外しますか？自分用の編集内容も削除されます。共有元は残ります。'],
    ['delete-schedule', 'delete', 'この予定を削除済みにしますか？取り込み済みのユーザーには削除されたことが表示されます。'],
];
for (const [id, action, confirmation] of scheduleActions) {
    const button = document.getElementById(id);
    button?.addEventListener('click', () => performScheduleAction(button, action, confirmation));
}
