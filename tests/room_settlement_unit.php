<?php
/** room_settlement_unit.php の役割：DBを使わず精算例・相殺の決定性・異常データ・金額保存則を確認する。 */
declare(strict_types=1);
require_once __DIR__.'/../app/services/room_settlement_service.php';
$checks=0;
/** 指定した支払者と内訳で、保存済み共同支出と同じ入力を作る。 */
function expenseFixture(int $id,int $payer,array $shares,string $status='active'): array
{
    $parts=[];foreach($shares as $uid=>$amount)$parts[]=['user_id'=>$uid,'share_amount'=>$amount,'display_name_snapshot'=>'ユーザー'.$uid];
    return ['id'=>$id,'status'=>$status,'paid_by_user_id'=>$payer,'paid_by_name_snapshot'=>'ユーザー'.$payer,'total_amount'=>array_sum($shares),'shares'=>$parts];
}
/** 条件違反なら例外にして、PHPのassert設定に左右されず失敗させる。 */
function checkSettlement(bool $condition): void
{
    global $checks;if(!$condition)throw new RuntimeException('精算検証が失敗しました。');$checks++;
}
/** 期待する送金案と一致し、ユーザーごとの出入りが元の差額を再現することを調べる。 */
function verifySettlement(array $expenses,?array $expected=null): array
{
    $result=calculateRoomSettlement($expenses);
    if($expected!==null)checkSettlement($result['transfers']===$expected);
    checkSettlement(array_sum(array_column($result['balances'],'balance'))===0);
    $negative=0;$movement=[];
    foreach($result['balances'] as $row){if($row['balance']<0)$negative-=$row['balance'];$movement[$row['user_id']]=0;}
    $total=0;
    foreach($result['transfers'] as $t){checkSettlement($t['amount']>0 && $t['from_user_id']!==$t['to_user_id']);$movement[$t['from_user_id']]-=$t['amount'];$movement[$t['to_user_id']]+=$t['amount'];$total+=$t['amount'];}
    checkSettlement($total===$negative);
    foreach($result['balances'] as $row)checkSettlement($movement[$row['user_id']]===$row['balance']);
    checkSettlement(calculateRoomSettlement(array_reverse($expenses))===$result);
    return $result;
}
function transferFixture(int $from,int $to,int $amount): array {return ['from_user_id'=>$from,'to_user_id'=>$to,'amount'=>$amount];}
$hotel=expenseFixture(1,1,[1=>10000,2=>10000]);$travel=expenseFixture(2,2,[1=>6000,2=>6000]);
verifySettlement([$hotel],[transferFixture(2,1,10000)]);
verifySettlement([$hotel,$travel],[transferFixture(2,1,4000)]);
verifySettlement([expenseFixture(1,1,[2=>4000,3=>2000])],[transferFixture(2,1,4000),transferFixture(3,1,2000)]);
verifySettlement([expenseFixture(1,1,[3=>5000]),expenseFixture(2,2,[3=>3000])],[transferFixture(3,1,5000),transferFixture(3,2,3000)]);
verifySettlement([],[]);verifySettlement([expenseFixture(1,1,[1=>100])],[]);
verifySettlement([expenseFixture(1,1,[2=>100]),expenseFixture(2,2,[1=>100])],[]);
$cancelled=$travel;$cancelled['status']='cancelled';verifySettlement([$hotel,$cancelled],[transferFixture(2,1,10000)]);
verifySettlement([expenseFixture(1,1,[1=>3334,2=>3333,3=>3333])],[transferFixture(2,1,3333),transferFixture(3,1,3333)]);
verifySettlement([expenseFixture(1,1,[2=>1])],[transferFixture(2,1,1)]);
verifySettlement([expenseFixture(1,1,[3=>5]),expenseFixture(2,2,[4=>5])],[transferFixture(3,1,5),transferFixture(4,2,5)]);
verifySettlement([expenseFixture(1,1,[2=>999999999]),expenseFixture(2,1,[2=>999999999])],[transferFixture(2,1,1999999998)]);
foreach(['missing','mismatch','negative','fraction','duplicate','overflow'] as $bad) {
    $e=$hotel;
    if($bad==='missing')$e['shares']=[];
    if($bad==='mismatch')$e['total_amount']=1;
    if($bad==='negative')$e['shares'][0]['share_amount']=-1;
    if($bad==='fraction')$e['shares'][0]['share_amount']='1.5';
    if($bad==='duplicate')$e['shares'][1]['user_id']=1;
    if($bad==='overflow')$e['total_amount']='999999999999999999999';
    $thrown=false;try{calculateRoomSettlement([$e]);}catch(RoomSettlementDataException $error){$thrown=true;}checkSettlement($thrown);
}
// 固定seedの多数ケースで、相殺後も各人の差額が保たれることを検証する。
mt_srand(20261004);
for($case=0;$case<150;$case++){
    $expenses=[];for($j=1;$j<=12;$j++){$shares=[];for($uid=1;$uid<=7;$uid++)$shares[$uid]=mt_rand(0,10000);$expenses[]=expenseFixture($j,mt_rand(1,7),$shares);}
    verifySettlement($expenses);
}
echo "Settlement unit PASS: $checks checks; examples, deterministic netting, integer yen, invalid data and 150 fixed-seed cases.\n";
