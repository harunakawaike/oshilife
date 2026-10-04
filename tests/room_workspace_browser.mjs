/** room_workspace_browser.mjs の役割：複数イベント・補助招待・同ページの共同支出操作をPC／スマホで検証する。 */
import {spawn} from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
const input=JSON.parse(await fs.readFile(process.argv[2],'utf8'));
const profile=await fs.mkdtemp(path.join(os.tmpdir(),'oshilife-event-browser-'));
const chrome=spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',['--headless=new','--remote-debugging-port=0','--remote-debugging-address=127.0.0.1',`--user-data-dir=${profile}`,'--no-first-run','--no-default-browser-check','about:blank'],{stdio:'ignore'});
const pause=ms=>new Promise(r=>setTimeout(r,ms));
let ws;
try {
    let info;
    for(let i=0;i<100;i++){try{info=await fs.readFile(path.join(profile,'DevToolsActivePort'),'utf8');break;}catch{await pause(100);}}
    assert(info,'Chrome起動失敗');
    const [port,socket]=info.trim().split('\n');ws=new WebSocket(`ws://127.0.0.1:${port}${socket}`);
    await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject;});
    let id=0;const pending=new Map();const errors=[];const badResponses=[];
    ws.onmessage=({data})=>{const m=JSON.parse(data);if(m.id){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(new Error(JSON.stringify(m.error))):p.resolve(m.result);}else if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);else if(m.method==='Network.responseReceived'&&m.params.response.status>=400&&!m.params.response.url.endsWith('/favicon.ico'))badResponses.push(m.params.response.url);};
    /** DevToolsへ要求を送り、対応する応答だけを待つ。 */
    const send=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const requestId=++id;pending.set(requestId,{resolve,reject});ws.send(JSON.stringify({id:requestId,method,params,...(sessionId?{sessionId}:{})}));});
    const {targetId}=await send('Target.createTarget',{url:'about:blank'});
    const {sessionId}=await send('Target.attachToTarget',{targetId,flatten:true});
    const call=(method,params)=>send(method,params,sessionId);
    await call('Page.enable');await call('Runtime.enable');await call('Network.enable');
    await call('Network.setCookies',{cookies:input.cookies.map(c=>({name:c.name,value:c.value,url:input.base+'/',path:c.path,httpOnly:true}))});
    let count=0;
    const evaluate=async expression=>(await call('Runtime.evaluate',{expression,returnByValue:true})).result.value;
    const waitFor=async expression=>{for(let n=0;n<100;n++){await pause(100);try{if(await evaluate(`document.readyState==='complete' && (${expression})`))return;}catch(_){}}throw new Error('画面待機失敗: '+expression);};
    const cookies=async values=>call('Network.setCookies',{cookies:values.map(c=>({name:c.name,value:c.value,url:input.base+'/',path:c.path,httpOnly:true}))});
    const navigate=async page=>{await call('Page.navigate',{url:input.base+'/'+page});await waitFor(`location.href===${JSON.stringify(input.base+'/'+page)}`);};
    const capture=async label=>{
        assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'横はみ出し');
        assert.equal(await evaluate('document.querySelectorAll(".bottom-nav a").length'),5,'ナビを増やさない');
        const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
        await fs.writeFile(path.join(profile,label+'.png'),Buffer.from(shot.data,'base64'));count++;
    };
    const roomPage='room_detail.php?room_id='+input.roomId;
    for(const [label,width,height] of [['pc',1440,1000],['mobile',390,844]]){
        await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:label==='mobile'});
        await cookies(input.cookies);await navigate(roomPage);
        if(label==='pc')for(const eid of input.eventIds){
            await evaluate(`(()=>{const f=document.querySelector('[data-api="events/add"]');f.closest('details').open=true;f.elements.event_id.value='${eid}';f.requestSubmit();})()`);
            await waitFor(`document.querySelectorAll('.room-event').length===${input.eventIds.indexOf(eid)+1}`);
        }
        assert.equal(await evaluate('document.querySelectorAll(".room-event").length'),2);
        assert(await evaluate(`(()=>{const sels=['.room-workspace-heading','.room-events-top','.room-members-compact','#room-talk','#room-money','.room-management'];const ys=sels.map(s=>document.querySelector(s).getBoundingClientRect().top);return ys.every((y,i)=>i===0||y>ys[i-1]);})()`));
        assert(await evaluate('document.querySelector(".room-members-compact").offsetHeight<document.querySelector("#room-talk").offsetHeight'));
        assert.equal(await evaluate('document.querySelector(".room-invite-panel").open'),false);
        assert.equal(await evaluate('document.querySelector(".room-management details").open'),false);
        assert(await evaluate('document.querySelector("#room-talk").innerText.includes("次のStep")'));
        await capture(label+'-workspace');
        // 招待は必要な時だけ開く。発行・コピー・再発行・無効化も従来API。
        await evaluate('document.querySelector(".room-invite-panel summary").click()');
        await evaluate(`document.querySelector('[data-api="links/create"]').requestSubmit()`);
        await waitFor('document.getElementById("room-invite-url").value');
        const link=await evaluate('document.getElementById("room-invite-url").value');
        await evaluate('document.getElementById("room-copy-link").click()');
        await waitFor('document.getElementById("room-copy-message").textContent');
        await capture(label+'-invite');
        await evaluate(`document.querySelector('[data-api="links/create"]').requestSubmit()`);
        await waitFor(`document.getElementById('room-invite-url').value!==${JSON.stringify(link)}`);
        await evaluate(`window.confirm=()=>true;document.querySelector('[data-api="links/revoke"]').requestSubmit()`);
        await waitFor('document.querySelector(".room-invite-panel").open===false');
        // ここから共同支出の追加・編集・取消まで、ドキュメント遷移がないことを検査。
        const origin=await evaluate('performance.timeOrigin');
        await evaluate('document.querySelector("[data-expense-form]").click()');
        await waitFor('document.querySelector("#room-expense-dialog[open] #room-expense-form")');
        await evaluate(`(()=>{const f=document.getElementById('room-expense-form');f.elements.title.value='同ページホテル${label}';f.elements.total_amount.value='20001';for(const cb of f.querySelectorAll('.share-selected'))cb.checked=true;document.getElementById('room-expense-split').click();})()`);
        assert.deepEqual(await evaluate('[...document.querySelectorAll(".share-amount")].map(x=>Number(x.value))'),[10001,10000]);
        await capture(label+'-add-dialog');
        await evaluate('document.getElementById("room-expense-form").requestSubmit()');
        await waitFor(`!document.getElementById('room-expense-dialog').open && [...document.querySelectorAll('.room-money-item')].some(e=>e.innerText.includes('同ページホテル${label}'))`);
        assert.equal(await evaluate('document.querySelector(".room-settlement-transfers strong").textContent'),label==='pc'?'¥10,000':'¥4,000');
        const id=await evaluate(`[...document.querySelectorAll('.room-money-item')].find(e=>e.innerText.includes('同ページホテル${label}')).dataset.expenseId`);
        assert.equal(await evaluate('location.pathname.split("/").pop()+location.search'),roomPage);
        assert.equal(await evaluate('performance.timeOrigin'),origin);
        await evaluate(`document.querySelector('[data-expense-form="${id}"]').click()`);
        await waitFor('document.getElementById("room-expense-form")');
        await evaluate(`(()=>{const f=document.getElementById('room-expense-form');f.elements.title.value='変更後ホテル${label}';const v=f.querySelectorAll('.share-amount');v[0].value='8001';v[1].value='12000';f.requestSubmit();})()`);
        await waitFor(`!document.getElementById('room-expense-dialog').open && document.querySelector('[data-expense-id="${id}"]').innerText.includes('¥12,000')`);
        assert.equal(await evaluate('document.querySelector(".room-settlement-transfers strong").textContent'),label==='pc'?'¥12,000':'¥6,000');
        await capture(label+'-money');
        assert.equal(await evaluate('performance.timeOrigin'),origin);
        // 精算確認はキャンセルできる。ownerによる確定後もフォームを開くだけでは解除しない。
        await evaluate(`window.lastConfirm='';window.confirm=t=>{window.lastConfirm=t;return false;};document.querySelector('[data-mark-settled]').click()`);
        await waitFor(`window.lastConfirm.includes('実際の支払い') && !document.querySelector('[data-mark-settled]').disabled`);
        assert.equal(await evaluate(`document.querySelector('.room-settled-badge')!==null`),false);
        await evaluate(`window.confirm=()=>true;document.querySelector('[data-mark-settled]').click()`);
        await waitFor(`document.querySelector('.room-settled-badge')`);
        await capture(label+'-settled');
        assert.equal(await evaluate('performance.timeOrigin'),origin);
        await evaluate(`document.querySelector('[data-expense-form="${id}"]').click()`);
        await waitFor('document.getElementById("room-expense-form")');
        assert(await evaluate(`document.querySelector('.room-settled-badge')!==null`));
        await evaluate(`window.lastConfirm='';window.confirm=t=>{window.lastConfirm=t;return false;};document.getElementById('room-expense-form').requestSubmit()`);
        await waitFor(`document.querySelector('#room-expense-form .event-message').textContent.includes('キャンセル')`);
        assert(await evaluate(`window.lastConfirm.includes('未精算に戻し') && document.querySelector('.room-settled-badge')!==null`));
        await evaluate(`window.confirm=()=>true;document.getElementById('room-expense-form').requestSubmit()`);
        await waitFor(`!document.getElementById('room-expense-dialog').open && !document.querySelector('.room-settled-badge')`);
        await evaluate(`document.querySelector('[data-mark-settled]').click()`);
        await waitFor(`document.querySelector('.room-settled-badge')`);
        // memberも同じ内訳を閲覧。ownerが登録した支出を編集するボタンは出ない。
        await cookies(input.memberCookies);await navigate(roomPage);
        await evaluate(`document.querySelector('[data-expense-id="${id}"]').open=true`);
        assert(await evaluate(`document.querySelector('[data-expense-id="${id}"]').innerText.includes('¥12,000')`));
        assert.equal(await evaluate(`document.querySelector('[data-expense-id="${id}"] [data-expense-form]')!==null`),false);
        assert.equal(await evaluate('document.querySelector(".room-invite-panel")!==null'),false);
        assert(await evaluate(`document.querySelector('.room-settled-badge')!==null && document.querySelector('[data-mark-settled]')===null`));
        await capture(label+'-member');
        await evaluate('window.confirm=()=>true');
        // member自身の登録も同ページで完結。
        const memberOrigin=await evaluate('performance.timeOrigin');
        await evaluate('document.querySelector("[data-expense-form]").click()');
        await waitFor('document.getElementById("room-expense-form")');
        await evaluate(`(()=>{const f=document.getElementById('room-expense-form');f.elements.title.value='member交通${label}';f.elements.category.value='transportation';f.elements.total_amount.value='12000';for(const cb of f.querySelectorAll('.share-selected'))cb.checked=true;document.getElementById('room-expense-split').click();f.requestSubmit();})()`);
        await waitFor(`!document.getElementById('room-expense-dialog').open && document.querySelector('#room-money').innerText.includes('member交通${label}')`);
        assert.equal(await evaluate('performance.timeOrigin'),memberOrigin);
        await cookies(input.cookies);await navigate(roomPage);
        const cancelOrigin=await evaluate('performance.timeOrigin');
        await evaluate(`window.confirm=()=>true;document.querySelector('[data-expense-id="${id}"]').open=true;document.querySelector('[data-cancel-expense="${id}"]').click()`);
        await waitFor(`document.querySelector('[data-expense-id="${id}"]').dataset.cancelled==='true'`);
        assert.equal(await evaluate('performance.timeOrigin'),cancelOrigin);
        assert.equal(await evaluate('document.querySelector(".room-settlement-transfers strong").textContent'),label==='pc'?'¥6,000':'¥12,000');
        await evaluate('document.querySelector("[data-show-cancelled]").click()');
        assert.equal(await evaluate(`document.querySelector('[data-expense-id="${id}"]').hidden`),false);
        // ダイアログをEscapeで閉じて元の画面へ戻れる。
        await evaluate('document.querySelector("[data-expense-form]").click()');await waitFor('document.getElementById("room-expense-form")');
        await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
        await waitFor('!document.getElementById("room-expense-dialog").open');
    }
    await evaluate('document.querySelector(".room-management summary").click()');
    await evaluate(`window.confirm=()=>true;document.querySelector('[data-api="close"]').requestSubmit()`);
    await waitFor('document.body.innerText.includes("終了したルームです")');
    assert.equal(await evaluate('document.querySelector("[data-expense-form]")!==null'),false);
    assert.deepEqual(errors,[]);assert.deepEqual(badResponses,[]);
    console.log(`Browser PASS: ${count} pages; 390px / 1440px; no overflow, JS exceptions or failed resources. Screenshots: ${profile}`);
} finally {if(ws)ws.close();chrome.kill();}
