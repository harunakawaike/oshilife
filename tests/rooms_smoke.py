"""新Step 3-2：ルーム・招待・参加・認可・複数イベントと、既存個人データ不変を検証する。専用DBだけで実行。"""
import event_test_support as support
from event_test_support import *
import os,tempfile
assert os.environ.get('DB_NAME','').startswith('oshilife_v2_rooms_verify_'),'専用検証DBだけで実行してください'

def snapshot():
    """全テーブルの既存行を比較し、個人会計や参加者へ副作用がないことを確認する。"""
    php=r'''
    define('PROJECT_ROOT',$argv[1]);require PROJECT_ROOT.'/app/helpers/env.php';loadEnv(PROJECT_ROOT.'/.env');require PROJECT_ROOT.'/config/database.php';$p=database();$out=[];
    foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){$rows=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();$out[$table]=hash('sha256',serialize($rows));}echo json_encode($out);
    '''
    return json.loads(subprocess.check_output([args.php,'-r',php,str(root)]))

def test_sql(query,values):
    """専用検証DBの試験用行のみ検査・期限変更する。本番DBでは実行しない。"""
    php=r'''
    define('PROJECT_ROOT',$argv[1]);require PROJECT_ROOT.'/app/helpers/env.php';loadEnv(PROJECT_ROOT.'/.env');require PROJECT_ROOT.'/config/database.php';
    if(!str_starts_with(env('DB_NAME'),'oshilife_v2_rooms_verify_'))throw new RuntimeException('test DB only');
    $s=database()->prepare($argv[2]);$s->execute(json_decode($argv[3],true));echo json_encode($s->fetchAll());
    '''
    return json.loads(subprocess.check_output([args.php,'-r',php,str(root),query,json.dumps(values)]))

