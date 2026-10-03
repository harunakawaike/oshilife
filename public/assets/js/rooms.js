/** rooms.js の役割：ルーム操作・招待リンクの発行とコピー・本人の参加をAPIへ送り、画面へ反映する。 */
'use strict';
(() => {
    /** 生URLは発行直後の画面だけに表示し、localStorageなどへ保存しない。 */
    function showInviteLink(result, roomId) {
        const box=document.getElementById('room-link-result');
        document.getElementById('room-invite-url').value=new URL(result.url,window.location.origin).href;
        document.getElementById('room-copy-message').textContent='';box.hidden=false;
        const item=document.createElement('article');item.className='room-link';item.dataset.linkId=result.id;
        const expiry=document.createElement('p');expiry.textContent=`有効期限：${result.expires_at}まで`;item.append(expiry);
        const form=document.createElement('form');form.className='room-form';form.dataset.api='links/revoke';form.dataset.confirm='この招待リンクを無効化しますか？';
        for(const [key,value] of Object.entries({room_id:roomId,id:result.id})) {
            const input=document.createElement('input');input.type='hidden';input.name=key;input.value=value;form.append(input);
        }
        const button=document.createElement('button');button.type='submit';button.className='button secondary';button.textContent='このリンクを無効化';form.append(button);
        const message=document.createElement('p');message.className='event-message';message.setAttribute('role','status');form.append(message);item.append(form);
        document.getElementById('room-links').prepend(item);
    }
    /** 二重送信を止める。権限とCSRFはAPIでも確認し、参加はPOSTでのみ実行する。 */
    async function submitRoomForm(form) {
        if(form.dataset.saving==='true')return;
        if(form.dataset.confirm && !window.confirm(form.dataset.confirm))return;
        const buttons=[...form.querySelectorAll('button')];const message=form.querySelector('.event-message');
        form.dataset.saving='true';buttons.forEach(b=>b.disabled=true);message.textContent='保存しています…';
        try {
            const input=Object.fromEntries(new FormData(form));
            const result=await apiRequest(`api/rooms/${form.dataset.api}.php`,'POST',input);
            if(form.dataset.api==='links/create'){showInviteLink(result,input.room_id);message.textContent='招待リンクを作成しました。';}
            else if(form.dataset.create) window.location.assign(appUrl(`room_detail.php?room_id=${result.id}`));
            else if(form.dataset.redirect) window.location.assign(form.dataset.redirect);
            else window.location.reload();
        }catch(error){message.textContent=error.message;}
        finally{form.dataset.saving='false';buttons.forEach(b=>b.disabled=false);}
    }
    document.addEventListener('submit',event=>{
        if(event.target.matches('.room-form')){event.preventDefault();submitRoomForm(event.target);}
    });
    /** Clipboard APIが使えないブラウザーでは入力欄を選択して手動コピーを案内する。 */
    document.getElementById('room-copy-link')?.addEventListener('click',async()=>{
        const input=document.getElementById('room-invite-url');const message=document.getElementById('room-copy-message');
        try {await navigator.clipboard.writeText(input.value);message.textContent='コピーしました。';}
        catch(_){input.focus();input.select();input.setSelectionRange(0,input.value.length);message.textContent='リンクを選択しました。長押し、またはコピー操作でコピーしてください。';}
    });
})();
