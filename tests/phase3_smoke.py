"""Apache上のPhase 3統合テスト。固有名の一時ユーザー2名とそのデータのみを作成・清掃する。"""
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
emails = [f'phase3-{key}-{i}@example.test' for i in range(2)]
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
    """専用DBにテスト自身の識別子を渡し、件数確認か限定した清掃だけを行う。"""
    code = r'''
    define('PROJECT_ROOT', $argv[1]);
    require PROJECT_ROOT . '/app/helpers/env.php';
    require PROJECT_ROOT . '/config/database.php';
    loadEnv(PROJECT_ROOT . '/.env');
    $pdo = database();
    $emails = [$argv[3], $argv[4]];
    if ($argv[2] === 'cleanup' || $argv[2] === 'deactivate') {
        $s = $pdo->prepare('SELECT id FROM users WHERE email IN (?, ?)');
        $s->execute($emails);
        $ids = $s->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            if ($argv[2] === 'deactivate') {
                $s=$pdo->prepare('UPDATE oshis SET is_active=0 WHERE created_by_user_id=:id');
                $s->execute(['id'=>$id]);
            }
        }
        if ($argv[2] === 'cleanup' && $ids) {
            $pdo->beginTransaction();
            try {
                foreach ($ids as $id) {
                    $s=$pdo->prepare('DELETE FROM user_schedules WHERE user_id=?'); $s->execute([$id]);
                }
                foreach ($ids as $id) {
                    $s=$pdo->prepare('DELETE FROM schedules WHERE created_by_user_id=?'); $s->execute([$id]);
                    $s=$pdo->prepare('DELETE FROM user_oshis WHERE user_id=:id'); $s->execute(['id'=>$id]);
                }
                foreach ($ids as $id) {
                    $s=$pdo->prepare('DELETE m FROM members m JOIN oshis o ON o.id=m.oshi_id WHERE o.created_by_user_id=:id'); $s->execute(['id'=>$id]);
                    $s=$pdo->prepare('DELETE FROM oshis WHERE created_by_user_id=:id'); $s->execute(['id'=>$id]);
                    $s=$pdo->prepare('DELETE FROM users WHERE id=:id'); $s->execute(['id'=>$id]);
                }
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        }
    }
    $counts=[];
    foreach (['users','user_settings','oshis','members','user_oshis','schedules','schedule_members','schedule_sources','user_schedules'] as $table) {
        // テーブル名は固定の許可リスト。ユーザー入力をSQL識別子に連結しない。
        $s=$pdo->prepare('SELECT COUNT(*) FROM ' . $table); $s->execute(); $counts[$table]=(int)$s->fetchColumn();
    }
    echo json_encode($counts);
    '''
    return json.loads(subprocess.check_output([args.php, '-r', code, str(root), mode, *emails]))


from datetime import datetime, timezone, timedelta
before = db('counts')
a, b, anonymous = Client(), Client(), Client()
today = datetime.now(timezone(timedelta(hours=9))).date()
later = (today.replace(day=1) + timedelta(days=32)).replace(day=1)
def rows(client, endpoint, **query):
    return client.call('api/schedules/' + endpoint + '.php?' + urllib.parse.urlencode(query))['data']['schedules']
def detail(client, sid):
    return client.call(f'api/schedules/detail.php?id={sid}')['data']['schedule']
def post(client, action, data, status=200):
    return client.call('api/schedules/' + action + '.php', 'POST', data, status=status)
