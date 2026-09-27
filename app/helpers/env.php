<?php
/**
 * env.php の役割：.env の設定値を読み込む。
 * .env は環境ごとに異なるDB認証情報をコードから分離するためのファイル。
 * Gitに含めず、ブラウザーからアクセスできない public/ の外に置く。
 */
declare(strict_types=1);

/** KEY=VALUE形式の設定を読む。引用符で囲んだ値と行全体のコメントに対応する。 */
function loadEnv(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('.env がありません。READMEのセットアップを確認してください。');
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2 || !preg_match('/^[A-Z_]+$/', trim($parts[0]))) {
            throw new RuntimeException('.env の形式が正しくありません。');
        }
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        // サーバー側で設定済みの環境変数があれば、そちらを優先する。
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

/** 設定が空欄なら既定値を返す。 */
function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}
