"""Apache上のPhase 6統合テスト。固有名の一時ユーザー2名とそのデータのみを作成・清掃する。"""
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
emails = [f'phase6-{key}-{i}@example.test' for i in range(3)]
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
            foreach(['expenses','savings','todos','trips','user_event_status','user_schedules'] as $table) { $s=$p->prepare('DELETE FROM '.$table.' WHERE user_id=?');$s->execute([$id]); }
            foreach(['notifications','reactions','correction_requests'] as $table) { $s=$p->prepare('DELETE child FROM '.$table.' child JOIN schedules s ON s.id=child.schedule_id WHERE s.created_by_user_id=?');$s->execute([$id]); }
        }
        foreach($ids as $id) {
            $s=$p->prepare('DELETE l FROM events l JOIN schedules s ON s.id=l.schedule_id WHERE s.created_by_user_id=?');$s->execute([$id]);
            $s=$p->prepare('DELETE FROM schedules WHERE created_by_user_id=?');$s->execute([$id]);
            $s=$p->prepare('DELETE FROM user_oshis WHERE user_id=?');$s->execute([$id]);
        }
        $s=$p->prepare('DELETE FROM oshis WHERE name IN (?,?)');$s->execute(['Phase6-'.$argv[6],'Phase6-'.$argv[6].'-second']);
        $s=$p->prepare('DELETE FROM venues WHERE name=?');$s->execute(['Phase6-'.$argv[6]]);
        foreach($ids as $id) {$s=$p->prepare('DELETE FROM users WHERE id=?');$s->execute([$id]);}
        $p->commit();
    }
    $out=[];foreach(['users','user_settings','oshis','members','user_oshis','schedules','schedule_members','schedule_sources','user_schedules','correction_requests','reactions','notifications','venues','events','user_event_status','todos','trips','transportations','accommodations','savings','expenses'] as $table) {
        $rows=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();$out[$table]=['count'=>count($rows),'hash'=>hash('sha256',serialize($rows))];
    }echo json_encode($out);
    '''
    return json.loads(subprocess.check_output([args.php,'-r',code,str(root),mode,*emails,key]))

def post(client,path,data,status=200):
    return client.call('api/'+path+'.php','POST',data,status=status).get('data')
def get(client,path,**params):
    return client.call('api/'+path+'.php?'+urllib.parse.urlencode(params))['data']
from decimal import Decimal
from datetime import datetime,timezone,timedelta
now=datetime.now(timezone.utc).astimezone(timezone(timedelta(hours=9)))
year=now.year;today=now.date().isoformat();prior=f'{year-1}-12-31';jan=f'{year}-01-01';june=f'{year}-06-30'
a,b,anonymous=Client(),Client(),Client();before=db('snapshot')
def amount(value):return Decimal(str(value))
def dashboard(client=a,**params):return get(client,'money/dashboard',year=year,**params)
def saving(value,date,oshi=None,**extra):return {'amount':str(value),'saving_date':date,'oshi_id':oshi,'note':'積立メモ',**extra}
def expense(value,date,oshi=None,category='goods',**extra):return {'title':'支出 <script>文字</script>','amount':str(value),'expense_date':date,'oshi_id':oshi,'category':category,'event_id':None,'trip_id':None,'note':'本人の支出メモ',**extra}
try:
    for path in ['dashboard','savings/list','expenses/list','monthly','categories','by-oshi','special-effects','source']:
        anonymous.call('api/money/'+path+'.php',status=401)
    aid,bid=a.register_login(emails[0]),b.register_login(emails[1])
    oid=post(a,'oshis/create',{'name':'Phase6-'+key,'oshi_type':'group','emoji':'💎'},201)['oshi']['id']
    oid2=post(a,'oshis/create',{'name':'Phase6-'+key+'-second','oshi_type':'group','emoji':'♡'},201)['oshi']['id']
    post(b,'oshis/follow',{'oshi_id':oid})
    assert amount(dashboard()['balance'])==0
    a.call('api/money/savings/create.php','POST',saving(1,today),status=419,token=False)
    post(a,'money/savings/create',saving(30000,prior),201)
    post(a,'money/expenses/create',expense(10000,prior,oid),201)
    common=post(a,'money/savings/create',saving(70000,jan),201)['record']['id']
    designated=post(a,'money/savings/create',saving(30000,jan,oid),201)['record']['id']
    goods=post(a,'money/expenses/create',expense(45000,jan,oid),201)['record']['id']
    fare=post(a,'money/expenses/create',expense(13000,june,oid2,'transportation'),201)['record']['id']
    result=dashboard();assert [amount(result[k]) for k in ['carryover','savings','expenses','balance','special_effect_eligible']]==[20000,100000,58000,62000,45000]
    assert [amount(x['amount']) for x in result['monthly']]==[45000,0,0,0,0,13000,0,0,0,0,0,0]
    effects=get(a,'money/special-effects',year=year);assert [effects['effects'][key]['shots'] for key in ['fire','co2','silver_tape']]==[450,150,11.25]
    assert effects['effects']['fire']['unit_price']==100
    assert '実際のライブ演出費とは異なります' in effects['notice']
    result=dashboard(oshi_id=oid);assert [amount(result[k]) for k in ['carryover','savings','expenses','balance','common_savings','common_savings_before_year']]==[-10000,30000,45000,-25000,70000,30000]
    assert amount(get(a,'money/special-effects',year=year,oshi_id=oid2)['eligible_amount'])==0
    assert amount(get(a,'money/dashboard',year=year+1)['carryover'])==62000
    assert get(b,'money/savings/list',year=year,user_id=aid)['items']==[]
    assert get(b,'money/expenses/list',year=year,user_id=aid)['items']==[]
    assert amount(dashboard(b,user_id=aid)['balance'])==0
    for kind,rid,payload in [('savings',designated,saving(1,jan,oid)),('expenses',goods,expense(1,jan,oid))]:
        b.call('api/money/'+kind+'/detail.php?id='+str(rid),status=404)
        post(b,'money/'+kind+'/update',{**payload,'id':rid},404)
        post(b,'money/'+kind+'/delete',{'id':rid},404)
        b.call(('saving' if kind=='savings' else 'expense')+'_form.php?id='+str(rid),status=404)
    for path in ['monthly','categories','by-oshi']:
        result=get(b,'money/'+path,year=year,user_id=aid)
        assert all(amount(row['amount'])==0 for row in result['items'])
    for payload in [saving(-1,today),saving('1.234',today),saving('',today),saving('1e3',today),saving(1,'2026-02-30'),saving(1,'9999-01-01'),saving(1,today,99999999)]:post(a,'money/savings/create',payload,422)
    for category in ['transportation','accommodation','food','sightseeing','other_trip']:
        post(a,'money/expenses/create',expense(10,today,oid,category,special_effect_eligible=True),422)
    post(a,'money/expenses/create',expense(10,today,oid,'unknown'),422)
    post(a,'money/expenses/create',expense(10,today,oid,source_type='room_settlement',source_id=1),422)
    for query in ['year[]=2026','year=abc','year=9999','oshi_id[]=1','oshi_id=99999999']:
        a.call('api/money/dashboard.php?'+query,status=422)
    extra=post(a,'money/expenses/create',expense('0.25',june,oid,'cd',special_effect_eligible=False),201)['record']['id']
    assert amount(dashboard()['expenses'])==Decimal('58000.25')
    assert amount(dashboard()['special_effect_eligible'])==45000
    post(a,'money/expenses/update',{**expense('1.50',june,oid,'cd',special_effect_eligible=True),'id':extra})
    assert amount(dashboard()['special_effect_eligible'])==Decimal('45001.50')
    post(a,'money/expenses/delete',{'id':extra})
    post(a,'money/savings/update',{**saving(30001,jan,oid),'id':designated})
    assert amount(dashboard()['balance'])==62001
    post(a,'money/savings/update',{**saving(30000,jan,oid),'id':designated})
    removable=post(a,'money/savings/create',saving(1,today,oid),201)['record']['id'];post(a,'money/savings/delete',{'id':removable})
    assert amount(dashboard()['balance'])==62000
    # 登録年の外側にある記録は今年の支出・積立へ混ざらない。
    later=post(a,'money/expenses/create',expense(50,f'{year+1}-01-01',oid),201)['record']['id']
    assert amount(dashboard()['expenses'])==58000
    assert amount(get(a,'money/dashboard',year=year+1)['balance'])==61950
    vid=post(a,'venues/create',{'name':'Phase6-'+key,'prefecture':'東京都'},201)['id']
    live=post(a,'events/create',{'oshi_id':oid,'title':'Phase6 Live','venue_id':vid,'event_date':today,'status':'scheduled','start_time':'18:00','open_time':'17:00','end_time':'20:00'},201)['event']
    lid=live['id'];post(a,'events/status/update',{'event_id':lid,'application_status':'applied','lottery_status':'won','trip_type':'trip','note':''})
    trip=post(a,'trips/create',{'event_id':lid,'trip_name':'本人の遠征','departure_date':today,'return_date':today,'note':''})['id']
    transport={'trip_id':trip,'transport_type':'shinkansen','departure_place':'東京','arrival_place':'大阪','departure_at':today+'T08:00','arrival_at':today+'T11:00','amount':'24000.50','reservation_status':'reserved','payment_status':'paid','note':'交通メモ'}
    transportId=post(a,'trips/transport/create',transport)['id']
    hotel={'trip_id':trip,'hotel_name':'ホテル','check_in_at':today+'T15:00','check_out_at':(now.date()+timedelta(days=1)).isoformat()+'T10:00','amount':'8000','reservation_status':'reserved','payment_status':'paid','url':'https://example.com','note':'ホテルメモ'}
    hotelId=post(a,'trips/accommodation/create',hotel)['id']
    # 確認画面を開くだけでは支出は追加されない。
    assert amount(dashboard()['expenses'])==58000
    imported=[]
    for kind,sourceId in [('transportation',transportId),('accommodation',hotelId)]:
        b.call('api/money/source.php?source_type='+kind+'&source_id='+str(sourceId),status=404)
        b.call('expense_form.php?source_type='+kind+'&source_id='+str(sourceId),status=404)
        source=get(a,'money/source',source_type=kind,source_id=sourceId)['source']
        assert source['expense_id'] is None
        _,html=a.call('expense_form.php?source_type='+kind+'&source_id='+str(sourceId));assert 'Fatal error' not in html and 'Warning:' not in html
        assert amount(dashboard()['expenses'])==58000+sum(amount(item['amount']) for item in imported)
        post(b,'money/expenses/create',source,404)
        post(a,'money/expenses/create',{**source,'oshi_id':oid2},422)
        row=post(a,'money/expenses/create',source,201)['record'];imported.append(row)
        assert row['source_type']==kind and int(row['special_effect_eligible'])==0
        post(a,'money/expenses/create',source,409)
        assert int(get(a,'money/source',source_type=kind,source_id=sourceId)['source']['expense_id'])==int(row['id'])
    assert amount(dashboard()['expenses'])==Decimal('90000.50')
    assert amount(dashboard()['special_effect_eligible'])==45000
    # 元予約の金額変更・削除が会計を勝手に変えない。保存済み支出の編集は可能。
    post(a,'trips/transport/update',{**transport,'id':transportId,'amount':'30000'})
    assert amount(get(a,'money/expenses/detail',id=imported[0]['id'])['record']['amount'])==Decimal('24000.50')
    post(a,'trips/accommodation/delete',{'trip_id':trip,'id':hotelId})
    post(a,'money/expenses/update',{**imported[1],'amount':'7000'})
    assert amount(dashboard()['expenses'])==Decimal('89000.50')
    post(a,'money/expenses/delete',{'id':imported[0]['id']})
    source=get(a,'money/source',source_type='transportation',source_id=transportId)['source'];assert source['expense_id'] is None
    post(a,'money/expenses/create',source,201)
    post(a,'money/expenses/create',expense(1,today,oid2,event_id=lid),422)
    post(b,'money/expenses/create',expense(1,today,oid,event_id=lid,trip_id=trip),404)
    ticket=post(a,'money/expenses/create',expense(100,today,oid,'live_ticket',event_id=lid,trip_id=trip),201)['record']
    assert int(ticket['event_id'])==int(lid)
    # ランキングではなく名前順の本人内訳。12ヶ月とカテゴリの合計も年間額に一致する。
    result=dashboard()
    assert sum(amount(row['amount']) for row in result['monthly'])==amount(result['expenses'])
    assert sum(amount(row['amount']) for row in result['categories'])==amount(result['expenses'])
    assert sum(amount(row['amount']) for row in result['by_oshi'])==amount(result['expenses'])
    for path in ['money.php','saving_form.php','expense_form.php','saving_form.php?id='+str(common),'expense_form.php?id='+str(goods),'trip_detail.php?id='+str(trip),'home.php','profile.php','assets/css/money.css','assets/js/money.js']:
        _,html=a.call(path);assert 'Fatal error' not in html and 'Warning:' not in html
    _,html=a.call('expense_form.php?id='+str(goods));assert '&lt;script&gt;' in html
    _,html=a.call('profile.php');assert '/money.php' in html
    _,html=a.call('home.php');assert 'id="home-money"' in html and '¥30,000' not in html
    _,html=a.call('trip_detail.php?id='+str(trip));assert '✓ お金管理に反映済み' in html
    # 1年30件ずつのページ送りでも本人条件を維持。
    for i in range(31):post(a,'money/savings/create',saving(0,jan,oid),201)
    assert len(get(a,'money/savings/list',year=year)['items'])==30
    assert get(a,'money/savings/list',year=year)['has_more'] is True
    assert len(get(a,'money/savings/list',year=year,page=2)['items'])==3
finally:
    after=db('cleanup');assert before==after,'既存データが変化しました'
print(f'Phase 6: {checks} HTTP checks passed; all 21 existing table snapshots unchanged.')
