<?php
/** rooms_constraints.php の役割：専用検証DBでowner・旧招待履歴・招待リンク・中間テーブルの制約を検査し、試験行をロールバックする。 */
declare(strict_types=1);
$dbName=$argv[1]??'';
if (!preg_match('/^oshilife_v2_rooms_verify_(?:links_)?[0-9_]+$/D',$dbName)) throw new RuntimeException('ルーム専用検証DBを指定してください。');
require_once __DIR__.'/../app/helpers/env.php';loadEnv(__DIR__.'/../.env');
$pdo=new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306').';dbname='.$dbName.';charset=utf8mb4',env('DB_USER'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
/** 期待した制約違反だけを合格とし、接続・SQL構文など別の失敗を見逃さない。 */
function expectRoomConstraint(PDO $pdo,string $sql,array $values,array $codes): void
{
    try {$s=$pdo->prepare($sql);$s->execute($values);}
    catch(PDOException $error){if(in_array((int)$error->errorInfo[1],$codes,true))return;throw $error;}
    throw new RuntimeException('不正な行が受け入れられました。');
}
$users=$pdo->query('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$event=$pdo->query('SELECT id FROM events ORDER BY id LIMIT 1')->fetchColumn();
if(count($users)<2 || !$event)throw new RuntimeException('検証用の元ユーザー2名・イベントが必要です。');
[$owner,$member]=$users;
$pdo->beginTransaction();
try {
    $s=$pdo->prepare("INSERT INTO rooms(owner_user_id,name) VALUES(?,'制約検証')");$s->execute([$owner]);$room=(int)$pdo->lastInsertId();
    $memberSql='INSERT INTO room_members(room_id,user_id,room_owner_user_id,role) VALUES(?,?,?,?)';
    expectRoomConstraint($pdo,$memberSql,[$room,$owner,$member,'member'],[1452]);
    $s=$pdo->prepare($memberSql);$s->execute([$room,$owner,$owner,'owner']);
    expectRoomConstraint($pdo,$memberSql,[$room,$owner,$owner,'owner'],[1062]);
    expectRoomConstraint($pdo,$memberSql,[$room,$member,$owner,'owner'],[4025,1062]);
    expectRoomConstraint($pdo,$memberSql,[$room,$owner,$owner,'member'],[4025,1062]);
    expectRoomConstraint($pdo,"UPDATE room_members SET status='left' WHERE room_id=?",[$room],[4025]);
    $inviteSql='INSERT INTO room_invitations(room_id,inviter_user_id,invited_user_id) VALUES(?,?,?)';
    $s=$pdo->prepare($inviteSql);$s->execute([$room,$owner,$member]);
    expectRoomConstraint($pdo,$inviteSql,[$room,$owner,$member],[1062]);
    expectRoomConstraint($pdo,$inviteSql,[$room,$member,$owner],[1452]);
    expectRoomConstraint($pdo,$inviteSql,[$room,$owner,$owner],[4025]);
    expectRoomConstraint($pdo,"UPDATE room_invitations SET status='accepted' WHERE room_id=?",[$room],[4025]);
    $pdo->prepare("UPDATE room_invitations SET status='declined',responded_at=CURRENT_TIMESTAMP WHERE room_id=?")->execute([$room]);
    $s->execute([$room,$owner,$member]); // 過去の招待を残しながら再招待可能。
    $eventSql='INSERT INTO room_events(room_id,event_id) VALUES(?,?)';$s=$pdo->prepare($eventSql);$s->execute([$room,$event]);
    expectRoomConstraint($pdo,$eventSql,[$room,$event],[1062]);
    expectRoomConstraint($pdo,$eventSql,[$room,0],[1452]);
    expectRoomConstraint($pdo,'DELETE FROM rooms WHERE id=?',[$room],[1451]);
    $linkSql="INSERT INTO room_invite_links(room_id,created_by_user_id,token_hash,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))";
    $hash=hash('sha256',random_bytes(32));$s=$pdo->prepare($linkSql);$s->execute([$room,$owner,$hash]);$linkId=(int)$pdo->lastInsertId();
    expectRoomConstraint($pdo,$linkSql,[$room,$owner,$hash],[1062]);
    expectRoomConstraint($pdo,$linkSql,[$room,$member,hash('sha256',random_bytes(32))],[1452]);
    expectRoomConstraint($pdo,"UPDATE room_invite_links SET status='wrong' WHERE id=?",[$linkId],[4025]);
    expectRoomConstraint($pdo,"UPDATE room_invite_links SET max_uses=0 WHERE id=?",[$linkId],[4025]);
    expectRoomConstraint($pdo,"UPDATE room_invite_links SET max_uses=1,used_count=2 WHERE id=?",[$linkId],[4025]);
    echo "Room DB constraints PASS: owner consistency/uniqueness, member uniqueness, pending uniqueness, invitation sender, status/time, event uniqueness/FK, history retention; link hash uniqueness, owner FK, status, max uses.\n";
} finally {$pdo->rollBack();}
