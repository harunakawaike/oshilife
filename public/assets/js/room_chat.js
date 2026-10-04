/** room_chat.js の役割：4秒ごとにトークの新着だけを取得し、安全なテキスト表示・投稿・本人の削除を行う。 */
'use strict';
(() => {
    const section=document.getElementById('room-talk');if(!section)return;
    const roomId=section.dataset.roomId;
    const history=document.getElementById('room-chat-history');
    const form=document.getElementById('room-chat-form');const input=document.getElementById('room-chat-input');
    const status=document.getElementById('room-chat-status');const latest=document.getElementById('room-chat-latest');
    const entries=new Map();let afterId=null;let timer=null;let pending=null;let stopped=false;let sending=false;let scrollAfterSend=false;
    /** HTML文字列を挿入せずtextContentを使う。scriptやURLも本文として表示し、実行しない。 */
    function render(message) {
        const article=document.createElement('article');article.className='room-chat-message'+(message.is_me?' is-me':'');article.dataset.messageId=message.id;
        const name=document.createElement('p');name.className='room-chat-author';name.textContent=message.is_me?'あなた':message.display_name_snapshot;
        const body=document.createElement('p');body.className='room-chat-body';body.textContent=message.message;
        const meta=document.createElement('div');meta.className='room-chat-meta';const time=document.createElement('time');time.dateTime=message.created_at.replace(' ','T');time.textContent=message.created_at.slice(0,16).replaceAll('-','/');meta.append(time);
        if(message.is_me && message.status==='active') {const button=document.createElement('button');button.type='button';button.className='room-chat-delete';button.textContent='削除';button.dataset.deleteMessage=message.id;meta.append(button);}
        article.append(name,body,meta);history.append(article);entries.set(String(message.id),article);
        if(message.status==='deleted')markDeleted(message.id);
    }
    /** 削除通知はIDだけで届く。既に表示した本文も速やかに置き換える。 */
    function markDeleted(id) {
        const node=entries.get(String(id));if(!node)return;
        node.classList.add('is-deleted');node.querySelector('.room-chat-body').textContent='メッセージを削除しました';node.querySelector('[data-delete-message]')?.remove();
    }
    function scrollLatest() {history.scrollTop=history.scrollHeight;latest.hidden=true;}
    /** 認可が失われたら取得を止め、会話を画面に残さない。通信障害は後で再取得する。 */
    function showError(error) {
        status.textContent=error.message;
        if([401,403,404].includes(error.status)) {stopped=true;clearTimeout(timer);entries.clear();history.replaceChildren();form.hidden=true;}
    }
    /** 重複通信を避け、終わった4秒後に次回取得する。非表示タブでは新しく通信しない。 */
    async function sync(forceScroll=false) {
        if(stopped || document.hidden)return;
        if(pending){await pending;if(forceScroll)return sync(true);return;}
        clearTimeout(timer);let more=false;
        pending=(async()=>{
            const query=new URLSearchParams({room_id:roomId});
            if(afterId!==null)query.set('after_id',afterId);
            query.set('known_ids',[...entries.keys()].filter(id=>!entries.get(id).classList.contains('is-deleted')).join(','));
            try {
                const data=await apiRequest(`api/rooms/messages/list.php?${query}`);
                const atEnd=history.scrollHeight-history.scrollTop-history.clientHeight<80;
                const initial=afterId===null;let added=0;
                for(const message of data.messages) {
                    if(!entries.has(String(message.id))){render(message);added++;}
                    afterId=String(message.id);
                }
                if(afterId===null)afterId='0';
                for(const id of data.deleted_ids)markDeleted(id);
                document.getElementById('room-chat-empty')?.remove();
                if(!entries.size){const empty=document.createElement('p');empty.id='room-chat-empty';empty.textContent='メッセージはまだありません。';history.append(empty);}
                // 長時間開いたままでも画面とDOMは直近100件に制限する。
                while(entries.size>100){const id=entries.keys().next().value;entries.get(id).remove();entries.delete(id);}
                form.hidden=data.room_status==='closed';document.getElementById('room-chat-closed').hidden=data.room_status!=='closed';
                if(data.room_status==='closed')history.querySelectorAll('[data-delete-message]').forEach(button=>button.remove());
                if(initial || forceScroll || scrollAfterSend || atEnd){scrollLatest();scrollAfterSend=false;}else if(added)latest.hidden=false;
                more=data.has_more && !initial;
            }catch(error){showError(error);}
        })();
        try{await pending;}finally{pending=null;if(!stopped && !document.hidden)timer=setTimeout(()=>sync(),more?100:4000);}
    }
    input.addEventListener('input',()=>{document.getElementById('room-chat-count').textContent=`${Array.from(input.value).length} / 1000文字`;});
    /** 投稿はCSRF付き共通APIを使う。成功時だけ入力を消し、失敗時は再編集できるよう本文を残す。 */
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(sending || stopped)return;
        if(Array.from(input.value).length>1000 || !/[^\s\p{Z}\p{C}]/u.test(input.value)){status.textContent='メッセージは空白だけにせず、1000文字以内で入力してください。';return;}
        sending=true;const button=form.querySelector('button');button.disabled=true;input.readOnly=true;status.textContent='送信しています…';
        try {
            await apiRequest('api/rooms/messages/send.php','POST',{room_id:roomId,message:input.value});
            input.value='';input.dispatchEvent(new Event('input'));status.textContent='送信しました。';scrollAfterSend=true;
            // sendのIDをカーソルにすると、直前の他人の投稿を飛ばすため、listから順番に取得する。
            await sync(true);
        }catch(error){showError(error);}
        finally{sending=false;button.disabled=false;input.readOnly=false;}
    });
    history.addEventListener('click',async event=>{
        const button=event.target.closest('[data-delete-message]');if(!button || button.disabled)return;
        if(!window.confirm('このメッセージを削除しますか？'))return;
        button.disabled=true;
        try{await apiRequest('api/rooms/messages/delete.php','POST',{room_id:roomId,id:button.dataset.deleteMessage});markDeleted(button.dataset.deleteMessage);status.textContent='メッセージを削除しました。';}
        catch(error){showError(error);button.disabled=false;}
    });
    latest.addEventListener('click',scrollLatest);
    document.addEventListener('visibilitychange',()=>{clearTimeout(timer);if(!document.hidden)sync();});
    window.addEventListener('pagehide',()=>{clearTimeout(timer);});
    sync();
})();
