<?php
/** database.php の役割：.envを使い、このアプリ専用のMySQLへPDOで接続する。 */
declare(strict_types=1);

/** 同じリクエスト内では接続を再利用する。接続は実際にDBが必要になるまで行わない。 */
function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $name = env('DB_NAME');
    $user = env('DB_USER');
    $host = env('DB_HOST', '127.0.0.1');
    $port = env('DB_PORT', '3306');
    if ($name === '' || $user === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name) || !ctype_digit($port) || str_contains($host, ';')) {
        throw new RuntimeException('新規DBの接続設定を確認してください。');
    }
    // utf8mb4 は4バイト文字（絵文字）も保存できるMySQLの文字コード。
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, env('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // 月次集計の月境界をPHPと合わせる。DBサーバー全体の設定は変更しない。
    $pdo->exec("SET time_zone = '+09:00'");
    return $pdo;
}
