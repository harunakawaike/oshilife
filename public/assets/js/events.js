/** events.js の役割：イベント検索・各フォーム保存・重複確認・個人TODO操作・ホームの次のイベントを担当する。 */
'use strict';
(() => {
    /** サーバー由来の文字列はHTMLとして挿入せず、textContentで表示する。 */
    function node(tag, text, className = '') {
        const element = document.createElement(tag);element.textContent = text;element.className = className;return element;
    }
    /** 公演カードを一覧とホームで共用し、当落は本人分だけを表示する。 */
    function eventCard(event, countdown = false) {
        const card = node('article','','card event-section');
        card.append(node('p',`${event.oshi_emoji} ${event.oshi_name}`),node('h3',event.title),node('p',`${event.event_date} · ${event.venue_name}`));
        card.append(node('span',event.event_type_label || 'LIVE','tag'));
        card.append(node('span',event.status_label,`event-state state-${event.status}`));
        if (countdown) card.append(node('strong',Number(event.days_until) === 0 ? '今日！' : `あと${event.days_until}日`,'event-countdown'));
        if (event.application_status) {
            const labels=[event.participation_label];
            if(event.entry_method!=='no_application') labels.push(event.application_label);
            if(event.entry_method==='lottery') labels.push(event.lottery_label);
            if(event.trip_label) labels.push(event.trip_label);
            card.append(node('p',labels.filter(Boolean).join(' · ')));
            if (Number(event.incomplete_todos)>0) card.append(node('p',`TODO ${event.incomplete_todos}件未完了`,'caption'));
        }
        const link=node('a','イベント管理を見る →');link.href=appUrl(`event_detail.php?id=${event.id}`);card.append(link);return card;
    }
    /** エラー時にも入力値を残す。409重複候補は別公演を確認してから明示的に再送する。 */
    async function saveForm(form, allowDuplicate = false) {
        if (form.dataset.saving === 'true') return;
        form.dataset.saving = 'true';
        const message=form.querySelector('.event-message');
        const controls=[...form.querySelectorAll('button')];controls.forEach(button=>button.disabled=true);
        const payload=Object.fromEntries(new FormData(form));
        if (allowDuplicate) payload.allow_duplicate=true;
        message.textContent='保存しています…';
        try {
            const result=await apiRequest(`api/${form.dataset.api}.php`,'POST',payload);
            if (form.dataset.venue) {
                const select=document.querySelector('select[name="venue_id"]');
                let option=[...select.options].find(item=>String(item.value)===String(result.id));
                if (!option) { option=node('option',`${payload.name}（${payload.prefecture}）`);option.value=result.id;select.append(option); }
                select.value=String(result.id);message.textContent='会場を追加し、選択しました。公演の保存を続けてください。';form.reset();return;
            }
            if (form.dataset.reload) { window.location.reload();return; }
            if (form.dataset.redirect) { window.location.assign(form.dataset.redirect);return; }
            if (form.dataset.target) {
                const id=form.dataset.result?result[form.dataset.result].id:result.id;
                window.location.assign(appUrl(`${form.dataset.target}?id=${id}`));return;
            }
            message.textContent='保存しました。';
        } catch(error) {
            message.textContent=error.message;
            if (error.duplicates?.length && form.querySelector('.event-duplicates')) {
                const box=form.querySelector('.event-duplicates');box.hidden=false;box.replaceChildren(node('p','似た公演があります。既存の公演を使うか、別の公演として登録してください。'));
                for (const duplicate of error.duplicates) {
                    const link=node('a',`${duplicate.event_date} ${duplicate.title} · ${duplicate.venue_name}`);link.href=appUrl(`event_detail.php?id=${duplicate.id}`);box.append(link);
                }
                const confirm=node('button','別の公演として登録する','button secondary');confirm.type='button';confirm.addEventListener('click',()=>saveForm(form,true));box.append(confirm);
            }
        } finally { form.dataset.saving = 'false'; controls.forEach(button=>button.disabled=false); }
    }
    document.querySelectorAll('.event-form').forEach(form=>form.addEventListener('submit',event=>{event.preventDefault();saveForm(form);}));
    /** 削除は確認後に本人APIへ送信し、完了状態は明示的な真偽値で保存する。 */
    document.querySelectorAll('[data-delete-api]').forEach(button=>button.addEventListener('click',async()=>{
        if (!window.confirm('この項目を削除しますか？')) return;
        button.disabled=true;
        try { await apiRequest(`api/${button.dataset.deleteApi}.php`,'POST',{id:button.dataset.id,trip_id:button.dataset.tripId});window.location.reload(); }
        catch(error) { window.alert(error.message);button.disabled=false; }
    }));
    document.querySelectorAll('[data-todo-toggle]').forEach(checkbox=>checkbox.addEventListener('change',async()=>{
        checkbox.disabled=true;
        try { await apiRequest('api/events/todos/toggle.php','POST',{id:checkbox.dataset.todoToggle,is_completed:checkbox.checked});
            // 入金TODOの変更後は、チケット反映案内をサーバーの最新状態で表示する。
            if (checkbox.dataset.ticketPayment === 'true') window.location.reload(); }
        catch(error) { checkbox.checked=!checkbox.checked;window.alert(error.message); }
        finally { checkbox.disabled=false; }
    }));
    const transport=document.querySelector('[name="transport_type"]');
    if (transport) {
        /** 「その他」以外では自由入力欄を隠し、不要な値は送信しない。 */
        const updateOther=()=>{const box=document.querySelector('[data-transport-other]');const input=box.querySelector('input');box.hidden=transport.value!=='other';input.disabled=box.hidden;input.required=!box.hidden;};
        transport.addEventListener('change',updateOther);updateOther();
    }
    /** 非表示項目は送信せず、サーバーでも受付方式に合わせて正規化する。 */
    const management=document.querySelector('form[data-api="events/status/update"]');
    if(management) {
        const method=management.querySelector('[name="entry_method"]');
        const amount=management.querySelector('[name="ticket_amount"]');
        const show=(selector,visible)=>{const box=management.querySelector(selector);box.hidden=!visible;box.querySelectorAll('input,select').forEach(field=>field.disabled=!visible);};
        const refresh=()=>{
            const fanclub=method.value==='fanclub_presale';
            const noApplication=method.value==='no_application';
            show('[data-management-application]',!noApplication);
            show('[data-management-lottery]',method.value==='lottery' || fanclub);
            show('[data-management-sales]',!fanclub && !noApplication && method.value!=='unknown');
            const free=amount.value.trim()!=='' && Number(amount.value)===0;
            show('[data-management-payment]',amount.value.trim()!=='' && !free);
            management.querySelector('[data-payment-free]').hidden=!free;
            const app=management.querySelector('[name="application_status"]');
            if(!noApplication && app.value==='not_required') app.value='not_applied';
            app.parentElement.firstChild.textContent=method.value==='reservation'?'予約状況':method.value==='first_come'?'申込 / 購入状況':'申込状況';
            app.querySelector('[value="applied"]').textContent=method.value==='reservation'?'予約済み':'申込済み';
        };
        let previousMethod=method.value;
        method.addEventListener('change',()=>{
            if(previousMethod==='fanclub_presale' && method.value!=='fanclub_presale') management.querySelector('[name="sales_type"]').value='none';
            previousMethod=method.value;refresh();
        });amount.addEventListener('input',refresh);refresh();
    }
    const search=document.getElementById('event-search');
    if (search) {
        let page=1,requestNumber=0;const list=document.getElementById('event-list');const more=document.getElementById('event-more');const message=document.getElementById('event-list-message');
        /** 検索を連続操作したときは、後から返った古い応答で画面を上書きしない。 */
        async function load(append=false) {
            const current=++requestNumber;more.disabled=true;message.textContent='読み込んでいます…';
            const params=new URLSearchParams(new FormData(search));params.set('page',String(page));
            try {
                const data=await apiRequest(`api/events/list.php?${params}`);
                if (current!==requestNumber) return;
                if (!append) list.replaceChildren();
                data.events.forEach(event=>list.append(eventCard(event)));more.hidden=!data.has_more;
                message.textContent=list.children.length?'':'該当するイベントはまだありません。';
            } catch(error) { if(current===requestNumber) {message.textContent=error.message;if(append) page--;}}
            finally {if(current===requestNumber) more.disabled=false;}
        }
        search.addEventListener('submit',event=>{event.preventDefault();page=1;load();});
        more.addEventListener('click',()=>{page++;load(true);});load();
    }
    const next=document.getElementById('home-next-event');
    if (next) {
        let requestNumber=0;
        /** 推し切替に追随し、本人が管理中の直近公演だけを表示する。 */
        async function loadNext() {
            const current=++requestNumber;next.textContent='読み込んでいます…';
            const selector=document.getElementById('home-oshi');
            try { const data=await apiRequest(`api/events/next.php?oshi_id=${encodeURIComponent(selector?.value||'')}`);if(current!==requestNumber)return;next.replaceChildren(data.event?eventCard(data.event,true):node('p','次のイベントはまだ登録されていません。イベント一覧から「自分の管理」を保存してください。')); }
            catch(error) {if(current===requestNumber)next.textContent=error.message;}
        }
        document.getElementById('home-oshi')?.addEventListener('change',loadNext);loadNext();
    }
})();
