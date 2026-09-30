/** money.js の役割：年間集計と簡易グラフ、特効切替、本人の積立・支出保存、ホーム資金表示を担当する。 */
'use strict';
(() => {
    const yen = value => `¥${new Intl.NumberFormat('ja-JP', {maximumFractionDigits: 2}).format(Number(value))}`;
    /** APIの文字列はHTMLとして解釈させず、textContentで表示する。 */
    function element(tag, text = '', className = '') {
        const node = document.createElement(tag);
        node.textContent = text;
        node.className = className;
        return node;
    }
    /** 残高の式に使う3項目を、ラベルと金額のペアで共通表示する。 */
    function metrics(data) {
        return [['前年繰越',data.carryover],['今年の積立',data.savings],['今年の支出',data.expenses]].map(([label,value]) => {
            const pair = element('div');
            pair.append(element('dt',label),element('dd',yen(value)));
            return pair;
        });
    }
    /** 棒だけに頼らず月名・金額も表示する。0円の月も12ヶ月分並べる。 */
    function bars(container, items, includeZero = false) {
        const rows = includeZero ? items : items.filter(item => Number(item.amount) !== 0);
        const max = Math.max(1, ...rows.map(item => Number(item.amount)));
        container.replaceChildren();
        if (!rows.length) container.append(element('p','この期間の支出はありません。','caption'));
        rows.forEach(item => {
            const row = element('div','','money-bar-row');
            const heading = element('div','','money-bar-label');
            heading.append(element('span',item.label),element('span',yen(item.amount)));
            const track = element('div','','money-bar-track');
            track.setAttribute('aria-hidden','true');
            const fill = element('span');
            fill.style.width = `${Math.max(0,Number(item.amount)/max*100)}%`;
            track.append(fill);row.append(heading,track);container.append(row);
        });
    }
    let activeEffect = 'fire';
    /** 基準金額はPHP設定由来のAPI値を使い、画面側へ重複して定義しない。 */
    function showEffects(data) {
        document.getElementById('money-eligible').textContent = yen(data.eligible_amount);
        document.getElementById('money-effect-notice').textContent = data.notice;
        const tabs = document.getElementById('money-effect-tabs');
        const panel = document.getElementById('money-effect-panel');
        const keys = Object.keys(data.effects);
        tabs.replaceChildren();
        function select(key, focus = false) {
            activeEffect = key;
            [...tabs.children].forEach(button => {
                const selected = button.dataset.effect === key;
                button.setAttribute('aria-selected',String(selected));
                button.tabIndex = selected ? 0 : -1;
                if (selected && focus) button.focus();
            });
            const effect = data.effects[key];
            panel.setAttribute('aria-labelledby',`money-effect-${key}`);
            panel.replaceChildren(element('h3',effect.label),element('strong',`約${new Intl.NumberFormat('ja-JP',{maximumFractionDigits:1}).format(effect.shots)}発分`,'money-shots'),element('p',`1発 = ${yen(effect.unit_price)} · ${effect.description}`));
        }
        keys.forEach((key,index) => {
            const button = element('button',data.effects[key].label,'button secondary small');
            button.type='button';button.id=`money-effect-${key}`;button.dataset.effect=key;
            button.setAttribute('role','tab');button.setAttribute('aria-controls','money-effect-panel');
            button.addEventListener('click',()=>select(key));
            button.addEventListener('keydown',event=>{
                let target;
                if(event.key==='ArrowRight')target=keys[(index+1)%keys.length];
                if(event.key==='ArrowLeft')target=keys[(index+keys.length-1)%keys.length];
                if(event.key==='Home')target=keys[0];
                if(event.key==='End')target=keys.at(-1);
                if(target){event.preventDefault();select(target,true);}
            });
            tabs.append(button);
        });
        select(keys.includes(activeEffect)?activeEffect:keys[0]);
    }
    const filter = document.getElementById('money-filter');
    if (filter) {
        let dashboardRequest = 0;
        const lists = {savings:{page:1,request:0},expenses:{page:1,request:0}};
        function query() {return new URLSearchParams(new FormData(filter));}
        /** 画面切替中は前の数値を隠し、古い応答が新しい年の欄へ入らないようにする。 */
        async function dashboard() {
            const request = ++dashboardRequest;
            const status = document.getElementById('money-status');
            const body = document.getElementById('money-dashboard');
            status.textContent='集計しています…';body.hidden=true;
            try {
                const data=await apiRequest(`api/money/dashboard.php?${query()}`);
                if(request!==dashboardRequest)return;
                document.getElementById('money-balance').textContent=yen(data.balance);
                document.getElementById('money-scope').textContent=data.oshi_id===null?`${data.year}年 · すべての推し`:`${data.year}年 · 選択した推しのみ`;
                document.getElementById('money-summary').replaceChildren(...metrics(data));
                document.getElementById('money-common').textContent=data.oshi_id===null?'':`共通積立（参考）：今年 ${yen(data.common_savings)} ／ 前年まで ${yen(data.common_savings_before_year)}。この推しの残高には含めていません。`;
                bars(document.getElementById('money-monthly'),data.monthly,true);
                bars(document.getElementById('money-categories'),data.categories);
                document.getElementById('money-oshi-section').hidden=data.oshi_id!==null;
                bars(document.getElementById('money-by-oshi'),data.by_oshi);
                showEffects(data.special_effects);
                body.hidden=false;status.textContent='';
            } catch(error) {if(request===dashboardRequest)status.textContent=error.message;}
        }
        /** 積立と支出のページ番号を別に持ち、どちらも現在の年・推しで取得する。 */
        async function records(kind, append = false) {
            const state=lists[kind];const request=++state.request;const page=append?state.page+1:1;
            const list=document.getElementById(`money-${kind}`);
            const message=document.getElementById(`money-${kind}-message`);
            const more=document.getElementById(`money-${kind}-more`);
            const params=query();params.set('page',String(page));
            message.textContent='読み込んでいます…';more.disabled=true;
            if(!append){list.replaceChildren();more.hidden=true;}
            try {
                const data=await apiRequest(`api/money/${kind}/list.php?${params}`);
                if(request!==state.request)return;
                data.items.forEach(item=>{
                    const row=element('article','','money-record');
                    const link=element('a',kind==='savings'?'積立を編集':item.title);
                    link.href=appUrl(`${kind==='savings'?'saving':'expense'}_form.php?id=${item.id}`);
                    row.append(link,element('strong',yen(item.amount)),element('p',`${item.saving_date||item.expense_date} · ${item.oshi_name||'全推し共通・未指定'}`,'caption'));
                    list.append(row);
                });
                if(!data.total)list.append(element('p','この期間の記録はありません。','caption'));
                state.page=page;more.hidden=!data.has_more;message.textContent='';
            } catch(error) {if(request===state.request)message.textContent=error.message;}
            finally {if(request===state.request)more.disabled=false;}
        }
        function reload(){dashboard();records('savings');records('expenses');}
        filter.addEventListener('submit',event=>{event.preventDefault();reload();});
        filter.querySelector('select').addEventListener('change',reload);
        for(const kind of Object.keys(lists))document.getElementById(`money-${kind}-more`).addEventListener('click',()=>records(kind,true));
        reload();
    }
    const category=document.getElementById('expense-category');
    const eligible=document.getElementById('expense-eligible');
    if(category){
        /** 初回表示では保存済みのチェックを維持し、カテゴリ変更時だけ初期値を設定する。 */
        function eligibility(changed=false){
            const allowed=category.selectedOptions[0].dataset.eligible==='true';
            eligible.disabled=!allowed;
            if(changed || !allowed)eligible.checked=allowed;
        }
        category.addEventListener('change',()=>eligibility(true));eligibility();
    }
    document.querySelectorAll('.money-form').forEach(form=>{
        /** ボタンを押したときだけ保存。金額は文字列のままAPIへ渡して小数を保つ。 */
        form.addEventListener('submit',async event=>{
            event.preventDefault();if(form.dataset.saving==='true')return;
            const payload=Object.fromEntries(new FormData(form));
            if(form.dataset.kind==='expenses')payload.special_effect_eligible=Boolean(eligible.checked);
            const button=form.querySelector('button[type="submit"]');const message=form.querySelector('.money-message');
            form.dataset.saving='true';button.disabled=true;message.textContent='保存しています…';
            try {
                const data=await apiRequest(`api/money/${form.dataset.kind}/${form.dataset.action}.php`,'POST',payload);
                const date=data.record.saving_date||data.record.expense_date;
                window.location.assign(appUrl(`money.php?year=${date.slice(0,4)}`));
            } catch(error){message.textContent=error.message;}
            finally{form.dataset.saving='false';button.disabled=false;}
        });
    });
    document.querySelectorAll('[data-money-delete]').forEach(button=>button.addEventListener('click',async()=>{
        if(!window.confirm('この記録を削除しますか？残高と集計にも反映されます。'))return;
        button.disabled=true;
        try{await apiRequest(`api/money/${button.dataset.moneyDelete}/delete.php`,'POST',{id:button.dataset.id});window.location.assign(appUrl(`money.php?year=${button.dataset.year}`));}
        catch(error){window.alert(error.message);button.disabled=false;}
    }));
    const home=document.getElementById('home-money');
    if(home){
        let sequence=0;
        /** ホームの推し切替に合わせ、同じ集計APIから今年の実残高を取得する。 */
        async function loadHomeMoney(){
            const request=++sequence;const oshi=document.getElementById('home-oshi')?.value||'all';
            home.textContent='読み込んでいます…';
            try{
                const data=await apiRequest(`api/money/dashboard.php?year=${home.dataset.year}&oshi_id=${encodeURIComponent(oshi)}`);
                if(request!==sequence)return;
                const total=element('strong',yen(data.balance),'money-home-balance');
                const rows=element('dl','','money-home-metrics');rows.append(...metrics(data));
                const link=element('a','お金管理を見る →');link.href=appUrl(`money.php?year=${data.year}&oshi_id=${encodeURIComponent(oshi)}`);
                home.replaceChildren(element('p','現在残高'),total,rows,link);
                if(data.oshi_id!==null)home.append(element('p',`共通積立は含みません。今年の共通積立 ${yen(data.common_savings)}`,'caption'));
            }catch(error){if(request===sequence)home.textContent=error.message;}
        }
        document.getElementById('home-oshi')?.addEventListener('change',loadHomeMoney);loadHomeMoney();
    }
})();
