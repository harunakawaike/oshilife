"""Apache上のPhase 5統合テスト。固有名の一時ユーザー3名とそのデータのみを作成・清掃する。"""
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
emails = [f'phase5-{key}-{i}@example.test' for i in range(3)]
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
    """このテストの3人だけを削除し、全19テーブルを開始前のハッシュと比較する。"""
    code=r'''
    define('PROJECT_ROOT',$argv[1]);require PROJECT_ROOT.'/app/helpers/env.php';loadEnv(PROJECT_ROOT.'/.env');require PROJECT_ROOT.'/config/database.php';$p=database();
    $s=$p->prepare('SELECT id FROM users WHERE email IN (?,?,?)');$s->execute(array_slice($argv,3,3));$ids=$s->fetchAll(PDO::FETCH_COLUMN);
    if($argv[2]==='cleanup') {
        $p->beginTransaction();
        foreach($ids as $id) {
            foreach(['todos','trips','user_event_status','user_schedules'] as $table) { $s=$p->prepare('DELETE FROM '.$table.' WHERE user_id=?');$s->execute([$id]); }
            foreach(['notifications','reactions','correction_requests'] as $table) { $s=$p->prepare('DELETE child FROM '.$table.' child JOIN schedules s ON s.id=child.schedule_id WHERE s.created_by_user_id=?');$s->execute([$id]); }
        }
        foreach($ids as $id) {
            $s=$p->prepare('DELETE l FROM events l JOIN schedules s ON s.id=l.schedule_id WHERE s.created_by_user_id=?');$s->execute([$id]);
            $s=$p->prepare('DELETE FROM schedules WHERE created_by_user_id=?');$s->execute([$id]);
            $s=$p->prepare('DELETE FROM user_oshis WHERE user_id=?');$s->execute([$id]);
        }
        $s=$p->prepare('DELETE FROM oshis WHERE name=?');$s->execute(['Phase5-'.$argv[6]]);
        $s=$p->prepare('DELETE FROM venues WHERE name=?');$s->execute(['Phase5-'.$argv[6]]);
        foreach($ids as $id) {$s=$p->prepare('DELETE FROM users WHERE id=?');$s->execute([$id]);}
        $p->commit();
    }
    $out=[];foreach(['users','user_settings','oshis','members','user_oshis','schedules','schedule_members','schedule_sources','user_schedules','correction_requests','reactions','notifications','venues','events','user_event_status','todos','trips','transportations','accommodations'] as $table) {
        $rows=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();$out[$table]=['count'=>count($rows),'hash'=>hash('sha256',serialize($rows))];
    }echo json_encode($out);
    '''
    return json.loads(subprocess.check_output([args.php,'-r',code,str(root),mode,*emails,key]))

def post(client,path,data,status=200):
    return client.call('api/'+path+'.php','POST',data,status=status).get('data')
def get(client,path,**params):
    return client.call('api/'+path+'.php?'+urllib.parse.urlencode(params))['data']
