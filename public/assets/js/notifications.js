/** notifications.js の役割：ログイン画面内で受信を定期確認し、通知一覧・未読件数・ポップアップを更新する。 */
'use strict';
const notificationToggle = document.getElementById('notification-toggle');
const notificationPanel = document.getElementById('notification-panel');
const notificationStatus = document.getElementById('notification-status');
const notificationToasts = document.getElementById('notification-toasts');
let notificationItems = [];
let notificationBusy = false;
let notificationStopped = false;
let notificationTimer;
const displayedNotifications = new Set();
/** 通知には利用者が入力した予定名もあるので、HTMLとして解釈させない。 */
function notificationElement(tag, className, value = '') {
    const node = document.createElement(tag); node.className = className; node.textContent = value; return node;
}
/** 本人の通知だけを既読・表示済みにする。失敗時はサーバーの未読状態を残す。 */
async function acknowledgeNotice(ids, mode = 'read') {
    await apiRequest('api/notifications/read.php','POST',{ids,mode});
}
/** キーボード操作でも閉じた後に通知ボタンへ戻れるようにする。 */
function closeNotifications(returnFocus = false) {
    notificationPanel.hidden = true; notificationToggle.setAttribute('aria-expanded','false');
    if (returnFocus) notificationToggle.focus();
}
/** 通知から開いた時だけ既読にする。リンク先への移動は既読処理が失敗しても妨げない。 */
function notificationLink(item) {
    const link = notificationElement('a','notification-link');
    link.href = appUrl(item.path);
    link.append(notificationElement('strong','',item.message),notificationElement('span','notification-title',item.title),notificationElement('small','',item.created_at));
    if (item.unread) link.classList.add('unread');
    link.addEventListener('click', async (event) => {
        // 新しいタブで開く等のブラウザー標準操作は維持する。
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        try { await acknowledgeNotice([item.id]); } catch (_) { /* 遷移先で内容を確認できることを優先する。 */ }
        window.location.assign(link.href);
    });
    return link;
}
/** ポップアップを自動で閉じても、通知一覧の未読は残す。読み上げを強制せず操作を邪魔しない。 */
function showNotificationToast(item) {
    if (displayedNotifications.has(item.id) || notificationToasts.children.length >= 3) return false;
    displayedNotifications.add(item.id);
    const toast = notificationElement('article','notification-toast');
    const close = notificationElement('button','notification-close','×'); close.type='button'; close.setAttribute('aria-label','お知らせのポップアップを閉じる');
    close.addEventListener('click',()=>toast.remove());
    toast.append(notificationLink(item),close); notificationToasts.append(toast);
    setTimeout(()=>toast.remove(),12000);
    return true;
}
/** 開いている画面で20秒ごとに確認。背景タブでは止め、画面へ戻った時にも取り直す。 */
async function refreshNotifications() {
    if (notificationBusy || document.hidden || notificationStopped) return;
    notificationBusy=true;
    try {
        const data = await apiRequest('api/notifications/list.php');
        notificationItems=data.notifications;
        const badge=document.getElementById('notification-badge');
        badge.hidden=data.unread_count===0; badge.textContent=data.unread_count>99?'99+':String(data.unread_count);
        notificationToggle.setAttribute('aria-label',data.unread_count?`通知を開く、未読${data.unread_count}件`:'通知を開く');
        const list=document.getElementById('notification-list');
        list.replaceChildren(...data.notifications.map(notificationLink));
        if (!data.notifications.length) list.append(notificationElement('p','caption','届いたお知らせはまだありません。'));
        notificationStatus.textContent=data.unread_count?`未読 ${data.unread_count}件 · 最新30件を表示`:'未読のお知らせはありません。';
        document.getElementById('notification-read').hidden=!data.notifications.some((item)=>item.unread);
        // 既に一覧を開いている場合も「表示済み」にするが、既読にはしない。
        const shown=[];
        for (const item of data.popups) {
            if (!notificationPanel.hidden) { displayedNotifications.add(item.id); shown.push(item.id); }
            else if (showNotificationToast(item) || displayedNotifications.has(item.id)) shown.push(item.id);
        }
        if (shown.length) await acknowledgeNotice(shown,'shown');
    } catch (error) {
        notificationStatus.textContent=error.message;
        if (error.status===401) notificationStopped=true;
    } finally {
        notificationBusy=false;
        clearTimeout(notificationTimer);
        if (!notificationStopped) notificationTimer=setTimeout(refreshNotifications,20000);
    }
}
notificationToggle.addEventListener('click',()=>{
    const open=notificationPanel.hidden; notificationPanel.hidden=!open;
    notificationToggle.setAttribute('aria-expanded',String(open));
    if (open) refreshNotifications();
});
document.getElementById('notification-close').addEventListener('click',()=>closeNotifications(true));
document.addEventListener('keydown',(event)=>{if(event.key==='Escape'&&!notificationPanel.hidden) closeNotifications(true);});
document.addEventListener('click',(event)=>{if(!notificationPanel.hidden&&!event.target.closest('.notification-control')) closeNotifications();});
document.addEventListener('visibilitychange',()=>{clearTimeout(notificationTimer);if(!document.hidden)refreshNotifications();});
document.getElementById('notification-read').addEventListener('click',async(event)=>{
    const button=event.currentTarget;button.disabled=true;
    try {const ids=notificationItems.filter((item)=>item.unread).map((item)=>item.id);if(ids.length)await acknowledgeNotice(ids);await refreshNotifications();}
    catch(error){notificationStatus.textContent=error.message;}
    finally{button.disabled=false;}
});
refreshNotifications();
