/** participants_browser.mjs の役割：同行者画面をPC・スマホで操作し、保存・非表示・表示崩れを検証する。 */
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
            if(page.startsWith('event_companions.php')) {
                const evaluate=async expression=>(await call('Runtime.evaluate',{expression,returnByValue:true})).result.value;
                assert.equal(await evaluate('document.body.innerText.includes("他ユーザー専用名")'),false);
                // 実際のフォーム送信を使い、JS・CSRF・API・再描画まで通して確認する。
                await evaluate(`(()=>{document.querySelector('#add-companion').open=true;const f=document.querySelector('[data-api="events/companions/create"]');f.elements.name.value='ブラウザー${label}';f.elements.note.value='画面から登録';f.requestSubmit();})()`);
                let saved=false;
                for(let n=0;n<40;n++){await pause(150);if(await evaluate(`document.readyState==='complete' && document.querySelector('#active-participants')?.innerText.includes('ブラウザー${label}')`)){saved=true;break;}}
                assert(saved,'フォーム追加が反映される');
                await evaluate(`(()=>{const card=[...document.querySelectorAll('#active-participants article')].find(c=>c.querySelector('h3').textContent==='ブラウザー${label}');card.querySelector('details').open=true;const f=card.querySelector('[data-api="events/companions/update"]');f.elements.name.value='編集済み${label}';f.elements.note.value='編集したメモ';f.requestSubmit();})()`);
                let edited=false;
                for(let n=0;n<40;n++){await pause(150);if(await evaluate(`document.readyState==='complete' && document.querySelector('#active-participants')?.innerText.includes('編集済み${label}')`)){edited=true;break;}}
                assert(edited,'名前・メモ変更が反映される');
                await evaluate(`(()=>{const card=[...document.querySelectorAll('#active-participants article')].find(c=>c.querySelector('h3').textContent==='編集済み${label}');card.querySelector('[data-api="events/companions/archive"]').requestSubmit();})()`);
                let archived=false;
                for(let n=0;n<40;n++){await pause(150);if(await evaluate(`document.readyState==='complete' && !!document.querySelector('#archived-participants')?.textContent.includes('編集済み${label}') && !document.querySelector('#active-participants')?.textContent.includes('編集済み${label}')`)){archived=true;break;}}
                assert(archived,'利用終了で通常一覧から非表示になる: '+await evaluate('JSON.stringify({url:location.href,text:document.body.innerText})'));
                assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'操作後も横にはみ出さない');
                await evaluate("document.querySelector('#add-companion').open=true");
            }
            const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
            await fs.writeFile(path.join(profile,`${label}-${page.replace(/[^a-zA-Z0-9_-]/g,'_')}.png`),Buffer.from(shot.data,'base64'));count++;
        }
    }
    assert.deepEqual(errors,[]);assert.deepEqual(badResponses,[]);
    console.log(`Browser PASS: ${count} pages; 390px / 1440px; no overflow, JS exceptions or failed resources. Screenshots: ${profile}`);
} finally {if(ws)ws.close();chrome.kill();}
