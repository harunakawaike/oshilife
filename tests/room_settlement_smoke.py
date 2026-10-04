"""精算APIの認可・取消/編集再計算・退出者・異常データ・読み取り専用を専用DBで検証する。"""
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
    foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){$rows=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();$out[$table]=hash('sha256',serialize($rows));}echo json_encode($out);
    '''
    return json.loads(subprocess.check_output([args.php,'-r',php,str(root)]))

a,b,c,anonymous=Client(),Client(),Client(),Client();before=snapshot()
try:
    aid=a.register_login(emails[0]);bid=b.register_login(emails[1]);cid=c.register_login(emails[2])
    room=post(a,'rooms/create',{'name':'精算サマリー検証'},201)['id'];other=post(c,'rooms/create',{'name':'別ルーム'},201)['id']
    link=post(a,'rooms/links/create',{'room_id':room},201);token=urllib.parse.parse_qs(urllib.parse.urlparse(link['url']).query)['token'][0]
    post(b,'rooms/links/join',{'token':token})
    def summary(client=a,rid=room):return get(client,'rooms/expenses/settlement-summary',room_id=rid)
    def transfer(amount,frm=bid,to=aid):return [{'from_user_id':frm,'to_user_id':to,'amount':amount}]
    def create(payer,shares,title):
        data={'room_id':room,'title':title,'category':'accommodation','paid_by_user_id':payer,'total_amount':sum(shares.values()),'expense_date':'2026-10-04','note':'','shares':[{'user_id':uid,'share_amount':amount} for uid,amount in shares.items()]}
        return post(a,'rooms/expenses/create',data,201)['id'],data
    def detail(id):return get(a,'rooms/expenses/detail',room_id=room,id=id)['expense']
    def cancel(id):post(a,'rooms/expenses/cancel',{'room_id':room,'id':id,'version':detail(id)['version']})
    assert summary()=={'balances':[],'transfers':[]}
    hotel,hotel_data=create(aid,{aid:10000,bid:10000},'ホテル')
    assert summary()['transfers']==transfer(10000)
    traffic,traffic_data=create(bid,{aid:6000,bid:6000},'交通')
    both=summary();assert both['transfers']==transfer(4000) and summary(b)==both
    values={p['user_id']:p for p in both['balances']};assert values[aid]['paid_total']==20000 and values[aid]['share_total']==16000 and values[bid]['balance']==-4000
    assert sum(p['balance'] for p in both['balances'])==0
    _,html=a.call('room_detail.php?room_id='+str(room));assert '精算サマリー' in html and '¥4,000' in html and '→ あなた' in html
    _,html=b.call('room_detail.php?room_id='+str(room));assert 'あなた →' in html
    anonymous.call('api/rooms/expenses/settlement-summary.php?room_id='+str(room),status=401)
    c.call('api/rooms/expenses/settlement-summary.php?room_id='+str(room),status=404)
    a.call('api/rooms/expenses/settlement-summary.php?room_id='+str(other),status=404)
    assert summary(c,other)['transfers']==[]
    a.call('api/rooms/expenses/settlement-summary.php?room_id[]=1',status=422)
    post(a,'rooms/expenses/settlement-summary',{'room_id':room},405)
    # 取得操作でDBのどの行も変わらない。
    state=snapshot();summary();summary(b);a.call('room_detail.php?room_id='+str(room));assert snapshot()==state
    post(a,'rooms/expenses/update',{**traffic_data,'id':traffic,'version':detail(traffic)['version'],'total_amount':10000,'shares':[{'user_id':aid,'share_amount':5000},{'user_id':bid,'share_amount':5000}]})
    assert summary()['transfers']==transfer(5000)
    cancel(traffic);assert summary()['transfers']==transfer(10000)
    # 退出・改名でもsnapshotと負担を残す。退出本人は閲覧不可。
    old_name=next(x for x in summary()['balances'] if x['user_id']==bid)['display_name']
    query('UPDATE users SET display_name=? WHERE id=?',['別の名前',bid]);post(b,'rooms/members/leave',{'room_id':room})
    result=summary();assert result['transfers']==transfer(10000) and next(x for x in result['balances'] if x['user_id']==bid)['display_name']==old_name
    b.call('api/rooms/expenses/settlement-summary.php?room_id='+str(room),status=404)
    # 壊れた合計は全体をエラー扱い。UIの他領域は使えるが誤った精算額は表示しない。
    share_id=detail(hotel)['shares'][0]['id']
    query('UPDATE room_expense_members SET share_amount=9999 WHERE id=?',[share_id])
    try:
        a.call('api/rooms/expenses/settlement-summary.php?room_id='+str(room),status=503)
        _,html=a.call('room_detail.php?room_id='+str(room));part=html.split('class="room-settlement"',1)[1].split('</section>',1)[0]
        assert '整合性を確認できない' in part and '精算が必要な金額はありません' not in part and 'room-settlement-transfers' not in part
    finally:query('UPDATE room_expense_members SET share_amount=10000 WHERE id=?',[share_id])
    cancel(hotel);assert summary()['transfers']==[]
    # 支払のみ・負担のみの3名でも同じAPIで相殺。
    post(b,'rooms/links/join',{'token':token});post(c,'rooms/links/join',{'token':token})
    first,_=create(aid,{bid:4000,cid:2000},'3人')
    assert summary()['transfers']==[{'from_user_id':bid,'to_user_id':aid,'amount':4000},{'from_user_id':cid,'to_user_id':aid,'amount':2000}]
    cancel(first)
    x,_=create(aid,{cid:5000},'受取A');y,_=create(bid,{cid:3000},'受取B')
    assert summary()['transfers']==[{'from_user_id':cid,'to_user_id':aid,'amount':5000},{'from_user_id':cid,'to_user_id':bid,'amount':3000}]
    cancel(x);cancel(y)
    one,_=create(aid,{bid:1},'1円');assert summary()['transfers']==transfer(1)
    post(a,'rooms/close',{'room_id':room});assert summary(b)['transfers']==transfer(1)
finally:
    db('cleanup');assert snapshot()==before,'既存データに差分があります'
print(f'Settlement API: {support.checks} HTTP checks passed; read-only, authorization, recalculation, departed members, corruption handled.')
