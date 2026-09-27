<?php
/** csv_smoke.php の役割：固有名のテストデータでCSVの確認・投入・再実行・拒否を検証し、最後に清掃する。 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const PROJECT_ROOT = __DIR__ . '/..';
require_once PROJECT_ROOT . '/app/helpers/env.php';
require_once PROJECT_ROOT . '/app/repositories/user_repository.php';
require_once PROJECT_ROOT . '/app/repositories/oshi_repository.php';
loadEnv(PROJECT_ROOT . '/.env');

/** 外部プロセスに配列で引数を渡し、シェル展開せずにインポーターを実行する。 */
function runImport(array $arguments, int $expectedStatus): string
{
    $process = proc_open([PHP_BINARY, PROJECT_ROOT . '/scripts/import_masters.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('CLIを起動できません。');
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== $expectedStatus) throw new RuntimeException('CSVステータス違い: ' . $output);
    return $output;
}

/** 指定したテスト作成者の推し件数だけを調べる。 */
function countTestOshis(PDO $pdo, int $id): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM oshis WHERE created_by_user_id=:id');
    $statement->execute(['id' => $id]);
    return (int) $statement->fetchColumn();
}

/** PHPのassert設定に依存せず、不一致なら必ず失敗させる。 */
function checkCsv(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = database();
$key = bin2hex(random_bytes(8));
$id = createUser($pdo, 'CSV検証', "csv-{$key}@example.test", password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT));
$directory = sys_get_temp_dir() . '/oshilife-csv-' . $key;
mkdir($directory, 0700);
$oshisPath = $directory . '/oshis.csv';
$membersPath = $directory . '/members.csv';
$name = 'CSV検証-' . $key;
try {
    file_put_contents($oshisPath, "name,oshi_type,emoji,theme_color\n{$name},group,👑🐃,#986879\n");
    file_put_contents($membersPath, "oshi_name,name,color_name,heart_emoji,hex_color\n{$name},メンバーA,pink,🩷,#E7A6C0\n");
    $arguments = ["--creator-id={$id}", "--oshis={$oshisPath}", "--members={$membersPath}"];
    runImport($arguments, 0);
    checkCsv(countTestOshis($pdo, $id) === 0, 'dry-runで変更された');
    runImport([...$arguments, '--apply'], 0);
    checkCsv(countTestOshis($pdo, $id) === 1, '投入件数が不正');
    $statement = $pdo->prepare('SELECT m.heart_emoji, m.hex_color, o.emoji FROM members m JOIN oshis o ON o.id=m.oshi_id WHERE o.created_by_user_id=:id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    checkCsv($row && $row['heart_emoji'] === '🩷' && $row['emoji'] === '👑🐃', '絵文字が保持されていない');
    checkCsv(findMyOshis($pdo, $id) === [], 'CSVが個人の登録を変更した');
    runImport([...$arguments, '--apply'], 0);
    checkCsv(countTestOshis($pdo, $id) === 1, '再実行で二重投入された');
    file_put_contents($membersPath, "oshi_name,name,color_name,heart_emoji,hex_color\n{$name},メンバーA,black,🖤,#000000\n");
    runImport([...$arguments, '--apply'], 1);
    file_put_contents($membersPath, "oshi_name,name,color_name,heart_emoji,hex_color\n{$name},メンバーB,pink,●,#bad\n");
    runImport([...$arguments, '--apply'], 1);
    $statement->execute(['id' => $id]);
    checkCsv(count($statement->fetchAll()) === 1, 'エラー後に追加データが残った');
    echo "CSV checks passed: dry-run, apply, emoji, no follow, repeat, conflict, invalid input.\n";
} finally {
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('DELETE m FROM members m JOIN oshis o ON o.id=m.oshi_id WHERE o.created_by_user_id=:id');
        $statement->execute(['id' => $id]);
        $statement = $pdo->prepare('DELETE FROM oshis WHERE created_by_user_id=:id');
        $statement->execute(['id' => $id]);
        $statement = $pdo->prepare('DELETE FROM users WHERE id=:id AND email=:email');
        $statement->execute(['id' => $id, 'email' => "csv-{$key}@example.test"]);
        $pdo->commit();
    } catch (Throwable $exception) { $pdo->rollBack(); throw $exception; }
    foreach ([$oshisPath, $membersPath] as $file) if (is_file($file)) unlink($file);
    rmdir($directory);
    echo "CSV temporary records/files removed.\n";
}
