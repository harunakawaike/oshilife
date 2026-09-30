<?php
/** live_validator.php の役割：共有公演・個人管理・遠征の入力を、保存前に検証する。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_validator.php';
require_once __DIR__.'/../../config/lives.php';
/** 配列等の型違いも空値として通さず、文字数と必須入力を確認する。 */
function liveText(array $raw, string $key, string $label, int $max, bool $required = false): string
{
    $value=$raw[$key]??'';
    if (!is_string($value)) throw new ScheduleOperationException($label.'の形式が正しくありません。',422);
    $value=trim($value);
    if (($required && $value==='') || mb_strlen($value,'UTF-8')>$max || str_contains($value,"\0")) throw new ScheduleOperationException($label.'は'.($required?'1〜':'').''.$max.'文字以内で入力してください。',422);
    return $value;
}
/** 選択値の許可リストを通し、将来の状態追加を設定ファイルへ集約する。 */
function liveChoice(array $raw, string $key, array $choices): string
{
    $value=$raw[$key]??null;
    if (!is_string($value) || !isset($choices[$value])) throw new ScheduleOperationException('選択項目を確認してください。',422);
    return $value;
}
/** 日付・時刻は実在する形式だけを受け付ける。 */
function liveDate(array $raw, string $key, bool $optional = false): ?string
{
    $value=$raw[$key]??'';
    if ($optional && ($value===null || $value==='')) return null;
    if (!is_string($value) || !validScheduleDate($value)) throw new ScheduleOperationException('日付を正しく入力してください。',422);
    return $value;
}
/** datetime-local入力を日本時間のSQL形式へ変換し、2月30日などを拒否する。 */
function liveDateTime(array $raw, string $key): string
{
    $value=$raw[$key]??null;
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D',$value)) throw new ScheduleOperationException('日時を入力してください。',422);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value);
    if (!$date || $date->format('Y-m-d\TH:i')!==$value) throw new ScheduleOperationException('日時を正しく入力してください。',422);
    return $date->format('Y-m-d H:i:s');
}
/** 金額は小数2桁までの非負数。浮動小数へ変換せずDECIMALへ文字列で渡す。 */
function liveAmount(array $raw): ?string
{
    $value=$raw['amount']??null;
    if ($value===null || $value==='') return null;
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D',(string)$value)) throw new ScheduleOperationException('金額は0以上、小数2桁までで入力してください。',422);
    return (string)$value;
}
/** 外部URLは安全なhttp/httpsリンクだけ。サーバーからアクセスはしない。 */
function liveUrl(array $raw, string $key): ?string
{
    $value=liveText($raw,$key,'URL',2048);
    $parts=parse_url($value);
    if ($value!=='' && (!filter_var($value,FILTER_VALIDATE_URL) || !in_array($parts['scheme']??'',['http','https'],true) || isset($parts['user']) || isset($parts['pass']))) throw new ScheduleOperationException('URLはhttp://またはhttps://で入力してください。',422);
    return $value===''?null:$value;
}
/** 共有元の予定ルールを再利用し、開場・開演・終了の順序も検証する。 */
function validateLiveInput(array $raw): array
{
    $state=liveChoice($raw,'status',LIVE_STATUSES);
    $scheduleRaw=$raw;
    $scheduleRaw['schedule_date']=$raw['event_date']??null;
    $scheduleRaw['category']='live';$scheduleRaw['visibility']='public';$scheduleRaw['is_all_day']=false;
    $scheduleRaw['status']=$state==='cancelled'?'cancelled':'active';
    [$schedule,$errors]=validateScheduleInput($scheduleRaw);
    if ($errors) throw new ScheduleOperationException(implode(' ',$errors),422);
    $venue=positiveOshiId($raw['venue_id']??null);
    if ($venue===null) throw new ScheduleOperationException('会場を選んでください。',422);
    $open=$raw['open_time']??'';
    if ($open===null) $open='';
    if (!is_string($open) || ($open!==''&&!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$open))) throw new ScheduleOperationException('開場時間を正しく入力してください。',422);
    if ($open!==''&&$schedule['start_time']!==null&&$open>$schedule['start_time']) throw new ScheduleOperationException('開場は開演以前にしてください。',422);
    return ['schedule'=>$schedule,'venue_id'=>$venue,'open_time'=>$open?:null,'status'=>$state];
}
/** 遠征の日程は帰着が出発より前にならないようにする。 */
function validateTripInput(array $raw): array
{
    $values=['trip_name'=>liveText($raw,'trip_name','遠征名',150,true),'departure_date'=>liveDate($raw,'departure_date'),'return_date'=>liveDate($raw,'return_date'),'note'=>liveText($raw,'note','メモ',3000)];
    if ($values['return_date']<$values['departure_date']) throw new ScheduleOperationException('帰着日は出発日以降にしてください。',422);
    return $values;
}
/** 交通と宿泊で共通する予約・金額を確認し、それぞれの時間範囲も検査する。 */
function validateTravelItem(array $raw, string $kind): array
{
    $values=['amount'=>liveAmount($raw),'reservation_status'=>liveChoice($raw,'reservation_status',RESERVATION_STATUSES),'note'=>liveText($raw,'note','メモ',3000)];
    if ($kind==='transport') {
        $values['transport_type']=liveChoice($raw,'transport_type',TRANSPORT_TYPES);
        $other=liveText($raw,'transport_type_other','その他の交通手段',100,$values['transport_type']==='other');
        $values['transport_type_other']=$values['transport_type']==='other'?$other:null;
        $values['departure_place']=liveText($raw,'departure_place','出発地',150,true);
        $values['arrival_place']=liveText($raw,'arrival_place','到着地',150,true);
        $values['departure_at']=liveDateTime($raw,'departure_at');$values['arrival_at']=liveDateTime($raw,'arrival_at');
        if ($values['arrival_at']<$values['departure_at']) throw new ScheduleOperationException('到着は出発以降にしてください。',422);
    } else {
        $values['hotel_name']=liveText($raw,'hotel_name','ホテル名',150,true);
        $values['check_in_at']=liveDateTime($raw,'check_in_at');$values['check_out_at']=liveDateTime($raw,'check_out_at');
        if ($values['check_out_at']<=$values['check_in_at']) throw new ScheduleOperationException('チェックアウトはチェックインより後にしてください。',422);
        $values['address']=liveText($raw,'address','住所',255);$values['url']=liveUrl($raw,'url');
    }
    return $values;
}
