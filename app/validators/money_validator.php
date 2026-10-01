<?php
/** money_validator.php の役割：お金の入力値・対象年・本人の推しとイベントの整合を検証する。 */
declare(strict_types=1);
require_once __DIR__.'/event_validator.php';
require_once __DIR__.'/../../config/money.php';
/** 未選択をNULLとして扱い、配列や負数をIDとして受け入れない。 */
function moneyOptionalId(mixed $value): ?int
{
    if ($value===null || $value==='') return null;
    $id = positiveOshiId($value);
    if ($id===null) throw new ScheduleOperationException('対象の指定が正しくありません。',422);
    return $id;
}
/** 年は4桁。翌年の境界もDATE型の範囲に収まるよう9998年までにする。 */
function moneyYear(mixed $value): int
{
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{3}$/D',(string)$value) || (int)$value>9998) {
        throw new ScheduleOperationException('年は1000〜9998の範囲で指定してください。',422);
    }
    return (int)$value;
}
/** 登録解除後の過去記録は編集できる。新しい推しの指定は自分の登録一覧から選ぶ。 */
function validateMoneyOshi(PDO $pdo, int $userId, ?int $oshiId, ?array $existing): void
{
    if ($oshiId===null || ($existing && (int)$existing['oshi_id']===$oshiId)) return;
    if (!eventQuery($pdo,'SELECT id FROM user_oshis WHERE user_id=? AND oshi_id=?',[$userId,$oshiId])->fetch()) {
        throw new ScheduleOperationException('対象の推しを先に推し管理へ登録してください。',422);
    }
}
/** 共通の金額とメモを検証する。予約と同じ小数2桁を許可し、丸めて切り捨てない。 */
function validateMoneyInput(array $raw, string $table): array
{
    $amount = eventAmount($raw);
    if ($amount===null) throw new ScheduleOperationException('金額を入力してください。',422);
    $values = ['oshi_id'=>moneyOptionalId($raw['oshi_id']??null),'amount'=>$amount,'note'=>eventText($raw,'note','メモ',3000)];
    $date = $table==='savings'?'saving_date':'expense_date';
    $values[$date] = eventDate($raw,$date);
    moneyYear(substr($values[$date],0,4));
    if ($table==='savings') return $values;
    $category = eventChoice($raw,'category',MONEY_CATEGORIES);
    $eligible = array_key_exists('special_effect_eligible',$raw) ? scheduleBoolean($raw['special_effect_eligible']) : MONEY_CATEGORIES[$category]['eligible'];
    if ($eligible===null) throw new ScheduleOperationException('特効換算対象を確認してください。',422);
    // 手数料・交通・宿泊・周辺費用は直接の推し活支出ではないので、改ざんされたtrueも拒否する。
    if ($eligible && !MONEY_CATEGORIES[$category]['eligible']) throw new ScheduleOperationException('手数料・交通・宿泊・周辺費用は特効換算の対象外です。',422);
    return $values + [
        'title'=>eventText($raw,'title','支出名',150,true),'category'=>$category,
        'event_id'=>moneyOptionalId($raw['event_id']??null),
        'trip_id'=>moneyOptionalId($raw['trip_id']??null),'special_effect_eligible'=>(int)$eligible,
    ];
}
