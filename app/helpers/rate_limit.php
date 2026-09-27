<?php
/** rate_limit.php の役割：短時間の認証リクエスト集中を、IP単位のファイル記録で制限する。 */
declare(strict_types=1);

/** Cookieを削除しても回避できないよう、サーバー側で15分に30回までに制限する。 */
function limitAuthRequests(): void
{
    // 生のIPをファイル名に残さない。偽装可能な転送ヘッダーは使用しない。
    $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local');
    $path = PROJECT_ROOT . '/storage/rate_limits/' . $key . '.json';
    $file = fopen($path, 'c+');
    if ($file === false || !flock($file, LOCK_EX)) {
        throw new RuntimeException('アクセス制限の保存先に書き込めません。');
    }
    try {
        $record = json_decode(stream_get_contents($file), true) ?: [];
        $now = time();
        if (($record['until'] ?? 0) <= $now) {
            $record = ['until' => $now + 900, 'count' => 0];
        }
        $limited = $record['count'] >= 30;
        if (!$limited) {
            $record['count']++;
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($record, JSON_THROW_ON_ERROR));
            fflush($file);
        }
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
    if ($limited) {
        header('Retry-After: ' . max(1, $record['until'] - time()));
        apiError('操作が続いています。15分ほど待ってからお試しください。', 429);
    }
}
