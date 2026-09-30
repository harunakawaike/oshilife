/** schedule_form.js の役割：予定フォーム、推しに応じたメンバー選択、情報元、重複確認を制御する。 */
'use strict';
const scheduleForm = document.getElementById('schedule-form');
let memberRequest = 0;
let loadingMembers = false;
let confirmedDuplicate = false;

/** 時間を手入力しなくても終日を選べる。終日のときは開始・終了を送信しない。 */
function toggleAllDay() {
    const allDay = scheduleForm.elements.is_all_day.checked;
    scheduleForm.elements.start_time.disabled = allDay;
    scheduleForm.elements.end_time.disabled = allDay;
}

/** 公開予定にだけ情報元欄を表示する。非公開の保存では情報元を送らない。 */
function toggleScheduleVisibility() {
    const sources = document.getElementById('schedule-sources');
    if (!sources) return;
    sources.hidden = scheduleForm.elements.visibility.value !== 'public';
    if (!sources.hidden && !document.querySelector('.source-row')) addSourceRow();
}

/** 5つまで情報元を追加する。既存入力と同じHTMLテンプレートを使う。 */
function addSourceRow() {
    const rows = document.getElementById('source-rows');
    if (rows.children.length >= 5) return;
    rows.append(document.getElementById('source-template').content.cloneNode(true));
    document.getElementById('add-source').disabled = rows.children.length >= 5;
}

/** 推しを変更したら、別グループのメンバーを残さず選び直す。 */
async function loadScheduleMembers() {
    const request = ++memberRequest;
    const id = document.getElementById('schedule-oshi').value;
    const container = document.getElementById('schedule-members');
    const error = document.getElementById('schedule-error-member_ids');
    const save = document.getElementById('save-schedule');
    container.replaceChildren(); error.textContent = '';
    if (!id) { loadingMembers = false; save.disabled = false; return; }
    loadingMembers = true; save.disabled = true;
    try {
        const data = await apiRequest(`api/oshis/members.php?id=${id}`);
        if (request !== memberRequest) return;
        for (const member of data.members) {
            const label = scheduleElement('label', 'check-label');
            const input = document.createElement('input');
            input.type = 'checkbox'; input.name = 'member_ids'; input.value = member.id;
            label.append(input, document.createTextNode(`${member.heart_emoji} ${member.name}`));
            container.append(label);
        }
        if (!data.members.length) container.append(scheduleElement('p', 'caption', 'この推しのメンバーは未登録です。グループ全体として登録できます。'));
        loadingMembers = false; save.disabled = false;
    } catch (failure) {
        if (request !== memberRequest) return;
        error.textContent = `${failure.message} 推しを選び直して再試行してください。`;
    }
}

/** 入力をAPIのJSONへ揃える。user_idを送らず、本人の判定はセッションへ任せる。 */
function readScheduleForm() {
    const fields = new FormData(scheduleForm);
    const allDay = scheduleForm.elements.is_all_day.checked;
    const input = {
        title: fields.get('title'), schedule_date: fields.get('schedule_date'),
        start_time: allDay ? '' : (fields.get('start_time') || ''),
        end_time: allDay ? '' : (fields.get('end_time') || ''),
        is_all_day: allDay, note: fields.get('note'),
    };
    if (scheduleForm.dataset.mode !== 'custom') {
        Object.assign(input, {
            oshi_id: Number(fields.get('oshi_id')), category: fields.get('category'),
            visibility: fields.get('visibility'), status: fields.get('status') || 'active',
            member_ids: fields.getAll('member_ids').map(Number), sources: [], allow_duplicate: confirmedDuplicate,
        });
        if (input.visibility === 'public') {
            document.querySelectorAll('#source-rows .source-row').forEach((row) => {
                const source = {
                    source_type: row.querySelector('[name="source_type"]').value,
                    source_url: row.querySelector('[name="source_url"]').value.trim(),
                    note: row.querySelector('[name="source_note"]').value.trim(),
                };
                if (source.source_type || source.source_url || source.note) input.sources.push(source);
            });
        }
    }
    if (scheduleForm.dataset.id) input.schedule_id = Number(scheduleForm.dataset.id);
    return input;
}

