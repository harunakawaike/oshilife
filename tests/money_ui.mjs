/** money_ui.mjs の役割：DOMモデルで年間集計・棒表示・特効タブ・入力・ホーム連動を検証する。実描画とは区別する。 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
class Element {
    constructor(tag='div'){this.tag=tag;this.children=[];this.dataset={};this.style={};this.attributes={};this.events={};this.hidden=false;this.disabled=false;this.checked=false;this.value='';this.textContent='';this.className='';}
    append(...items){this.children.push(...items);}
    replaceChildren(...items){this.children=items;}
    addEventListener(name,fn){this.events[name]=fn;}
    setAttribute(name,value){this.attributes[name]=value;}
    focus(){this.focused=true;}
    querySelectorAll(selector){return descendants(this).filter(node=>selector==='button[type="submit"]'?node.tag==='button'&&node.type==='submit':selector.startsWith('.')?node.className.split(' ').includes(selector.slice(1)):node.tag===selector);}
    querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
}
const descendants=node=>node.children.flatMap(child=>[child,...descendants(child)]);
const tick=()=>new Promise(setImmediate);
function setup({ids={},forms=[],deletes=[],api=async()=>({})}={}){
    const calls=[],moves=[],alerts=[];
    class FormData{constructor(form){this.entries=Object.entries(form.fields||{});}[Symbol.iterator](){return this.entries[Symbol.iterator]();}}
    const document={getElementById:id=>ids[id]||null,createElement:tag=>new Element(tag),querySelectorAll:selector=>selector==='.money-form'?forms:selector==='[data-money-delete]'?deletes:[]};
    const window={location:{assign:url=>moves.push(url)},confirm:()=>true,alert:message=>alerts.push(message)};
    vm.runInNewContext(fs.readFileSync(new URL('../public/assets/js/money.js',import.meta.url),'utf8'),{document,window,FormData,URLSearchParams,Intl,apiRequest:async(...args)=>{calls.push(args);return api(...args);},appUrl:path=>'/oshilife-v2/public/'+path});
    return {calls,moves,alerts,window};
}
const summary={year:2026,oshi_id:null,carryover:'20000.00',savings:'100000.00',expenses:'58000.00',balance:'62000.00',special_effect_eligible:'45000.00',common_savings:'70000.00',common_savings_before_year:'30000.00',monthly:Array.from({length:12},(_,i)=>({label:(i+1)+'月',amount:i===0?'58000.00':'0.00'})),categories:[{label:'グッズ',amount:'45000.00'},{label:'交通',amount:'13000.00'}],by_oshi:[{label:'<script>安全な文字</script>',amount:'58000.00'}],special_effects:{eligible_amount:'45000.00',notice:'注意書き',effects:{fire:{label:'🔥 炎',unit_price:100,shots:450,description:'燃料'},co2:{label:'💨 CO₂',unit_price:300,shots:150,description:'ガス'},silver_tape:{label:'✨ 銀テ',unit_price:4000,shots:11.25,description:'テープ'}}}};
const ids=Object.fromEntries(['money-filter','money-status','money-dashboard','money-balance','money-scope','money-summary','money-common','money-monthly','money-categories','money-oshi-section','money-by-oshi','money-eligible','money-effect-notice','money-effect-tabs','money-effect-panel',...['savings','expenses'].flatMap(k=>[`money-${k}`,`money-${k}-message`,`money-${k}-more`])].map(id=>[id,new Element()]));
const select=new Element('select');ids['money-filter'].append(select);ids['money-filter'].fields={year:'2026',oshi_id:''};let pending=null;
const runtime=setup({ids,api:async path=>{
    const params=new URLSearchParams(path.split('?')[1]);
    if(path.includes('dashboard')){
        if(pending)return new Promise(resolve=>pending.push({params,resolve}));
        return params.get('oshi_id')?{...summary,oshi_id:7,balance:'-25000.00'}:summary;
    }
    return {items:[{id:1,title:'<img>文字',amount:'0.25',expense_date:'2026-01-01',saving_date:'2026-01-01',oshi_name:null}],total:31,has_more:params.get('page')==='1'};
}});
await tick();assert.equal(ids['money-balance'].textContent,'¥62,000');assert.equal(ids['money-monthly'].children.length,12);assert.equal(ids['money-effect-tabs'].children.length,3);
const tabs=ids['money-effect-tabs'].children;tabs[2].events.click();assert(descendants(ids['money-effect-panel']).some(node=>node.textContent==='約11.3発分'));assert.equal(tabs[2].attributes['aria-selected'],'true');tabs[2].events.keydown({key:'ArrowRight',preventDefault(){}});assert.equal(tabs[0].focused,true);
assert(descendants(ids['money-by-oshi']).some(node=>node.textContent==='<script>安全な文字</script>'));
await ids['money-savings-more'].events.click();assert.equal(ids['money-savings'].children.length,2);assert.equal(ids['money-expenses'].children.length,1);
ids['money-filter'].fields.oshi_id='7';select.events.change();await tick();assert.equal(ids['money-balance'].textContent,'¥-25,000');assert.match(ids['money-common'].textContent,/残高には含めていません/);assert.equal(ids['money-oshi-section'].hidden,true);
// 年を連続変更したとき、遅れて返った古い集計で上書きしない。
pending=[];ids['money-filter'].fields.year='2025';ids['money-filter'].events.submit({preventDefault(){}});ids['money-filter'].fields.year='2027';ids['money-filter'].events.submit({preventDefault(){}});
pending.find(item=>item.params.get('year')==='2027').resolve({...summary,year:2027,balance:'2.00'});await tick();pending.find(item=>item.params.get('year')==='2025').resolve({...summary,year:2025,balance:'1.00'});await tick();assert.equal(ids['money-balance'].textContent,'¥2');
const category=new Element('select');category.selectedOptions=[{dataset:{eligible:'true'}}];const checkbox=new Element('input');checkbox.checked=false;
const form=new Element('form');form.dataset={kind:'expenses',action:'create'};form.fields={title:'記録',amount:'123.45',expense_date:'2026-01-01',oshi_id:'7',category:'goods'};
const message=new Element('p');message.className='money-message';const submit=new Element('button');submit.type='submit';form.append(message,submit);
const formRuntime=setup({ids:{'expense-category':category,'expense-eligible':checkbox},forms:[form],api:async()=>({record:{expense_date:'2026-01-01'}})});
assert.equal(checkbox.checked,false,'編集時の保存済みfalseを維持');category.events.change();assert.equal(checkbox.checked,true);
category.selectedOptions[0].dataset.eligible='false';category.events.change();assert.equal(checkbox.checked,false);assert.equal(checkbox.disabled,true);
await form.events.submit({preventDefault(){}});assert.equal(formRuntime.calls[0][2].amount,'123.45');assert.equal(formRuntime.calls[0][2].special_effect_eligible,false);assert.equal(formRuntime.calls[0][2].user_id,undefined);assert.match(formRuntime.moves[0],/money.php\?year=2026/);
const button=new Element('button');button.dataset={moneyDelete:'savings',id:'6',year:'2026'};const removal=setup({deletes:[button]});removal.window.confirm=()=>false;await button.events.click();assert.equal(removal.calls.length,0);removal.window.confirm=()=>true;await button.events.click();assert.equal(removal.calls[0][2].id,'6');
const home=new Element();home.dataset.year='2026';const oshi=new Element('select');const homeRuntime=setup({ids:{'home-money':home,'home-oshi':oshi},api:async path=>path.endsWith('7')?{...summary,oshi_id:7,balance:'-25000.00'}:summary});await tick();assert(descendants(home).some(node=>node.textContent==='¥62,000'));oshi.value='7';await oshi.events.change();assert.match(homeRuntime.calls.at(-1)[0],/oshi_id=7$/);assert(descendants(home).some(node=>node.textContent==='¥-25,000'));
console.log('Money UI passed: yearly balance, 12 bars, breakdown, effect tabs/keyboard, decimals, scope, stale responses, category defaults, save/delete and home.');
// チケット由来の特効対象は確認画面で固定（disabled）だが、trueを送る必要がある。
const ticketForm=new Element('form');ticketForm.dataset={kind:'expenses',action:'create'};ticketForm.fields={title:'チケット',amount:'15000',expense_date:'2026-10-01',category:'live_ticket',source_type:'live_ticket',source_id:'9'};
const ticketSubmit=new Element('button');ticketSubmit.type='submit';const ticketMessage=new Element('p');ticketMessage.className='money-message';ticketForm.append(ticketSubmit,ticketMessage);
const ticketEligible=new Element('input');ticketEligible.checked=true;ticketEligible.disabled=true;
const ticketRuntime=setup({ids:{'expense-eligible':ticketEligible},forms:[ticketForm],api:async()=>({record:{expense_date:'2026-10-01'}})});
assert.equal(ticketRuntime.calls.length,0,'確認画面を開くだけでは保存しない');
await ticketForm.events.submit({preventDefault(){}});assert.equal(ticketRuntime.calls[0][2].special_effect_eligible,true);
console.log('Ticket confirmation passed: no automatic save; fixed eligibility stays true.');
