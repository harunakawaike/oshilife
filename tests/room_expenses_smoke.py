"""Step 3-3：専用DBで共同支出の共有・金額・権限・履歴・ロールバックとPC/スマホを検証する。"""
import event_test_support as support
from event_test_support import *
import os,tempfile,copy
assert os.environ.get('DB_NAME','').startswith('oshilife_v2_rooms_verify_expenses_'),'専用検証DBだけで実行してください'

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
    foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){$rows=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();$out[$table]=hash('sha256',serialize($rows));}echo json_encode($out);
    '''
    return json.loads(subprocess.check_output([args.php,'-r',php,str(root)]))

a,b,c,anonymous=Client(),Client(),Client(),Client();before=snapshot()
names=['支払A-'+key[:8],'登録B-'+key[:8],'第三C-'+key[:8]]
try:
    ids=[]
    for client,email,name in zip([a,b,c],emails,names):
        client.call('api/auth/me.php');post(client,'auth/register',{'display_name':name,'email':email,'password':password,'password_confirmation':password},201)
        ids.append(post(client,'auth/login',{'email':email,'password':password})['user']['id'])
    aid,bid,cid=ids
    room=post(a,'rooms/create',{'name':'共同支出ルーム'},201)['id'];other=post(c,'rooms/create',{'name':'別ルーム'},201)['id']
    link=post(a,'rooms/links/create',{'room_id':room},201)
    tok=urllib.parse.parse_qs(urllib.parse.urlparse(link['url']).query)['token'][0]
    post(b,'rooms/links/join',{'token':tok})
    base={'room_id':room,'title':'ホテル <script>bad()</script>','category':'accommodation','total_amount':20000,'paid_by_user_id':aid,'expense_date':'2026-10-10','note':'みんなの予約\n共有メモ','shares':[{'user_id':aid,'share_amount':10000},{'user_id':bid,'share_amount':10000}]}
    def detail(client,id,rid=room):return get(client,'rooms/expenses/detail',room_id=rid,id=id)['expense']
    def listing(client=a,rid=room,**kw):return get(client,'rooms/expenses/list',room_id=rid,**kw)['expenses']
    def create(client=a,payload=None):return post(client,'rooms/expenses/create',payload or base,201)['id']
    def edit(client,id,patch={},rid=room,status=200):
        current=detail(a if rid==room else c,id,rid)
        data={**base,**patch,'room_id':rid,'id':id,'version':current['version']}
        return post(client,'rooms/expenses/update',data,status)
    def cancel(client,id,status=200):
        return post(client,'rooms/expenses/cancel',{'room_id':room,'id':id,'version':detail(a,id)['version']},status)
    personal_before=snapshot()
    anonymous.call('api/rooms/expenses/list.php?room_id='+str(room),status=401)
    a.call('api/rooms/expenses/create.php',status=405)
    a.call('api/rooms/expenses/create.php','POST',base,status=419,token=False)
    post(c,'rooms/expenses/create',base,404)
    for page in ['room_expenses.php','room_expense_form.php']:
        c.call(page+'?room_id='+str(room),status=404)
    c.call('api/rooms/expenses/members.php?room_id='+str(room),status=404)
    assert {m['user_id'] for m in get(a,'rooms/expenses/members',room_id=room)['members']}=={aid,bid}
    eid=create();value=detail(a,eid);other_value=detail(b,eid)
    assert value=={**other_value,'can_edit':True} and not other_value['can_edit']
    assert value['total_amount']==20000 and value['paid_by_user_id']==aid and value['created_by_user_id']==aid
    assert value['paid_by_name_snapshot']==names[0] and value['created_by_name_snapshot']==names[0]
    assert sum(s['share_amount'] for s in value['shares'])==20000
    assert len(listing(b))==1
    _,html=b.call('room_expense_detail.php?room_id='+str(room)+'&id='+str(eid));assert '&lt;script&gt;' in html and '<script>bad()' not in html and names[0] in html and names[1] in html
    c.call('api/rooms/expenses/detail.php?room_id='+str(room)+'&id='+str(eid),status=404)
    c.call('room_expense_detail.php?room_id='+str(room)+'&id='+str(eid),status=404)
    post(c,'rooms/expenses/update',{**base,'id':eid,'version':1},404)
    post(c,'rooms/expenses/cancel',{'room_id':room,'id':eid,'version':1},404)
    b.call('room_expense_form.php?room_id='+str(room)+'&id='+str(eid),status=403)
    post(b,'rooms/expenses/update',{**base,'id':eid,'version':1},403);cancel(b,eid,403)
    # 形式・総額・重複・所属を改ざんしてもPHPで拒否し、本体/内訳とも不変。
    state=snapshot()
    bads=[{'total_amount':0},{'total_amount':-1},{'total_amount':'1.5'},{'total_amount':'1e4'},{'total_amount':True},{'total_amount':20000.0},{'total_amount':1000000000},
        {'total_amount':[]},{'category':'unknown'},{'title':''},{'title':'x'*151},{'note':'x'*3001},{'expense_date':'2026-02-30'},
        {'shares':[]},{'shares':{}},{'shares':[None]},{'shares':[{'user_id':aid,'share_amount':-1}]},
        {'shares':[{'user_id':aid,'share_amount':'1.2'}]}, {'shares':[{'user_id':aid,'share_amount':True}]},
        {'shares':[{'user_id':aid,'share_amount':10000},{'user_id':aid,'share_amount':10000}]},
        {'shares':[{'user_id':aid,'share_amount':15000}]},{'paid_by_user_id':cid},
        {'shares':[{'user_id':cid,'share_amount':20000}]},{'created_by_user_id':bid},{'status':'cancelled'},
        {'paid_by_name_snapshot':'偽名'},{'user_id':bid},{'role':'owner'}]
    for patch in bads:post(a,'rooms/expenses/create',{**base,**patch},422)
    assert snapshot()==state
    mismatch=a.call('api/rooms/expenses/create.php','POST',{**base,'total_amount':30000},status=422);assert mismatch['message']=='負担額の合計が支出総額と一致していません'
    # 選択した人の0円を許可するが、未選択の人の行は作らない。支払者が負担者でなくても可。
    zero=create(payload={**base,'total_amount':1,'shares':[{'user_id':aid,'share_amount':1},{'user_id':bid,'share_amount':0}]})
    assert [s['share_amount'] for s in detail(a,zero)['shares']]==[1,0]
    payer_out=create(payload={**base,'total_amount':100,'shares':[{'user_id':bid,'share_amount':100}]})
    assert len(detail(a,payer_out)['shares'])==1
    for category in ['ticket','transportation','accommodation','food','goods','sightseeing','other']:
        x=create(payload={**base,'category':category});assert detail(a,x)['category']==category
    mine=create(b);assert detail(a,mine)['created_by_user_id']==bid and detail(a,mine)['created_by_name_snapshot']==names[1]
    edit(b,mine,{'title':'登録者の編集'});edit(a,mine,{'title':'ownerの編集'})
    stale=detail(a,mine)['version'];edit(b,mine,{'title':'先に保存'})
    post(a,'rooms/expenses/update',{**base,'id':mine,'version':stale},409)
    post(a,'rooms/expenses/cancel',{'room_id':room,'id':mine,'version':stale},409)
    post(a,'rooms/expenses/update',{**base,'id':mine,'version':detail(a,mine)['version'],'total_amount':30000},422)
    # 別ルームに属する支出IDを混ぜても検索できない。
    foreign=create(c,{**base,'room_id':other,'paid_by_user_id':cid,'shares':[{'user_id':cid,'share_amount':20000}]})
    for action in ['update','cancel']:
        post(a,'rooms/expenses/'+action,{**base,'id':foreign,'version':1},404)
    a.call('api/rooms/expenses/detail.php?room_id='+str(room)+'&id='+str(foreign),status=404)
    a.call('api/rooms/expenses/detail.php?room_id='+str(other)+'&id='+str(eid),status=404)
    post(c,'rooms/links/join',{'token':tok})
    edit(c,mine,{},status=403);cancel(c,mine,403)
    triple=create(payload={**base,'total_amount':30000,'shares':[{'user_id':aid,'share_amount':10000},{'user_id':bid,'share_amount':15000},{'user_id':cid,'share_amount':5000}]})
    assert [s['share_amount'] for s in detail(c,triple)['shares']]==[10000,15000,5000]
    # 改名後の取得・編集で過去の名前を勝手に書き換えない。
    query('UPDATE users SET display_name=? WHERE id=?',[names[1]+'改名',bid])
    edit(a,mine,{'note':'改名後も保存名を維持'})
    assert detail(a,mine)['created_by_name_snapshot']==names[1]
    assert next(s for s in detail(a,mine)['shares'] if s['user_id']==bid)['display_name_snapshot']==names[1]
    paid_b=create(payload={**base,'paid_by_user_id':bid})
    paid_snapshot=detail(a,paid_b)['paid_by_name_snapshot']
    post(b,'rooms/members/leave',{'room_id':room})
    assert bid not in {m['user_id'] for m in get(a,'rooms/expenses/members',room_id=room)['members']}
    post(a,'rooms/expenses/create',{**base,'paid_by_user_id':bid},422)
    post(a,'rooms/expenses/create',base,422)
    assert detail(a,paid_b)['paid_by_name_snapshot']==paid_snapshot
    assert next(s for s in detail(a,mine)['shares'] if s['user_id']==bid)['share_amount']==10000
    b.call('api/rooms/expenses/detail.php?room_id='+str(room)+'&id='+str(mine),status=404)
    post(b,'rooms/expenses/update',{**base,'id':mine,'version':detail(a,mine)['version']},404)
    edit(a,paid_b,{'paid_by_user_id':bid,'note':'過去の支払者と負担を維持'})
    edit(a,mine,{'note':'退出後も固定負担保持'})
    edit(a,mine,{'shares':[{'user_id':aid,'share_amount':20000}]},status=422)
    edit(a,mine,{'shares':[{'user_id':aid,'share_amount':5000},{'user_id':bid,'share_amount':15000}]},status=422)
    post(b,'rooms/links/join',{'token':tok})
    # 登録者/ownerによる取消は状態だけ変更し、内訳行とIDを保持。
    shares=detail(a,mine)['shares'];cancel(b,mine)
    assert detail(a,mine)['status']=='cancelled' and detail(a,mine)['shares']==shares
    assert mine not in {e['id'] for e in listing()} and mine in {e['id'] for e in listing(include_cancelled='1')}
    edit(a,mine,{},status=409)
    owner_cancel=create(b);cancel(a,owner_cancel)
    # 本体INSERT/UPDATEの後、内訳で故意にDB例外を起こし、変更が全て戻ることを確認。
    for action,trigger_event in [('create','INSERT'),('update','UPDATE')]:
        state=snapshot()
        query("CREATE TRIGGER step33_test_failure BEFORE "+trigger_event+" ON room_expense_members FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test rollback'")
        try:
            if action=='create':post(a,'rooms/expenses/create',base,500)
            else:post(a,'rooms/expenses/update',{**base,'id':eid,'version':detail(a,eid)['version'],'title':'ロールバック対象'},500)
        finally:query('DROP TRIGGER step33_test_failure')
        assert snapshot()==state,action+' did not roll back'
    if os.environ.get('OSHILIFE_BROWSER_TEST')=='1':
        with tempfile.NamedTemporaryFile(mode='w',suffix='.json') as cfg:
            json.dump({'base':args.base,'cookies':[{'name':x.name,'value':x.value,'path':x.path} for x in a.jar], 'memberCookies':[{'name':x.name,'value':x.value,'path':x.path} for x in b.jar], 'roomId':room,'userIds':ids},cfg);cfg.flush()
            subprocess.run(['node',str(root/'tests/room_expenses_browser.mjs'),cfg.name],check=True,timeout=180)
    # closedルームは閲覧のみ。
    post(a,'rooms/close',{'room_id':room})
    assert detail(c,eid)['can_edit'] is False
    post(a,'rooms/expenses/create',base,409)
    post(a,'rooms/expenses/update',{**base,'id':eid,'version':detail(a,eid)['version']},409)
    cancel(a,eid,409)
    a.call('room_expense_form.php?room_id='+str(room),status=409)
    after=snapshot()
    for table in ['expenses','savings','user_settings','event_participants','events','schedules','user_event_status','todos','trips','transportations','accommodations']:
        assert after[table]==personal_before[table],table+' unexpectedly changed'
finally:
    query('DROP TRIGGER IF EXISTS step33_test_failure')
    db('cleanup');assert snapshot()==before,'既存データがテスト開始時と一致しません'
print(f'Room expenses: {support.checks} HTTP checks passed; transaction failures rolled back; private money/event data unchanged.')
