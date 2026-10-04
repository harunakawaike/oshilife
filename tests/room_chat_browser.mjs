/** room_workspace_browser.mjs の役割：トークのpolling・安全な表示・削除・スクロールをPC／スマホで検証する。 */
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
    let id=0;const pending=new Map();const errors=[];const badResponses=[];const listRequests=[];
    ws.onmessage=({data})=>{const m=JSON.parse(data);if(m.id){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(new Error(JSON.stringify(m.error))):p.resolve(m.result);}else if(m.method==='Network.requestWillBeSent'&&m.params.request.url.includes('/messages/list.php'))listRequests.push(m.params.request.url);else if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);else if(m.method==='Network.responseReceived'&&m.params.response.status>=400&&!m.params.response.url.endsWith('/favicon.ico'))badResponses.push(m.params.response.url);};
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
    /** 別のメンバーのCookieで投稿する。ブラウザーの本人セッションは変更しない。 */
    const memberApi=async(action,data)=>{
        const response=await fetch(input.base+'/api/rooms/messages/'+action+'.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':input.memberCsrf,Cookie:input.memberCookies.map(c=>c.name+'='+c.value).join('; ')},body:JSON.stringify({room_id:input.roomId,...data})});
        assert(response.ok,await response.clone().text());return (await response.json()).data;
    };
    for(const [label,width,height] of [['pc',1440,1000],['mobile',390,844]]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:label==='mobile'});
        await navigate(roomPage);await waitFor('document.querySelectorAll(".room-chat-message").length===100');
        const origin=await evaluate('performance.timeOrigin');
        assert(await evaluate('(()=>{const e=document.getElementById("room-chat-history");return e.scrollHeight-e.scrollTop-e.clientHeight<4;})()'));
        await capture(label+'-initial');
        await evaluate('document.getElementById("room-chat-history").scrollTop=0');
        const reply=await memberApi('send',{message:'別メンバーから '+label+' 😊'});
        await waitFor(`document.querySelector('[data-message-id="${reply.id}"]')`);
        assert(await evaluate('document.getElementById("room-chat-latest").hidden===false'));
        assert.equal(await evaluate(`document.querySelector('[data-message-id="${reply.id}"] [data-delete-message]')!==null`),false);
        await evaluate('document.getElementById("room-chat-latest").click()');
        // HTMLを実行せず文字として表示し、送信時には末尾へ移動する。
        const malicious='<img src=x onerror="window.chatXss=1"><script>window.chatXss=1</script>\n日本語 🎵 '+label;
        await evaluate(`(()=>{const f=document.getElementById('room-chat-form');f.elements.message.value=${JSON.stringify(malicious)};f.requestSubmit();})()`);
        await waitFor(`document.querySelector('#room-chat-history').textContent.includes(${JSON.stringify(malicious)})`);
        assert.equal(await evaluate('window.chatXss'),undefined);
        assert.equal(await evaluate('document.querySelector("#room-chat-history img,#room-chat-history script")!==null'),false);
        assert(await evaluate('(()=>{const e=document.getElementById("room-chat-history");return e.scrollHeight-e.scrollTop-e.clientHeight<4;})()'));
        await capture(label+'-messages');
        await memberApi('delete',{id:reply.id});
        await waitFor(`document.querySelector('[data-message-id="${reply.id}"]').classList.contains('is-deleted')`);
        assert.equal(await evaluate(`document.querySelector('[data-message-id="${reply.id}"] .room-chat-body').textContent`),'メッセージを削除しました');
        await evaluate(`window.confirm=()=>true;[...document.querySelectorAll('[data-delete-message]')].at(-1).click()`);
        await waitFor(`document.querySelector('#room-chat-status').textContent==='メッセージを削除しました。'`);
        await evaluate(`document.getElementById('room-chat-input').value='あ'.repeat(1000);document.getElementById('room-chat-form').requestSubmit()`);
        await waitFor(`document.getElementById('room-chat-input').value==='' && [...document.querySelectorAll('.room-chat-body')].at(-1).textContent.length===1000`);
        await capture(label+'-long');
        await evaluate(`document.getElementById('room-chat-input').value='あ'.repeat(1001);document.getElementById('room-chat-form').requestSubmit()`);
        await waitFor(`document.getElementById('room-chat-status').textContent.includes('1000文字以内')`);
        assert.equal(await evaluate('document.getElementById("room-chat-input").value.length'),1001);
        await evaluate('document.getElementById("room-chat-input").value=""');
        // visibilitychangeで新規pollingが止まり、復帰後に追いつく。既に飛んだ要求は完了させる。
        await evaluate(`Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'))`);
        await pause(600);const calls=listRequests.length;
        const hiddenReply=await memberApi('send',{message:'タブ復帰 '+label});
        await pause(4300);assert.equal(listRequests.length,calls);
        await evaluate(`delete document.hidden;document.dispatchEvent(new Event('visibilitychange'))`);
        await waitFor(`document.querySelector('[data-message-id="${hiddenReply.id}"]')`);
        assert.equal(await evaluate('performance.timeOrigin'),origin);
        assert.equal(await evaluate('document.querySelectorAll(".room-chat-message").length'),100);
    }
    // 終了後は送信欄が消え、履歴は残る。
    await call('Runtime.evaluate',{expression:`apiRequest('api/rooms/close.php','POST',{room_id:${input.roomId}})`,awaitPromise:true});
    await waitFor('document.getElementById("room-chat-form").hidden');
    assert.equal(await evaluate('document.getElementById("room-chat-closed").hidden'),false);
    assert.equal(await evaluate('document.querySelector("[data-delete-message]")!==null'),false);
    await capture('mobile-closed');
    assert(listRequests.some(url=>new URL(url).searchParams.has('after_id')));
    assert(listRequests.filter(url=>!new URL(url).searchParams.has('after_id')).length===2,'初回以外に全体を再取得しない');
    assert.deepEqual(errors,[]);assert.deepEqual(badResponses,[]);
    console.log(`Chat browser PASS: ${count} screens; 390px / 1440px; polling, deletion, XSS, scrolling, visibility, 100-row cap. Screenshots: ${profile}`);
} finally {if(ws)ws.close();chrome.kill();}
