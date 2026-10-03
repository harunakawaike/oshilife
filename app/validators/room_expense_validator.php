<?php
/** room_expense_validator.php の役割：共同支出の整数円・内訳・日付をPHP側で再検証する。 */
declare(strict_types=1);
require_once __DIR__.'/event_validator.php';
require_once __DIR__.'/../../config/room_expenses.php';

/** 日本円は整数で扱い、小数の丸め誤差や巨大な入力を避ける。文字列の数字もフォーム用に許可する。 */
function roomExpenseYen(mixed $value, bool $positive = false): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[0-9]{1,9}\z/',(string)$value)
        || (int)$value>ROOM_EXPENSE_MAX_YEN || ($positive && (int)$value<1)) {
        throw new ScheduleOperationException('金額は'.($positive?'1':'0').'〜999,999,999円の整数で入力してください。',422);
    }
    return (int)$value;
}

/** 名前ではなくIDで負担者を識別し、同じ人の重複や総額との不一致を保存前に拒否する。 */
function validateRoomExpenseInput(array $raw): array
{
    $payer=positiveOshiId($raw['paid_by_user_id']??null);
    if ($payer===null) throw new ScheduleOperationException('支払者を選択してください。',422);
    $shares=$raw['shares']??null;
    if (!is_array($shares) || !array_is_list($shares) || count($shares)===0 || count($shares)>1000) throw new ScheduleOperationException('負担メンバーを1人以上選択してください。',422);
    $clean=[];$sum=0;
    foreach($shares as $share) {
        // 共通readJsonは入れ子をstdClassで返すため、この境界で連想配列へ揃える。
        if ($share instanceof stdClass) $share=(array)$share;
        if (!is_array($share)) throw new ScheduleOperationException('負担内訳の形式を確認してください。',422);
        $id=positiveOshiId($share['user_id']??null);
        if ($id===null || isset($clean[$id])) throw new ScheduleOperationException('負担メンバーの指定が不正または重複しています。',422);
        $amount=roomExpenseYen($share['share_amount']??null);
        $clean[$id]=['user_id'=>$id,'share_amount'=>$amount];$sum+=$amount;
    }
    $total=roomExpenseYen($raw['total_amount']??null,true);
    if ($sum!==$total) throw new ScheduleOperationException('負担額の合計が支出総額と一致していません',422);
    return ['title'=>eventText($raw,'title','支出名',150,true),'category'=>eventChoice($raw,'category',ROOM_EXPENSE_CATEGORIES),
        'total_amount'=>$total,'paid_by_user_id'=>$payer,'expense_date'=>eventDate($raw,'expense_date'),
        'note'=>eventText($raw,'note','メモ',3000),'shares'=>$clean];
}
