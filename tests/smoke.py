"""Phase 1のHTTP統合テスト。自分で作ったテストユーザーだけを最後に削除する。"""
import argparse
import http.cookiejar
import json
import pathlib
import subprocess
import urllib.error
import urllib.request
import urllib.parse
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--base', default='http://localhost/oshilife-v2/public')
parser.add_argument('--php', default='/Applications/XAMPP/xamppfiles/bin/php')
args = parser.parse_args()
args.base = args.base.rstrip('/')
parsed = urllib.parse.urlparse(args.base)
if parsed.scheme != 'http' or parsed.hostname not in ('127.0.0.1', 'localhost'):
    parser.error('ローカル開発サーバーのみを対象にしてください。')
root = pathlib.Path(__file__).resolve().parents[1]
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
email = 'smoke-' + uuid.uuid4().hex + '@example.test'
password = 'Test-only-password-2026'
name = '💎👑🐃🩷❤️🖤<script>alert(1)</script>'
count = 0

def request(path, method='GET', data=None, token=None, raw=None, expected=200):
    """APIやページを呼び、期待するHTTPステータスも確認する。"""
    global count
    headers = {}
    body = None
    if data is not None:
        body = json.dumps(data).encode()
        headers['Content-Type'] = 'application/json'
    if raw is not None:
        body = raw
        headers['Content-Type'] = 'application/json'
    if token is not None:
        headers['X-CSRF-Token'] = token
    req = urllib.request.Request(args.base + '/' + path, data=body, method=method, headers=headers)
    try:
        response = client.open(req)
    except urllib.error.HTTPError as error:
        response = error
    text = response.read().decode()
    assert response.status == expected, (path, response.status, expected, text)
    count += 1
    return response, json.loads(text) if 'application/json' in response.headers.get('Content-Type', '') else text

try:
    for page in ['home', 'calendar', 'discover', 'live', 'profile']:
        response, _ = request(page + '.php')
        assert response.url.endswith('/login.php')
    _, me = request('api/auth/me.php')
    assert me['data']['user'] is None
    token = me['data']['csrf_token']
    request('api/auth/register.php', 'POST', {}, expected=419)
    request('api/auth/login.php', expected=405)
    request('api/auth/register.php', 'POST', token=token, raw=b'{', expected=400)
    request('api/auth/register.php', 'POST', {}, token, expected=422)
    input_data = {'display_name': name, 'email': email, 'password': password, 'password_confirmation': password}
    request('api/auth/register.php', 'POST', input_data, token, expected=201)
    request('api/auth/register.php', 'POST', input_data, token, expected=409)
    request('api/auth/login.php', 'POST', {'email': email, 'password': 'wrong'}, token, expected=401)
    request('api/auth/login.php', 'POST', {'email': "x' OR 1=1 --@example.test", 'password': 'wrong'}, token, expected=401)
    before = next(c.value for c in jar if c.name == 'OSHILIFE_V2_SESSION')
    _, login = request('api/auth/login.php', 'POST', {'email': email, 'password': password}, token)
    after = next(c.value for c in jar if c.name == 'OSHILIFE_V2_SESSION')
    assert before != after, 'ログイン後にセッションIDが変わること'
    token = login['data']['csrf_token']
    _, me = request('api/auth/me.php')
    assert me['data']['user']['display_name'] == name
    assert 'password_hash' not in me['data']['user']
    for page in ['home', 'calendar', 'discover', 'live', 'profile']:
        response, html = request(page + '.php')
        assert response.url.endswith('/' + page + '.php')
        assert 'aria-current="page"' in html
        assert '<script>alert(1)</script>' not in html
        if page in ['home', 'profile']:
            assert '&lt;script&gt;alert(1)&lt;/script&gt;' in html
    request('.env', expected=404)
    request('database/schema.sql', expected=404)
    request('config/database.php', expected=404)
    request('api/auth/logout.php', expected=405)
    request('api/auth/logout.php', 'POST', {}, 'bad-token', expected=419)
    request('api/auth/logout.php', 'POST', {}, token)
    _, me = request('api/auth/me.php')
    assert me['data']['user'] is None
    response, _ = request('home.php')
    assert response.url.endswith('/login.php')
    request('api/auth/logout.php', 'POST', {}, me['data']['csrf_token'], expected=401)
    # DB保存値と設定の同時作成を確認し、作成したテスト用レコードだけを削除する。
    print(f'HTTP checks passed: {count}')
finally:
    php_code = r'''
    require $argv[1] . '/config/app.php';
    require PROJECT_ROOT . '/config/database.php';
    $pdo = database();
    $statement = $pdo->prepare('SELECT u.id, u.password_hash, s.user_id FROM users u LEFT JOIN user_settings s ON s.user_id=u.id WHERE u.email=:email');
    $statement->execute(['email' => $argv[2]]);
    $user = $statement->fetch();
    if ($user) {
        $valid = password_verify($argv[3], $user['password_hash']) && $user['user_id'] !== null && $user['password_hash'] !== $argv[3];
        $delete = $pdo->prepare('DELETE FROM users WHERE id=:id AND email=:email');
        $delete->execute(['id' => $user['id'], 'email' => $argv[2]]);
        $settings = $pdo->prepare('SELECT COUNT(*) FROM user_settings WHERE user_id=:id');
        $settings->execute(['id' => $user['id']]);
        if (!$valid || (int) $settings->fetchColumn() !== 0) { fwrite(STDERR, 'DB verification failed'); exit(1); }
        echo "DB hash/settings/CASCADE checks passed; temporary user removed.\n";
    }
    '''
    subprocess.run([args.php, '-r', php_code, str(root), email, password], check=True)
