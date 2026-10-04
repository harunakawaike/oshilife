"""精算完了と自動解除・認可・失敗時ROLLBACKを専用DBで検証する。"""
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

a,b,c,anonymous=Client(),Client(),Client(),Client();before=snapshot()
try:
    aid=a.register_login(emails[0]);bid=b.register_login(emails[1]);cid=c.register_login(emails[2])
    room=post(a,'rooms/create',{'name':'精算済み検証'},201)['id'];other=post(c,'rooms/create',{'name':'他ルーム'},201)['id']
    link=post(a,'rooms/links/create',{'room_id':room},201);token=urllib.parse.parse_qs(urllib.parse.urlparse(link['url']).query)['token'][0]
    post(b,'rooms/links/join',{'token':token})
    def summary(client=a,rid=room):return get(client,'rooms/expenses/settlement-summary',room_id=rid)
    def mark(client=a,rid=room,code=200,t=None):return post(client,'rooms/expenses/mark-settled',{'room_id':rid,'confirmation_token':t or summary()['confirmation_token']},code)
    def data(title='ホテル'):
        return {'room_id':room,'title':title,'category':'accommodation','paid_by_user_id':aid,'total_amount':20000,'expense_date':'2026-10-04','shares':[{'user_id':aid,'share_amount':10000},{'user_id':bid,'share_amount':10000}]}
    def expense(eid):return get(a,'rooms/expenses/detail',room_id=room,id=eid)['expense']
    def settled():
        s=summary();assert s['settlement_status']=='settled' and s['settled_at'] and int(s['settled_by_user_id'])==aid
        return s
    mark(code=409) # 0件は確定不可
    eid=post(a,'rooms/expenses/create',data(),201)['id'];initial=summary()
    anonymous.call('api/rooms/expenses/mark-settled.php','POST',{'room_id':room},status=401)
    mark(b,code=403);mark(c,code=404);mark(rid=other,code=404)
    a.call('api/rooms/expenses/mark-settled.php?room_id='+str(room),status=405)
    # CSRFなし・ID改変を拒否。
    request=urllib.request.Request(args.base+'/api/rooms/expenses/mark-settled.php',data=json.dumps({'room_id':room}).encode(),headers={'Content-Type':'application/json'},method='POST')
    try:a.opener.open(request);raise AssertionError('CSRF accepted')
    except urllib.error.HTTPError as error:assert error.code==419
    mark(t='stale',code=409)
    mark();state=settled();assert summary(b)==state
    mark();assert summary()==state # 二重操作で時刻を更新しない
    _,html=a.call('room_detail.php?room_id='+str(room));assert '✅ 精算済み' in html and 'data-mark-settled' not in html
    _,html=b.call('room_detail.php?room_id='+str(room));assert '✅ 精算済み' in html and 'data-mark-settled' not in html
    a.call('room_expense_form.php?room_id='+str(room)+'&id='+str(eid));assert summary()==state
    a.call('room_expense_form.php?room_id='+str(room));assert summary()==state
    post(a,'rooms/expenses/create',data(),409);assert summary()==state # 警告確認なしの直接APIを拒否
    post(a,'rooms/expenses/create',{**data(),'total_amount':1,'settlement_token':state['confirmation_token']},422);assert summary()==state
    post(a,'rooms/expenses/update',{**data(),'id':eid,'version':0,'settlement_token':state['confirmation_token']},409);assert summary()==state
    # 状態解除のDB障害：支出本体・内訳の更新もROLLBACKされる。
    query("CREATE TRIGGER test_settled_failure BEFORE UPDATE ON room_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected test failure'")
    frozen=snapshot()
    try:
        post(a,'rooms/expenses/create',{**data(),'settlement_token':state['confirmation_token']},500);assert snapshot()==frozen
        post(a,'rooms/expenses/update',{**data('変更'),'id':eid,'version':expense(eid)['version'],'settlement_token':state['confirmation_token']},500);assert snapshot()==frozen
        post(a,'rooms/expenses/cancel',{'room_id':room,'id':eid,'version':expense(eid)['version'],'settlement_token':state['confirmation_token']},500);assert snapshot()==frozen
    finally:query('DROP TRIGGER test_settled_failure')
    eid2=post(a,'rooms/expenses/create',{**data(),'settlement_token':state['confirmation_token']},201)['id']
    s=summary();assert s['settlement_status']=='unsettled' and s['settled_at'] is None and s['settled_by_user_id'] is None
    mark(t=initial['confirmation_token'],code=409) # 確認後に支出が増えた
    mark();state=settled()
    post(a,'rooms/expenses/update',{**data('編集成功'),'id':eid,'version':expense(eid)['version'],'settlement_token':state['confirmation_token']})
    assert summary()['settlement_status']=='unsettled'
    mark();state=settled()
    post(a,'rooms/expenses/cancel',{'room_id':room,'id':eid2,'version':expense(eid2)['version'],'settlement_token':state['confirmation_token']})
    assert summary()['settlement_status']=='unsettled' and summary()['transfers'][0]['amount']==10000
    # memberも自分の支出を変更でき、同じ自動解除が適用される。
    own=post(b,'rooms/expenses/create',data('メンバー登録'),201)['id'];mark();state=settled()
    post(b,'rooms/expenses/update',{**data('メンバー編集'),'id':own,'version':expense(own)['version'],'settlement_token':state['confirmation_token']})
    assert summary()['settlement_status']=='unsettled'
    post(b,'rooms/expenses/cancel',{'room_id':room,'id':own,'version':expense(own)['version']})
    # 退出者の負担を残したままownerが確定できる。
    post(b,'rooms/members/leave',{'room_id':room});mark();assert settled()['transfers'][0]['from_user_id']==bid
    mark(b,code=404)
    # 終了ルームは精算表示を維持し、支出変更は既存どおり拒否。
    post(a,'rooms/close',{'room_id':room});mark();assert settled()['transfers']==initial['transfers']
    post(a,'rooms/expenses/create',{**data(),'settlement_token':summary()['confirmation_token']},409)
    # 差額0円かつ支出あり。closed前の確定を必須にしない。
    zero=post(a,'rooms/create',{'name':'ゼロ差額'},201)['id']
    post(a,'rooms/expenses/create',{**data(),'room_id':zero,'shares':[{'user_id':aid,'share_amount':20000}]},201)
    assert summary(rid=zero)['transfers']==[]
    post(a,'rooms/close',{'room_id':zero});mark(rid=zero,t=summary(rid=zero)['confirmation_token'])
    assert summary(rid=zero)['settlement_status']=='settled'
finally:
    db('cleanup');assert snapshot()==before,'既存データに差分があります'
print(f'Settled management: {support.checks} HTTP checks PASS; permissions, CSRF, reopen, rollback, zero balance, closed rooms.')
