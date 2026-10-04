"""トークの投稿・差分・認可・削除・連投制限・データ保持を専用DBで検証する。"""
import event_test_support as support
from event_test_support import *
import os
assert os.environ.get('DB_NAME','').startswith('oshilife_v2_rooms_verify_expenses_')
def query(sql,params=[]):
    """試験DBへの検証用SQL。障害を注入するトリガーも本番には作らない。"""
    php=r'''
    define('PROJECT_ROOT',$argv[1]);require PROJECT_ROOT.'/app/helpers/env.php';loadEnv(PROJECT_ROOT.'/.env');require PROJECT_ROOT.'/config/database.php';
    if(!str_starts_with(env('DB_NAME'),'oshilife_v2_rooms_verify_expenses_'))throw new RuntimeException('test DB only');
    $s=database()->prepare($argv[2]);$s->execute(json_decode($argv[3],true));echo json_encode($s->fetchAll());
    '''
    return json.loads(subprocess.check_output([args.php,'-r',php,str(root),sql,json.dumps(params)]))

def snapshot():
    """各テーブルの全行をハッシュ化し、試験前後の実データが不変であることを確認する。"""
    php=r'''
    define('PROJECT_ROOT',$argv[1]);require PROJECT_ROOT.'/app/helpers/env.php';loadEnv(PROJECT_ROOT.'/.env');require PROJECT_ROOT.'/config/database.php';$p=database();$out=[];
    foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){$rows=$p->query('SELECT * FROM '.$table.' ORDER BY '.($table==='room_settlements'?'room_id':'id'))->fetchAll();$out[$table]=hash('sha256',serialize($rows));}echo json_encode($out);
    '''
    return json.loads(subprocess.check_output([args.php,'-r',php,str(root)]))

