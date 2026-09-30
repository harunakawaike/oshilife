"""Apache上の支払い連携統合テスト。固有名の一時ユーザー2名とそのデータのみを作成・清掃する。"""
import argparse
import http.cookiejar
import json
import pathlib
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--base', default='http://localhost/oshilife-v2/public')
parser.add_argument('--php', default='/Applications/XAMPP/xamppfiles/bin/php')
args = parser.parse_args()
args.base = args.base.rstrip('/')
url = urllib.parse.urlparse(args.base)
if url.scheme != 'http' or url.hostname not in ('localhost', '127.0.0.1'):
    parser.error('ローカルHTTP環境だけを対象にしてください。')
root = pathlib.Path(__file__).resolve().parents[1]
key = uuid.uuid4().hex
emails = [f'payment-{key}-{i}@example.test' for i in range(3)]
password = 'Phase2-test-only-2026'
checks = 0

class Client:
    """CookieとCSRFをユーザーごとに分離する。"""
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = None

    def call(self, path, method='GET', data=None, status=200, token=True):
        """JSON/HTMLとHTTPステータスを検査する。"""
        global checks
        headers = {}
        body = None
        if data is not None:
            headers['Content-Type'] = 'application/json'
            body = json.dumps(data).encode()
        if token and self.csrf and method != 'GET':
            headers['X-CSRF-Token'] = self.csrf
        request = urllib.request.Request(args.base + '/' + path, method=method, data=body, headers=headers)
        try:
            response = self.opener.open(request)
        except urllib.error.HTTPError as error:
            response = error
        text = response.read().decode()
        assert response.status == status, (path, response.status, status, text)
        checks += 1
        if 'application/json' in response.headers.get('Content-Type', ''):
            payload = json.loads(text)
            if status < 400:
                assert payload['success'] is True
                self.csrf = payload['data'].get('csrf_token', self.csrf)
            else:
                assert payload['success'] is False
            return payload
        return response, text

    def register_login(self, email):
        """実際の登録APIとログインAPIを経由して本人を作る。"""
        self.call('api/auth/me.php')
        self.call('api/auth/register.php', 'POST', {'display_name': '検証 💎', 'email': email, 'password': password, 'password_confirmation': password}, status=201)
        data = self.call('api/auth/login.php', 'POST', {'email': email, 'password': password})
        return data['data']['user']['id']

def db(mode):
    """このテストの3人だけを削除し、全21テーブルを開始前のハッシュと比較する。"""
    code=r'''
    define('PROJECT_ROOT',$argv[1]);require PROJECT_ROOT.'/app/helpers/env.php';loadEnv(PROJECT_ROOT.'/.env');require PROJECT_ROOT.'/config/database.php';$p=database();
    $s=$p->prepare('SELECT id FROM users WHERE email IN (?,?,?)');$s->execute(array_slice($argv,3,3));$ids=$s->fetchAll(PDO::FETCH_COLUMN);
    if($argv[2]==='cleanup') {
        $p->beginTransaction();
        foreach($ids as $id) {
            foreach(['expenses','savings','todos','trips','user_live_status','user_schedules'] as $table) { $s=$p->prepare('DELETE FROM '.$table.' WHERE user_id=?');$s->execute([$id]); }
            foreach(['notifications','reactions','correction_requests'] as $table) { $s=$p->prepare('DELETE child FROM '.$table.' child JOIN schedules s ON s.id=child.schedule_id WHERE s.created_by_user_id=?');$s->execute([$id]); }
        }
        foreach($ids as $id) {
            $s=$p->prepare('DELETE l FROM live_events l JOIN schedules s ON s.id=l.schedule_id WHERE s.created_by_user_id=?');$s->execute([$id]);
            $s=$p->prepare('DELETE FROM schedules WHERE created_by_user_id=?');$s->execute([$id]);
            $s=$p->prepare('DELETE FROM user_oshis WHERE user_id=?');$s->execute([$id]);
        }
        $s=$p->prepare('DELETE FROM oshis WHERE name IN (?,?)');$s->execute(['Payment-'.$argv[6],'Payment-'.$argv[6].'-second']);
        $s=$p->prepare('DELETE FROM venues WHERE name=?');$s->execute(['Payment-'.$argv[6]]);
        foreach($ids as $id) {$s=$p->prepare('DELETE FROM users WHERE id=?');$s->execute([$id]);}
        $p->commit();
    }
    $out=[];foreach(['users','user_settings','oshis','members','user_oshis','schedules','schedule_members','schedule_sources','user_schedules','correction_requests','reactions','notifications','venues','live_events','user_live_status','todos','trips','transportations','accommodations','savings','expenses'] as $table) {
        $rows=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();$out[$table]=['count'=>count($rows),'hash'=>hash('sha256',serialize($rows))];
    }echo json_encode($out);
    '''
    return json.loads(subprocess.check_output([args.php,'-r',code,str(root),mode,*emails,key]))

