<?php
/** live_detail.php の役割：共有公演と自分だけの当落・TODO・遠征への入口を分けて表示する。 */
require_once __DIR__.'/../app/helpers/live_view.php';
require_once PROJECT_ROOT.'/app/helpers/payment_view.php';
$live=findLive(database(),(int)$user['id'],livePageId());$pageTitle=$live['title'];
require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('live.php')) ?>">← ライブ一覧</a>
<section class="card live-section"><p><?= e($live['oshi_emoji'].' '.$live['oshi_name']) ?> · 共有情報</p><h1><?= e($live['title']) ?></h1>
<p class="live-state state-<?= e($live['status']) ?>"><?= e(LIVE_STATUSES[$live['status']]) ?></p>
<p class="live-date"><?= e($live['event_date']) ?> · <?= e($live['venue_name']) ?></p><p><?= e($live['prefecture'].' '.$live['address']) ?></p>
<div class="live-time"><span>開場 <?= e($live['open_time']??'未定') ?></span><span>開演 <?= e($live['start_time']??'未定') ?></span><span>終了予定 <?= e($live['end_time']??'未定') ?></span></div>
<p class="live-note"><?= e($live['note']) ?></p><div class="live-actions">
<?php if ($live['is_owner']): ?><a class="button secondary" href="<?= e(appUrl('live_form.php?id='.$live['id'])) ?>">共有情報を編集</a><?php endif; ?>
<a href="<?= e(appUrl('schedule_detail.php?id='.$live['schedule_id'])) ?>">カレンダーの予定・修正提案を見る →</a></div></section>
<section class="card live-section"><h2>自分の管理 <small>自分だけ</small></h2><form class="live-form live-fields" data-api="lives/status/update" data-reload="true"><input type="hidden" name="live_event_id" value="<?= (int)$live['id'] ?>">
<?php liveSelect('application_status','申込状況',APPLICATION_STATUSES,$live['application_status']??'not_applied');liveSelect('lottery_status','当落・販売状況',LOTTERY_STATUSES,$live['lottery_status']??'pending');liveSelect('trip_type','移動',TRIP_TYPES,$live['trip_type'],true);liveField('ticket_amount','チケット代（円・任意）',$live['ticket_amount'],'number');liveMemo($live['personal_note']);liveSubmit(); ?>
</form><p class="caption">保存すると自分のカレンダーにも追加されます。当選後に移動区分を選ぶとTODOが作られます。</p></section>
<?php if ($live['application_status']!==null) liveTodoSection((int)$user['id'],(int)$live['id']); ?>
<?php renderTicketPayment((int)$user['id'],$live); ?>
<?php if ($live['trip_id']): ?><a class="button primary" href="<?= e(appUrl('trip_detail.php?id='.$live['trip_id'])) ?>">遠征まとめを見る →</a>
<?php elseif ($live['trip_type']==='trip'): ?>
<section class="card live-section"><h2>遠征まとめを作成</h2><form class="live-form live-fields" data-api="trips/create" data-target="trip_detail.php"><input type="hidden" name="live_event_id" value="<?= (int)$live['id'] ?>">
<?php liveField('trip_name','遠征名',$live['title'].' 遠征','text',true);liveField('departure_date','出発日',$live['event_date'],'date',true);liveField('return_date','帰着日',$live['event_date'],'date',true);liveMemo();liveSubmit('遠征まとめを作成'); ?></form></section>
<?php endif; require PROJECT_ROOT.'/includes/footer.php'; ?>