a,b,c,anonymous=Client(),Client(),Client(),Client();before=snapshot()
names=['ルームA-'+key[:12],'<b>同じ表示名-'+key[:12],'<b>同じ表示名-'+key[:12]]
try:
    ids=[]
    for client,email,name in zip([a,b,c],emails,names):
        client.call('api/auth/me.php');post(client,'auth/register',{'display_name':name,'email':email,'password':password,'password_confirmation':password},201)
        ids.append(post(client,'auth/login',{'email':email,'password':password})['user']['id'])
    aid,bid,cid=ids
    oid=post(a,'oshis/create',{'name':'Payment-'+key,'oshi_type':'actor','emoji':'🎟️'},201)['oshi']['id']
    post(b,'oshis/follow',{'oshi_id':oid})
    vid=post(a,'venues/create',{'name':'Payment-'+key,'prefecture':'東京都','address':'テスト'},201)['id']
    def event(client,title,date,kind='live'):
        return post(client,'events/create',{'oshi_id':oid,'title':title,'event_type':kind,'venue_id':vid,'event_date':date,'status':'scheduled'},201)['event']['id']
    e1=event(a,'初日','2026-12-10');e2=event(a,'舞台','2026-12-11','sports');e3=event(b,'他人だけの管理','2026-12-12')
    post(a,'events/status/update',{'event_id':e1,'entry_method':'lottery','lottery_status':'won','participation_status':'confirmed','ticket_amount':12345,'ticket_payment_status':'paid','trip_type':'local','note':'秘密の管理メモ'})
    post(a,'events/companions/create',{'event_id':e1,'name':'秘密の同行者','note':'秘密の同行メモ'},201)
    baseline=snapshot();old_tables={k:v for k,v in baseline.items() if k not in ['rooms','room_members','room_events','room_invite_links']}
    anonymous.call('api/rooms/list.php',status=401)
    a.call('api/rooms/create.php','POST',{'name':'不正'},status=419,token=False)
    a.call('api/rooms/create.php',status=405)
    for raw in [{'name':''},{'name':[]},{'name':'x'*151},{'name':'room','owner_user_id':bid},{'name':'room','role':'owner'}]:post(a,'rooms/create',raw,422)
    room=post(a,'rooms/create',{'name':'東京ドーム <ルーム>'},201)['id'];other=post(c,'rooms/create',{'name':'別ルーム'},201)['id']
    def detail(client=a,rid=room):return get(client,'rooms/detail',room_id=rid)
    def issue(client=a,rid=room):return post(client,'rooms/links/create',{'room_id':rid},201)
    def token(link):return urllib.parse.parse_qs(urllib.parse.urlparse(link['url']).query)['token'][0]
    original=detail();assert original['room']['my_role']=='owner' and len(original['members'])==1
    assert any(r['id']==room for r in get(a,'rooms/list')['rooms'])
    for route in ['detail','members/list','events/list','links/list']:
        b.call('api/rooms/'+route+'.php?room_id='+str(room),status=404)
    b.call('room_detail.php?room_id='+str(room),status=404)
    post(b,'rooms/links/create',{'room_id':room},404)
    # 廃止ルートは個人の検索結果も過去の招待も返さず410。
    for route in ['search','list','create','accept','decline','cancel']:
        a.call('api/rooms/invitations/'+route+'.php','GET' if route in ['search','list'] else 'POST',status=410)
    link=issue();tok=token(link);assert re.fullmatch('[a-f0-9]{64}',tok)
    link2=issue();assert token(link2)!=tok
    saved=test_sql('SELECT * FROM room_invite_links WHERE id=?',[link['id']])[0]
    import hashlib
    assert saved['token_hash']==hashlib.sha256(tok.encode()).hexdigest() and tok not in json.dumps(saved)
    assert saved['max_uses'] is None and saved['used_count']==0
    expiry=datetime.strptime(saved['expires_at'],'%Y-%m-%d %H:%M:%S');created=datetime.strptime(saved['created_at'],'%Y-%m-%d %H:%M:%S')
    assert abs((expiry-created).total_seconds()-7*86400)<5
    listing=get(a,'rooms/links/list',room_id=room);assert 'token_hash' not in json.dumps(listing) and tok not in json.dumps(listing)
    a.call('api/rooms/links/create.php','POST',{'room_id':room},status=419,token=False)
    path='rooms/join.php?token='+tok
    response,html=anonymous.call(path);assert response.url.endswith('/login.php')
    assert len(detail()['members'])==1
    # パス指定を無視し、セッションに保存した招待トークンからのみ復帰先を決める。
    anonymous.call('api/auth/me.php')
    post(anonymous,'auth/login',{'email':emails[1],'password':'incorrect','return_to':'https://example.invalid'},401)
    logged=post(anonymous,'auth/login',{'email':emails[1],'password':password,'return_to':'https://example.invalid'})
    assert logged['redirect']==path
    _,html=anonymous.call(logged['redirect']);assert '参加しますか？' in html and '秘密の' not in html
    assert len(detail()['members'])==1
    cancel,_=anonymous.call('rooms.php');assert len(detail()['members'])==1
    preview=get(b,'rooms/links/preview',token=tok);assert set(preview)=={'room_id','room_name','owner_name','already_joined'}
    assert not preview['already_joined']
    post(b,'rooms/links/join',{'token':tok,'user_id':cid},422)
    post(b,'rooms/links/join',{'token':tok,'role':'owner'},422)
    post(b,'rooms/links/join',{'token':tok,'invited_user_id':cid},422)
    b.call('api/rooms/links/join.php','POST',{'token':tok},status=419,token=False)
    anonymous2=Client();post(anonymous2,'rooms/links/join',{'token':tok},401)
    post(b,'rooms/links/join',{'token':tok});post(b,'rooms/links/join',{'token':tok})
    post(a,'rooms/links/join',{'token':tok})
    assert len(detail(b)['members'])==2
    assert test_sql('SELECT used_count FROM room_invite_links WHERE id=?',[link['id']])[0]['used_count']==1
    for client in [a,b]:
        _,html=client.call(path);assert 'すでにこのルームに参加しています' in html and 'data-api="links/join"' not in html
    # 同じ表示名でも別アカウントとして参加できる。
    post(c,'rooms/links/join',{'token':tok});assert len(detail()['members'])==3
    for route,patch in [('update',{'name':'不正'}),('close',{}),('events/add',{'event_id':e1}),('events/remove',{'event_id':e1}),('links/create',{}),('links/revoke',{'id':link['id']})]:
        post(b,'rooms/'+route,{'room_id':room,**patch},403)
    b.call('api/rooms/links/list.php?room_id='+str(room),status=403)
    post(c,'rooms/links/revoke',{'room_id':other,'id':link['id']},404)
    post(a,'rooms/events/add',{'room_id':room,'event_id':e3},422)
    post(a,'rooms/events/add',{'room_id':room,'event_id':e1});post(a,'rooms/events/add',{'room_id':room,'event_id':e2})
    post(a,'rooms/events/add',{'room_id':room,'event_id':e1},409)
    assert len(detail(b)['events'])==2
    public=json.dumps(detail(b),ensure_ascii=False)
    for secret in ['秘密の管理メモ','秘密の同行者','秘密の同行メモ','ticket_amount','password_hash','email','12345']:assert secret not in public
    post(a,'rooms/events/remove',{'room_id':room,'event_id':e2});post(a,'rooms/events/add',{'room_id':room,'event_id':e2})
    post(a,'rooms/update',{'room_id':room,'name':'共有ルーム <script>alert(1)</script>'})
    _,html=b.call('room_detail.php?room_id='+str(room));assert '&lt;script&gt;' in html and '<script>alert(1)</script>' not in html
    member_before=test_sql('SELECT id FROM room_members WHERE room_id=? AND user_id=?',[room,bid])[0]['id']
    post(b,'rooms/members/leave',{'room_id':room});b.call('api/rooms/detail.php?room_id='+str(room),status=404)
    assert not any(r['id']==room for r in get(b,'rooms/list')['rooms'])
    post(b,'rooms/links/join',{'token':tok})
    assert test_sql('SELECT id FROM room_members WHERE room_id=? AND user_id=?',[room,bid])[0]['id']==member_before
    post(a,'rooms/members/leave',{'room_id':room},409)
    expired=issue();test_sql('UPDATE room_invite_links SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?',[expired['id']])
    _,html=c.call('rooms/join.php?token='+token(expired),status=410);assert '期限切れ' in html
    post(c,'rooms/links/join',{'token':token(expired)},410)
    post(a,'rooms/links/revoke',{'room_id':room,'id':link2['id']});post(a,'rooms/links/revoke',{'room_id':room,'id':link2['id']})
    _,html=c.call('rooms/join.php?token='+token(link2),status=410);assert '無効です' in html
    post(c,'rooms/links/join',{'token':token(link2)},410)
    for invalid in ['0'*64,'x',[],tok[:-1]+'z']:
        post(c,'rooms/links/join',{'token':invalid},404)
    # 再発行後は別トークン。元リンクの無効化が新リンクに波及しない。
    fresh=issue();assert token(fresh) not in [tok,token(link2)]
    assert get(c,'rooms/links/preview',token=token(fresh))['already_joined']
    if os.environ.get('OSHILIFE_BROWSER_TEST')=='1':
        with tempfile.NamedTemporaryFile(mode='w',suffix='.json') as cfg:
            json.dump({'base':args.base,'cookies':[{'name':x.name,'value':x.value,'path':x.path} for x in a.jar], 'memberCookies':[{'name':x.name,'value':x.value,'path':x.path} for x in b.jar], 'memberEmail':emails[1],'password':password,'eventId':e1,'roomId':room},cfg);cfg.flush()
            subprocess.run(['node',str(root/'tests/rooms_browser.mjs'),cfg.name],check=True,timeout=180)
    post(a,'rooms/close',{'room_id':room});post(a,'rooms/close',{'room_id':room})
    assert detail(b)['room']['status']=='closed' and len(detail(b)['events'])==2 and len(detail()['members'])==3
    _,html=c.call(path,status=409);assert 'このルームは終了しています' in html
    post(c,'rooms/links/join',{'token':tok},409)
    for route,patch in [('links/create',{}),('events/add',{'event_id':e1}),('events/remove',{'event_id':e1}),('update',{'name':'変更'})]:post(a,'rooms/'+route,{'room_id':room,**patch},409)
    post(a,'rooms/links/revoke',{'room_id':room,'id':link['id']})
    after=snapshot();assert all(after[t]==v for t,v in old_tables.items()),'ルーム操作が既存個人テーブル・旧招待履歴を変更した'
    _,html=a.call('room_detail.php?room_id='+str(room));assert 'room-user-search' not in html and '表示名を正確' not in html
    for unsafe in ['https://example.invalid','//example.invalid','rooms/join.php?token=bad']:
        normal=Client();normal.call('login.php?return_to='+urllib.parse.quote(unsafe));normal.call('api/auth/me.php')
        assert post(normal,'auth/login',{'email':emails[1],'password':password,'return_to':unsafe})['redirect']=='home.php'
finally:
    db('cleanup');assert snapshot()==before,'テスト前の既存データと一致しません'
print(f'Rooms links: {support.checks} HTTP checks passed; old invitation history and existing personal tables unchanged.')
