/** lives_ui.mjs の役割：DOMモデルで公演検索・当落保存・重複確認・TODO・移動入力・ホーム更新を検証する。実ブラウザの描画テストとは区別する。 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
class Element {
    constructor(tag='div') {this.tag=tag;this.children=[];this.dataset={};this.events={};this.textContent='';this.value='';this.hidden=false;this.disabled=false;}
    append(...children){this.children.push(...children);}
    replaceChildren(...children){this.children=[];this.append(...children);}
    addEventListener(name,fn){this.events[name]=fn;}
    querySelectorAll(selector){return descendants(this).filter(e=>selector.startsWith('.')?(e.className||'').split(' ').includes(selector.slice(1)):e.tag===selector);}
    querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
    reset(){this.resetCalled=true;}
    get options(){return this.children;}
}
function descendants(element){return element.children.flatMap(child=>[child,...descendants(child)]);}
const tick=()=>new Promise(setImmediate);
function context({ids={},forms=[],selectors={},toggles=[],deletes=[],api=async()=>({})}={}){
    const calls=[],moves=[],alerts=[];let reloads=0;
    const document={getElementById:id=>ids[id]||null,createElement:tag=>new Element(tag),querySelector:selector=>selectors[selector]||null,querySelectorAll:selector=>({'.event-form':forms,'[data-todo-toggle]':toggles,'[data-delete-api]':deletes}[selector]||[])};
    const window={location:{assign:target=>moves.push(target),reload:()=>reloads++},confirm:()=>true,alert:text=>alerts.push(text)};
    class FormData {constructor(form){this.entries=Object.entries(form.fields||{});}[Symbol.iterator](){return this.entries[Symbol.iterator]();}}
    vm.runInNewContext(fs.readFileSync(new URL('../public/assets/js/events.js',import.meta.url),'utf8'),{document,window,FormData,URLSearchParams,apiRequest:async(...args)=>{calls.push(args);return api(...args);},appUrl:path=>'/oshilife-v2/public/'+path});
    return {calls,moves,alerts,window,get reloads(){return reloads;}};
}
function form(api,fields){const f=new Element('form');f.dataset.api=api;f.fields=fields;const msg=new Element('p');msg.className='event-message';f.append(msg,new Element('button'));return f;}
const status=form('events/status/update',{event_id:'12',application_status:'applied',lottery_status:'won',trip_type:'trip',note:'本人だけ'});status.dataset.reload='true';const saved=context({forms:[status]});
status.events.submit({preventDefault(){}});await tick();assert.equal(saved.calls[0][0],'api/events/status/update.php');assert.equal(saved.calls[0][2].user_id,undefined);assert.equal(saved.reloads,1);
const create=form('events/create',{title:'TEST'});create.dataset.target='event_detail.php';create.dataset.result='live';const duplicates=new Element();duplicates.className='event-duplicates';create.append(duplicates);
const runtime=context({forms:[create],api:async(path,method,data)=>{if(!data.allow_duplicate){const error=new Error('似た公演');error.duplicates=[{id:8,event_date:'2026-10-01',title:'<img onerror=alert(1)>',venue_name:'会場'}];throw error;}return {live:{id:12}};}});
create.events.submit({preventDefault(){}});await tick();assert.equal(runtime.moves.length,0);assert.equal(duplicates.hidden,false);assert.match(duplicates.querySelector('a').textContent,/<img/);assert.match(duplicates.querySelector('a').href,/id=8$/);
await duplicates.querySelector('button').events.click();assert.equal(runtime.calls[1][2].allow_duplicate,true);assert.match(runtime.moves[0],/event_detail.php\?id=12$/);
const venue=form('venues/create',{name:'東京会場',prefecture:'東京都'});venue.dataset.venue='true';const select=new Element('select');const venues=context({forms:[venue],selectors:{'select[name="venue_id"]':select},api:async()=>({id:9})});venue.events.submit({preventDefault(){}});await tick();assert.equal(select.value,'9');assert.equal(select.options.length,1);assert.equal(venue.resetCalled,true);assert.equal(venues.reloads,0);
const transport=new Element('select');transport.value='shinkansen';const other=new Element();const input=new Element('input');other.append(input);context({selectors:{'[name="transport_type"]':transport,'[data-transport-other]':other}});assert.equal(other.hidden,true);assert.equal(input.disabled,true);transport.value='other';transport.events.change();assert.equal(other.hidden,false);assert.equal(input.required,true);
const toggle=new Element('input');toggle.dataset.todoToggle='4';toggle.checked=true;const remove=new Element('button');remove.dataset={deleteApi:'trips/transport/delete',id:'7',tripId:'3'};
const actions=context({toggles:[toggle],deletes:[remove]});await toggle.events.change();assert.equal(actions.calls[0][2].is_completed,true);actions.window.confirm=()=>false;await remove.events.click();assert.equal(actions.calls.length,1);actions.window.confirm=()=>true;await remove.events.click();assert.equal(actions.calls[1][2].trip_id,'3');assert.equal(actions.reloads,1);
const next=new Element();const selector=new Element();const live={id:12,oshi_emoji:'💎',oshi_name:'TEST',title:'LIVE',event_date:'2026-09-30',venue_name:'会場',status:'postponed',status_label:'延期',application_status:'applied',application_label:'申込済み',lottery_label:'当選',trip_type:'trip',trip_label:'遠征',days_until:0,incomplete_todos:2};
const home=context({ids:{'home-next-event':next,'home-oshi':selector},api:async path=>({event:path.endsWith('77')?null:live})});await tick();assert.equal(next.querySelector('strong').textContent,'今日！');assert(descendants(next).some(e=>e.textContent==='TODO 2件未完了'));selector.value='77';await selector.events.change();assert.match(home.calls.at(-1)[0],/oshi_id=77$/);assert.match(next.textContent+next.children[0].textContent,/まだ登録/);
const search=new Element('form');search.fields={q:'LIVE',period:'upcoming'};const list=new Element();const more=new Element('button');const message=new Element();const browsing=context({ids:{'event-search':search,'event-list':list,'event-more':more,'event-list-message':message},api:async()=>({events:[live],has_more:false})});await tick();assert.equal(list.children.length,1);assert.equal(more.hidden,true);search.events.submit({preventDefault(){}});await tick();assert.match(browsing.calls.at(-1)[0],/page=1/);
console.log('Live UI behavior passed: status save, duplicates, safe text, venue selection, other transport, TODO, delete confirmation, home countdown/filter, list.');
// 入金TODOだけは保存後に最新の反映案内を再表示する。
toggle.dataset.ticketPayment='true';toggle.checked=false;
await toggle.events.change();assert.equal(actions.reloads,2);
console.log('Payment TODO passed: reload after payment completion/undo to refresh import eligibility.');
