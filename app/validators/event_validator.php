<?php
/** event_validator.php の役割：共有公演・個人管理・遠征の入力を、保存前に検証する。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_validator.php';
require_once __DIR__.'/../../config/events.php';
/** 配列等の型違いも空値として通さず、文字数と必須入力を確認する。 */
function eventText(array $raw, string $key, string $label, int $max, bool $required = false): string
{
    $value=$raw[$key]??'';
    if (!is_string($value)) throw new ScheduleOperationException($label.'の形式が正しくありません。',422);
    $value=trim($value);
    if (($required && $value==='') || mb_strlen($value,'UTF-8')>$max || str_contains($value,"\0")) throw new ScheduleOperationException($label.'は'.($required?'1〜':'').''.$max.'文字以内で入力してください。',422);
    return $value;
}
/** 選択値の許可リストを通し、将来の状態追加を設定ファイルへ集約する。 */
function eventChoice(array $raw, string $key, array $choices): string
{
    $value=$raw[$key]??null;
    if (!is_string($value) || !isset($choices[$value])) throw new ScheduleOperationException('選択項目を確認してください。',422);
    return $value;
}
/** 日付・時刻は実在する形式だけを受け付ける。 */
function eventDate(array $raw, string $key, bool $optional = false): ?string
{
    $value=$raw[$key]??'';
    if ($optional && ($value===null || $value==='')) return null;
    if (!is_string($value) || !validScheduleDate($value)) throw new ScheduleOperationException('日付を正しく入力してください。',422);
    return $value;
}
/** datetime-local入力を日本時間のSQL形式へ変換し、2月30日などを拒否する。 */
function eventDateTime(array $raw, string $key): string
{
    $value=$raw[$key]??null;
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D',$value)) throw new ScheduleOperationException('日時を入力してください。',422);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value);
    if (!$date || $date->format('Y-m-d\TH:i')!==$value) throw new ScheduleOperationException('日時を正しく入力してください。',422);
    return $date->format('Y-m-d H:i:s');
}
/** 金額は小数2桁までの非負数。浮動小数へ変換せずDECIMALへ文字列で渡す。 */
function eventAmount(array $raw): ?string
{
    $value=$raw['amount']??null;
    if ($value===null || $value==='') return null;
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D',(string)$value)) throw new ScheduleOperationException('金額は0以上、小数2桁までで入力してください。',422);
    return (string)$value;
}
/** 外部URLは安全なhttp/httpsリンクだけ。サーバーからアクセスはしない。 */
function eventUrl(array $raw, string $key): ?string
{
    $value=eventText($raw,$key,'URL',2048);
    $parts=parse_url($value);
    if ($value!=='' && (!filter_var($value,FILTER_VALIDATE_URL) || !in_array($parts['scheme']??'',['http','https'],true) || isset($parts['user']) || isset($parts['pass']))) throw new ScheduleOperationException('URLはhttp://またはhttps://で入力してください。',422);
    return $value===''?null:$value;
}
/** 共有元の予定ルールを再利用し、開場・開始・終了の順序も検証する。 */
function validateEventInput(array $raw): array
{
    $state=eventChoice($raw,'status',EVENT_STATUSES);
    $scheduleRaw=$raw;
    $scheduleRaw['schedule_date']=$raw['event_date']??null;
    $eventType=array_key_exists('event_type',$raw) ? eventChoice($raw,'event_type',EVENT_TYPES) : null;
    // 種類と予定カテゴリは別。新規時の表示カテゴリだけLIVE／イベントに合わせる。
    $scheduleRaw['category']=($eventType===null || $eventType==='live') ? 'live' : 'event';$scheduleRaw['visibility']='public';$scheduleRaw['is_all_day']=false;
    $scheduleRaw['status']=$state==='cancelled'?'cancelled':'active';
    [$schedule,$errors]=validateScheduleInput($scheduleRaw);
    if ($errors) throw new ScheduleOperationException(implode(' ',$errors),422);
    $venue=positiveOshiId($raw['venue_id']??null);
    if ($venue===null) throw new ScheduleOperationException('会場を選んでください。',422);
    $open=$raw['open_time']??'';
    if ($open===null) $open='';
    if (!is_string($open) || ($open!==''&&!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$open))) throw new ScheduleOperationException('開場時間を正しく入力してください。',422);
    if ($open!==''&&$schedule['start_time']!==null&&$open>$schedule['start_time']) throw new ScheduleOperationException('開場は開始以前にしてください。',422);
    return ['schedule'=>$schedule,'venue_id'=>$venue,'open_time'=>$open?:null,'status'=>$state,'event_type'=>$eventType];
}
/** 遠征の日程は帰着が出発より前にならないようにする。 */
function validateTripInput(array $raw): array
{
    $values=['trip_name'=>eventText($raw,'trip_name','遠征名',150,true),'departure_date'=>eventDate($raw,'departure_date'),'return_date'=>eventDate($raw,'return_date'),'note'=>eventText($raw,'note','メモ',3000)];
    if ($values['return_date']<$values['departure_date']) throw new ScheduleOperationException('帰着日は出発日以降にしてください。',422);
    return $values;
}
/** 交通と宿泊で共通する予約・金額を確認し、それぞれの時間範囲も検査する。 */
function validateTravelItem(array $raw, string $kind): array
{
    $values=['amount'=>eventAmount($raw),'reservation_status'=>eventChoice($raw,'reservation_status',RESERVATION_STATUSES),'note'=>eventText($raw,'note','メモ',3000)];
    if ($kind==='transport') {
        $values['transport_type']=eventChoice($raw,'transport_type',TRANSPORT_TYPES);
        $other=eventText($raw,'transport_type_other','その他の交通手段',100,$values['transport_type']==='other');
        $values['transport_type_other']=$values['transport_type']==='other'?$other:null;
        $values['departure_place']=eventText($raw,'departure_place','出発地',150,true);
        $values['arrival_place']=eventText($raw,'arrival_place','到着地',150,true);
        $values['departure_at']=eventDateTime($raw,'departure_at');$values['arrival_at']=eventDateTime($raw,'arrival_at');
        if ($values['arrival_at']<$values['departure_at']) throw new ScheduleOperationException('到着は出発以降にしてください。',422);
    } else {
        $values['hotel_name']=eventText($raw,'hotel_name','ホテル名',150,true);
        $values['check_in_at']=eventDateTime($raw,'check_in_at');$values['check_out_at']=eventDateTime($raw,'check_out_at');
        if ($values['check_out_at']<=$values['check_in_at']) throw new ScheduleOperationException('チェックアウトはチェックインより後にしてください。',422);
        $values['address']=eventText($raw,'address','住所',255);$values['url']=eventUrl($raw,'url');
    }
    return $values;
}

