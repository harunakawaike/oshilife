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
