<?php
/** participants_constraints.php の役割：専用復元検証DBで同行者のDB制約を確認し、追加行をロールバックする。 */
declare(strict_types=1);
$dbName = $argv[1] ?? '';
if (!preg_match('/^oshilife_v2_step31_verify_[0-9_]+$/D', $dbName)) {
    throw new RuntimeException('Step 3-1の専用復元検証DBを指定してください。');
}
require_once __DIR__.'/../app/helpers/env.php';
loadEnv(__DIR__.'/../.env');
$pdo = new PDO('mysql:host='.env('DB_HOST', '127.0.0.1').';port='.env('DB_PORT', '3306').';dbname='.$dbName.';charset=utf8mb4', env('DB_USER'), env('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);

/** DB制約違反で拒否されることを確認する。接続不良等は合格としない。 */
function expectParticipantConstraint(PDO $pdo, string $sql, array $values, int $code): void
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($values);
    } catch (PDOException $error) {
        if ((int)$error->errorInfo[1] === $code) return;
        throw $error;
    }
    throw new RuntimeException('DBが不正な行を受け付けました。');
}
$management = $pdo->query('SELECT user_id,event_id FROM user_event_status ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$management) throw new RuntimeException('検証元の本人管理がありません。');
$values = [$management['user_id'], $management['event_id']];
$insert = "INSERT INTO event_participants (user_id,event_id,name,note,self_marker,archived_at) VALUES (?,?,'検証','',?,?)";
$pdo->beginTransaction();
try {
    $statement=$pdo->prepare($insert);
    $statement->execute([...$values,1,null]);
    expectParticipantConstraint($pdo,$insert,[...$values,1,null],1062);
    $statement->execute([...$values,null,null]);
    $statement->execute([...$values,null,null]); // 同名の同行者は複数許可。
    expectParticipantConstraint($pdo,$insert,[...$values,0,null],4025);
    expectParticipantConstraint($pdo,$insert,[...$values,1,'2026-10-02 00:00:00'],4025);
    expectParticipantConstraint($pdo,$insert,[0,$values[1],null,null],1452);
    expectParticipantConstraint($pdo,'DELETE FROM user_event_status WHERE user_id=? AND event_id=?',$values,1451);
    echo "DB constraints PASS: self UNIQUE, same names, CHECK, owner/event FK, parent deletion RESTRICT; all test inserts rolled back.\n";
} finally {
    $pdo->rollBack();
}
