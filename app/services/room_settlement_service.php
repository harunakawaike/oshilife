<?php
/** room_settlement_service.php の役割：有効な共同支出から精算案を整数円で算出する。結果や送金状態はDB保存しない。 */
declare(strict_types=1);
require_once __DIR__.'/../repositories/room_expense_repository.php';

/** 不整合な元データの計算結果を表示しないための専用例外。詳細な金銭データはメッセージに含めない。 */
class RoomSettlementDataException extends RuntimeException {}

/** DB値も信用せず整数・範囲を確認する。丸めて整合性を合わせない。 */
function settlementInteger(mixed $value, int $min, int $max): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[0-9]+\z/',(string)$value)) throw new RoomSettlementDataException('共同支出の金額・IDを確認してください。');
    $number=filter_var($value,FILTER_VALIDATE_INT);
    if ($number===false || $number<$min || $number>$max) throw new RoomSettlementDataException('共同支出の金額・IDを確認してください。');
    return $number;
}

/** 合計がPHP整数の上限を超える場合は、浮動小数へ変換せず計算を停止する。 */
function settlementAdd(int $left, int $right): int
{
    if ($left>PHP_INT_MAX-$right) throw new RoomSettlementDataException('精算額の上限を超えています。');
    return $left+$right;
}

/** 副作用のない共通計算。DB・UIに依存せず、同じ入力なら同じ送金案を返す。 */
function calculateRoomSettlement(array $expenses): array
{
    // 改名時の名前は、最新の有効支出IDに保存されたsnapshotを採用する。同じ支出では支払者の保存名を優先。
    usort($expenses,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
    $people=[];$totalPaid=0;
    foreach($expenses as $expense) {
        if ($expense['status']!=='active') continue;
        $payer=settlementInteger($expense['paid_by_user_id'],1,PHP_INT_MAX);
        $amount=settlementInteger($expense['total_amount'],1,ROOM_EXPENSE_MAX_YEN);
        $shares=$expense['shares']??[];
        if (!$shares) throw new RoomSettlementDataException('負担内訳がありません。');
        $totalPaid=settlementAdd($totalPaid,$amount);
        if (!isset($people[$payer])) $people[$payer]=['user_id'=>$payer,'display_name'=>'','paid_total'=>0,'share_total'=>0];
        $people[$payer]['display_name']=$expense['paid_by_name_snapshot'];
        $people[$payer]['paid_total']=settlementAdd($people[$payer]['paid_total'],$amount);
        $sum=0;$seen=[];
        foreach($shares as $share) {
            $uid=settlementInteger($share['user_id'],1,PHP_INT_MAX);
            $part=settlementInteger($share['share_amount'],0,ROOM_EXPENSE_MAX_YEN);
            if (isset($seen[$uid])) throw new RoomSettlementDataException('負担内訳が重複しています。');
            $seen[$uid]=true;$sum=settlementAdd($sum,$part);
            if (!isset($people[$uid])) $people[$uid]=['user_id'=>$uid,'display_name'=>'','paid_total'=>0,'share_total'=>0];
            if ($uid!==$payer) $people[$uid]['display_name']=$share['display_name_snapshot'];
            $people[$uid]['share_total']=settlementAdd($people[$uid]['share_total'],$part);
        }
        if ($sum!==$amount) throw new RoomSettlementDataException('負担額合計が総額と一致していません。');
    }
    ksort($people,SORT_NUMERIC);$receivers=[];$payers=[];$positive=0;$negative=0;
    foreach($people as $uid=>&$person) {
        $person['balance']=$person['paid_total']-$person['share_total'];
        if ($person['balance']>0) {$receivers[]=['user_id'=>$uid,'remaining'=>$person['balance']];$positive=settlementAdd($positive,$person['balance']);}
        if ($person['balance']<0) {$payers[]=['user_id'=>$uid,'remaining'=>-$person['balance']];$negative=settlementAdd($negative,-$person['balance']);}
    }
    unset($person);
    // 正の差額と負の差額の絶対値が等しければ、全員のnet_balanceの合計は0。
    if ($positive!==$negative) throw new RoomSettlementDataException('差額合計が0になりません。');
    $order=fn($a,$b)=>($b['remaining']<=>$a['remaining']) ?: ($a['user_id']<=>$b['user_id']);
    usort($receivers,$order);usort($payers,$order);
    $transfers=[];$received=[];$sent=[];$i=0;$j=0;$transferTotal=0;
    // 金額の大きい順に相殺する単純な方式。最小送金回数の厳密な最適化ではない。
    while($i<count($payers) && $j<count($receivers)) {
        $amount=min($payers[$i]['remaining'],$receivers[$j]['remaining']);
        $from=$payers[$i]['user_id'];$to=$receivers[$j]['user_id'];
        $transfers[]=['from_user_id'=>$from,'to_user_id'=>$to,'amount'=>$amount];
        $sent[$from]=settlementAdd($sent[$from]??0,$amount);$received[$to]=settlementAdd($received[$to]??0,$amount);
        $transferTotal=settlementAdd($transferTotal,$amount);
        $payers[$i]['remaining']-=$amount;$receivers[$j]['remaining']-=$amount;
        if ($payers[$i]['remaining']===0) $i++;
        if ($receivers[$j]['remaining']===0) $j++;
    }
    if ($transferTotal!==$negative) throw new RoomSettlementDataException('精算額合計が一致しません。');
    foreach($people as $uid=>$person) if (($received[$uid]??0)-($sent[$uid]??0)!==$person['balance']) throw new RoomSettlementDataException('個人の精算額が一致しません。');
    return ['balances'=>array_values($people),'transfers'=>$transfers];
}

/** 閲覧者はactiveメンバー限定。1回のSELECTで本体・内訳を読み、同時編集で別時点の行を混ぜない。 */
function roomSettlementSummary(PDO $pdo, int $userId, int $roomId): array
{
    requireRoom($pdo,$userId,$roomId);
    // 退出状態で絞らない。過去の支払者・負担者はsnapshotとIDで計算に含める。
    $rows=eventQuery($pdo,"SELECT e.id,e.status,e.total_amount,e.paid_by_user_id,e.paid_by_name_snapshot,
        s.user_id AS share_user_id,s.display_name_snapshot,s.share_amount
        FROM room_expenses e LEFT JOIN room_expense_members s ON s.room_expense_id=e.id AND s.room_id=e.room_id
        WHERE e.room_id=? AND e.status='active' ORDER BY e.id,s.id",[$roomId])->fetchAll();
    $expenses=[];
    foreach($rows as $row) {
        $id=(int)$row['id'];
        if (!isset($expenses[$id])) $expenses[$id]=['id'=>$id,'status'=>$row['status'],'total_amount'=>$row['total_amount'],'paid_by_user_id'=>$row['paid_by_user_id'],'paid_by_name_snapshot'=>$row['paid_by_name_snapshot'],'shares'=>[]];
        if ($row['share_user_id']!==null) $expenses[$id]['shares'][]=['user_id'=>$row['share_user_id'],'display_name_snapshot'=>$row['display_name_snapshot'],'share_amount'=>$row['share_amount']];
    }
    return calculateRoomSettlement(array_values($expenses));
}