try:
    anonymous.call('api/schedules/calendar.php', status=401)
    aid, bid = a.register_login(emails[0]), b.register_login(emails[1])
    oid = a.call('api/oshis/create.php', 'POST', {'name': '予定検証-' + key, 'oshi_type':'group', 'emoji':'💎'}, status=201)['data']['oshi']['id']
    b.call('api/oshis/follow.php', 'POST', {'oshi_id':oid})
    for name, heart, color in [('A','🩷','#E7A6C0'),('B','🖤','#333333')]:
        a.call('api/oshis/members/create.php', 'POST', {'oshi_id':oid, 'name':name, 'heart_emoji':heart, 'color_name':name, 'hex_color':color}, status=201)
    mids = [m['id'] for m in a.call(f'api/oshis/members.php?id={oid}')['data']['members']]
    payload = {'oshi_id':oid,'title':'TV出演 '+key,'category':'tv','schedule_date':str(today),'start_time':'19:00','end_time':'21:00','is_all_day':False,'visibility':'public','status':'active','note':'<script>alert(1)</script>','member_ids':mids,'sources':[{'source_type':'official_site','source_url':'https://example.com/','note':'公式'}],'created_by_user_id':bid}
    a.call('api/schedules/create.php','POST',payload,status=419,token=False)
    for changes in [{'schedule_date':'2026-02-30'},{'end_time':'18:00'},{'category':'invalid'},{'member_ids':[999999999]},{'sources':[{'source_type':'other','source_url':'javascript:alert(1)'}]}]:
        post(a,'create',{**payload,**changes},422)
    sid = post(a,'create',payload,201)['data']['schedule']['id']
    assert detail(a,sid)['is_owner'] and not detail(b,sid)['is_owner']
    assert len(detail(b,sid)['members'])==2
    assert detail(b,sid)['member_hearts']==['🩷','🖤']
    assert any(x['id']==sid for x in rows(b,'unadded',oshi_id=oid))
    assert not rows(a,'unadded',oshi_id=oid)
    duplicate = post(a,'create',payload,409)
    assert duplicate['duplicates'][0]['id']==sid
    dupquery = urllib.parse.urlencode({k:v for k,v in payload.items() if k in ['oshi_id','title','category','schedule_date','start_time','end_time','visibility','status']})
    assert a.call('api/schedules/duplicate-check.php?'+dupquery)['data']['duplicates']
    sid2 = post(a,'create',{**payload,'allow_duplicate':True},201)['data']['schedule']['id']
    private = post(a,'create',{**payload,'visibility':'private','title':'秘密 '+key},201)['data']['schedule']['id']
    b.call(f'api/schedules/detail.php?id={private}',status=404)
    post(b,'update',{**payload,'schedule_id':private},404)
    post(b,'delete',{'schedule_id':private},404)
    post(b,'add-to-calendar',{'schedule_id':private},404)
    assert private not in [x['id'] for x in rows(b,'public',q=key)]
    assert private not in [x['id'] for x in rows(b,'calendar',year=today.year,month=today.month)]
    post(b,'update',{**payload,'schedule_id':sid},404)
    post(b,'add-to-calendar',{'schedule_id':sid,'user_id':aid})
    post(b,'add-to-calendar',{'schedule_id':sid},409)
    assert sid not in [x['id'] for x in rows(b,'unadded',oshi_id=oid)]
    assert next(x for x in rows(b,'public',q=key) if x['id']==sid)['is_added']
    assert next(x for x in rows(b,'list',date=str(today)) if x['id']==sid)['source_type']=='synced'
    post(a,'update',{**payload,'schedule_id':sid,'start_time':'20:00'})
    assert detail(b,sid)['start_time']=='20:00'
    custom={'schedule_id':sid,'title':'自分用','schedule_date':str(later),'start_time':'','end_time':'','is_all_day':True,'note':''}
    post(b,'customize',custom)
    assert detail(b,sid)['source_type']=='customized'
    post(a,'update',{**payload,'schedule_id':sid,'title':'元の変更','note':'元メモ'})
    mine=detail(b,sid)
    assert mine['title']=='自分用' and mine['date']==str(later) and mine['start_time'] is None and mine['note']=='' and mine['is_all_day']
    assert mine['original']['title']=='元の変更'
    assert sid not in [x['id'] for x in rows(b,'calendar',year=today.year,month=today.month)]
    assert sid in [x['id'] for x in rows(b,'calendar',year=later.year,month=later.month)]
    for page in ['home.php','calendar.php','discover.php','schedule_form.php',f'schedule_form.php?id={sid}',f'schedule_detail.php?id={sid}']:
        response, html=a.call(page)
        assert '<script>alert(1)</script>' not in html
        for resource in re.findall(r'(?:href|src)="([^"]+\.(?:css|js))"',html):
            a.call(resource[len(url.path)+1:])
    b.call(f'schedule_form.php?id={sid}&mode=custom')
    post(a,'update',{**payload,'schedule_id':sid,'status':'cancelled'})
    assert detail(b,sid)['status']=='cancelled'
    assert '中止' in b.call(f'schedule_detail.php?id={sid}')[1]
    post(a,'delete',{'schedule_id':sid})
    assert detail(b,sid)['status']=='deleted'
    assert '共有元の予定は削除されました' in b.call(f'schedule_detail.php?id={sid}')[1]
    post(b,'remove-from-calendar',{'schedule_id':sid})
    b.call(f'api/schedules/detail.php?id={sid}',status=404)
    assert detail(a,sid)['status']=='deleted'
    post(b,'add-to-calendar',{'schedule_id':sid2})
    post(a,'update',{**payload,'schedule_id':sid2,'visibility':'private'})
    b.call(f'api/schedules/detail.php?id={sid2}',status=404)
    assert sid2 not in [x['id'] for x in rows(b,'calendar',year=today.year,month=today.month)]
    post(b,'customize',{**custom,'schedule_id':sid2},404)
    post(b,'remove-from-calendar',{'schedule_id':sid2})
    assert detail(a,sid2)['visibility']=='private'
    for bad in ['calendar.php?year=2026&month=13','public.php?q[]=x','unadded.php?oshi_id[]=1','list.php?date=2026-02-30','detail.php?id[]=1']:
        a.call('api/schedules/'+bad,status=422)
finally:
    after = db('cleanup')
    assert before == after, (before,after)
print(f'Phase 3: {checks} HTTP checks passed; existing DB counts unchanged.')
