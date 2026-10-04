/** room_workspace_browser.mjs の役割：共同支出の本人会計反映・更新・取消後削除をPC／スマホで検証する。 */
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
    for(const [index,label,width,height] of [[0,'pc',1440,1000],[1,'mobile',390,844]]){
        const eid=input.ids[index];const scope=`[data-expense-id="${eid}"]`;
        const action=name=>`${scope} [data-personal-action="${name}"]`;
        await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:label==='mobile'});
        await cookies(input.cookies);await navigate(roomPage);
        await evaluate(`window.confirm=()=>true;if(document.querySelector('[data-mark-settled]'))document.querySelector('[data-mark-settled]').click()`);
        await waitFor(`document.querySelector('${action('create')}')`);
        await evaluate(`document.querySelector('${scope}').open=true;window.confirm=()=>false;document.querySelector('${action('create')}').click()`);
        await waitFor(`!document.querySelector('${action('create')}').disabled`);
        await evaluate(`window.confirm=()=>true;document.querySelector('${action('create')}').click()`);
        await waitFor(`document.querySelector('${scope}').textContent.includes('✅ お金管理に反映済み')`);
        await capture(label+'-reflected');
        const ownLink=await evaluate(`document.querySelector('${scope} .room-personal-money a').href`);
        await cookies(input.memberCookies);await navigate(roomPage);
        assert(await evaluate(`document.querySelector('${action('create')}')!==null`));
        await evaluate(`document.querySelector('${scope}').open=true;window.confirm=()=>true;document.querySelector('${action('create')}').click()`);
        await waitFor(`document.querySelector('${scope}').textContent.includes('✅ お金管理に反映済み')`);
        assert.notEqual(await evaluate(`document.querySelector('${scope} .room-personal-money a').href`),ownLink);
        await capture(label+'-member');
        await cookies(input.cookies);await navigate(roomPage);
        const origin=await evaluate('performance.timeOrigin');
        await evaluate(`window.confirm=()=>true;document.querySelector('${scope}').open=true;document.querySelector('[data-expense-form="${eid}"]').click()`);
        await waitFor('document.getElementById("room-expense-form")');
        await evaluate(`(()=>{const f=document.getElementById('room-expense-form');const amounts=f.querySelectorAll('.share-amount');amounts[0].value='12000';amounts[1].value='8000';f.elements.category.value='goods';f.elements.expense_date.value='2026-10-05';f.requestSubmit();})()`);
        await waitFor(`!document.getElementById('room-expense-dialog').open && document.querySelector('${scope}').textContent.includes('差があります')`);
        assert.equal(await evaluate(`document.querySelector('${action('update')}')!==null`),false);
        await capture(label+'-difference');
        await evaluate(`document.querySelector('[data-mark-settled]').click()`);await waitFor(`document.querySelector('${action('update')}')`);
        await evaluate(`document.querySelector('${action('update')}').click()`);
        await waitFor(`!document.querySelector('${scope}').textContent.includes('差があります') && document.querySelector('${scope} .room-personal-money').textContent.includes('12,000.00')`);
        assert.equal(await evaluate('performance.timeOrigin'),origin);
        await evaluate(`document.querySelector('[data-cancel-expense="${eid}"]').click()`);
        await waitFor(`document.querySelector('#room-money').textContent.includes('取消済みの共同支出がお金管理に反映されています')`);
        await evaluate(`document.querySelector('[data-show-cancelled]').click();document.querySelector('${scope}').open=true`);
        assert(await evaluate(`document.querySelector('${scope}').textContent.includes('お金管理には反映済み')`));
        await capture(label+'-cancelled');
        await evaluate(`document.querySelector('${action('delete')}').click()`);
        await waitFor(`!document.querySelector('${scope}').textContent.includes('✅ お金管理に反映済み')`);
        await cookies(input.memberCookies);await navigate(roomPage);
        await evaluate(`document.querySelector('[data-show-cancelled]').click();document.querySelector('${scope}').open=true`);
        assert(await evaluate(`document.querySelector('${scope}').textContent.includes('✅ お金管理に反映済み')`));
        // 個人会計の一覧・編集にも由来を表示する。他人の負担は渡さない。
        const memberLink=await evaluate(`document.querySelector('${scope} .room-personal-money a').href`);
        await call('Page.navigate',{url:memberLink});await waitFor(`document.querySelector('input[name="amount"]')`);
        assert(await evaluate(`document.body.textContent.includes('連番ルームから反映した本人負担分')`));
        assert.equal(await evaluate(`document.querySelector('input[name="amount"]').value`),'10000.00');
        await capture(label+'-personal');
        await navigate('money.php');await waitFor(`document.querySelector('#money-expenses').textContent.includes('連番ルームから反映')`);
    }
    assert.deepEqual(errors,[]);assert.deepEqual(badResponses,[]);
    console.log(`Personal money browser PASS: ${count} screens; 390px / 1440px; create/update/delete, own accounts, cancellation, partial refresh. Screenshots: ${profile}`);
} finally {if(ws)ws.close();chrome.kill();}
