"""共同支出の本人会計反映・権限・差分・再反映・既存データ保持を専用DBで検証する。"""
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

a,b,c=Client(),Client(),Client();before=snapshot()
try:
    aid=a.register_login(emails[0]);bid=b.register_login(emails[1]);cid=c.register_login(emails[2])
    room=post(a,'rooms/create',{'name':'個人反映検証'},201)['id'];other=post(c,'rooms/create',{'name':'他ルーム'},201)['id']
    link=post(a,'rooms/links/create',{'room_id':room},201);token=urllib.parse.parse_qs(urllib.parse.urlparse(link['url']).query)['token'][0];post(b,'rooms/links/join',{'token':token})
    def create(cat='accommodation',parts=None):
        parts=parts or {aid:10000,bid:10000}
        raw={'room_id':room,'title':'ホテル','category':cat,'paid_by_user_id':bid,'total_amount':sum(parts.values()),'expense_date':'2026-10-04','shares':[{'user_id':u,'share_amount':v} for u,v in parts.items()]}
        return post(a,'rooms/expenses/create',raw,201)['id'],raw
    def preview(client,eid):return get(client,'rooms/expenses/personal',room_id=room,id=eid)
    def reflect(client,eid,action='create',code=None,t=None):
        return post(client,'rooms/expenses/personal',{'room_id':room,'id':eid,'action':action,'confirmation_token':t or preview(client,eid)['confirmation_token']},code or (201 if action=='create' else 200))
    def mark():
        summary=get(a,'rooms/expenses/settlement-summary',room_id=room)
        post(a,'rooms/expenses/mark-settled',{'room_id':room,'confirmation_token':summary['confirmation_token']})
    def source(eid):return get(a,'rooms/expenses/detail',room_id=room,id=eid)['expense']
    eid,raw=create();assert preview(a,eid)['can_create'] is False
    reflect(a,eid,code=409);mark();assert preview(a,eid)['can_create']
    post(a,'rooms/expenses/personal',{'room_id':room,'id':eid,'action':'create','user_id':bid},422)
    c.call('api/rooms/expenses/personal.php?room_id='+str(room)+'&id='+str(eid),status=404)
    post(c,'rooms/expenses/personal',{'room_id':room,'id':eid,'action':'create'},404)
    post(a,'rooms/expenses/personal',{'room_id':other,'id':eid,'action':'create'},404)
    a.call('api/rooms/expenses/personal.php','POST',{'room_id':room,'id':eid,'action':'create'},status=419,token=False)
    oldtoken=preview(a,eid)['confirmation_token'];one=reflect(a,eid)['expense_id'];two=reflect(b,eid)['expense_id']
    assert one!=two
    record=get(a,'money/expenses/detail',id=one)['record'];assert record['amount']=='10000.00' and record['source_type']=='room_expense' and record['source_id']==eid and record['event_id'] is None
    assert record['special_effect_eligible']==0 and record['category']=='accommodation'
    reflect(a,eid,code=409,t=oldtoken);assert preview(a,eid)['existing']['id']==one
    # 個人側の編集・削除は既存APIを維持。反映元に依存せず本人だけが編集できる。
    post(a,'money/expenses/update',{**record,'id':one,'amount':'9999.00'})
    assert 'amount' in preview(a,eid)['differences'];reflect(a,eid,'update');assert preview(a,eid)['existing']['amount']=='10000.00'
    stale=preview(a,eid)['confirmation_token']
    settled=get(a,'rooms/expenses/settlement-summary',room_id=room)
    changed={**raw,'id':eid,'version':source(eid)['version'],'settlement_token':settled['confirmation_token'],'total_amount':22000,'category':'goods','expense_date':'2026-10-05','shares':[{'user_id':aid,'share_amount':12000},{'user_id':bid,'share_amount':10000}]}
    post(a,'rooms/expenses/update',changed)
    p=preview(a,eid);assert p['existing']['amount']=='10000.00' and p['settlement_status']=='unsettled'
    assert set(['amount','category','expense_date','special_effect_eligible']).issubset(p['differences'])
    reflect(a,eid,'update',409);mark();reflect(a,eid,'update',409,t=stale);reflect(a,eid,'update')
    assert preview(a,eid)['existing']['amount']=='12000.00' and preview(a,eid)['existing']['special_effect_eligible']==1
    assert preview(b,eid)['existing']['amount']=='10000.00' and preview(b,eid)['existing']['category']=='accommodation' # 他人を更新しない
    # 元支出取消では個人会計を消さない。
    summary=get(a,'rooms/expenses/settlement-summary',room_id=room)
    post(a,'rooms/expenses/cancel',{'room_id':room,'id':eid,'version':source(eid)['version'],'settlement_token':summary['confirmation_token']})
    assert preview(a,eid)['existing'] and 'cancelled' in preview(a,eid)['differences']
    _,html=a.call('room_detail.php?room_id='+str(room));assert '取消済みの共同支出がお金管理に反映されています' in html
    reflect(a,eid,'delete');assert preview(a,eid)['existing'] is None and not preview(a,eid)['can_create']
    # 7カテゴリを既存定義へマッピング。支払者本人も総額でなく自分の負担だけ。
    mapping={'ticket':('live_ticket',1),'goods':('goods',1),'transportation':('transportation',0),'accommodation':('accommodation',0),'food':('food',0),'sightseeing':('sightseeing',0),'other':('other_trip',0)}
    ids=[]
    for cat in mapping:
        item,_=create(cat);ids.append((cat,item))
    zero,_=create(parts={aid:0,bid:1000});missing,_=create(parts={bid:1000});mark()
    assert not preview(a,zero)['can_create'] and not preview(a,missing)['can_create']
    for cat,item in ids:
        exp=reflect(b,item)['expense_id'];v=preview(b,item)['existing'];assert v['amount']=='10000.00' and (v['category'],v['special_effect_eligible'])==mapping[cat]
    special=get(b,'money/special-effects',year=2026)['eligible_amount'];assert special=='20000.00'
    item=ids[0][1];exp=reflect(a,item)['expense_id'];post(a,'money/expenses/delete',{'id':exp});assert preview(a,item)['can_create'];reflect(a,item)
    # 制約違反を注入しても個人支出の中途半端な登録・精算解除は起こらない。
    pending=ids[1][1];frozen=snapshot()
    query("CREATE TRIGGER test_personal_failure BEFORE INSERT ON expenses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test failure'")
    try:reflect(a,pending,code=500);assert snapshot()==frozen
    finally:query('DROP TRIGGER test_personal_failure')
    # 関連イベントが一意かどうか。同じ推しでも複数なら推測しない。
    oid=post(a,'oshis/create',{'name':'Payment-'+key,'oshi_type':'group','emoji':'🎵'},201)['oshi']['id']
    vid=post(a,'venues/create',{'name':'Payment-'+key,'prefecture':'東京都'},201)['id']
    events=[]
    for n in [1,2]:events.append(post(a,'events/create',{'oshi_id':oid,'venue_id':vid,'title':'Payment-'+key+str(n),'event_type':'live','event_date':f'2027-02-{16+n}','start_time':'18:00','status':'scheduled'},201)['event']['id'])
    post(a,'rooms/events/add',{'room_id':room,'event_id':events[0]});assert preview(a,pending)['values']['event_id']==events[0]
    assert preview(b,pending)['values']['event_id'] is None # bは推し未登録、または既存リンクはNULLを保持
    reflect(a,pending);assert preview(a,pending)['existing']['oshi_id']==oid
    post(a,'rooms/events/add',{'room_id':room,'event_id':events[1]});assert preview(a,ids[2][1])['values']['event_id'] is None
    assert preview(a,pending)['values']['event_id']==events[0] # 保存済み関連は勝手に変えない
    post(b,'rooms/members/leave',{'room_id':room});b.call('api/rooms/expenses/personal.php?room_id='+str(room)+'&id='+str(item),status=404)
    post(b,'rooms/expenses/personal',{'room_id':room,'id':item,'action':'create'},404)
    # 退出後も自分の個人支出の通常編集は可能。
    own=get(b,'money/expenses/detail',id=two)['record'];post(b,'money/expenses/update',{**own,'id':two,'title':'本人のメモ用支出'})
    post(a,'rooms/close',{'room_id':room});assert preview(a,ids[2][1])['can_create'];reflect(a,ids[2][1])
finally:
    db('cleanup');assert snapshot()==before,'元データが変わりました'
print(f'Room personal money: {support.checks} HTTP checks PASS; shares, mapping, permissions, differences, delete/reimport, rollback and existing data.')
