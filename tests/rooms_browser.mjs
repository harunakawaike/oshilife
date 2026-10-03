/** rooms_browser.mjs の役割：ownerと招待先の2アカウントでルーム作成・招待・参加・退出・終了をPC／スマホで検証する。 */
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
        await cookies(input.cookies);await navigate('rooms.php');await capture(label+'-rooms');
        const name='ブラウザー連番ルーム'+label;
        await evaluate(`(()=>{const f=document.querySelector('[data-api="create"]');f.elements.name.value=${JSON.stringify(name)};f.requestSubmit();})()`);
        await waitFor(`location.pathname.endsWith('room_detail.php') && document.querySelector('h1')?.textContent===${JSON.stringify(name)}`);
        const rid=await evaluate('new URL(location.href).searchParams.get("room_id")');
        await evaluate(`(()=>{const f=document.querySelector('[data-api="events/add"]');f.closest('details').open=true;f.elements.event_id.value=${JSON.stringify(String(input.eventId))};f.requestSubmit();})()`);
        await waitFor('document.querySelectorAll(".room-event").length===1');
        await evaluate(`document.querySelector('[data-api="links/create"]').requestSubmit()`);
        await waitFor('document.getElementById("room-invite-url")?.value');
        const inviteUrl=await evaluate('document.getElementById("room-invite-url").value');
        assert(inviteUrl.startsWith(input.base+'/rooms/join.php?token='));
        await evaluate('document.getElementById("room-copy-link").click()');
        await waitFor('document.getElementById("room-copy-message").textContent.length>0');
        await capture(label+'-link');
        // Cookieを消し、実際のログインフォームから元の招待ページへの復帰を確認する。
        await call('Network.clearBrowserCookies');
        await call('Page.navigate',{url:inviteUrl});
        await waitFor('location.pathname.endsWith("login.php")');
        await evaluate(`(()=>{const f=document.getElementById('auth-form');f.elements.email.value=${JSON.stringify(input.memberEmail)};f.elements.password.value=${JSON.stringify(input.password)};f.requestSubmit();})()`);
        await waitFor(`location.href===${JSON.stringify(inviteUrl)}`);
        assert(await evaluate('document.body.innerText.includes("参加しますか？")'));
        assert.equal(await evaluate('document.querySelectorAll(".room-people li").length'),0);
        await capture(label+'-join');
        await evaluate(`document.querySelector('[data-api="links/join"]').requestSubmit()`);
        await waitFor(`location.pathname.endsWith('room_detail.php') && new URL(location.href).searchParams.get('room_id')===${JSON.stringify(rid)}`);
        assert.equal(await evaluate('document.querySelectorAll(".room-people li").length'),2);
        assert.equal(await evaluate('document.querySelectorAll(".room-event").length'),1);
        assert.equal(await evaluate(`!!document.querySelector('[data-api="events/add"]')`),false);
        assert.equal(await evaluate('!!document.getElementById("room-user-search")'),false);
        await capture(label+'-member');
        await cookies(input.cookies);await navigate('room_detail.php?room_id='+rid);await capture(label+'-owner');
        await cookies(input.memberCookies);await navigate('room_detail.php?room_id='+rid);
        await evaluate(`window.confirm=()=>true;document.querySelector('[data-api="members/leave"]').requestSubmit()`);
        await waitFor('location.pathname.endsWith("rooms.php")');
        await cookies(input.cookies);await navigate('room_detail.php?room_id='+rid);
        assert.equal(await evaluate('document.querySelectorAll(".room-people li").length'),1);
        await evaluate(`window.confirm=()=>true;document.querySelector('[data-api="close"]').requestSubmit()`);
        await waitFor('document.body.innerText.includes("終了したルームです")');
        assert.equal(await evaluate('!!document.getElementById("room-user-search")'),false);
        await capture(label+'-closed');
    }
    assert.deepEqual(errors,[]);assert.deepEqual(badResponses,[]);
    console.log(`Browser PASS: ${count} pages; 390px / 1440px; no overflow, JS exceptions or failed resources. Screenshots: ${profile}`);
} finally {if(ws)ws.close();chrome.kill();}
