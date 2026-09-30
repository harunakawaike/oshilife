"""Apache上のPhase 4統合テスト。固有名の一時ユーザー3名とそのデータのみを作成・清掃する。"""
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
emails = [f'phase4-{key}-{i}@example.test' for i in range(3)]
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
    """一時ユーザーだけを対象に清掃・月境界の配置を行い、既存11テーブルの内容をハッシュで比較する。"""
    code = r'''
    define('PROJECT_ROOT',$argv[1]);
    require PROJECT_ROOT.'/app/helpers/env.php';
    require PROJECT_ROOT.'/config/database.php';
    loadEnv(PROJECT_ROOT.'/.env'); $pdo=database();
    $emails=[$argv[3],$argv[4],$argv[5]];
    $s=$pdo->prepare('SELECT id FROM users WHERE email IN (?,?,?) ORDER BY id');$s->execute($emails);$ids=$s->fetchAll(PDO::FETCH_COLUMN);
    if($argv[2]==='cleanup') {
        $pdo->beginTransaction();
        try {
            foreach($ids as $id) {
                foreach(['correction_requests','reactions','notifications'] as $table) {
                    $s=$pdo->prepare('DELETE child FROM '.$table.' child JOIN schedules s ON s.id=child.schedule_id WHERE s.created_by_user_id=?'); $s->execute([$id]);
                }
                $s=$pdo->prepare('DELETE FROM user_schedules WHERE user_id=?');$s->execute([$id]);
            }
            foreach($ids as $id) {
                $s=$pdo->prepare('DELETE FROM schedules WHERE created_by_user_id=?');$s->execute([$id]);
                $s=$pdo->prepare('DELETE FROM user_oshis WHERE user_id=?');$s->execute([$id]);
            }
            foreach($ids as $id) {
                $s=$pdo->prepare('DELETE m FROM members m JOIN oshis o ON o.id=m.oshi_id WHERE o.created_by_user_id=?');$s->execute([$id]);
                $s=$pdo->prepare('DELETE FROM oshis WHERE created_by_user_id=?');$s->execute([$id]);
                $s=$pdo->prepare('DELETE FROM users WHERE id=?');$s->execute([$id]);
            }
            $pdo->commit();
        } catch(Throwable $e) {$pdo->rollBack();throw $e;}
    }
    if($argv[2]==='boundaries') {
        $owner=$ids[0];
        // このテストの1人目が作った公開予定1件だけを、12月末と1月初にまたがるデータへ配置する。
        $s=$pdo->prepare("SELECT id FROM schedules WHERE created_by_user_id=? AND visibility='public' ORDER BY id LIMIT 1");$s->execute([$owner]);$sid=$s->fetchColumn();
        $s=$pdo->prepare("UPDATE schedules SET created_at='2025-12-31 23:59:59' WHERE id=?");$s->execute([$sid]);
        $s=$pdo->prepare("UPDATE user_schedules SET added_at='2025-12-31 23:59:59' WHERE schedule_id=? AND user_id=?");$s->execute([$sid,$ids[1]]);
        $s=$pdo->prepare("UPDATE user_schedules SET added_at='2026-01-01 00:00:00' WHERE schedule_id=? AND user_id=?");$s->execute([$sid,$ids[2]]);
        $s=$pdo->prepare("UPDATE reactions SET created_at=CASE WHEN reaction_type='helped' THEN '2025-12-31 23:59:59' ELSE '2026-01-01 00:00:00' END WHERE schedule_id=?");$s->execute([$sid]);
    }
    $result=[];
    foreach(['users','user_settings','oshis','members','user_oshis','schedules','schedule_members','schedule_sources','user_schedules','correction_requests','reactions','notifications'] as $table) {
        $rows=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
        $result[$table]=['count'=>count($rows),'hash'=>hash('sha256',serialize($rows))];
    }
    echo json_encode($result);
    '''
    return json.loads(subprocess.check_output([args.php,'-r',code,str(root),mode,*emails]))

