<?php
/** trip_detail.php の役割：本人の遠征に属する公演・交通・ホテル・TODO・会場を一画面にまとめる。 */
require_once __DIR__.'/../app/helpers/event_view.php';
require_once PROJECT_ROOT.'/app/helpers/payment_view.php';
$trip=findTripDetail(database(),(int)$user['id'],eventPageId());$event=$trip['event'];$pageTitle=$trip['trip_name'];
require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('event_detail.php?id='.$event['id'])) ?>">← イベント詳細</a>
<div class="page-heading"><div><p class="eyebrow">MY TRIP · 自分だけ</p><h1><?= e($trip['trip_name']) ?></h1></div></div>
<section class="card event-section"><p><?= e($event['oshi_emoji'].' '.$event['oshi_name']) ?></p><h2><?= e($event['title']) ?></h2><p><?= e($event['event_date'].' · '.$event['venue_name']) ?></p><span class="event-state state-<?= e($event['status']) ?>"><?= e(EVENT_STATUSES[$event['status']]) ?></span><p><?= e($trip['departure_date'].' → '.$trip['return_date']) ?></p><p class="event-note"><?= e($trip['note']) ?></p>
<details><summary>遠征名・日程・メモを編集</summary><form class="event-form event-fields" data-api="trips/update" data-reload="true"><input type="hidden" name="id" value="<?= (int)$trip['id'] ?>"><?php eventField('trip_name','遠征名',$trip['trip_name'],'text',true);eventField('departure_date','出発日',$trip['departure_date'],'date',true);eventField('return_date','帰着日',$trip['return_date'],'date',true);eventMemo($trip['note']);eventSubmit(); ?></form></details></section>
<?php foreach (['transport'=>'交通','accommodation'=>'ホテル'] as $kind=>$label): $items=$trip[$kind==='transport'?'transportations':'accommodations']; ?>
<section class="card event-section"><div class="section-heading"><h2><?= e($label) ?></h2><a class="button secondary small" href="<?= e(appUrl('travel_form.php?trip_id='.$trip['id'].'&kind='.$kind)) ?>">＋ <?= e($label) ?>を追加</a></div>
<?php if (!$items): ?><p>まだ登録されていません。</p><?php endif; ?>
<div class="travel-list"><?php foreach ($items as $item): ?>
<article class="travel-card"><p class="caption">予約状況：<?= e(RESERVATION_STATUSES[$item['reservation_status']]) ?></p><p class="caption">支払い状況：<?= e(PAYMENT_STATUSES[$item['payment_status']]) ?></p>
<?php if ($kind==='transport'): ?><h3><?= e($item['transport_type']==='other'?$item['transport_type_other']:TRANSPORT_TYPES[$item['transport_type']]) ?></h3><p><?= e($item['departure_place'].' → '.$item['arrival_place']) ?></p><p><?= e(substr($item['departure_at'],0,16).' → '.substr($item['arrival_at'],0,16)) ?></p>
<?php else: ?><h3><?= e($item['hotel_name']) ?></h3><p><?= e(substr($item['check_in_at'],0,16).' → '.substr($item['check_out_at'],0,16)) ?></p><p><?= e($item['address']) ?></p><?php if ($item['url']): ?><a href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer">予約ページを見る ↗</a><?php endif; endif; ?>
<?php if ($item['amount']!==null): ?><p>金額 ¥<?= e(number_format((float)$item['amount'],2)) ?></p><?php endif; ?><p class="event-note"><?= e($item['note']) ?></p>
<?php
$sourceType=$kind==='transport'?'transportation':'accommodation';
renderPaymentImport(moneySource(database(),(int)$user['id'],$sourceType,(int)$item['id']));
?>
<div class="event-actions"><a href="<?= e(appUrl('travel_form.php?trip_id='.$trip['id'].'&kind='.$kind.'&id='.$item['id'])) ?>">編集</a><button class="button secondary small" type="button" data-delete-api="trips/<?= e($kind) ?>/delete" data-id="<?= (int)$item['id'] ?>" data-trip-id="<?= (int)$trip['id'] ?>">削除</button></div></article>
<?php endforeach; ?></div></section>
<?php endforeach; eventTodoSection((int)$user['id'],(int)$event['id']); ?>
<section class="card event-section"><h2>会場</h2><h3><?= e($event['venue_name']) ?></h3><p><?= e($event['prefecture'].' '.$event['address']) ?></p></section>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
