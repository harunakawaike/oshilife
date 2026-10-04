/** room_workspace.js の役割：ルーム内で既存の共同支出フォームを開き、保存後はお金の表示だけ更新する。Chat通信は行わない。 */
'use strict';
(() => {
    const workspace=document.querySelector('.room-workspace');if(!workspace)return;
    const roomId=workspace.dataset.roomId;const dialog=document.getElementById('room-expense-dialog');
    const body=document.getElementById('room-expense-dialog-body');const loadMessage=document.getElementById('room-expense-load-message');
    let opener=null;let loading=null;
    /** 権限確認済みの既存フォームを取得する。サーバーで描画したformだけ使い、取得HTML内のscriptは実行しない。 */
    async function openForm(button) {
        opener=button;body.replaceChildren();loadMessage.textContent='読み込んでいます…';
        document.getElementById('room-expense-dialog-title').textContent=button.dataset.expenseForm?'共同支出を編集':'共同支出を追加';
        dialog.showModal();loading=new AbortController();
        try {
            const query=new URLSearchParams({room_id:roomId});if(button.dataset.expenseForm)query.set('id',button.dataset.expenseForm);
            const response=await fetch(appUrl(`room_expense_form.php?${query}`),{signal:loading.signal,cache:'no-store'});
            if(!response.ok)throw new Error('フォームを開けません。参加状態・編集権限を確認してください。');
            const doc=new DOMParser().parseFromString(await response.text(),'text/html');const source=doc.getElementById('room-expense-form');
            if(!source)throw new Error('ログイン状態を確認して、ページを再読み込みしてください。');
            if(!dialog.open)return;
            const form=document.importNode(source,true);form.dataset.inline='true';body.replaceChildren(form);loadMessage.textContent='';
            document.dispatchEvent(new CustomEvent('room-expense-form-loaded',{detail:{form}}));form.elements.title.focus();
        }catch(error){if(error.name!=='AbortError')loadMessage.textContent=error.message;}
    }
    /** API保存後の最新表示をサーバーの共通テンプレートから取得する。ページ全体の遷移・再読み込みはしない。 */
    async function refreshMoney(id=null) {
        const current=document.getElementById('room-money');
        const expanded=[...current.querySelectorAll('[data-expense-id][open]')].map(el=>el.dataset.expenseId);
        const showCancelled=current.querySelector('[data-show-cancelled]').checked;
        try {
            const response=await fetch(appUrl(`room_detail.php?room_id=${roomId}`),{cache:'no-store'});
            const doc=new DOMParser().parseFromString(await response.text(),'text/html');const section=doc.getElementById('room-money');
            if(!response.ok || !section)throw new Error('保存は完了しましたが、一覧を更新できませんでした。表示を更新してください。');
            const updated=document.importNode(section,true);current.replaceWith(updated);
            updated.querySelector('[data-show-cancelled]').checked=showCancelled;
            updated.querySelectorAll('[data-expense-id]').forEach(el=>{el.hidden=el.dataset.cancelled==='true'&&!showCancelled;el.open=expanded.includes(el.dataset.expenseId)||el.dataset.expenseId===String(id);});
            const message=updated.querySelector('#room-money-message');message.textContent='更新しました。';
            const item=[...updated.querySelectorAll('[data-expense-id]')].find(el=>el.dataset.expenseId===String(id));
            (item&&!item.hidden?item.querySelector('summary'):message).focus();
        }catch(error){current.querySelector('#room-money-message').textContent=error.message;current.querySelector('[data-refresh-money]').hidden=false;}
    }
    dialog.querySelector('[data-close-expense]').addEventListener('click',()=>dialog.close());
    dialog.addEventListener('close',()=>{loading?.abort();body.replaceChildren();opener?.focus();});
    document.addEventListener('room-expense-saved',event=>{dialog.close();refreshMoney(event.detail.id);});
    workspace.addEventListener('change',event=>{
        if(event.target.matches('[data-show-cancelled]'))document.querySelectorAll('#room-money [data-cancelled="true"]').forEach(el=>el.hidden=!event.target.checked);
    });
    workspace.addEventListener('click',async event=>{
        const button=event.target.closest('button');if(!button)return;
        if(button.matches('[data-expense-form]'))openForm(button);
        if(button.matches('[data-refresh-money]'))refreshMoney();
        if(button.matches('[data-cancel-expense]')) {
            if(button.disabled || !window.confirm('共同支出を取り消しますか？履歴は残ります。'))return;
            button.disabled=true;
            try {await apiRequest('api/rooms/expenses/cancel.php','POST',{room_id:roomId,id:button.dataset.cancelExpense,version:button.dataset.version});await refreshMoney();}
            catch(error){document.getElementById('room-money-message').textContent=error.message;button.disabled=false;}
        }
    });
})();
