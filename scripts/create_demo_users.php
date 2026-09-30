<?php
/** create_demo_users.php の役割：ローカル確認用A/Bアカウントを乱数で作り、非公開ファイルへ接続情報を保存するCLI。 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const PROJECT_ROOT = __DIR__ . '/..';
require_once PROJECT_ROOT . '/app/helpers/env.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/app/repositories/user_repository.php';
loadEnv(PROJECT_ROOT . '/.env');
if (env('APP_ENV', 'local') !== 'local' || env('DB_NAME') !== 'oshilife_v2') {
    throw new RuntimeException('ローカルのoshilife_v2専用です。');
}
$path = PROJECT_ROOT . '/storage/demo-accounts.txt';
// 再実行でアカウントを増やしたり、以前のパスワードを上書きしたりしない。
if (file_exists($path)) { echo "確認用アカウントは作成済みです: storage/demo-accounts.txt\n"; exit; }
umask(0077);
$file = fopen($path, 'x');
if ($file === false) throw new RuntimeException('接続情報の保存先を確保できません。');
try {
    fwrite($file, "Oshilife v2 ローカル確認専用アカウント\n本番へコピーしないでください。\nURL: " . env('APP_URL') . "/login.php\n\n");
    foreach (['A', 'B'] as $label) {
        $email = 'demo-' . strtolower($label) . '-' . bin2hex(random_bytes(6)) . '@example.test';
        $password = bin2hex(random_bytes(12));
        // 元のパスワードではなく、逆算しにくいハッシュをDBへ保存する。
        $id = createUser(database(), '確認ユーザー' . $label, $email, password_hash($password, PASSWORD_DEFAULT));
        fwrite($file, "ユーザー{$label} (ID: {$id})\nメール: {$email}\nパスワード: {$password}\n\n");
    }
} finally { fclose($file); }
echo "A/Bの2ユーザーを作成しました。接続情報: storage/demo-accounts.txt\n";
