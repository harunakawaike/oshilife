<?php
/** legacy_event_api.php の役割：旧lives APIの入出力名だけを変換し、新events APIへ渡す。保存処理・認証は共通。 */
declare(strict_types=1);
define('LEGACY_EVENT_API', true);
/** 古いクライアントへ旧JSONキーを返す。イベント種類liveやlive_ticketの保存値は変換しない。 */
function legacyEventResponse(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    // 旧画面には従来の販売区分を抽選欄へ返す。DBでは分離した値のまま保持する。
    if (($value['lottery_status']??null)==='not_applicable' && in_array($value['sales_type']??'', ['general_sale','production_release','other'],true)) $value['lottery_status']=$value['sales_type'];
    $names = ['event'=>'live','events'=>'lives','event_id'=>'live_event_id','event_status'=>'live_status','user_event_status_id'=>'user_live_status_id'];
    $result = [];
    foreach ($value as $key=>$item) $result[$names[$key] ?? $key] = legacyEventResponse($item);
    return $result;
}
// 新APIがexitしても共通のJSON応答を変換できるよう、出力時に一度だけ変換する。
ob_start(function(string $output): string {
    $body = json_decode($output, true);
    return is_array($body) ? json_encode(legacyEventResponse($body), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $output;
});
