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
    foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){$rows=$p->query('SELECT * FROM '.$table.' ORDER BY '.($table==='room_settlements'?'room_id':'id'))->fetchAll();$out[$table]=hash('sha256',serialize($rows));}echo json_encode($out);
    '''
    return json.loads(subprocess.check_output([args.php,'-r',php,str(root)]))

import tempfile
a,b=Client(),Client();before=snapshot()
try:
    aid=a.register_login(emails[0]);bid=b.register_login(emails[1])
    room=post(a,'rooms/create',{'name':'本人会計UI検証'},201)['id']
    link=post(a,'rooms/links/create',{'room_id':room},201);token=urllib.parse.parse_qs(urllib.parse.urlparse(link['url']).query)['token'][0];post(b,'rooms/links/join',{'token':token})
    ids=[]
    for label in ['PCホテル','スマホホテル']:
        ids.append(post(a,'rooms/expenses/create',{'room_id':room,'title':label,'category':'accommodation','total_amount':20000,'paid_by_user_id':bid,'expense_date':'2026-10-04','shares':[{'user_id':aid,'share_amount':10000},{'user_id':bid,'share_amount':10000}]},201)['id'])
    summary=get(a,'rooms/expenses/settlement-summary',room_id=room);post(a,'rooms/expenses/mark-settled',{'room_id':room,'confirmation_token':summary['confirmation_token']})
    with tempfile.NamedTemporaryFile(mode='w',suffix='.json') as cfg:
        json.dump({'base':args.base,'roomId':room,'ids':ids,'cookies':[{'name':x.name,'value':x.value,'path':x.path} for x in a.jar],'memberCookies':[{'name':x.name,'value':x.value,'path':x.path} for x in b.jar]},cfg);cfg.flush()
        subprocess.run(['node',str(root/'tests/room_personal_browser.mjs'),cfg.name],check=True,timeout=180)
finally:
    db('cleanup');assert snapshot()==before
print('Personal money UI fixtures cleaned; existing data unchanged.')
