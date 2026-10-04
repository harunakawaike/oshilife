<?php
/** travel_form.php の役割：本人の遠征に交通・宿泊を複数登録・編集する入力画面。 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_PRIVATE_ROOT . '/app/helpers/event_view.php';
$trip=requireOwnTrip(database(),(int)$user['id'],eventPageId('trip_id'));
$kind=$_GET['kind']??'transport';
if (!in_array($kind,['transport','accommodation'],true)) throw new ScheduleOperationException('種類が正しくありません。',404);
$item=[];
if (isset($_GET['id'])) {
    $table=$kind==='transport'?'transportations':'accommodations';
    $item=eventQuery(database(),"SELECT * FROM $table WHERE id=? AND trip_id=?",[eventPageId(),$trip['id']])->fetch();
    if (!$item) throw new ScheduleOperationException('予約情報が見つかりません。',404);
}
$label=$kind==='transport'?'交通':'宿泊';
require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('trip_detail.php?id='.$trip['id'])) ?>">← 遠征まとめ</a><h1><?= e($label) ?>を<?= $item?'編集':'追加' ?></h1>
<form class="event-form event-fields card" data-api="trips/<?= e($kind) ?>/<?= $item?'update':'create' ?>" data-redirect="<?= e(appUrl('trip_detail.php?id='.$trip['id'])) ?>"><input type="hidden" name="trip_id" value="<?= (int)$trip['id'] ?>">
<?php if ($item): ?><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><?php endif; ?>
<?php
if ($kind==='transport') {
    eventSelect('transport_type','交通手段',TRANSPORT_TYPES,$item['transport_type']??'shinkansen');
    echo '<div data-transport-other>';eventField('transport_type_other','その他の交通手段',$item['transport_type_other']??'');echo '</div>';
    eventField('departure_place','出発地',$item['departure_place']??'','text',true);eventField('arrival_place','到着地',$item['arrival_place']??'','text',true);
    foreach (['departure_at'=>'出発日時','arrival_at'=>'到着日時'] as $key=>$text) eventField($key,$text,isset($item[$key])?str_replace(' ','T',substr($item[$key],0,16)):'','datetime-local',true);
} else {
    eventField('hotel_name','ホテル名',$item['hotel_name']??'','text',true);
    foreach (['check_in_at'=>'チェックイン','check_out_at'=>'チェックアウト'] as $key=>$text) eventField($key,$text,isset($item[$key])?str_replace(' ','T',substr($item[$key],0,16)):'','datetime-local',true);
    eventField('address','住所',$item['address']??'');eventField('url','予約ページのURL',$item['url']??'','url');
}
eventField('amount','金額（円・任意）',$item['amount']??'','number');eventSelect('reservation_status','予約状況',RESERVATION_STATUSES,$item['reservation_status']??'considering');eventSelect('payment_status','支払い状況',PAYMENT_STATUSES,$item['payment_status']??'unpaid');eventMemo($item['note']??'');eventSubmit();
?>
</form><?php require PROJECT_ROOT.'/includes/footer.php'; ?>