from datetime import datetime,timezone,timedelta
now=datetime.now(timezone.utc).astimezone(timezone(timedelta(hours=9)))
today=now.date().isoformat();future=(now.date()+timedelta(days=18)).isoformat()
a,b,c,anonymous=Client(),Client(),Client(),Client();before=db('snapshot')
try:
    anonymous.call('api/events/list.php',status=401)
    aid,bid,cid=[client.register_login(email) for client,email in zip([a,b,c],emails)]
    oid=post(a,'oshis/create',{'name':'Phase5-'+key,'oshi_type':'group','emoji':'💎'},201)['oshi']['id']
    for client in [b,c]:post(client,'oshis/follow',{'oshi_id':oid})
    vid=post(a,'venues/create',{'name':'Phase5-'+key,'prefecture':'東京都','address':'会場住所','latitude':'35.5','longitude':'139.7'},201)['id']
    assert any(str(v['id'])==str(vid) for v in get(b,'venues/list')['venues'])
    data={'oshi_id':oid,'title':'LIVE '+key,'venue_id':vid,'event_date':future,'open_time':'17:00','start_time':'18:00','end_time':'20:00','status':'scheduled','note':'共有メモ'}
    a.call('api/events/create.php','POST',data,status=419,token=False)
    for patch in [{'event_date':'2026-02-30'},{'open_time':'19:00'},{'status':'deleted'},{'venue_id':99999999}]:post(a,'events/create',{**data,**patch},422)
    live=post(a,'events/create',data,201)['event'];lid=live['id'];sid=live['schedule_id']
    assert live['is_owner'] and live['days_until']==18
    assert live['event_type']=='live' and live['event_type_label']=='LIVE'
    # 旧APIは入出力の旧キーを保持し、認証・CSRF・保存は新APIと共通。
    anonymous.call('api/lives/list.php',status=401)
    old=get(a,'lives/detail',id=lid)['live'];assert old['id']==lid and old['event_type']=='live'
    assert 'lives' in get(a,'lives/list',oshi_id=oid)
    assert 'live' in get(a,'lives/next',oshi_id=oid)
    legacy_status={'live_event_id':lid,'application_status':'not_applied','lottery_status':'pending','trip_type':'','note':''}
    a.call('api/lives/status/update.php','POST',legacy_status,status=419,token=False)
    post(a,'lives/status/update',legacy_status)
    assert int(get(a,'lives/status',live_event_id=lid)['status']['live_event_id'])==int(lid)
    legacy_todo=post(a,'lives/todos/create',{'live_event_id':lid,'title':'互換TODO','due_date':''})['id']
    assert any(int(t['id'])==int(legacy_todo) for t in get(a,'lives/todos/list',live_event_id=lid)['todos'])
    post(a,'lives/todos/update',{'id':legacy_todo,'title':'互換TODO更新','due_date':''})
    post(a,'lives/todos/toggle',{'id':legacy_todo,'is_completed':True})
    post(a,'lives/todos/delete',{'id':legacy_todo})
    post(a,'lives/update',{**data,'id':lid})
    assert len(get(a,'lives/duplicate-check',oshi_id=oid,title=data['title'],event_date=future,venue_id=vid)['duplicates'])==1
    post(a,'lives/create',data,409)
    for oldpage,newpage in [('live.php','events.php'),('live_detail.php','event_detail.php'),('live_form.php','event_form.php')]:
        response,_=a.call(oldpage+'?id='+str(lid));assert newpage in response.url and 'id='+str(lid) in response.url
    assert get(b,'events/detail',id=lid)['event']['personal_note'] is None
    post(b,'events/update',{**data,'id':lid},404)
    duplicates=get(a,'events/duplicate-check',oshi_id=oid,title=data['title'],event_date=future,venue_id=vid)['duplicates'];assert len(duplicates)==1
    post(a,'events/create',data,409)
    second=post(a,'lives/create',{**data,'allow_duplicate':True},201)['live']
    assert second['id']!=lid
    assert len(get(a,'events/list',oshi_id=oid,period='upcoming')['events'])==2
    assert get(a,'events/list',oshi_id=oid,period='past')['events']==[]
    assert get(b,'events/next',oshi_id=oid)['event'] is None
    # 新着は通常予定とイベントをSQL側で分け、分類ごとに正しい件数を返す。
    regular=post(a,'schedules/create',{'oshi_id':oid,'title':'通常予定','schedule_date':future,'start_time':'18:00','end_time':'19:00','is_all_day':False,'category':'tv','visibility':'public','note':''},201)['schedule']['id']
    legacy=post(a,'schedules/create',{'oshi_id':oid,'title':'従来イベント','schedule_date':future,'start_time':'12:00','end_time':'13:00','is_all_day':False,'category':'live','visibility':'public','note':''},201)['schedule']['id']
    fresh=get(b,'schedules/unadded',oshi_id=oid,kind='schedule')
    assert fresh['total']==1 and int(fresh['schedules'][0]['id'])==int(regular)
    fresh=get(b,'schedules/unadded',oshi_id=oid,kind='event')
    assert fresh==get(b,'schedules/unadded',oshi_id=oid,kind='live')
    assert fresh['total']==3 and {int(row['id']) for row in fresh['schedules']}=={int(sid),int(second['schedule_id']),int(legacy)}
    assert get(a,'schedules/unadded',oshi_id=oid,kind='live')['total']==0
    b.call('api/schedules/unadded.php?kind=invalid',status=422)
    status={'event_id':lid,'application_status':'applied','lottery_status':'won','trip_type':'local','note':'B個人メモ','user_id':aid}
    post(b,'events/status/update',status)
    assert all(int(row['id'])!=int(sid) for row in get(b,'schedules/unadded',oshi_id=oid,kind='live')['schedules'])
    assert get(b,'events/status',event_id=lid)['status']['note']=='B個人メモ'
    assert get(a,'events/status',event_id=lid,user_id=bid)['status']['note']==''
    assert get(b,'events/next',oshi_id=oid)['event']['days_until']==18
    # 落選は本人のホームだけから除外し、一覧や他のユーザーの表示は残す。
    post(b,'events/status/update',{**status,'lottery_status':'lost'})
    assert get(b,'events/next',oshi_id=oid)['event'] is None
    assert any(int(row['id'])==int(lid) for row in get(b,'events/list',oshi_id=oid)['events'])
    assert int(get(a,'events/next',oshi_id=oid)['event']['id'])==int(lid)
    # 後続の参加公演があれば、落選公演を飛ばして次の候補を表示する。
    post(b,'events/status/update',{**status,'event_id':second['id'],'lottery_status':'pending','trip_type':None})
    assert int(get(b,'events/next',oshi_id=oid)['event']['id'])==int(second['id'])
    post(b,'events/status/update',{**status,'event_id':second['id'],'lottery_status':'lost','trip_type':None})
    post(b,'events/status/update',status)
    assert int(get(b,'events/next',oshi_id=oid)['event']['id'])==int(lid)
    todos=get(b,'events/todos/list',event_id=lid)['todos'];assert len(todos)==6
    assert get(a,'events/todos/list',event_id=lid,user_id=bid)['todos']==[]
    tid=todos[0]['id']
    for action,patch in [('update',{'title':'盗用','due_date':''}),('toggle',{'is_completed':True}),('delete',{})]:post(a,'events/todos/'+action,{'id':tid,**patch},404)
    post(b,'events/todos/update',{'id':tid,'title':'変更済み','due_date':future})
    post(b,'events/todos/toggle',{'id':tid,'is_completed':True})
    post(b,'events/status/update',{**status,'lottery_status':'pending'})
    post(b,'events/status/update',status)
    todos=get(b,'events/todos/list',event_id=lid)['todos'];assert len(todos)==6 and any(t['title']=='変更済み' and int(t['is_completed'])==1 for t in todos)
    post(b,'events/todos/delete',{'id':tid})
    post(b,'events/status/update',status);assert len(get(b,'events/todos/list',event_id=lid)['todos'])==5
    post(b,'events/status/update',{**status,'trip_type':'trip'})
    todos=get(b,'events/todos/list',event_id=lid)['todos'];assert len(todos)==7 and any(t['template_key']=='hotel' for t in todos)
    post(b,'events/status/update',status);assert len(get(b,'events/todos/list',event_id=lid)['todos'])==7
    post(b,'events/todos/create',{'event_id':lid,'title':'追加 TODO','due_date':'2026-02-30'},422)
    post(b,'events/todos/create',{'event_id':lid,'title':'追加 TODO','due_date':future})
    tripData={'event_id':lid,'trip_name':'B専用遠征','departure_date':today,'return_date':future,'note':'B秘密の遠征メモ'}
    post(b,'trips/create',tripData,409)
    post(b,'events/status/update',{**status,'trip_type':'trip'})
    tripId=post(b,'trips/create',tripData)['id']
    post(b,'trips/create',tripData,409)
    post(b,'trips/update',{**tripData,'id':tripId,'return_date':'2020-01-01'},422)
    post(b,'trips/update',{**tripData,'id':tripId,'trip_name':'B遠征変更'})
    a.call('api/trips/detail.php?id='+str(tripId),status=404)
    post(a,'trips/update',{**tripData,'id':tripId},404)
    transport={'trip_id':tripId,'transport_type':'shinkansen','departure_place':'東京','arrival_place':'新大阪','departure_at':today+'T10:00','arrival_at':today+'T12:30','amount':'14000.50','reservation_status':'reserved','note':'B交通秘密'}
    tr1=post(b,'trips/transport/create',transport)['id'];tr2=post(b,'trips/transport/create',{**transport,'transport_type':'local_train'})['id']
    post(b,'trips/transport/create',{**transport,'transport_type':'other'},422)
    post(b,'trips/transport/update',{**transport,'id':tr2,'transport_type':'other','transport_type_other':'フェリー'})
    post(b,'trips/transport/create',{**transport,'amount':'-1'},422)
    post(b,'trips/transport/create',{**transport,'arrival_at':today+'T09:00'},422)
    hotel={'trip_id':tripId,'hotel_name':'B専用ホテル','check_in_at':today+'T15:00','check_out_at':future+'T10:00','amount':'13000','reservation_status':'considering','address':'Bホテル住所','url':'https://example.com/reserve','note':'Bホテル秘密'}
    hotelId=post(b,'trips/accommodation/create',hotel)['id']
    post(b,'trips/accommodation/create',{**hotel,'url':'javascript:alert(1)'},422)
    post(b,'trips/accommodation/create',{**hotel,'check_out_at':today+'T15:00'},422)
    post(b,'trips/accommodation/update',{**hotel,'id':hotelId,'reservation_status':'reserved'})
    for kind,itemId,payload in [('transport',tr1,transport),('accommodation',hotelId,hotel)]:
        for action in ['create','update','delete']:post(a,'trips/'+kind+'/'+action,{**payload,'id':itemId},404)
    trip=get(b,'trips/detail',id=tripId)['trip'];assert len(trip['transportations'])==2 and len(trip['accommodations'])==1
    assert trip['transportations'][0]['amount']=='14000.50' and all(int(t['trip_id'])==int(tripId) for t in trip['todos'])
    # Cは同期解除した日時・タイトルを保持、Bは公開公演の更新を取得する。
    post(c,'events/status/update',{**status,'lottery_status':'pending','trip_type':None,'note':'C秘密'})
    post(c,'schedules/customize',{'schedule_id':sid,'title':'C専用','schedule_date':future,'start_time':'12:00','end_time':'13:00','is_all_day':False,'note':'Cメモ'})
    assert get(c,'events/todos/list',event_id=lid)['todos']==[]
    post(c,'events/status/update',{**status,'trip_type':'trip','note':'C秘密'})
    assert len(get(c,'events/todos/list',event_id=lid)['todos'])==8
    ctrip=post(c,'trips/create',{**tripData,'trip_name':'C遠征'})['id']
    for kind,itemId,payload in [('transport',tr1,transport),('accommodation',hotelId,hotel)]:
        for action in ['update','delete']:post(c,'trips/'+kind+'/'+action,{**payload,'id':itemId,'trip_id':ctrip},404)
    c.call('travel_form.php?trip_id='+str(ctrip)+'&kind=accommodation&id='+str(hotelId),status=404)
    post(a,'events/update',{**data,'id':lid,'title':'共有更新','event_date':today,'status':'postponed'})
    assert get(b,'schedules/detail',id=sid)['schedule']['title']=='共有更新'
    assert get(c,'schedules/detail',id=sid)['schedule']['title']=='C専用'
    assert get(b,'events/next',oshi_id=oid)['event']['days_until']==0
    post(a,'schedules/delete',{'schedule_id':sid},409)
    normal={**data,'schedule_id':sid,'schedule_date':today,'category':'live','visibility':'private','is_all_day':False,'status':'active'}
    post(a,'schedules/update',normal,409)
    proposal={'schedule_id':sid,'field_name':'title','new_value':'承認済公演','reason':'確認','source_url':''}
    rid=post(b,'schedules/corrections/create',proposal,201)['correction_id']
    post(a,'schedules/corrections/approve',{'correction_id':rid})
    assert get(b,'events/detail',id=lid)['event']['title']=='承認済公演'
    post(b,'schedules/corrections/create',{**proposal,'field_name':'category','new_value':'tv'},422)
    post(b,'schedules/corrections/create',{**proposal,'field_name':'start_time','new_value':'16:00'},422)
    for reaction in ['helped','thanks']:post(b,'schedules/reactions/toggle',{'schedule_id':sid,'reaction_type':reaction})
    calendar=get(b,'schedules/calendar',year=now.year,month=now.month,oshi_id=oid)['schedules'];assert sum(int(row['id'])==int(sid) for row in calendar)==1
    assert all(int(row['id'])!=int(sid) for row in get(b,'schedules/unadded',oshi_id=oid)['schedules'])
    for path in ['events.php','event_form.php','event_form.php?id='+str(lid),'event_detail.php?id='+str(lid),'home.php','schedule_detail.php?id='+str(sid)]:
        response,html=a.call(path);assert 'Fatal error' not in html and 'Warning:' not in html
    for path in ['trip_detail.php?id='+str(tripId),'travel_form.php?trip_id='+str(tripId)+'&kind=transport','travel_form.php?trip_id='+str(tripId)+'&kind=accommodation&id='+str(hotelId)]:
        response,html=b.call(path);assert 'Fatal error' not in html and 'Warning:' not in html
        a.call(path,status=404)
    _,html=b.call('trip_detail.php?id='+str(tripId));assert 'rel="noopener noreferrer"' in html
    assert 'B秘密' not in a.call('event_detail.php?id='+str(lid))[1]
    for path in ['assets/js/events.js','assets/css/events.css']:a.call(path)
    # 指定時だけ実ブラウザで確認。既存ユーザーのログイン情報は使わない。
    import os,tempfile
    if os.environ.get('OSHILIFE_BROWSER_TEST')=='1':
        with tempfile.NamedTemporaryFile(mode='w',suffix='.json') as config:
            json.dump({'base':args.base,'cookies':[{'name':c.name,'value':c.value,'path':c.path} for c in b.jar],
                'pages':['events.php','event_detail.php?id='+str(lid),'home.php','trip_detail.php?id='+str(tripId),'money.php']},config)
            config.flush()
            subprocess.run(['node',str(root/'tests/events_browser.mjs'),config.name],check=True,timeout=90)
    post(a,'events/update',{**data,'id':lid,'event_date':(now.date()-timedelta(days=1)).isoformat()})
    assert get(b,'events/next',oshi_id=oid)['event'] is None
    assert len(get(b,'events/list',oshi_id=oid,period='past')['events'])==1
    post(a,'events/update',{**data,'id':lid,'status':'cancelled'})
    assert get(b,'events/next',oshi_id=oid)['event'] is None
    assert get(b,'schedules/detail',id=sid)['schedule']['status']=='cancelled'
    post(a,'events/update',{**data,'id':lid,'status':'completed'})
    assert get(b,'events/next',oshi_id=oid)['event'] is None
    post(b,'trips/transport/delete',{'trip_id':tripId,'id':tr2})
    post(b,'trips/accommodation/delete',{'trip_id':tripId,'id':hotelId})
    assert len(get(b,'trips/detail',id=tripId)['trip']['transportations'])==1
    assert get(b,'trips/detail',id=tripId)['trip']['accommodations']==[]
finally:
    after=db('cleanup');assert before==after, '既存データが変化しました'
print(f'Phase 5: {checks} HTTP checks passed; all 19 existing table snapshots unchanged.')