/** 項目別エラーを関連付け、情報元など折りたたみ領域にも表示する。 */
function displayScheduleErrors(error) {
    for (const [key, text] of Object.entries(error.fields || {})) {
        const target = document.getElementById(`schedule-error-${key}`);
        if (target) target.textContent = text;
        const field = scheduleForm.elements.namedItem(key);
        if (field instanceof HTMLElement) field.setAttribute('aria-invalid', 'true');
    }
    const message = document.getElementById('schedule-form-message');
    message.textContent = error.message;
    (scheduleForm.querySelector('[aria-invalid]') || message).focus();
}

/** 個人編集は同期解除を確認してから送信する。重複警告では入力を残して再確認を待つ。 */
async function submitScheduleForm(event) {
    event.preventDefault();
    if (loadingMembers) return;
    const mode = scheduleForm.dataset.mode;
    if (mode === 'custom' && !window.confirm('この予定は共有元との自動同期が解除されます。自分用の内容を保存しますか？')) return;
    const save = document.getElementById('save-schedule');
    const message = document.getElementById('schedule-form-message');
    const duplicateButton = document.getElementById('confirm-duplicate');
    scheduleForm.querySelectorAll('.field-error').forEach((element) => { element.textContent = ''; });
    scheduleForm.querySelectorAll('[aria-invalid]').forEach((element) => element.removeAttribute('aria-invalid'));
    save.disabled = true; duplicateButton.disabled = true; message.textContent = '保存しています…';
    try {
        const endpoint = { create: 'create', edit: 'update', custom: 'customize' }[mode];
        const data = await apiRequest(`api/schedules/${endpoint}.php`, 'POST', readScheduleForm());
        window.location.assign(appUrl(`schedule_detail.php?id=${data.schedule.id}`));
    } catch (error) {
        if (error.duplicates?.length) {
            const warning = document.getElementById('duplicate-warning');
            const links = document.getElementById('duplicate-links');
            links.replaceChildren();
            error.duplicates.forEach((candidate) => {
                const link = scheduleElement('a', 'duplicate-link', `${candidate.date} ${candidate.start_time || '時間未定'} ${candidate.title} — 既存予定を見る ↗`);
                link.href = appUrl(`schedule_detail.php?id=${candidate.id}`); link.target = '_blank'; link.rel = 'noopener noreferrer';
                links.append(link);
            });
            warning.hidden = false; message.textContent = error.message; duplicateButton.focus();
        } else displayScheduleErrors(error);
        save.disabled = false; duplicateButton.disabled = false;
    }
}
scheduleForm.addEventListener('submit', submitScheduleForm);
scheduleForm.addEventListener('input', () => { confirmedDuplicate = false; document.getElementById('duplicate-warning').hidden = true; });
scheduleForm.elements.is_all_day.addEventListener('change', toggleAllDay);
document.getElementById('schedule-oshi')?.addEventListener('change', loadScheduleMembers);
document.getElementById('schedule-visibility')?.addEventListener('change', toggleScheduleVisibility);
document.getElementById('add-source')?.addEventListener('click', addSourceRow);
document.getElementById('source-rows')?.addEventListener('click', (event) => {
    if (event.target.closest('.source-remove')) {
        event.target.closest('.source-row').remove(); document.getElementById('add-source').disabled = false;
        confirmedDuplicate = false; document.getElementById('duplicate-warning').hidden = true;
    }
});
document.getElementById('confirm-duplicate').addEventListener('click', () => { confirmedDuplicate = true; scheduleForm.requestSubmit(); });
toggleAllDay(); toggleScheduleVisibility();
