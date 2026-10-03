"""HTTP統合テスト共通部品。認証・CSRF・一時データ清掃・全テーブル不変確認をまとめる。"""
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
            $s=$p->prepare('DELETE child FROM room_expense_members child JOIN rooms r ON r.id=child.room_id WHERE r.owner_user_id=?');$s->execute([$id]);
            $s=$p->prepare('DELETE child FROM room_expenses child JOIN rooms r ON r.id=child.room_id WHERE r.owner_user_id=?');$s->execute([$id]);
            // このテストが作成したownerのルームだけを、外部キーの子→親の順で清掃する。
            foreach(['room_invite_links','room_events','room_invitations','room_members'] as $table) { $s=$p->prepare('DELETE child FROM '.$table.' child JOIN rooms r ON r.id=child.room_id WHERE r.owner_user_id=?');$s->execute([$id]); }
            $s=$p->prepare('DELETE FROM rooms WHERE owner_user_id=?');$s->execute([$id]);
            foreach(['event_participants','expenses','savings','todos','trips','user_event_status','user_schedules'] as $table) { $s=$p->prepare('DELETE FROM '.$table.' WHERE user_id=?');$s->execute([$id]); }
            foreach(['notifications','reactions','correction_requests'] as $table) { $s=$p->prepare('DELETE child FROM '.$table.' child JOIN schedules s ON s.id=child.schedule_id WHERE s.created_by_user_id=?');$s->execute([$id]); }
        }
        foreach($ids as $id) {
            $s=$p->prepare('DELETE l FROM events l JOIN schedules s ON s.id=l.schedule_id WHERE s.created_by_user_id=?');$s->execute([$id]);
            $s=$p->prepare('DELETE FROM schedules WHERE created_by_user_id=?');$s->execute([$id]);
            $s=$p->prepare('DELETE FROM user_oshis WHERE user_id=?');$s->execute([$id]);
        }
        $s=$p->prepare('DELETE FROM oshis WHERE name IN (?,?)');$s->execute(['Payment-'.$argv[6],'Payment-'.$argv[6].'-second']);
        $s=$p->prepare('DELETE FROM venues WHERE name=?');$s->execute(['Payment-'.$argv[6]]);
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
from datetime import datetime,timezone,timedelta
