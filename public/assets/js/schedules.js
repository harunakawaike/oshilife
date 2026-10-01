/** schedules.js の役割：予定の短い表示・カード・追加ボタンをホームとカレンダーと検索で共通化する。 */
'use strict';
/** API文字列はHTMLとして扱わず、常に文字として表示する。 */
function scheduleElement(tag, className, text = '') {
    const element = document.createElement(tag);
    element.className = className;
    element.textContent = text;
    return element;
}
/** 推し名・メンバー名を長く並べず、絵文字＋ハート＋時間＋タイトルで表す。 */
function scheduleLabel(item) {
    const time = item.is_all_day ? '終日' : (item.start_time || '時間未定');
    return `${item.oshi_emoji}${item.member_hearts.join('')} ${time} ${item.title}`;
}
/** 公開一覧では個人編集の内容を混ぜず、共有元の表示を使う。 */
function publicScheduleView(item) {
    return { ...item, ...item.original };
}
/** 予定カードを作り、追加成功を呼び出し元へ知らせる。 */
function scheduleCard(item, options = {}) {
    const card = scheduleElement('article', 'schedule-card');
    card.dataset.scheduleId = item.id;
    const heading = scheduleElement('a', 'schedule-title', scheduleLabel(item));
    heading.href = appUrl(`schedule_detail.php?id=${item.id}`);
    card.append(heading);
    if (item.status !== 'active') {
        card.append(scheduleElement('p', 'schedule-warning', item.status === 'cancelled' ? '中止' : '共有元の予定は削除されました'));
    }
    if (item.event_status === 'postponed') card.append(scheduleElement('p', 'schedule-warning', '延期'));
    if (item.event_id) { const link = scheduleElement('a', 'text-button', 'イベント管理 →'); link.href = appUrl(`event_detail.php?id=${item.event_id}`); card.append(link); }
    const meta = options.public ? `${item.date} · ${item.category_label} · ${item.oshi_name}` : `${item.date} · ${item.category_label}`;
    card.append(scheduleElement('p', 'schedule-meta', meta));
    if (options.public) {
        card.append(scheduleElement('p', 'schedule-meta', item.sources.length ? `情報元：${item.sources.map((s) => s.label).join('・')}` : '情報元未登録'));
    }
    if (item.source_type === 'customized' && !options.public) card.append(scheduleElement('span', 'tag', '自分用・同期解除'));
    else if (item.sync_enabled && !options.public) card.append(scheduleElement('span', 'tag lavender', '同期中'));
    if (options.public) {
        if (item.is_added) card.append(scheduleElement('span', 'tag', item.is_owner ? '自分の予定' : '✓ 追加済み'));
        else if (item.status === 'active') {
            const button = scheduleElement('button', 'button secondary small', '＋ カレンダーに追加');
            button.type = 'button';
            const message = scheduleElement('p', 'form-message');
            message.setAttribute('role', 'alert');
            button.addEventListener('click', async () => {
                button.disabled = true;
                try {
                    await apiRequest('api/schedules/add-to-calendar.php', 'POST', { schedule_id: item.id });
                    button.replaceWith(scheduleElement('span', 'tag', '✓ 追加済み'));
                    if (options.onAdded) options.onAdded(card, item);
                } catch (error) {
                    // 別タブで追加済みになった場合も、画面を最新状態へ揃える。
                    if (error.status === 409) {
                        button.replaceWith(scheduleElement('span', 'tag', '✓ 追加済み'));
                        if (options.onAdded) options.onAdded(card, item);
                    } else { message.textContent = error.message; button.disabled = false; }
                }
            });
            card.append(button, message);
        }
    }
    if (!options.public && options.onRemoved && ((item.is_owner && !item.event_id && item.status !== 'deleted') || (!item.is_owner && item.is_added))) {
        const remove = scheduleElement('button', 'button secondary small', item.is_owner ? '予定を削除' : 'カレンダーから外す');
        remove.type = 'button';
        remove.setAttribute('aria-label', `${item.title}を${item.is_owner ? '削除' : 'カレンダーから外す'}`);
        const message = scheduleElement('p', 'form-message');
        message.setAttribute('role', 'alert');
        remove.addEventListener('click', async () => {
            const explanation = item.is_owner
                ? `「${item.title}」を削除しますか？${item.visibility === 'public' ? '取り込み済みのユーザーには削除されたことが表示されます。' : ''}`
                : `「${item.title}」を自分のカレンダーから外しますか？自分用の編集内容も削除されます。共有元は残ります。`;
            if (!window.confirm(explanation)) return;
            remove.disabled = true; message.textContent = '処理しています…';
            try {
                // 共有元の削除と本人の取り込み解除は別API。本人確認はサーバー側でも行う。
                const action = item.is_owner ? 'delete' : 'remove-from-calendar';
                await apiRequest(`api/schedules/${action}.php`, 'POST', { schedule_id: item.id });
                card.remove();
                await options.onRemoved();
            } catch (error) { message.textContent = error.message; remove.disabled = false; }
        });
        card.append(remove, message);
    }
    return card;
}
/** 空一覧も読み込み失敗と区別して表示する。 */
function scheduleEmpty(container, text) {
    container.replaceChildren(scheduleElement('p', 'empty-state', text));
}
