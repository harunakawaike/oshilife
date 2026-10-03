<?php
/** room_expenses_constraints.php の役割：検証DBで共同支出の外部キー・一意性・金額制約を確認し、試験行を戻す。 */
declare(strict_types=1);
$dbName=$argv[1]??'';
if (!preg_match('/\Aoshilife_v2_rooms_verify_expenses_[0-9_]+\z/',$dbName)) throw new RuntimeException('専用検証DBだけで実行してください。');
require_once __DIR__.'/../app/helpers/env.php';loadEnv(__DIR__.'/../.env');
$pdo=new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306').';dbname='.$dbName.';charset=utf8mb4',env('DB_USER'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
/** 想定した制約違反以外のエラーは隠さない。 */
function expectExpenseConstraint(PDO $pdo,string $sql,array $values,array $codes): void
{
    try {$s=$pdo->prepare($sql);$s->execute($values);}
    catch(PDOException $e){if(in_array((int)$e->errorInfo[1],$codes,true))return;throw $e;}
    throw new RuntimeException('不正なデータが保存されました。');
}
$users=$pdo->query('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if(count($users)!==2)throw new RuntimeException('元DBの検証ユーザーが足りません。');
[$a,$b]=$users;$pdo->beginTransaction();
try {
    $rooms=[];
    foreach([$a,$b] as $uid) {
        $pdo->prepare("INSERT INTO rooms(owner_user_id,name) VALUES(?,'共同支出制約テスト')")->execute([$uid]);$rid=(int)$pdo->lastInsertId();$rooms[]=$rid;
        $pdo->prepare("INSERT INTO room_members(room_id,user_id,room_owner_user_id,role) VALUES(?,?,?,'owner')")->execute([$rid,$uid,$uid]);
    }
    [$room,$other]=$rooms;
    $insert="INSERT INTO room_expenses(room_id,created_by_user_id,created_by_name_snapshot,title,category,total_amount,paid_by_user_id,paid_by_name_snapshot,expense_date) VALUES(?,?,'A','宿泊','accommodation',100,?,'A','2026-10-03')";
    $s=$pdo->prepare($insert);$s->execute([$room,$a,$a]);$id=(int)$pdo->lastInsertId();
    expectExpenseConstraint($pdo,$insert,[$room,$b,$a],[1452]);
    expectExpenseConstraint($pdo,$insert,[$room,$a,$b],[1452]);
    $share="INSERT INTO room_expense_members(room_expense_id,room_id,user_id,display_name_snapshot,share_amount) VALUES(?,?,?,'A',100)";
    $pdo->prepare($share)->execute([$id,$room,$a]);
    expectExpenseConstraint($pdo,$share,[$id,$room,$a],[1062]);
    expectExpenseConstraint($pdo,$share,[$id,$room,$b],[1452]);
    expectExpenseConstraint($pdo,$share,[$id,$other,$b],[1452]);
    foreach(["total_amount=0","total_amount=1000000000","category='unknown'","status='deleted'","version=0"] as $set) {
        expectExpenseConstraint($pdo,'UPDATE room_expenses SET '.$set.' WHERE id=?',[$id],[4025]);
    }
    expectExpenseConstraint($pdo,'UPDATE room_expense_members SET share_amount=1000000000 WHERE room_expense_id=?',[$id],[4025]);
    expectExpenseConstraint($pdo,'DELETE FROM room_expenses WHERE id=?',[$id],[1451]);
    expectExpenseConstraint($pdo,'DELETE FROM room_members WHERE room_id=?',[$room],[1451]);
    echo "Room expense constraints PASS: creator/payer/share membership FKs, room consistency, UNIQUE, amount/category/status/version checks, history RESTRICT.\n";
} finally {$pdo->rollBack();}
