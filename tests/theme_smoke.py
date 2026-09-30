"""Apache上の配色設定統合テスト。固有名の一時ユーザー2名とそのデータのみを作成・清掃する。"""
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
emails = [f'theme-{key}-{i}@example.test' for i in range(2)]
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
    if ($argv[2] === 'cleanup') {
        // テスト固有のメールだけを削除。設定行は外部キーのCASCADEで一緒に清掃する。
        $s=$pdo->prepare('DELETE FROM users WHERE email IN (?, ?)');
        $s->execute($emails);
    }
    $counts=[];
    foreach (['users','user_settings','oshis','members','user_oshis'] as $table) {
        // テーブル名は固定の許可リスト。ユーザー入力をSQL識別子に連結しない。
        $s=$pdo->prepare('SELECT COUNT(*) FROM ' . $table); $s->execute(); $counts[$table]=(int)$s->fetchColumn();
    }
    echo json_encode($counts);
    '''
    return json.loads(subprocess.check_output([args.php, '-r', code, str(root), mode, *emails]))


before = db('counts')
a,b,anonymous=Client(),Client(),Client()
try:
    anonymous.call('api/settings/theme.php',status=401)
    aid,bid=a.register_login(emails[0]),b.register_login(emails[1])
    b_before=b.call('api/settings/theme.php')['data']['theme']
    a.call('api/settings/theme.php','POST',{'theme_color':'#15171C','accent_color':'#FACC15','user_id':bid})
    assert b.call('api/settings/theme.php')['data']['theme']==b_before
    assert a.call('api/settings/theme.php')['data']['theme']=={'theme_color':'#15171C','accent_color':'#FACC15'}
    response,css=a.call('theme.css.php')
    assert 'text/css' in response.headers['Content-Type'] and '--bg:#15171C;' in css and '--on-accent:#000000;' in css
    assert 'no-store' in response.headers['Cache-Control']
    a.call('api/settings/theme.php','POST',{'theme_color':'#ffffff','accent_color':'#ffffff'},status=419,token=False)
    for bad in ['red','</style>',{},'#fff','#123456;body{}']:
        a.call('api/settings/theme.php','POST',{'theme_color':'#FFFFFF','accent_color':bad},status=422)
    a.call('theme.css.php?background=%23FFFFFF&accent=%23000000')
    assert a.call('api/settings/theme.php')['data']['theme']['theme_color']=='#15171C'
    assert '--bg:#15171C;' in a.call('theme.css.php?background=bad&accent=bad')[1]
    html=a.call('profile.php')[1]
    assert 'id="theme-form"' in html and 'type="color"' in html and '今後対応します' not in html
    for page in ['home.php','calendar.php','discover.php','oshis.php']:
        assert 'theme.css.php' in a.call(page)[1]
    for asset in ['assets/js/theme.js','assets/css/theme.css']:
        a.call(asset)
    a.call('api/auth/logout.php','POST',{})
    a.call('api/auth/me.php')
    a.call('api/auth/login.php','POST',{'email':emails[0],'password':password})
    assert a.call('api/settings/theme.php')['data']['theme']['accent_color']=='#FACC15'
finally:
    assert db('cleanup') == before
print(f'Theme: {checks} HTTP checks passed; existing data counts unchanged.')