import tempfile
assert os.environ['DB_NAME']=='oshilife_v2_rooms_verify_expenses_chat_20261004'
a,b,c,anonymous=Client(),Client(),Client(),Client();before=snapshot()
try:
    aid=a.register_login(emails[0]);bid=b.register_login(emails[1]);cid=c.register_login(emails[2])
    room=post(a,'rooms/create',{'name':'トーク検証'},201)['id'];other=post(c,'rooms/create',{'name':'別ルーム'},201)['id']
    link=post(a,'rooms/links/create',{'room_id':room},201);token=urllib.parse.parse_qs(urllib.parse.urlparse(link['url']).query)['token'][0];post(b,'rooms/links/join',{'token':token})
    post(a,'rooms/expenses/create',{'room_id':room,'title':'会計保持確認','category':'accommodation','paid_by_user_id':aid,'total_amount':1000,'expense_date':'2026-10-04','shares':[{'user_id':aid,'share_amount':1000}]},201)
    summary=get(a,'rooms/expenses/settlement-summary',room_id=room)
    post(a,'rooms/expenses/mark-settled',{'room_id':room,'confirmation_token':summary['confirmation_token']})
    money_tables=['room_expenses','room_expense_members','room_settlements','expenses','savings']
    money_before={t:snapshot()[t] for t in money_tables}
    def messages(client=a,**kw):return get(client,'rooms/messages/list',room_id=room,**kw)
    def send(client,text,code=201):return post(client,'rooms/messages/send',{'room_id':room,'message':text},code)
    assert messages()['messages']==[]
    first=send(a,'ホテル予約したよ！🎵\nhttps://example.test')['id']
    second=send(b,'ありがとう😊')['id']
    rows=messages()['messages'];assert len(rows)==2 and rows[0]['id']==first and rows[0]['is_me'] and rows[1]['is_me'] is False
    assert rows[0]['message']=='ホテル予約したよ！🎵\nhttps://example.test' and rows[0]['created_at'] and rows[0]['display_name_snapshot']=='検証 💎'
    assert messages(b)['messages'][0]['is_me'] is False
    assert [x['id'] for x in messages(after_id=first)['messages']]==[second]
    assert messages(after_id=second)['messages']==[]
    send(a,'舞'*1000);send(a,'😊'*1000);send(a,'a'*1001,422)
    for invalid in ['', ' 　\n\t', '\u200b', None, ['不正']]:send(a,invalid,422)
    a.call('api/rooms/messages/send.php','POST',{'room_id':room,'message':'CSRF'},status=419,token=False)
    post(a,'rooms/messages/send',{'room_id':room,'message':'偽装','user_id':bid},422)
    a.call('api/rooms/messages/send.php',status=405)
    for param in ['after_id[]=1','after_id=-1','after_id=1.5','known_ids[]=1','known_ids=1,no','room_id[]=1']:
        a.call('api/rooms/messages/list.php?room_id='+str(room)+'&'+param,status=422)
    a.call('api/rooms/messages/list.php?room_id='+str(room)+'&known_ids='+','.join(['1']*101),status=422)
    for client in [c,anonymous]:
        code=401 if client is anonymous else 404
        client.call('api/rooms/messages/list.php?room_id='+str(room),status=code)
        send(client,'権限なし',code)
    a.call('api/rooms/messages/list.php?room_id='+str(other),status=404)
    foreign=post(c,'rooms/messages/send',{'room_id':other,'message':'他ルーム'},201)['id']
    post(a,'rooms/messages/delete',{'room_id':room,'id':foreign},404)
    post(a,'rooms/messages/delete',{'room_id':room,'id':second},403) # ownerでも他人の削除不可
    post(b,'rooms/messages/delete',{'room_id':room,'id':first},403)
    post(a,'rooms/messages/delete',{'room_id':room,'id':first});post(a,'rooms/messages/delete',{'room_id':room,'id':first})
    row=messages()['messages'][0];assert row['status']=='deleted' and row['message'] is None
    assert messages(after_id=second,known_ids=str(first))['deleted_ids']==[first]
    assert query('SELECT message FROM room_messages WHERE id=?',[first])[0]['message'].startswith('ホテル')
    original=messages()['messages'][1]['display_name_snapshot']
    query('UPDATE users SET display_name=? WHERE id=?',['改名後',bid]);assert messages()['messages'][1]['display_name_snapshot']==original
    # 全ルーム合計30秒20件。削除も回数に含め、別ルームへ移っても制限をすり抜けない。
    count=int(query('SELECT COUNT(*) AS n FROM room_messages WHERE user_id=?',[aid])[0]['n'])
    for n in range(20-count):send(a,'連投検証 '+str(n))
    send(a,'制限',429)
    extra=post(a,'rooms/create',{'name':'連投別ルーム'},201)['id']
    post(a,'rooms/messages/send',{'room_id':extra,'message':'別でも制限'},429)
    query('UPDATE room_messages SET created_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE user_id=?',[aid])
    send(a,'時間経過後')
    # 別ルームへ同時送信しても、同一ユーザーの30秒20件を超えない。
    from concurrent.futures import ThreadPoolExecutor
    query('UPDATE room_messages SET created_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE user_id=?',[aid])
    php_parallel=r"""
    define('PROJECT_ROOT',$argv[1]);require PROJECT_ROOT.'/app/helpers/env.php';loadEnv(PROJECT_ROOT.'/.env');require PROJECT_ROOT.'/config/database.php';
    if(env('DB_NAME')!=='oshilife_v2_rooms_verify_expenses_chat_20261004')throw new RuntimeException('test DB only');
    require PROJECT_ROOT.'/app/services/room_message_service.php';
    try {sendRoomMessage((int)$argv[2],(int)$argv[3],'並列送信');echo 201;}
    catch(ScheduleOperationException $e){echo $e->getCode();}
    """
    def simultaneous(n):return int(subprocess.check_output([args.php,'-r',php_parallel,str(root),str(aid),str(room if n%2 else extra)]))
    with ThreadPoolExecutor(max_workers=12) as pool:results=list(pool.map(simultaneous,range(24)))
    assert results.count(201)==20 and results.count(429)==4,results
    query('UPDATE room_messages SET created_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE user_id=?',[aid])
    # 大量ログはテストDBでのみ用意。製品データ・採番に影響させない。
    for n in range(130):query('INSERT INTO room_messages(room_id,user_id,display_name_snapshot,message,created_at) VALUES(?,?,?,?,DATE_SUB(NOW(),INTERVAL 2 MINUTE))',[room,aid,'検証 💎','履歴'+str(n)+'あ'*50])
    initial=messages();assert len(initial['messages'])==100 and initial['messages'][-1]['message'].startswith('履歴129')
    for i in range(1,100):assert initial['messages'][i]['id']>initial['messages'][i-1]['id']
    state=snapshot();messages();assert snapshot()==state # GETは精算状態も含め一切保存しない
    if os.environ.get('OSHILIFE_CHAT_BROWSER')=='1':
        with tempfile.NamedTemporaryFile(mode='w',suffix='.json') as cfg:
            json.dump({'base':args.base,'roomId':room,'cookies':[{'name':x.name,'value':x.value,'path':x.path} for x in a.jar],'memberCookies':[{'name':x.name,'value':x.value,'path':x.path} for x in b.jar],'memberCsrf':b.csrf},cfg);cfg.flush()
            subprocess.run(['node',str(root/'tests/room_chat_browser.mjs'),cfg.name],check=True,timeout=180)
    post(b,'rooms/members/leave',{'room_id':room});send(b,'退出後',404)
    b.call('api/rooms/messages/list.php?room_id='+str(room),status=404)
    post(b,'rooms/messages/delete',{'room_id':room,'id':second},404)
    assert query('SELECT status FROM room_messages WHERE id=?',[second])[0]['status']=='active'
    if messages()['room_status']!='closed':post(a,'rooms/close',{'room_id':room})
    send(a,'終了後',409);assert messages()['room_status']=='closed'
    # 終了ルームは閲覧のみ。本人の削除も拒否する。
    last=next(m['id'] for m in reversed(messages()['messages']) if m['is_me']);post(a,'rooms/messages/delete',{'room_id':room,'id':last},409)
    assert all(snapshot()[t]==money_before[t] for t in money_tables),'トークが会計・精算状態を変更しています'
finally:
    db('cleanup');assert snapshot()==before,'既存データが変わっています'
print(f'Chat: {support.checks} HTTP checks PASS; posting, cursor, security, rate limit, closed/left, all existing rows unchanged.')
