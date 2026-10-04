/** room_expenses.js の役割：共同支出の整数円の均等割り・合計表示・保存・取消を行う。最終検証はPHPでも行う。 */
'use strict';
(() => {
    /** 保存直前に最新の精算状態を取得する。フォームを開いた時点では状態を変更しない。 */
    window.confirmRoomExpenseChange=async roomId=>{
        const summary=await apiRequest(`api/rooms/expenses/settlement-summary.php?room_id=${encodeURIComponent(roomId)}`,'GET');
        if(summary.settlement_status!=='settled')return {};
        if(!window.confirm('このルームは精算済みです。\n共同支出を変更すると精算済み状態を解除します。\n変更して未精算に戻しますか？'))return null;
        return {settlement_token:summary.confirmation_token};
    };
    /** 小数・指数・負数を黙って丸めず、入力不備として返す。最大9桁なのでJSでも整数を正確に計算できる。 */
    function yen(value) {return /^[0-9]{1,9}$/.test(value)?Number(value):null;}
    /** 同じフォーム処理を通常ページとルーム内ダイアログの両方へ取り付ける。 */
    function initializeForm(form) {
        if (!form || form.dataset.initialized==='true') return;
        form.dataset.initialized='true';
        const rows=[...form.querySelectorAll('.room-expense-member')];
        const message=form.querySelector('.event-message');
        const selected=()=>rows.filter(row=>row.querySelector('.share-selected').checked);
        /** 選択していない人の金額は合計・送信に含めない。 */
        function showBalance() {
            rows.forEach(row=>row.querySelector('.share-amount').disabled=!row.querySelector('.share-selected').checked);
            const amounts=selected().map(row=>yen(row.querySelector('.share-amount').value));const total=yen(form.elements.total_amount.value);
            const balance=form.querySelector('#room-expense-balance');
            if(total===null || amounts.includes(null)){balance.textContent='総額と負担額を整数円で入力してください。';return;}
            const sum=amounts.reduce((a,b)=>a+b,0);balance.textContent=`負担額の合計 ¥${sum.toLocaleString('ja-JP')} / 総額 ¥${total.toLocaleString('ja-JP')}（差額 ¥${(total-sum).toLocaleString('ja-JP')}）`;
        }
        form.addEventListener('input',showBalance);form.addEventListener('change',showBalance);showBalance();
        /** 端数は支払者→room_member ID昇順。退出済みの固定負担を除いた残額だけを配分する。 */
        form.querySelector('#room-expense-split').addEventListener('click',()=>{
            message.textContent='';
            const total=yen(form.elements.total_amount.value);const chosen=selected();
            const locked=chosen.filter(row=>row.dataset.locked==='true');
            const editable=chosen.filter(row=>row.dataset.locked!=='true');
            if(total===null || total<1 || !editable.length){message.textContent='総額と負担メンバーを選択してください。';return;}
            const fixed=locked.reduce((sum,row)=>sum+Number(row.querySelector('.share-amount').value),0);
            if(fixed>total){message.textContent='総額が利用終了メンバーの固定負担額を下回っています。';return;}
            const payer=form.elements.paid_by_user_id.value;
            editable.sort((a,b)=>(Number(b.dataset.userId===payer)-Number(a.dataset.userId===payer)) || (Number(a.dataset.memberId)-Number(b.dataset.memberId)));
            const remainder=total-fixed;const each=Math.floor(remainder/editable.length);const extra=remainder%editable.length;
            editable.forEach((row,index)=>row.querySelector('.share-amount').value=each+(index<extra?1:0));showBalance();
        });
        /** 画面の値をJSONとしてAPIへ送る。ユーザーが直接APIを呼んでもサーバーが権限・合計を検証する。 */
        form.addEventListener('submit',async event=>{
            event.preventDefault();if(form.dataset.saving==='true' || form.dataset.saved==='true')return;
            const button=form.querySelector('[type="submit"]');button.disabled=true;form.dataset.saving='true';message.textContent='保存しています…';
            try {
                const input=Object.fromEntries(new FormData(form));input.room_id=form.dataset.roomId;
                input.shares=selected().map(row=>({user_id:row.dataset.userId,share_amount:row.querySelector('.share-amount').value}));
                if(form.dataset.id){input.id=form.dataset.id;input.version=form.dataset.version;}
                const confirmation=await window.confirmRoomExpenseChange(form.dataset.roomId);
                if(confirmation===null){message.textContent='変更をキャンセルしました。';return;}
                Object.assign(input,confirmation);
                const result=await apiRequest(`api/rooms/expenses/${form.dataset.id?'update':'create'}.php`,'POST',input);
                if(form.dataset.inline==='true') {
                    form.dataset.saved='true';
                    document.dispatchEvent(new CustomEvent('room-expense-saved',{detail:{id:result.id}}));
                } else window.location.assign(appUrl(`room_expense_detail.php?room_id=${form.dataset.roomId}&id=${result.id}`));
            }catch(error){message.textContent=error.message;message.focus();}
            finally{button.disabled=form.dataset.saved==='true';form.dataset.saving='false';}
        });
    }
    initializeForm(document.getElementById('room-expense-form'));
    document.addEventListener('room-expense-form-loaded',event=>initializeForm(event.detail.form));
    /** 取消はPOSTで履歴の状態だけを変える。二重操作と、古い内容を見たままの取消を防ぐ。 */
    const cancel=document.getElementById('room-expense-cancel');
    cancel?.addEventListener('submit',async event=>{
        event.preventDefault();if(cancel.dataset.saving==='true' || !window.confirm('共同支出を取り消しますか？履歴は残ります。'))return;
        const button=cancel.querySelector('button');const message=cancel.querySelector('.event-message');button.disabled=true;cancel.dataset.saving='true';
        try {const confirmation=await window.confirmRoomExpenseChange(cancel.dataset.roomId);if(confirmation===null)return;await apiRequest('api/rooms/expenses/cancel.php','POST',{room_id:cancel.dataset.roomId,id:cancel.dataset.id,version:cancel.dataset.version,...confirmation});window.location.reload();}
        catch(error){message.textContent=error.message;}
        finally{button.disabled=false;cancel.dataset.saving='false';}
    });
})();