from datetime import datetime, timezone, timedelta
now=datetime.now(timezone.utc).astimezone(timezone(timedelta(hours=9)))
a,b,c,anonymous=Client(),Client(),Client(),Client()
before=db('snapshot')
def call(client,path,method='GET',data=None,status=200):
    return client.call('api/'+path+'.php',method,data,status=status)['data'] if status<400 else client.call('api/'+path+'.php',method,data,status=status)
def propose(client,sid,field,value,status=201,**extra):
    return call(client,'schedules/corrections/create','POST',{'schedule_id':sid,'field_name':field,'new_value':value,'reason':'公式ページで確認 <script>test</script>','source_url':'https://example.com/source',**extra},status)
def review(client,rid,approve=True,status=200):
    return call(client,'schedules/corrections/'+('approve' if approve else 'reject'),'POST',{'correction_id':rid},status)
# APIの.phpはクエリより前に置く。
def get(client,path,**query):
    return client.call('api/'+path+'.php?'+urllib.parse.urlencode(query))['data']
def detail(client,sid):
    return get(client,'schedules/detail',id=sid)['schedule']
def toggle(client,sid,kind,status=200):
    return call(client,'schedules/reactions/toggle','POST',{'schedule_id':sid,'reaction_type':kind},status)
try:
    anonymous.call('api/reflections/monthly-summary.php',status=401)
    aid,bid,cid=a.register_login(emails[0]),b.register_login(emails[1]),c.register_login(emails[2])
    oid=a.call('api/oshis/create.php','POST',{'name':'Phase4-'+key,'oshi_type':'group','emoji':'💎'},status=201)['data']['oshi']['id']
    for user in [b,c]: user.call('api/oshis/follow.php','POST',{'oshi_id':oid})
    payload={'title':'共有テスト '+key,'oshi_id':oid,'category':'tv','schedule_date':now.date().isoformat(),'start_time':'19:00','end_time':'21:00','is_all_day':False,'visibility':'public','status':'active','note':'元メモ','sources':[{'source_type':'tv_station','source_url':'https://example.com/','note':'番組公式'}]}
    sid=call(a,'schedules/create','POST',payload,201)['schedule']['id']
    private=call(a,'schedules/create','POST',{**payload,'title':'非公開 '+key,'visibility':'private'},201)['schedule']['id']
    for user in [b,c]: call(user,'schedules/add-to-calendar','POST',{'schedule_id':sid})
    custom={'schedule_id':sid,'title':'自分用のまま','schedule_date':now.date().isoformat(),'start_time':'18:00','end_time':'18:30','is_all_day':False,'note':'個人のメモ'}
    call(c,'schedules/customize','POST',custom)
    call(b,'schedules/update','POST',{**payload,'schedule_id':sid},404)
    a.call('api/schedules/corrections/create.php','POST',{},status=419,token=False)
    b.call('api/schedules/reactions/toggle.php','POST',{},status=419,token=False)
    propose(a,sid,'start_time','20:00',403)
    rid=propose(b,sid,'start_time','20:00',old_value='嘘の値',requested_by_user_id=cid)['correction_id']
    assert detail(b,sid)['start_time']=='19:00'
    notices=get(a,'notifications/list')
    assert notices['unread_count']==1 and len(notices['popups'])==1
    nid=notices['notifications'][0]['id']
    assert notices['notifications'][0]['kind']=='correction'
    assert get(b,'notifications/list')['notifications']==[]
    call(b,'notifications/read','POST',{'ids':[nid],'mode':'read'})
    assert get(a,'notifications/list')['unread_count']==1
    a.call('api/notifications/read.php','POST',{'ids':[nid]},status=419,token=False)
    call(a,'notifications/read','POST',{'ids':[nid],'mode':'shown'})
    assert get(a,'notifications/list')['popups']==[]
    assert get(a,'notifications/list')['unread_count']==1
    call(a,'notifications/read','POST',{'ids':[nid]})
    assert get(a,'notifications/list')['unread_count']==0
    call(a,'notifications/read','POST',{'ids':[]},422)
    anonymous.call('api/notifications/list.php',status=401)
    propose(c,sid,'start_time','20:00',409)
    mine=get(a,'schedules/corrections/list')['corrections']
    assert len(mine)==1 and mine[0]['old_value']=='19:00' and mine[0]['new_value']=='20:00'
    assert get(b,'schedules/corrections/list')['total']==0
    assert get(c,'schedules/corrections/list')['total']==0
    assert get(b,'schedules/reactions/status',schedule_id=sid)['pending_correction_count']==1
    review(b,rid,status=404);review(c,rid,status=404)
    review(a,rid)
    assert detail(b,sid)['start_time']=='20:00'
    assert detail(c,sid)['start_time']=='18:00' and detail(c,sid)['title']=='自分用のまま'
    review(a,rid,status=409)
    approved=get(a,'schedules/corrections/list',status='approved')['corrections'][0]
    assert approved['reviewed_at'] and approved['status']=='approved'
    reject_id=propose(b,sid,'title','却下されるタイトル')['correction_id']
    review(a,reject_id,False)
    assert detail(b,sid)['title']==payload['title']
    assert get(a,'schedules/corrections/list',status='rejected')['total']==1
    stale=propose(b,sid,'start_time','20:30')['correction_id']
    call(a,'schedules/update','POST',{**payload,'schedule_id':sid,'start_time':'20:15'})
    review(a,stale,status=409)
    assert detail(b,sid)['start_time']=='20:15'
    review(a,stale,False)
    for field,value in [('title','修正後 <b>タイトル</b>'),('schedule_date','2027-03-10'),('end_time','22:00'),('category','radio'),('note','')]:
        new_id=propose(b,sid,field,value)['correction_id'];review(a,new_id)
    assert detail(b,sid)['date']=='2027-03-10' and detail(b,sid)['note']==''
    assert detail(c,sid)['date']==custom['schedule_date'] and detail(c,sid)['note']=='個人のメモ'
    for field,value in [('member_ids','1'),('schedule_date','2026-02-30'),('start_time','25:00'),('end_time','10:00'),('title',[]),('category','bad')]: propose(b,sid,field,value,422)
    propose(b,sid,'note','新規',422,reason='')
    propose(b,sid,'note','新規',422,source_url='javascript:alert(1)')
    propose(b,private,'note','新規',404)
    toggle(b,private,'helped',404)
    b.call(f'api/schedules/reactions/status.php?schedule_id={private}',status=404)
    b.call(f'api/schedules/stats.php?schedule_id={sid}',status=404)
    b.call(f'api/schedules/stats.php?schedule_id={private}',status=404)
    toggle(a,sid,'helped',403);toggle(b,sid,'like',422)
    first=toggle(b,sid,'helped');assert first['active'] and first['count']==1
    helped_notices=[n for n in get(a,'notifications/list')['notifications'] if n['kind']=='helped']
    assert len(helped_notices)==1
    helped_notice_id=helped_notices[0]['id']
    call(a,'notifications/read','POST',{'ids':[helped_notice_id],'mode':'shown'})
    second=toggle(b,sid,'helped');assert not second['active'] and second['count']==0
    assert not [n for n in get(a,'notifications/list')['notifications'] if n['kind']=='helped']
    toggle(b,sid,'helped'); toggle(b,sid,'thanks')
    notices=get(a,'notifications/list')
    assert len([n for n in notices['notifications'] if n['kind']=='helped'])==1
    assert helped_notice_id not in [n['id'] for n in notices['popups']]
    assert any(n['kind']=='thanks' for n in notices['notifications'])
    both=get(b,'schedules/reactions/status',schedule_id=sid)['reactions']
    assert both['helped']['active'] and both['thanks']['active']
    assert not get(c,'schedules/reactions/status',schedule_id=sid)['reactions']['helped']['active']
    result=toggle(b,sid,'thanks');assert not result['active'] and result['count']==0
    toggle(b,sid,'thanks')
    stats=get(a,'schedules/stats',schedule_id=sid)
    assert stats['calendar_added_count']==2 and stats['helped_count']==1 and stats['thanks_count']==1
    monthly=get(a,'reflections/monthly-summary',year=now.year,month=f'{now.month:02d}')
    assert monthly['shared_schedule_count']==1 and monthly['calendar_added_count']==2 and monthly['calendar_added_user_count']==2 and monthly['thanks_count']==1
    assert get(b,'reflections/monthly-summary',year=now.year,month=now.month,user_id=aid)['thanks_count']==0
    assert get(a,'reflections/monthly-summary',year=now.year,month=now.month,oshi_id=oid)['helped_count']==1
    assert get(a,'reflections/monthly-summary',year=now.year,month=now.month,oshi_id=999999)['helped_count']==0
    for user,page in [(a,'home.php'),(a,'profile.php'),(a,'corrections.php'),(a,f'schedule_detail.php?id={sid}'),(b,f'schedule_detail.php?id={sid}'),(c,f'schedule_detail.php?id={sid}')]:
        html=user.call(page)[1]
        assert '<b>タイトル</b>' not in html
        for resource in re.findall(r'(?:href|src)="([^\"]+\.(?:css|js)(?:\?[^\"]*)?)"',html): user.call(resource[len(url.path)+1:])
    html=b.call(f'schedule_detail.php?id={sid}')[1]
    assert '修正を提案' in html and 'TV局 / 番組公式' in html and 'noopener noreferrer' in html
    assert 'data-value="20:15"' in c.call(f'schedule_detail.php?id={sid}')[1]
    assert 'id="correction-form"' not in a.call(f'schedule_detail.php?id={sid}')[1]
    assert 'schedule-feedback' not in a.call(f'schedule_detail.php?id={private}')[1]
    db('boundaries')
    december=get(a,'reflections/monthly-summary',year=2025,month='12')
    january=get(a,'reflections/monthly-summary',year=2026,month='01')
    assert december['shared_schedule_count']==1 and december['calendar_added_count']==1 and december['helped_count']==1 and december['thanks_count']==0
    assert january['shared_schedule_count']==0 and january['calendar_added_count']==1 and january['thanks_count']==1 and january['helped_count']==0
    for query in ['year=2026&month=00','year=2026&month=13','year=2026&month[]=01','year[]=2026&month=1','year=2026&month=1&oshi_id[]=1']:
        a.call('api/reflections/monthly-summary.php?'+query,status=422)
    a.call('api/schedules/corrections/list.php?status[]=pending',status=422)
    pending=propose(b,sid,'note','非公開化前の提案')['correction_id']
    call(a,'schedules/update','POST',{**payload,'schedule_id':sid,'visibility':'private'})
    review(a,pending,status=409)
    review(a,pending,False)
    toggle(b,sid,'thanks',404)
    assert get(a,'reflections/monthly-summary',year=2025,month=12)['helped_count']==0
    call(a,'schedules/update','POST',{**payload,'schedule_id':sid,'status':'cancelled'})
    propose(b,sid,'note','中止予定への提案',404)
    assert not toggle(b,sid,'helped')['active']
    call(a,'schedules/delete','POST',{'schedule_id':sid})
    toggle(b,sid,'thanks',404)
    propose(b,sid,'note','削除予定への提案',404)
finally:
    assert db('cleanup')==before, '既存データの内容が変化しています。'
print(f'Phase 4: {checks} HTTP checks passed; all 12 existing table snapshots unchanged.')
