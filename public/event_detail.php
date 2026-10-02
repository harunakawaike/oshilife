<?php
/** event_detail.php の役割：共有公演と自分だけの当落・TODO・遠征への入口を分けて表示する。 */
require_once __DIR__.'/../app/helpers/event_view.php';
require_once PROJECT_ROOT.'/app/helpers/payment_view.php';
$event=findEvent(database(),(int)$user['id'],eventPageId());$pageTitle=$event['title'];
require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('events.php')) ?>">← イベント一覧</a>
<section class="card event-section"><span class="tag"><?= e($event['event_type_label']) ?></span><p><?= e($event['oshi_emoji'].' '.$event['oshi_name']) ?> · 共有情報</p><h1><?= e($event['title']) ?></h1>
<p class="event-state state-<?= e($event['status']) ?>"><?= e(EVENT_STATUSES[$event['status']]) ?></p>
<p class="event-date"><?= e($event['event_date']) ?> · <?= e($event['venue_name']) ?></p><p><?= e($event['prefecture'].' '.$event['address']) ?></p>
<div class="event-time"><span>開場 <?= e($event['open_time']??'未定') ?></span><span>開始 <?= e($event['start_time']??'未定') ?></span><span>終了予定 <?= e($event['end_time']??'未定') ?></span></div>
<p class="event-note"><?= e($event['note']) ?></p><div class="event-actions">
<?php if ($event['is_owner']): ?><a class="button secondary" href="<?= e(appUrl('event_form.php?id='.$event['id'])) ?>">共有情報を編集</a> <a class="button secondary" href="<?= e(appUrl('event_form.php?copy_from='.$event['id'])) ?>">コピーして別日程を登録</a><?php endif; ?>
<a href="<?= e(appUrl('schedule_detail.php?id='.$event['schedule_id'])) ?>">カレンダーの予定・修正提案を見る →</a></div></section>
<section class="card event-section"><h2>自分の管理 <small>自分だけ</small></h2><form class="event-form event-fields" data-api="events/status/update" data-reload="true"><input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
<?php eventSelect('entry_method','受付方式',ENTRY_METHODS,($event['entry_method']==='lottery' && $event['sales_type']==='fanclub')?'fanclub_presale':($event['entry_method']??'unknown')); ?>
<div data-management-application><?php eventSelect('application_status','申込状況',APPLICATION_STATUSES,$event['application_status']??'not_applied'); ?></div>
<div data-management-lottery><?php eventSelect('lottery_status','抽選結果',array_diff_key(LOTTERY_STATUSES,['not_applicable'=>true]),$event['lottery_status']??'pending'); ?></div>
<?php eventSelect('participation_status','参加状況',PARTICIPATION_STATUSES,$event['participation_status']??'considering'); ?>
<details data-management-sales class="event-wide" <?= ($event['sales_type']??'none')!=='none'?'open':'' ?>><summary>販売区分（必要な場合）</summary><?php eventSelect('sales_type','販売区分',SALES_TYPES,$event['sales_type']??'none'); ?></details>
<?php eventSelect('trip_type','移動',TRIP_TYPES,$event['trip_type'],true);eventField('ticket_amount','チケット・入場料（円・無料は0）',$event['ticket_amount'],'number'); ?>
<div data-management-payment><?php eventSelect('ticket_payment_status','支払い状況',PAYMENT_STATUSES,$event['ticket_payment_status']??'unpaid'); ?></div>
<p class="caption" data-payment-free hidden>0円のため支払い不要です。参加確定すると準備TODOを利用できます。</p>
<?php eventMemo($event['personal_note']);eventSubmit(); ?>
</form><p class="caption">保存すると自分のカレンダーにも追加されます。参加確定にするとTODOが作られます。抽選結果と参加状況は別々に選んでください。</p></section>
<section class="card event-section"><h2>同行者 <small>自分だけ</small></h2>
<?php if ($event['user_event_status_id'] !== null): ?>
<a class="button secondary" href="<?= e(appUrl('event_companions.php?event_id='.$event['id'])) ?>">同行者を管理する →</a>
<?php else: ?><p class="caption">上の「自分の管理」を保存すると、同行者を登録できます。</p><?php endif; ?>
</section>
<?php if ($event['application_status']!==null) eventTodoSection((int)$user['id'],(int)$event['id']); ?>
<?php if ($event['trip_id']): ?><a class="button primary" href="<?= e(appUrl('trip_detail.php?id='.$event['trip_id'])) ?>">遠征まとめを見る →</a>
<?php elseif ($event['trip_type']==='trip' && $event['participation_status']==='confirmed'): ?>
<section class="card event-section"><h2>遠征まとめを作成</h2><form class="event-form event-fields" data-api="trips/create" data-target="trip_detail.php"><input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
<?php eventField('trip_name','遠征名',$event['title'].' 遠征','text',true);eventField('departure_date','出発日',$event['event_date'],'date',true);eventField('return_date','帰着日',$event['event_date'],'date',true);eventMemo();eventSubmit('遠征まとめを作成'); ?></form></section>
<?php endif; require PROJECT_ROOT.'/includes/footer.php'; ?>
