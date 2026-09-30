"""Apache上のPhase 2統合テスト。固有名の一時ユーザー2名とそのデータのみを作成・清掃する。"""
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
emails = [f'phase2-{key}-{i}@example.test' for i in range(2)]
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
    foreach (['users','user_settings','oshis','members','user_oshis'] as $table) {
        // テーブル名は固定の許可リスト。ユーザー入力をSQL識別子に連結しない。
        $s=$pdo->prepare('SELECT COUNT(*) FROM ' . $table); $s->execute(); $counts[$table]=(int)$s->fetchColumn();
    }
    echo json_encode($counts);
    '''
    return json.loads(subprocess.check_output([args.php, '-r', code, str(root), mode, *emails]))

before = db('counts')
a, b, anonymous = Client(), Client(), Client()
try:
    anonymous.call('api/oshis/my.php', status=401)
    anonymous.call('api/oshis/search.php?q=test', status=401)
    response, html = anonymous.call('')
    assert response.url == args.base + '/login.php'
    assert 'app-base-path' in html and url.path in html
    # 同じ公開パスのCSS/JSをすべて取得し、HTMLが返っていないことも検査する。
    for resource in re.findall(r'(?:href|src)="([^"]+\.(?:css|js)(?:\?[^\"]*)?)"', html):
        assert resource.startswith(url.path + '/assets/')
        response, content = anonymous.call(resource[len(url.path) + 1:])
        assert 'text/html' not in response.headers.get('Content-Type', '')
        assert len(content) > 100
    for protected in ['../.env', '../.git/config', '../config/database.php', '../database/schema.sql', '../storage/sessions/', '../scripts/import_masters.php']:
        anonymous.call(protected, status=403)
    a_id, b_id = a.register_login(emails[0]), b.register_login(emails[1])
    response, _ = a.call('')
    assert response.url == args.base + '/home.php'
    a.call('api/oshis/create.php', 'POST', {}, status=419, token=False)
    a.call('api/oshis/create.php', 'POST', {}, status=422)
    a.call('api/oshis/create.php', status=405)
    a.call('api/oshis/detail.php?id[]=1', status=422)
    a.call('api/oshis/search.php?q[]=1', status=422)
    a.call('api/oshis/search.php?q=%25', status=200)
    a.call('api/oshis/list.php?page=0', status=422)
    a.call('api/oshis/detail.php?id=999999999', status=404)
    oshi_name = f'QA-{key} <script>x</script>'
    oshi_input = {'name': oshi_name, 'oshi_type': 'group', 'emoji': '👑🐃💎', 'created_by_user_id': b_id}
    created = a.call('api/oshis/create.php', 'POST', oshi_input, status=201)['data']['oshi']
    oshi_id = created['id']
    assert int(created['can_manage']) == 1 and int(created['is_followed']) == 1
    assert created['emoji'] == '👑🐃💎' and 'theme_color' not in created
    a.call('api/oshis/create.php', 'POST', oshi_input, status=409)
    a.call('api/oshis/follow.php', 'POST', {'oshi_id': oshi_id}, status=409)
    detail = b.call(f'api/oshis/detail.php?id={oshi_id}')['data']['oshi']
    assert int(detail['can_manage']) == 0 and int(detail['is_followed']) == 0
    results = b.call('api/oshis/search.php?q=' + urllib.parse.quote(key))['data']['oshis']
    assert any(int(o['id']) == int(oshi_id) for o in results)
    b.call('api/oshis/follow.php', 'POST', {'oshi_id': oshi_id, 'user_id': a_id})
    mine = b.call('api/oshis/my.php?user_id=' + str(a_id))['data']['oshis']
    assert len(mine) == 1 and int(mine[0]['id']) == int(oshi_id)
    b.call('api/oshis/unfollow.php', 'POST', {'oshi_id': oshi_id, 'user_id': a_id})
    assert b.call('api/oshis/my.php')['data']['oshis'] == []
    assert len(a.call('api/oshis/my.php')['data']['oshis']) == 1
    member = {'oshi_id': oshi_id, 'name': 'メンバーA <b>🩷</b>', 'color_name': 'pink', 'heart_emoji': '🩷', 'hex_color': '#e7a6c0'}
    b.call('api/oshis/members/create.php', 'POST', {**member, 'user_id': a_id}, status=403)
    a.call('api/oshis/members/create.php', 'POST', {**member, 'heart_emoji': '●'}, status=422)
    a.call('api/oshis/members/create.php', 'POST', {**member, 'hex_color': 'red;'}, status=422)
    a.call('api/oshis/members/create.php', 'POST', member, status=201)
    a.call('api/oshis/members/create.php', 'POST', member, status=409)
    members = b.call(f'api/oshis/members.php?id={oshi_id}')['data']['members']
    assert members[0]['heart_emoji'] == '🩷' and members[0]['hex_color'] == '#E7A6C0'
    for page in ['home.php', 'calendar.php', 'discover.php', 'live.php', 'profile.php', 'oshis.php', f'oshi_detail.php?id={oshi_id}']:
        response, html = a.call(page)
        assert response.url == args.base + '/' + page
        assert html.count('aria-label="メインナビゲーション"') == 1
        assert '<script>x</script>' not in html
        if page.startswith('oshi_detail') or page == 'home.php':
            assert '&lt;script&gt;x&lt;/script&gt;' in html
        if page.startswith('oshi_detail'):
            assert '&lt;b&gt;🩷&lt;/b&gt;' in html
        for resource in re.findall(r'(?:href|src)="([^"]+\.(?:css|js)(?:\?[^\"]*)?)"', html):
            assert resource.startswith(url.path + '/assets/')
            a.call(resource[len(url.path) + 1:])
    # 作成者だけの編集、入力検証、重複時の取消、既存メンバー・登録関係の維持を確認する。
    response, owner_html = a.call(f'oshi_detail.php?id={oshi_id}')
    response, other_html = b.call(f'oshi_detail.php?id={oshi_id}')
    assert 'id="oshi-edit"' in owner_html and 'id="oshi-edit"' not in other_html
    assert 'theme_color' not in owner_html and 'テーマカラー' not in owner_html
    edit = {'oshi_id': oshi_id, 'name': f'更新-{key} <b>推し</b>', 'oshi_type': 'solo', 'emoji': '💎🩷'}
    anonymous.call('api/oshis/update.php', 'POST', edit, status=401)
    a.call('api/oshis/update.php', status=405)
    a.call('api/oshis/update.php', 'POST', edit, status=419, token=False)
    b.call('api/oshis/update.php', 'POST', {**edit, 'user_id': a_id, 'created_by_user_id': b_id}, status=403)
    a.call('api/oshis/update.php', 'POST', {**edit, 'oshi_id': []}, status=422)
    a.call('api/oshis/update.php', 'POST', {**edit, 'name': '', 'oshi_type': 'invalid'}, status=422)
    a.call('api/oshis/update.php', 'POST', {**edit, 'oshi_id': 999999999}, status=404)
    assert a.call(f'api/oshis/detail.php?id={oshi_id}')['data']['oshi']['name'] == oshi_name
    updated = a.call('api/oshis/update.php', 'POST', {**edit, 'created_by_user_id': b_id})['data']['oshi']
    assert updated['name'] == edit['name'] and updated['emoji'] == edit['emoji']
    assert updated['oshi_type'] == 'solo' and 'theme_color' not in updated
    assert int(updated['id']) == int(oshi_id) and int(updated['can_manage']) == 1 and int(updated['is_followed']) == 1
    assert b.call(f'api/oshis/detail.php?id={oshi_id}')['data']['oshi']['name'] == edit['name']
    assert len(a.call(f'api/oshis/members.php?id={oshi_id}')['data']['members']) == 1
    response, edited_html = a.call(f'oshi_detail.php?id={oshi_id}')
    assert '&lt;b&gt;推し&lt;/b&gt;' in edited_html and '<b>推し</b>' not in edited_html
    response, edited_home = a.call('home.php')
    assert '&lt;b&gt;推し&lt;/b&gt;' in edited_home
    assert any(o['name'] == edit['name'] for o in b.call('api/oshis/search.php?q=' + urllib.parse.quote(edit['name']))['data']['oshis'])
    # 値を変えない保存も成功することを確認する。
    a.call('api/oshis/update.php', 'POST', edit)
    conflict = {**oshi_input, 'name': f'重複用-{key}', 'oshi_type': 'solo'}
    conflict_id = a.call('api/oshis/create.php', 'POST', conflict, status=201)['data']['oshi']['id']
    a.call('api/oshis/update.php', 'POST', {**edit, 'name': conflict['name']}, status=409)
    assert a.call(f'api/oshis/detail.php?id={oshi_id}')['data']['oshi']['name'] == edit['name']
    a.call('api/oshis/unfollow.php', 'POST', {'oshi_id': conflict_id})
    a.call('api/oshis/unfollow.php', 'POST', {'oshi_id': oshi_id})
    assert a.call('api/oshis/my.php')['data']['oshis'] == []
    assert len(b.call(f'api/oshis/members.php?id={oshi_id}')['data']['members']) == 1
    a.call('api/oshis/follow.php', 'POST', {'oshi_id': oshi_id})
    # 空テンプレートのdry-runは実ユーザーのデータを変更しない。
    subprocess.run([args.php, str(root/'scripts/import_masters.php'), f'--creator-id={a_id}'], check=True)
    db('deactivate')
    a.call(f'api/oshis/detail.php?id={oshi_id}', status=404)
    a.call('api/oshis/update.php', 'POST', edit, status=404)
    a.call('api/oshis/follow.php', 'POST', {'oshi_id': oshi_id}, status=404)
    assert a.call('api/oshis/my.php')['data']['oshis'] == []
    assert b.call('api/oshis/search.php?q=' + urllib.parse.quote(key))['data']['oshis'] == []
    a.call('api/auth/logout.php', 'POST', {})
    response, _ = a.call('home.php')
    assert response.url == args.base + '/login.php'
    assert a.call('api/auth/me.php')['data']['user'] is None
    b.call('api/auth/logout.php', 'POST', {})
    print(f'Phase 2 HTTP checks passed: {checks}')
finally:
    after = db('cleanup')
    assert after == before, (before, after)
    print('Temporary records removed; existing table counts unchanged:', after)
