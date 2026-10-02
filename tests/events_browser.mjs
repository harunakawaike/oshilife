/** events_browser.mjs の役割：一時テストユーザーのCookieでPC・スマホの実ブラウザ表示を検証し、画像を一時領域へ保存する。 */
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
    for(const [label,width,height] of [['pc',1440,1000],['mobile',390,844]]){
        await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:label==='mobile'});
        for(const page of input.pages){
            await call('Page.navigate',{url:input.base+'/'+page});await pause(1300);
            const {result}=await call('Runtime.evaluate',{expression:'JSON.stringify({url:location.href,width:innerWidth,scroll:document.documentElement.scrollWidth,text:document.body.innerText,nav:[...document.querySelectorAll(".bottom-nav a")].map(a=>a.innerText),css:[...document.styleSheets].length})',returnByValue:true});
            const state=JSON.parse(result.value);assert(!state.url.includes('login.php'),'ログインが維持される');assert(state.scroll<=state.width+1,`${page}:横にはみ出し`);assert(state.nav.some(x=>x.includes('イベント')));assert(state.css>0);assert(!/Fatal error|Warning:|処理に失敗|読み込めません/.test(state.text));
            if(input.managementExpect?.[page]) {
                const {result:management}=await call('Runtime.evaluate',{expression:`JSON.stringify({entry:document.querySelector('[name="entry_method"]').value,lottery:!document.querySelector('[data-management-lottery]').hidden,application:!document.querySelector('[data-management-application]').hidden,payment:!document.querySelector('[data-management-payment]').hidden,free:!document.querySelector('[data-payment-free]').hidden,sales:!document.querySelector('[data-management-sales]').hidden})`,returnByValue:true});
                const actual=JSON.parse(management.value),expected=input.managementExpect[page];
                assert.equal(actual.entry,expected.entry);assert.equal(actual.lottery,['lottery','fanclub_presale'].includes(expected.entry));
                assert.equal(actual.application,expected.entry!=='no_application');assert.equal(actual.free,expected.free);
                if(expected.free){assert.equal(actual.payment,false);assert.equal(actual.sales,false);}
            }
            const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
            await fs.writeFile(path.join(profile,`${label}-${page.replace(/[^a-zA-Z0-9_-]/g,'_')}.png`),Buffer.from(shot.data,'base64'));count++;
        }
    }
    assert.deepEqual(errors,[]);assert.deepEqual(badResponses,[]);
    console.log(`Browser PASS: ${count} pages; 390px / 1440px; no overflow, JS exceptions or failed resources. Screenshots: ${profile}`);
} finally {if(ws)ws.close();chrome.kill();}
