<?php
/** expense_form.php の役割：本人の支出登録・編集、交通や宿泊からの反映内容を確認する画面。 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_PRIVATE_ROOT . '/app/helpers/money_view.php';
$userId = (int)$user['id'];
$record = isset($_GET['id'])?findMoneyRecord(database(),'expenses',$userId,eventPageId()):null;
$source = null;
if (!$record && isset($_GET['source_type'])) {
    $source = moneySource(database(),$userId,eventChoice($_GET,'source_type',['live_ticket'=>true,'transportation'=>true,'accommodation'=>true]),eventPageId('source_id'));
    if ($source['expense_id']) {
        header('Location: '.appUrl('expense_form.php?id='.$source['expense_id']));exit;
    }
    if (!$source['can_import']) throw new ScheduleOperationException($source['import_reason'],409);
}
$values = $record??$source??[];
$imported = ($values['source_type']??'manual')!=='manual';
$options = moneyOshiOptions($userId,$values);
$eventOptions = [''=>'関連付けなし'];
// 共有情報だけを選択肢に使う。他人の当落や個人メモは取得しない。
foreach (eventQuery(database(),'SELECT l.id,s.title,s.schedule_date FROM events l JOIN schedules s ON s.id=l.schedule_id ORDER BY s.schedule_date DESC,l.id DESC')->fetchAll() as $event) {
    $eventOptions[$event['id']] = $event['schedule_date'].' '.$event['title'];
}
$tripOptions = [''=>'関連付けなし'];
foreach (eventQuery(database(),'SELECT id,trip_name FROM trips WHERE user_id=? ORDER BY departure_date DESC',[$userId])->fetchAll() as $trip) $tripOptions[$trip['id']] = $trip['trip_name'];
$pageTitle = $source?'支払いをお金管理に反映':($record?'支出を編集':'支出を登録');
require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('money.php')) ?>">← お金管理</a><h1><?= e($pageTitle) ?></h1>
<?php if (($values['source_type']??'')==='room_expense'): ?><p class="notice">連番ルームから反映した本人負担分です。</p><?php endif; ?>
<?php if ($imported): ?><p>内容を確認して保存してください。元の支払い情報や予約を変更・削除しても、この支出は自動変更されません。反映後の金額変更や取消はお金管理で行えます。</p><?php endif; ?>
<?php if (($source['reservation_status']??'')==='cancelled'): ?><p class="notice">この予約はキャンセル済みです。キャンセル料など、実際に負担する金額と日付を確認してください。</p><?php endif; ?>
<form class="money-form event-fields card" data-kind="expenses" data-action="<?= $record?'update':'create' ?>">
<?php if ($record): ?><input type="hidden" name="id" value="<?= (int)$record['id'] ?>"><?php endif; ?>
<input type="hidden" name="source_type" value="<?= e($values['source_type']??'manual') ?>">
<?php if ($imported): ?><input type="hidden" name="source_id" value="<?= (int)$values['source_id'] ?>"><?php endif; ?>
<?php
eventField('title','支出名',$values['title']??'','text',true);
eventField('amount','金額（円）',$values['amount']??'','number',true);
eventField('expense_date','支出日',$values['expense_date']??date('Y-m-d'),'date',true);
if ($imported) {
    moneyFixed('oshi_id','推し',$values['oshi_id'],$options[$values['oshi_id']]??'共通');
    moneyFixed('event_id','関連イベント',$values['event_id'],$eventOptions[$values['event_id']]??'');
    moneyFixed('trip_id','関連遠征',$values['trip_id'],$tripOptions[$values['trip_id']??'']??'関連付けなし');
    moneyFixed('category','カテゴリ',$values['category'],MONEY_CATEGORIES[$values['category']]['label']);
} else {
    eventSelect('oshi_id','推し',$options,$values['oshi_id']??'');
    eventSelect('event_id','関連イベント',$eventOptions,$values['event_id']??'');
    eventSelect('trip_id','関連遠征',$tripOptions,$values['trip_id']??'');
}
?>
<?php if (!$imported): ?><label class="event-field">カテゴリ<select id="expense-category" name="category"><?php foreach (MONEY_CATEGORIES as $key=>$category): ?><option value="<?= e($key) ?>" data-eligible="<?= $category['eligible']?'true':'false' ?>" <?= ($values['category']??'live_ticket')===$key?'selected':'' ?>><?= e($category['label']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label class="money-check event-wide"><input id="expense-eligible" type="checkbox" <?= ($values['special_effect_eligible']??!$imported)?'checked':'' ?> <?= $imported?'disabled':'' ?>>特効換算の対象にする</label>
<p class="caption event-wide">推し活そのものの支出だけが対象です。手数料・交通・宿泊・食事・観光などは対象外です。対象カテゴリでもチェックを外せます。</p>
<?php eventMemo($values['note']??'');moneySubmit(); ?>
</form>
<?php if ($record): ?><button class="button secondary" data-money-delete="expenses" data-id="<?= (int)$record['id'] ?>" data-year="<?= e(substr($record['expense_date'],0,4)) ?>">この支出を削除</button><?php endif; ?>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
