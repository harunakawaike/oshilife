/** home_feeds_ui.mjs の役割：新着2項目の分類・独立した追加読込・推し切替・追加後更新をDOMモデルで検証する。 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
class Element {
    constructor(){this.children=[];this.dataset={};this.events={};this.hidden=false;this.textContent='';this.value='';}
    replaceChildren(...nodes){this.children=nodes;}
    append(node){this.children.push(node);}
    addEventListener(name,fn){this.events[name]=fn;}
}
const names=['home-today','home-oshi','home-filter-status','home-unadded','unadded-count','home-more','home-schedule-message','home-unadded-events','unadded-event-count','home-event-more','home-event-message'];
const ids=Object.fromEntries(names.map(name=>[name,new Element()]));
ids['home-today'].dataset.today='2026-09-30';ids['home-oshi'].selectedOptions=[{textContent:'すべての推し'}];
const calls=[];let empty=false;let pending=null;
const apiRequest=async path=>{
    calls.push(path);const params=new URLSearchParams(path.split('?')[1]);
    if(!path.includes('unadded'))return {schedules:[{id:'today'}]};
    if(pending) return new Promise(resolve=>pending.push({params,resolve}));
    return {schedules:empty?[]:[{id:params.get('kind')+'-'+params.get('page')}],total:empty?0:21,has_more:!empty&&params.get('page')==='1'};
};
vm.runInNewContext(fs.readFileSync(new URL('../public/assets/js/home.js',import.meta.url),'utf8'),{
    document:{getElementById:id=>ids[id]},apiRequest,
    scheduleCard:(item,options)=>({id:item.id,options}),publicScheduleView:item=>item,
    scheduleEmpty:(list,text)=>list.replaceChildren({empty:text}),
});
await new Promise(setImmediate);
assert.equal(ids['home-unadded'].children[0].id,'schedule-1');assert.equal(ids['home-unadded-events'].children[0].id,'event-1');
await ids['home-more'].events.click();assert.equal(ids['home-unadded'].children.length,2);assert.equal(ids['home-unadded-events'].children.length,1);assert.equal(ids['home-more'].hidden,true);
await ids['home-event-more'].events.click();assert.equal(ids['home-unadded-events'].children[1].id,'event-2');
empty=true;await ids['home-unadded-events'].children[0].options.onAdded();assert.equal(ids['unadded-event-count'].hidden,true);assert.equal(ids['unadded-count'].hidden,true);assert.match(ids['home-unadded-events'].children[0].empty,/イベント情報/);
// 古い推しの返答が最後に届いても、新しく選んだ推しの表示を維持する。
pending=[];ids['home-oshi'].value='1';const oldLoad=ids['home-oshi'].events.change();ids['home-oshi'].value='2';const newLoad=ids['home-oshi'].events.change();
for(const entry of pending.filter(e=>e.params.get('oshi_id')==='2'))entry.resolve({schedules:[{id:'new-'+entry.params.get('kind')}],total:1,has_more:false});
await newLoad;
for(const entry of pending.filter(e=>e.params.get('oshi_id')==='1'))entry.resolve({schedules:[{id:'old'}],total:1,has_more:false});
await oldLoad;assert.equal(ids['home-unadded'].children[0].id,'new-schedule');assert.equal(ids['home-unadded-events'].children[0].id,'new-event');
console.log('Home feeds passed: separate cards/counts/pagination, refresh after add, zero hidden, stale responses ignored.');
