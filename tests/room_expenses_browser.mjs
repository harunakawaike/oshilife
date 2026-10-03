/** room_expenses_browser.mjs の役割：2アカウントで共同支出の均等割り・共有・個別編集・取消をPC／スマホで検証する。 */
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
    for(const [label,width,height] of [['pc',1440,1000],['mobile',390,844]]){
        await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:label==='mobile'});
        await cookies(input.cookies);await navigate('room_expenses.php?room_id='+input.roomId);await capture(label+'-expenses');
        await navigate('room_expense_form.php?room_id='+input.roomId);
        // 支払者を3人目にし、端数1円を支払者へ、残りをメンバーID順に配ることを確認。
        await evaluate(`(()=>{const f=document.getElementById('room-expense-form');f.elements.title.value='ブラウザー共同支出${label}';f.elements.total_amount.value='10000';f.elements.paid_by_user_id.value='${input.userIds[2]}';for(const c of f.querySelectorAll('.share-selected'))c.checked=true;document.getElementById('room-expense-split').click();})()`);
        const shares=await evaluate(`[...document.querySelectorAll('.room-expense-member')].map(r=>({id:Number(r.dataset.userId),amount:Number(r.querySelector('.share-amount').value)}))`);
        assert.equal(shares.find(s=>s.id===input.userIds[2]).amount,3334);
        assert.equal(shares.reduce((sum,s)=>sum+s.amount,0),10000);
        // 支払者を負担対象から外しても配分できる。残り2人のうちIDの小さい人へ端数。
        await evaluate(`(()=>{const r=document.querySelector('[data-user-id="${input.userIds[2]}"]');r.querySelector('.share-selected').checked=false;document.querySelector('[name="total_amount"]').value='10001';document.getElementById('room-expense-split').click();})()`);
        const two=await evaluate(`[...document.querySelectorAll('.room-expense-member')].filter(r=>r.querySelector('.share-selected').checked).map(r=>Number(r.querySelector('.share-amount').value))`);
        assert.deepEqual(two,[5001,5000]);
        // 最終的には2人でホテル2万円、各1万円という要件例を登録する。
        await evaluate(`(()=>{const f=document.getElementById('room-expense-form');f.elements.total_amount.value='20000';f.elements.paid_by_user_id.value='${input.userIds[0]}';document.getElementById('room-expense-split').click();})()`);
        await capture(label+'-form');
        await evaluate(`document.getElementById('room-expense-form').requestSubmit()`);
        await waitFor('location.pathname.endsWith("room_expense_detail.php")');
        const detailUrl=await evaluate('location.pathname.split("/").pop()+location.search');
        const original=await evaluate('document.querySelector(".room-expense-detail").innerText');
        assert(original.includes('¥20,000') && original.includes('¥10,000'));
        assert(Number(await evaluate('parseFloat(getComputedStyle(document.querySelector(".room-expense-total")).fontSize)'))>=28,'総額を読みやすい文字サイズで表示');
        await capture(label+'-detail');
        await cookies(input.memberCookies);await navigate(detailUrl);
        assert.equal(await evaluate('document.querySelectorAll("#room-expense-cancel").length'),0);
        assert(await evaluate('document.querySelector(".room-expense-detail").innerText.includes("¥20,000")'));
        await capture(label+'-member');
        await cookies(input.cookies);await navigate(detailUrl);
        await evaluate(`document.querySelector('a[href*="room_expense_form.php"]').click()`);
        await waitFor('location.pathname.endsWith("room_expense_form.php")');
        // 均等割り後の個別編集。合計を保った変更が保存・共有される。
        await evaluate(`(()=>{const f=document.getElementById('room-expense-form');f.querySelector('[data-user-id="${input.userIds[0]}"] .share-amount').value='8000';f.querySelector('[data-user-id="${input.userIds[1]}"] .share-amount').value='12000';f.requestSubmit();})()`);
        await waitFor('location.pathname.endsWith("room_expense_detail.php") && document.body.innerText.includes("¥12,000")');
        await evaluate(`window.confirm=()=>true;document.getElementById('room-expense-cancel').requestSubmit()`);
        await waitFor('document.body.innerText.includes("取消済み")');await capture(label+'-cancelled');
    }
    assert.deepEqual(errors,[]);assert.deepEqual(badResponses,[]);
    console.log(`Browser PASS: ${count} pages; 390px / 1440px; no overflow, JS exceptions or failed resources. Screenshots: ${profile}`);
} finally {if(ws)ws.close();chrome.kill();}