def post(client,path,data,status=200):
    return client.call('api/'+path+'.php','POST',data,status=status).get('data')
def get(client,path,**params):
    return client.call('api/'+path+'.php?'+urllib.parse.urlencode(params))['data']
from datetime import datetime,timezone,timedelta
from decimal import Decimal
now=datetime.now(timezone.utc).astimezone(timezone(timedelta(hours=9)))
today=now.date().isoformat();a,b,anonymous=Client(),Client(),Client();before=db('snapshot')
def summary():return get(a,'money/dashboard',year=now.year)
def source(kind,source_id,client=a):return get(client,'money/source',source_type=kind,source_id=source_id)['source']
def live_status():return get(a,'lives/status',live_event_id=lid)['status']
def assert_button(page,kind,item_id,present):
    _,html=a.call(page)
    link=f'source_type={kind}&amp;source_id={item_id}'
    assert (link in html)==present,(page,kind,present)
    assert 'Warning:' not in html and 'Fatal error' not in html
try:
    anonymous.call('api/money/source.php?source_type=live_ticket&source_id=1',status=401)
    aid,bid=a.register_login(emails[0]),b.register_login(emails[1])
    oid=post(a,'oshis/create',{'name':'Payment-'+key,'oshi_type':'group','emoji':'💰'},201)['oshi']['id']
    post(b,'oshis/follow',{'oshi_id':oid})
    vid=post(a,'venues/create',{'name':'Payment-'+key,'prefecture':'東京都'},201)['id']
    live=post(a,'lives/create',{'oshi_id':oid,'title':'支払い連携ライブ','venue_id':vid,'event_date':today,'status':'scheduled'},201)['live'];lid=live['id']
    status={'live_event_id':lid,'application_status':'applied','lottery_status':'won','trip_type':'local','ticket_amount':'15000','note':''}
    post(a,'lives/status/update',status)
    sid=live_status()['id']
    assert live_status()['ticket_amount']=='15000.00'
    assert get(b,'lives/detail',id=lid)['live']['ticket_amount'] is None
    todos=get(a,'lives/todos/list',live_event_id=lid)['todos'];payment=next(row for row in todos if row['template_key']=='payment')['id']
    # 名前ではなく固定キーで識別するため、名前を編集しても動く。
    post(a,'lives/todos/update',{'id':payment,'title':'支払い済みをチェックする','due_date':today})
    src=source('live_ticket',sid);assert src['can_import'] is False and src['expense_id'] is None
    assert_button('live_detail.php?id='+str(lid),'live_ticket',sid,False)
    a.call('expense_form.php?source_type=live_ticket&source_id='+str(sid),status=409)
    post(a,'money/expenses/create',src,409)
    assert Decimal(summary()['expenses'])==0
    post(b,'lives/todos/toggle',{'id':payment,'is_completed':True},404)
    post(a,'lives/todos/toggle',{'id':payment,'is_completed':True})
    assert live_status()['ticket_payment_status']=='paid' and live_status()['ticket_paid_date']==today
    src=source('live_ticket',sid);assert src['can_import'] and src['special_effect_eligible'] and src['expense_date']==today
    assert_button('live_detail.php?id='+str(lid),'live_ticket',sid,True)
    assert Decimal(summary()['expenses'])==0
    b.call('api/money/source.php?source_type=live_ticket&source_id='+str(sid),status=404)
    post(b,'money/expenses/create',src,404)
    # 確認後に状態が変わった場合も、保存APIが最新状態で拒否する。
    post(a,'lives/todos/toggle',{'id':payment,'is_completed':False})
    post(a,'money/expenses/create',src,409)
    post(a,'lives/todos/toggle',{'id':payment,'is_completed':True})
    for patch in [{'ticket_amount':'0'},{'lottery_status':'lost'},{'ticket_amount':''}]:
        post(a,'lives/status/update',{**status,**patch});assert source('live_ticket',sid)['can_import'] is False
        post(a,'money/expenses/create',src,409)
    post(a,'lives/status/update',status)
    src=source('live_ticket',sid)
    post(a,'money/expenses/create',{**src,'special_effect_eligible':False},422)
    a.call('api/money/expenses/create.php','POST',src,status=419,token=False)
    _,html=a.call('expense_form.php?source_type=live_ticket&source_id='+str(sid));assert 'checked disabled' in html
    ticket=post(a,'money/expenses/create',src,201)['record'];eid=ticket['id']
    assert int(live_status()['ticket_expense_id'])==int(eid)
    assert ticket['category']=='live_ticket' and int(ticket['special_effect_eligible'])==1
    assert Decimal(summary()['expenses'])==15000 and Decimal(summary()['special_effect_eligible'])==15000
    post(a,'money/expenses/create',src,409)
    assert_button('live_detail.php?id='+str(lid),'live_ticket',sid,False)
    post(a,'lives/status/update',{**status,'ticket_amount':'16000'})
    _,html=a.call('live_detail.php?id='+str(lid));assert '反映済みの金額と現在の金額が異なります' in html
    assert source('live_ticket',sid)['amount_changed'] is True
    assert Decimal(get(a,'money/expenses/detail',id=eid)['record']['amount'])==15000
    post(a,'money/expenses/update',{**ticket,'amount':'16000'})
    assert source('live_ticket',sid)['amount_changed'] is False
    post(a,'money/expenses/delete',{'id':eid});assert live_status()['ticket_expense_id'] is None
    assert source('live_ticket',sid)['can_import']
    post(a,'money/expenses/create',source('live_ticket',sid),201)
    post(a,'lives/status/update',{**status,'ticket_amount':'16000','trip_type':'trip'})
    trip=post(a,'trips/create',{'live_event_id':lid,'trip_name':'連携検証遠征','departure_date':today,'return_date':today,'note':''})['id']
    transport={'trip_id':trip,'transport_type':'shinkansen','departure_place':'東京','arrival_place':'大阪','departure_at':today+'T09:00','arrival_at':today+'T12:00','amount':'24000','reservation_status':'reserved','note':''}
    hotel={'trip_id':trip,'hotel_name':'ホテル','check_in_at':today+'T15:00','check_out_at':(now.date()+timedelta(days=1)).isoformat()+'T10:00','amount':'8000','reservation_status':'reserved','note':''}
    for kind,api_kind,payload in [('transportation','transport',transport),('accommodation','accommodation',hotel)]:
        item=post(a,'trips/'+api_kind+'/create',payload)['id']
        src=source(kind,item);assert src['payment_status']=='unpaid' and not src['can_import']
        assert_button('trip_detail.php?id='+str(trip),kind,item,False)
        post(a,'money/expenses/create',src,409)
        for patch in [{'payment_status':'paid','reservation_status':'considering'},{'payment_status':'paid','reservation_status':'cancelled'},{'payment_status':'paid','amount':'0'}]:
            post(a,'trips/'+api_kind+'/update',{**payload,'id':item,**patch});assert not source(kind,item)['can_import']
            post(a,'money/expenses/create',src,409)
        paid={**payload,'id':item,'payment_status':'paid'}
        before_amount=summary()['expenses']
        post(a,'trips/'+api_kind+'/update',paid);src=source(kind,item)
        assert src['can_import'] and src['expense_date']==today and src['special_effect_eligible'] is False
        assert summary()['expenses']==before_amount
        assert_button('trip_detail.php?id='+str(trip),kind,item,True)
        _,html=a.call('travel_form.php?trip_id='+str(trip)+'&kind='+api_kind+'&id='+str(item));assert 'name="payment_status"' in html
        b.call('api/money/source.php?source_type='+kind+'&source_id='+str(item),status=404)
        post(b,'money/expenses/create',src,404)
        post(a,'money/expenses/create',{**src,'special_effect_eligible':True},422)
        record=post(a,'money/expenses/create',src,201)['record'];assert record['category']==kind and int(record['special_effect_eligible'])==0
        assert int(source(kind,item)['expense_id'])==int(record['id'])
        post(a,'money/expenses/create',src,409)
        assert_button('trip_detail.php?id='+str(trip),kind,item,False)
        post(a,'trips/'+api_kind+'/update',{**paid,'amount':'25000'})
        assert source(kind,item)['amount_changed'] is True
        assert get(a,'money/expenses/detail',id=record['id'])['record']['amount']==record['amount']
        post(a,'money/expenses/delete',{'id':record['id']});assert source(kind,item)['expense_id'] is None and source(kind,item)['can_import']
        post(a,'money/expenses/create',source(kind,item),201)
        # 支払済みのまま別項目だけ更新しても、支払日を失わない。
        post(a,'trips/'+api_kind+'/update',{**payload,'id':item,'amount':'25000'})
        assert source(kind,item)['payment_status']=='paid'
    assert Decimal(summary()['expenses'])==66000 and Decimal(summary()['special_effect_eligible'])==16000
    assert sum(Decimal(row['amount']) for row in summary()['monthly'])==66000
    assert sum(Decimal(row['amount']) for row in summary()['categories'])==66000
    # payment以外のTODOはチケットの支払い状態を変えない。
    other=next(row['id'] for row in get(a,'lives/todos/list',live_event_id=lid)['todos'] if row['template_key']=='hotel')
    post(a,'lives/todos/toggle',{'id':other,'is_completed':True});assert live_status()['ticket_payment_status']=='paid'
    post(a,'lives/todos/delete',{'id':payment});assert live_status()['ticket_payment_status']=='unpaid'
    last=live_status()['ticket_expense_id'];post(a,'money/expenses/delete',{'id':last})
    assert source('live_ticket',sid)['can_import'] is False
finally:
    after=db('cleanup');assert before==after,'既存データが変化しました'
print(f'Payment import: {checks} HTTP checks passed; all 21 existing table snapshots unchanged.')