/** 本人管理の各状態を独立して検証する。非抽選・申込不要はサーバー側でも矛盾を正規化する。 */
function validateEventManagement(array $raw,array $existing): array
{
    $legacy = !array_key_exists('entry_method',$raw) && !array_key_exists('participation_status',$raw) && array_key_exists('lottery_status',$raw);
    $oldLottery = $raw['lottery_status'] ?? null;
    $legacySales = $legacy && is_string($oldLottery) && in_array($oldLottery,['general_sale','production_release','other'],true);
    $entry = array_key_exists('entry_method',$raw) ? eventChoice($raw,'entry_method',ENTRY_METHODS) : ($existing['entry_method']??'unknown');
    if ($legacySales) $entry='unknown';
    elseif ($legacy && in_array($oldLottery,['pending','won','lost'],true) && in_array($entry,['unknown','lottery'],true)) $entry='lottery';

    // applyingは保存値として維持。新しい呼び名in_progressも同じ値へ変換する。
    if (($raw['application_status']??null)==='in_progress') $raw['application_status']='applying';
    $application=array_key_exists('application_status',$raw) ? eventChoice($raw,'application_status',APPLICATION_STATUSES) : ($existing['application_status']??'not_applied');
    $lottery=$legacySales ? 'not_applicable' : (array_key_exists('lottery_status',$raw) ? eventChoice($raw,'lottery_status',LOTTERY_STATUSES) : ($existing['lottery_status']??'not_applicable'));
    $participation=array_key_exists('participation_status',$raw) ? eventChoice($raw,'participation_status',PARTICIPATION_STATUSES) : ($existing['participation_status']??'considering');
    // 旧クライアントは参加欄を持たないため、旧当落操作だけは従来の意図へ変換する。
    if ($legacy) $participation=match($oldLottery) {'won'=>'confirmed','lost'=>'not_attending',default=>'considering'};
    $sales=array_key_exists('sales_type',$raw) ? eventChoice($raw,'sales_type',SALES_TYPES) : ($legacySales ? $oldLottery : ($existing['sales_type']??'none'));
    if ($entry!=='lottery') $lottery='not_applicable';
    elseif ($lottery==='not_applicable') $lottery='pending';
    if ($entry==='no_application') { $application='not_required';$sales='none'; }
    elseif ($application==='not_required') $application='not_applied';

    $trip=array_key_exists('trip_type',$raw) ? $raw['trip_type'] : ($existing['trip_type']??null);
    if ($trip==='') $trip=null;
    if ($trip!==null && (!is_string($trip)||!isset(TRIP_TYPES[$trip]))) throw new ScheduleOperationException('移動区分を確認してください。',422);
    $amount=array_key_exists('ticket_amount',$raw) ? eventAmount(['amount'=>$raw['ticket_amount']]) : ($existing['ticket_amount']??null);
    $payment=array_key_exists('ticket_payment_status',$raw) ? eventChoice($raw,'ticket_payment_status',PAYMENT_STATUSES) : ($existing['ticket_payment_status']??'unpaid');
    $values=[
        'entry_method'=>$entry,'application_status'=>$application,'lottery_status'=>$lottery,
        'participation_status'=>$participation,'sales_type'=>$sales,'trip_type'=>$trip,
        'note'=>array_key_exists('note',$raw)?eventText($raw,'note','個人メモ',3000):($existing['note']??''),
        'ticket_amount'=>$amount,'ticket_payment_status'=>$payment,
        'ticket_paid_date'=>$payment==='paid' ? (($existing['ticket_payment_status']??'unpaid')==='paid' ? ($existing['ticket_paid_date']??null) : date('Y-m-d')) : null,
    ];
    // 旧販売値での更新も元値を残す。参加確定や購入済みとは推測しない。
    if ($legacySales) $values['legacy_lottery_status']=$oldLottery;
    return $values;
}
