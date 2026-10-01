<?php
/** money_repository.php の役割：本人の積立・支出の取得と、年間・月間・分類別集計を担当する。 */
declare(strict_types=1);
require_once __DIR__.'/event_repository.php';
require_once __DIR__.'/../../config/money.php';
/** 金額の小数を整数の100分の1円へ変換し、残高の計算で浮動小数の誤差を避ける。 */
function moneyCents(string $amount): int
{
    $negative = str_starts_with($amount, '-');
    $parts = explode('.', ltrim($amount, '-'));
    $value = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
    return $negative ? -$value : $value;
}
/** APIの金額は小数2桁の文字列で返し、端数とマイナス残高を保持する。 */
function moneyDecimal(int $cents): string
{
    $absolute = abs($cents);
    return ($cents < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
}
/** 共通積立はoshi_id IS NULL。推し別へ按分せず、必要な条件だけを加える。 */
function moneyScope(int $userId, ?int $oshiId, bool $common = false): array
{
    $where = 'm.user_id=:user';
    $values = ['user'=>$userId];
    if ($common) $where .= ' AND m.oshi_id IS NULL';
    elseif ($oshiId !== null) {
        $where .= ' AND m.oshi_id=:oshi';
        $values['oshi'] = $oshiId;
    }
    return [$where, $values];
}
/** SUMは列の合計。年の前・対象年の2区間を分けて積立と支出を別々に合計する。 */
function moneyYearTotals(PDO $pdo, string $table, int $userId, int $year, ?int $oshiId, bool $common = false): array
{
    $date = $table === 'savings' ? 'saving_date' : 'expense_date';
    [$where, $values] = moneyScope($userId, $oshiId, $common);
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-01-01', $year + 1);
    $values += ['start'=>$start, 'end'=>$end];
    // テーブル名・列名はプログラム内の固定値。値はliveQueryのprepare/executeで束縛する。
    $row = eventQuery($pdo, "SELECT
        COALESCE(SUM(CASE WHEN m.$date<:start THEN m.amount ELSE 0 END),0) AS past,
        COALESCE(SUM(m.amount),0) AS total
        FROM $table m WHERE $where AND m.$date<:end", $values)->fetch();
    return ['past'=>moneyCents($row['past']), 'current'=>moneyCents($row['total'])-moneyCents($row['past'])];
}
/** 年間ダッシュボード。推し別では共通積立の残高への加算をせず参考情報で返す。 */
function moneyDashboard(PDO $pdo, int $userId, int $year, ?int $oshiId): array
{
    $saved = moneyYearTotals($pdo, 'savings', $userId, $year, $oshiId);
    $spent = moneyYearTotals($pdo, 'expenses', $userId, $year, $oshiId);
    $common = moneyYearTotals($pdo, 'savings', $userId, $year, null, true);
    $carry = $saved['past'] - $spent['past'];
    [$where, $values] = moneyScope($userId, $oshiId);
    $values += ['start'=>sprintf('%04d-01-01',$year), 'end'=>sprintf('%04d-01-01',$year+1)];
    $eligible = eventQuery($pdo, "SELECT COALESCE(SUM(m.amount),0) FROM expenses m
        WHERE $where AND m.expense_date>=:start AND m.expense_date<:end AND m.special_effect_eligible=1", $values)->fetchColumn();
    return [
        'year'=>$year, 'oshi_id'=>$oshiId, 'carryover'=>moneyDecimal($carry),
        'savings'=>moneyDecimal($saved['current']), 'expenses'=>moneyDecimal($spent['current']),
        'balance'=>moneyDecimal($carry+$saved['current']-$spent['current']),
        'special_effect_eligible'=>moneyDecimal(moneyCents($eligible)),
        'common_savings'=>moneyDecimal($common['current']),
        'common_savings_before_year'=>moneyDecimal($common['past']),
    ];
}
/** GROUP BYは行を月・カテゴリ・推しでまとめる。推し別は名前順で、金額ランキングにしない。 */
function moneyBreakdown(PDO $pdo, int $userId, int $year, ?int $oshiId, string $kind): array
{
    [$where, $values] = moneyScope($userId, $oshiId);
    $values += ['start'=>sprintf('%04d-01-01',$year), 'end'=>sprintf('%04d-01-01',$year+1)];
    $expression = ['monthly'=>'MONTH(m.expense_date)', 'categories'=>'m.category', 'by-oshi'=>'m.oshi_id'][$kind];
    $rows = eventQuery($pdo, "SELECT $expression AS item_key, SUM(m.amount) AS amount
        FROM expenses m WHERE $where AND m.expense_date>=:start AND m.expense_date<:end
        GROUP BY $expression ORDER BY $expression", $values)->fetchAll();
    if ($kind === 'monthly') {
        $totals = array_column($rows, 'amount', 'item_key');
        return array_map(fn($month)=>['month'=>$month,'label'=>$month.'月','amount'=>$totals[$month]??'0.00'], range(1,12));
    }
    if ($kind === 'categories') {
        $totals = array_column($rows, 'amount', 'item_key');
        $result = [];
        foreach (MONEY_CATEGORIES as $key=>$category) $result[] = ['category'=>$key,'label'=>$category['label'],'amount'=>$totals[$key]??'0.00'];
        return $result;
    }
    $names = [];
    foreach (eventQuery($pdo, 'SELECT id,name,emoji FROM oshis')->fetchAll() as $oshi) $names[$oshi['id']] = $oshi['emoji'].' '.$oshi['name'];
    foreach ($rows as &$row) $row = ['oshi_id'=>$row['item_key'], 'label'=>$row['item_key']===null?'共通・未指定':($names[$row['item_key']]??'推し'), 'amount'=>$row['amount']];
    unset($row);
    usort($rows, fn($a,$b)=>strcmp($a['label'],$b['label']));
    return $rows;
}
/** 特効は登録時に保存したeligibleだけで計算し、将来カテゴリ設定が変わっても過去を再判定しない。 */
function moneySpecialEffects(string $eligible): array
{
    $effects = MONEY_EFFECTS;
    foreach ($effects as &$effect) $effect['shots'] = moneyCents($eligible) / ($effect['unit_price'] * 100);
    return ['eligible_amount'=>$eligible, 'effects'=>$effects, 'notice'=>MONEY_EFFECT_NOTICE];
}
/** IDだけでなく本人IDを必須条件にするので、他人のお金情報は取得できない。 */
function findMoneyRecord(PDO $pdo, string $table, int $userId, int $id, bool $lock = false): array
{
    return eventQuery($pdo, "SELECT * FROM $table WHERE id=? AND user_id=?".($lock?' FOR UPDATE':''), [$id,$userId])->fetch()
        ?: throw new ScheduleOperationException('対象の記録が見つかりません。',404);
}
/** 一覧も本人・対象年・推しを限定し、編集画面へのIDだけを返す。 */
function listMoneyRecords(PDO $pdo, string $table, int $userId, int $year, ?int $oshiId, int $page): array
{
    [$where, $values] = moneyScope($userId,$oshiId);
    $date = $table==='savings'?'saving_date':'expense_date';
    $values += ['start'=>sprintf('%04d-01-01',$year),'end'=>sprintf('%04d-01-01',$year+1)];
    $where .= " AND m.$date>=:start AND m.$date<:end";
    $total = (int)eventQuery($pdo, "SELECT COUNT(*) FROM $table m WHERE $where", $values)->fetchColumn();
    $rows = eventQuery($pdo, "SELECT m.*,o.name AS oshi_name,o.emoji AS oshi_emoji FROM $table m
        LEFT JOIN oshis o ON o.id=m.oshi_id WHERE $where ORDER BY m.$date DESC,m.id DESC LIMIT 30 OFFSET ".(($page-1)*30), $values)->fetchAll();
    return ['items'=>$rows,'total'=>$total,'has_more'=>$page*30<$total];
}
/** 支払い済み候補を本人限定で取得。表示と保存時に同じ反映条件を使う。 */
function moneySource(PDO $pdo, int $userId, string $type, int $id, bool $lock = false): array
{
    if (!in_array($type,['live_ticket','transportation','accommodation'],true)) {
        throw new ScheduleOperationException('反映元が正しくありません。',422);
    }
    if ($type === 'live_ticket') {
        // source_idは共有公演IDではなく、本人のuser_event_status.id。
        $row = eventQuery($pdo,'SELECT * FROM user_event_status WHERE id=? AND user_id=?'.($lock?' FOR UPDATE':''),[$id,$userId])->fetch();
        if (!$row) throw new ScheduleOperationException('本人のチケット情報が見つかりません。',404);
        $event = findEvent($pdo,$userId,(int)$row['event_id']);
        $amount = $row['ticket_amount'];
        $paid = $row['ticket_payment_status']==='paid';
        $ready = $row['participation_status']==='confirmed' && $paid && moneyCents($amount??'0')>0;
        $expenseId = $row['ticket_expense_id'];
        $title = mb_substr($event['title'],0,110,'UTF-8').' '.$event['event_date'].' チケット';
        $date = $row['ticket_paid_date']??date('Y-m-d');
        $tripId = $event['trip_id'];
        $payment = $amount!==null && moneyCents($amount)===0 ? 'not_required' : ($paid?'paid':'unpaid');
        $reservation = null;
        $reason = $payment==='not_required' ? '0円のため、お金管理への反映は不要です。' : '参加確定・金額が0円より大きい・支払い済みの3条件を確認してください。';
    } else {
        $table = $type==='transportation'?'transportations':'accommodations';
        $row = eventQuery($pdo,"SELECT item.*,t.event_id FROM $table item JOIN trips t ON t.id=item.trip_id WHERE item.id=? AND t.user_id=?",[$id,$userId])->fetch();
        if (!$row) throw new ScheduleOperationException('反映できる予約が見つかりません。',404);
        if ($lock) {
            // 予約編集と同じ親→子の順番でロックし、確認後の支払い取消も再検査する。
            requireOwnTrip($pdo,$userId,(int)$row['trip_id'],true);
            $locked = eventQuery($pdo,"SELECT * FROM $table WHERE id=? AND trip_id=? FOR UPDATE",[$id,$row['trip_id']])->fetch();
            if (!$locked) throw new ScheduleOperationException('反映できる予約が見つかりません。',404);
            $row = $locked + ['event_id'=>$row['event_id']];
        }
        $event = findEvent($pdo,$userId,(int)$row['event_id']);
        $amount = $row['amount'];
        $payment = $row['payment_status'];
        $reservation = $row['reservation_status'];
        $ready = $reservation==='reserved' && $payment==='paid' && moneyCents($amount??'0')>0;
        $expenseId = $row['expense_id'];
        $title = $type==='transportation'
            ? (($row['transport_type']==='other'?$row['transport_type_other']:TRANSPORT_TYPES[$row['transport_type']]).' '.$row['departure_place'].' → '.$row['arrival_place'])
            : $row['hotel_name'].' 宿泊';
        $date = $row['paid_date']??date('Y-m-d');
        $tripId = $row['trip_id'];
        $reason = '予約済み・支払い済み・金額が0円より大きい状態で反映できます。';
    }
    $expense = $expenseId===null ? false : eventQuery($pdo,'SELECT amount FROM expenses WHERE id=? AND user_id=?',[$expenseId,$userId])->fetch();
    return [
        'title'=>mb_substr($title,0,150,'UTF-8'),'amount'=>$amount,'category'=>$type,
        'expense_date'=>$date,'oshi_id'=>$event['oshi_id'],'event_id'=>$event['id'],'trip_id'=>$tripId,
        'note'=>$type==='live_ticket'?'':$row['note'],'source_type'=>$type,'source_id'=>$id,
        'special_effect_eligible'=>$type==='live_ticket',
        'reservation_status'=>$reservation,'payment_status'=>$payment,
        'expense_id'=>$expenseId,'expense_amount'=>$expense?$expense['amount']:null,
        'amount_changed'=>$expense && ($amount===null || moneyCents($expense['amount'])!==moneyCents($amount)),
        'can_import'=>$ready && $expenseId===null,'import_reason'=>$reason,
    ];
}

/** 保存した支出IDを元データへ記録する。呼び出し元のトランザクションで一緒に確定する。 */
function linkMoneySource(PDO $pdo,string $type,int $sourceId,int $expenseId): void
{
    $table = ['live_ticket'=>'user_event_status','transportation'=>'transportations','accommodation'=>'accommodations'][$type];
    $column = $type==='live_ticket'?'ticket_expense_id':'expense_id';
    eventQuery($pdo,"UPDATE $table SET $column=? WHERE id=?",[$expenseId,$sourceId]);
}
