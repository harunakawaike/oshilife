/** calendar_ui.mjs の役割：DOMの最小モデルで、日付リンク・日別一覧・削除確認と再取得を検証する。実ブラウザの描画検証とは別。 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
class Element {
    constructor(tag = 'div') { this.tag = tag; this.children = []; this.dataset = {}; this.attributes = {}; this.events = {}; this.textContent = ''; this.classList = { toggle() {} }; }
    append(...children) { children.forEach((child) => { child.parent = this; this.children.push(child); }); }
    replaceChildren(...children) { this.children = []; this.append(...children); }
    setAttribute(name, value) { this.attributes[name] = value; }
    addEventListener(name, handler) { this.events[name] = handler; }
    remove() { this.parent.children = this.parent.children.filter((child) => child !== this); }
}
const ids = Object.fromEntries(['calendar-app','calendar-oshi','calendar-message','calendar-month','month-days','month-prev','month-next','month-today','selected-day','day-count','day-schedules','calendar-add'].map((id) => [id,new Element()]));
ids['calendar-app'].dataset.today = '2026-09-30'; ids['calendar-oshi'].value = 'all';
function descendants(root) { return root.children.flatMap((child) => [child,...descendants(child)]); }
const document = { getElementById: (id) => ids[id], createElement: (tag) => new Element(tag), querySelectorAll: (selector) => Object.values(ids).flatMap(descendants).filter((node) => node.className?.split(' ').includes(selector.slice(1))) };
let confirmed = false;
let schedules = [{id:1,date:'2026-09-30',title:'非公開テスト',oshi_emoji:'💎',member_hearts:[],is_all_day:true,category_label:'その他',is_owner:true,is_added:true,status:'active',visibility:'private'}, {id:2,date:'2026-09-30',title:'取り込みテスト',oshi_emoji:'💎',member_hearts:[],is_all_day:true,category_label:'その他',is_owner:false,is_added:true,status:'active',visibility:'public'}];
const calls = [];
const context = vm.createContext({ document, window: { confirm: () => confirmed }, appUrl: (path) => '/oshilife-v2/public/'+path, apiRequest: async (path, method, data) => {
    calls.push({path,method,data});
    if (method === 'POST') schedules = schedules.filter((item) => item.id !== data.schedule_id);
    return { schedules };
} });
for (const name of ['schedules','calendar']) vm.runInContext(fs.readFileSync(new URL(`../public/assets/js/${name}.js`,import.meta.url),'utf8'),context);
await new Promise(setImmediate);
assert.equal(ids['day-count'].textContent,'2件');
const dateLink = document.querySelectorAll('.month-day').find((node) => node.dataset.date==='2026-09-17');
assert.equal(dateLink.tag,'a');
assert.equal(dateLink.href,'/oshilife-v2/public/schedule_form.php?date=2026-09-17');
const view = document.querySelectorAll('.month-view').find((node) => node.dataset.date==='2026-09-17');
assert.equal(view.hidden,true);
assert.equal(view.textContent,'');
document.querySelectorAll('.month-view').find((node) => node.dataset.date==='2026-09-30').events.click();
let remove = descendants(ids['day-schedules']).find((node) => node.tag==='button' && node.textContent==='予定を削除');
await remove.events.click();
assert.equal(calls.filter((call) => call.method==='POST').length,0,'キャンセル時は削除しない');
confirmed = true;
await remove.events.click();
assert.equal(calls.find((call) => call.method==='POST').path,'api/schedules/delete.php');
assert.equal(ids['day-count'].textContent,'1件');
remove = descendants(ids['day-schedules']).find((node) => node.tag==='button' && node.textContent==='カレンダーから外す');
await remove.events.click();
assert.equal(calls.filter((call) => call.method==='POST')[1].path,'api/schedules/remove-from-calendar.php');
assert.equal(ids['day-count'].textContent,'');
assert.equal(ids['day-count'].hidden,true);
console.log('Calendar UI behavior passed: date registration link, day list, cancel, delete, unlink, counts.');
