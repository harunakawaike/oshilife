<?php
/** trip_detail.php の役割：本人の遠征に属する公演・交通・ホテル・TODO・会場を一画面にまとめる。 */
require_once __DIR__.'/../app/helpers/live_view.php';
require_once PROJECT_ROOT.'/app/helpers/payment_view.php';
$trip=findTripDetail(database(),(int)$user['id'],livePageId());$live=$trip['live'];$pageTitle=$trip['trip_name'];
require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('live_detail.php?id='.$live['id'])) ?>">← ライブ詳細</a>
<div class="page-heading"><div><p class="eyebrow">MY TRIP · 自分だけ</p><h1><?= e($trip['trip_name']) ?></h1></div></div>
<section class="card live-section"><p><?= e($live['oshi_emoji'].' '.$live['oshi_name']) ?></p><h2><?= e($live['title']) ?></h2><p><?= e($live['event_date'].' · '.$live['venue_name']) ?></p><span class="live-state state-<?= e($live['status']) ?>"><?= e(LIVE_STATUSES[$live['status']]) ?></span><p><?= e($trip['departure_date'].' → '.$trip['return_date']) ?></p><p class="live-note"><?= e($trip['note']) ?></p>
<details><summary>遠征名・日程・メモを編集</summary><form class="live-form live-fields" data-api="trips/update" data-reload="true"><input type="hidden" name="id" value="<?= (int)$trip['id'] ?>"><?php liveField('trip_name','遠征名',$trip['trip_name'],'text',true);liveField('departure_date','出発日',$trip['departure_date'],'date',true);liveField('return_date','帰着日',$trip['return_date'],'date',true);liveMemo($trip['note']);liveSubmit(); ?></form></details></section>
<?php foreach (['transport'=>'交通','accommodation'=>'ホテル'] as $kind=>$label): $items=$trip[$kind==='transport'?'transportations':'accommodations']; ?>
<section class="card live-section"><div class="section-heading"><h2><?= e($label) ?></h2><a class="button secondary small" href="<?= e(appUrl('travel_form.php?trip_id='.$trip['id'].'&kind='.$kind)) ?>">＋ <?= e($label) ?>を追加</a></div>
<?php if (!$items): ?><p>まだ登録されていません。</p><?php endif; ?>
<div class="travel-list"><?php foreach ($items as $item): ?>
<article class="travel-card"><p class="caption">予約状況：<?= e(RESERVATION_STATUSES[$item['reservation_status']]) ?></p><p class="caption">支払い状況：<?= e(PAYMENT_STATUSES[$item['payment_status']]) ?></p>
<?php if ($kind==='transport'): ?><h3><?= e($item['transport_type']==='other'?$item['transport_type_other']:TRANSPORT_TYPES[$item['transport_type']]) ?></h3><p><?= e($item['departure_place'].' → '.$item['arrival_place']) ?></p><p><?= e(substr($item['departure_at'],0,16).' → '.substr($item['arrival_at'],0,16)) ?></p>
<?php else: ?><h3><?= e($item['hotel_name']) ?></h3><p><?= e(substr($item['check_in_at'],0,16).' → '.substr($item['check_out_at'],0,16)) ?></p><p><?= e($item['address']) ?></p><?php if ($item['url']): ?><a href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer">予約ページを見る ↗</a><?php endif; endif; ?>
<?php if ($item['amount']!==null): ?><p>金額 ¥<?= e(number_format((float)$item['amount'],2)) ?></p><?php endif; ?><p class="live-note"><?= e($item['note']) ?></p>
<?php
$sourceType=$kind==='transport'?'transportation':'accommodation';
renderPaymentImport(moneySource(database(),(int)$user['id'],$sourceType,(int)$item['id']));
?>
<div class="live-actions"><a href="<?= e(appUrl('travel_form.php?trip_id='.$trip['id'].'&kind='.$kind.'&id='.$item['id'])) ?>">編集</a><button class="button secondary small" type="button" data-delete-api="trips/<?= e($kind) ?>/delete" data-id="<?= (int)$item['id'] ?>" data-trip-id="<?= (int)$trip['id'] ?>">削除</button></div></article>
<?php endforeach; ?></div></section>
<?php endforeach; liveTodoSection((int)$user['id'],(int)$live['id']); ?>
<section class="card live-section"><h2>会場</h2><h3><?= e($live['venue_name']) ?></h3><p><?= e($live['prefecture'].' '.$live['address']) ?></p></section>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
