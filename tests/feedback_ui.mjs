/** feedback_ui.mjs の役割：DOMモデルで感謝の切替と修正提案フォーム・承認画面の動きを検証する。描画テストとは区別する。 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
class Element {
    constructor(tag='div') { this.tag=tag; this.children=[]; this.dataset={}; this.attributes={}; this.events={}; this.textContent=''; this.value=''; }
    append(...nodes) { nodes.forEach((node)=>{node.parent=this;this.children.push(node);}); }
    replaceChildren(...nodes) { this.children=[];this.append(...nodes); }
    setAttribute(name,value) { this.attributes[name]=value; }
    addEventListener(name,fn) { this.events[name]=fn; }
    querySelectorAll(selector) { return descendants(this).filter((node)=>selector.split(',').some((part)=>matches(node,part.trim()))); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}
function descendants(node) { return node.children.flatMap((child)=>[child,...descendants(child)]); }
function matches(node,selector) {
    if(selector==='[data-reaction]') return Boolean(node.dataset.reaction);
    if(selector==='[data-count]') return Boolean(node.isCount);
    if(selector==='[role=alert]') return node.attributes.role==='alert';
    return node.tag===selector;
}
function runtime(ids,apiRequest,confirm=()=>true) {
    const document={getElementById:(id)=>ids[id]||null,createElement:(tag)=>new Element(tag)};
    const context=vm.createContext({document,apiRequest,window:{confirm},appUrl:(path)=>'/oshilife-v2/public/'+path});
    vm.runInContext(fs.readFileSync(new URL('../public/assets/js/schedules.js',import.meta.url),'utf8'),context);
    return context;
}
function load(context,file) {vm.runInContext(fs.readFileSync(new URL('../public/assets/js/'+file+'.js',import.meta.url),'utf8'),context);}
const ids=Object.fromEntries(['schedule-feedback','reaction-message','correction-form','correction-field','correction-current','correction-value','correction-note','correction-category','correction-input-label','correction-note-label','correction-category-label','correction-message','pending-corrections'].map((id)=>[id,new Element()]));
ids['schedule-feedback'].dataset.scheduleId='42';
const buttons=['helped','thanks'].map((type)=>{const button=new Element('button');button.dataset.reaction=type;const count=new Element('span');count.isCount=true;button.append(count);ids['schedule-feedback'].append(button);return button;});
ids['correction-field'].value='start_time';
ids['correction-field'].selectedOptions=[{dataset:{current:'19:00',value:'19:00'}}];
ids['correction-form'].elements={reason:new Element('textarea'),source_url:new Element('input')};
const pressed={helped:false,thanks:false};const calls=[];
const context=runtime(ids,async(path,method,data)=>{calls.push({path,method,data});if(path.includes('toggle')) {pressed[data.reaction_type]=!pressed[data.reaction_type];return {active:pressed[data.reaction_type],count:pressed[data.reaction_type]?1:0};}return {correction_id:7};});
load(context,'feedback');
assert.equal(ids['correction-value'].type,'time');assert.equal(ids['correction-current'].textContent,'19:00');
await buttons[0].events.click();await buttons[1].events.click();
assert.equal(buttons[0].attributes['aria-pressed'],'true');assert.equal(buttons[1].attributes['aria-pressed'],'true');
await buttons[0].events.click();assert.equal(buttons[0].querySelector('[data-count]').textContent,0);assert.equal(buttons[0].attributes['aria-pressed'],'false');
ids['correction-value'].value='20:00';ids['correction-form'].elements.reason.value='公式で確認';
await ids['correction-form'].events.submit({preventDefault(){}});
const sent=calls.at(-1).data;assert.equal(sent.new_value,'20:00');assert.equal(sent.schedule_id,42);assert.equal(sent.old_value,undefined);assert.equal(sent.user_id,undefined);
assert.match(ids['correction-message'].textContent,/送りました/);
assert.equal(ids['correction-current'].textContent,'19:00','承認前に元予定を変えない');
const listIds=Object.fromEntries(['correction-status','correction-more','correction-list-message','correction-list'].map((id)=>[id,new Element()]));
listIds['correction-status'].value='pending';let consent=false;let reviewed=false;const reviewCalls=[];
const proposal={id:7,schedule_id:42,schedule_title:'<script>文字として表示</script>',requester_name:'B',created_at:'2026-09-30',status_label:'確認待ち',field_label:'開始時間',old_display:'19:00',new_display:'20:00',reason:'時間変更',source_url:'https://example.com/',status:'pending',can_approve:true};
const reviewContext=runtime(listIds,async(path,method,data)=>{reviewCalls.push({path,method,data});if(method==='POST'){reviewed=true;return {};}return {corrections:reviewed?[]:[proposal],total:reviewed?0:1,has_more:false};},()=>consent);
load(reviewContext,'corrections');await new Promise(setImmediate);
const approve=listIds['correction-list'].querySelectorAll('button').find((button)=>button.textContent.includes('承認'));
assert.equal(listIds['correction-list'].children[0].children[0].textContent,proposal.schedule_title);
await approve.events.click();assert.equal(reviewCalls.filter((call)=>call.method==='POST').length,0);
consent=true;await approve.events.click();
assert.equal(reviewCalls.find((call)=>call.method==='POST').data.correction_id,7);
assert.equal(listIds['correction-list-message'].textContent,'0件の提案');
console.log('Feedback UI behavior passed: independent reaction toggles, proposal payload/current value, text rendering, approval confirmation/list refresh.');

// ホームの集計は0件でも3つの数値を同じ配置で表示する。
const dashboardIds={'monthly-thanks':new Element(),'home-oshi':new Element()};
dashboardIds['monthly-thanks'].dataset={year:'2026',month:'09'};
dashboardIds['home-oshi'].value='all';
const dashboardContext=runtime(dashboardIds,async()=>({year:2026,month:9,calendar_added_count:0,helped_count:1,thanks_count:0}));
load(dashboardContext,'monthly_summary');await new Promise(setImmediate);
const metrics=dashboardIds['monthly-thanks'].querySelector('dl');
assert.equal(metrics.children.length,3);
assert.deepEqual(metrics.children.map((metric)=>metric.querySelector('dd').textContent),['0','1','0']);
assert.deepEqual(metrics.children.map((metric)=>metric.querySelector('dt').textContent),['カレンダー追加','助かった！','ありがとう！']);

// 通知の表示済みと既読を区別し、ポップアップが消えても未読を残す。
Object.defineProperty(Element.prototype,'classList',{get(){return {add:(value)=>{this.className=(this.className||'')+' '+value;}};}});
Element.prototype.remove=function(){if(this.parent)this.parent.children=this.parent.children.filter((node)=>node!==this);};
Element.prototype.focus=function(){};
const noticeIds=Object.fromEntries(['notification-toggle','notification-panel','notification-status','notification-toasts','notification-badge','notification-list','notification-read','notification-close'].map((id)=>[id,new Element()]));
noticeIds['notification-panel'].hidden=true;
const timeouts=[];const noticeCalls=[];let shown=false;let read=false;
const notice={id:9,kind:'thanks',message:'「ありがとう！」が届きました',title:'<b>予定名</b>',created_at:'2026-09-30',path:'schedule_detail.php?id=42',unread:true};
const noticeContext=vm.createContext({
    document:{getElementById:(id)=>noticeIds[id],createElement:(tag)=>new Element(tag),addEventListener(){},hidden:false},
    window:{location:{assign(){}}},
    setTimeout:(fn,delay)=>{timeouts.push({fn,delay});return timeouts.length;},clearTimeout(){},
    appUrl:(path)=>'/oshilife-v2/public/'+path,
    apiRequest:async(path,method,data)=>{
        noticeCalls.push({path,method,data});
        if(method==='POST'){if(data.mode==='shown')shown=true;else read=true;return {};}
        return {notifications:[{...notice,unread:!read}],popups:shown||read?[]:[notice],unread_count:read?0:1};
    }
});
load(noticeContext,'notifications');await new Promise(setImmediate);
assert.equal(noticeIds['notification-toasts'].children.length,1);
assert.equal(noticeIds['notification-badge'].textContent,'1');
assert.equal(shown,true);assert.equal(read,false);
assert.equal(noticeIds['notification-list'].children[0].children[1].textContent,'<b>予定名</b>');
timeouts.find((timer)=>timer.delay===12000).fn();
assert.equal(noticeIds['notification-toasts'].children.length,0);
await noticeContext.refreshNotifications();
assert.equal(noticeIds['notification-toasts'].children.length,0);
await noticeIds['notification-read'].events.click({currentTarget:noticeIds['notification-read']});
assert.equal(read,true);assert.equal(noticeIds['notification-badge'].hidden,true);
console.log('Dashboard/notification UI behavior passed: 3 metrics, popup, shown/read separation, no repeat, escaped text.');
