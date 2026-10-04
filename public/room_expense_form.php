<?php
/** room_expense_form.php の役割：参加メンバーから支払者・負担者を選び、整数円の共同支出を登録・編集する。 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_PRIVATE_ROOT . '/app/helpers/room_expense_view.php';
$roomId=eventPageId('room_id');$userId=(int)$user['id'];$room=requireRoom(database(),$userId,$roomId,false,true);
$id=isset($_GET['id'])?eventPageId():null;$expense=$id===null?null:findRoomExpense(database(),$userId,$roomId,$id);
if ($expense) requireRoomExpenseEditor($room,$expense,$userId);
$members=roomExpenseCandidates(database(),$userId,$roomId);$old=[];
foreach($expense['shares']??[] as $share) $old[(int)$share['user_id']]=$share;
$choices=[];$rows=[];
foreach($members as $member) {
    $uid=(int)$member['user_id'];$label=$member['display_name'].($uid===$userId?'（自分）':'');
    $choices[$uid]=$label;
    $rows[$uid]=['member_id'=>$member['member_id'],'label'=>$label,'selected'=>isset($old[$uid]),'amount'=>$old[$uid]['share_amount']??0,'locked'=>false];
}
foreach($old as $uid=>$share) if (!isset($rows[$uid])) {
    $rows[$uid]=['member_id'=>$share['member_id'],'label'=>$share['display_name_snapshot'].'（利用終了）','selected'=>true,'amount'=>$share['share_amount'],'locked'=>true];
}
$payer=(int)($expense['paid_by_user_id']??$userId);
if ($expense && !isset($choices[$payer])) $choices[$payer]=$expense['paid_by_name_snapshot'].'（利用終了・過去の支払者）';
$pageTitle=$expense?'共同支出を編集':'共同支出を追加';require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('room_expenses.php?room_id='.$roomId)) ?>">← 共同支出一覧</a><h1><?= e($pageTitle) ?></h1>
<form id="room-expense-form" class="card event-fields" data-room-id="<?= $roomId ?>" data-id="<?= $id??'' ?>" data-version="<?= (int)($expense['version']??0) ?>">
<?php eventField('title','支出名',$expense['title']??'','text',true);eventSelect('category','カテゴリ',ROOM_EXPENSE_CATEGORIES,$expense['category']??'accommodation'); ?>
<label class="event-field">総額（円）<input name="total_amount" type="number" inputmode="numeric" min="1" max="999999999" step="1" required value="<?= e((string)($expense['total_amount']??'')) ?>"></label>
<?php eventSelect('paid_by_user_id','支払者（実際に支払った人）',$choices,$payer);eventField('expense_date','支払日',$expense['expense_date']??date('Y-m-d'),'date',true); ?>
<fieldset class="event-wide room-expense-members"><legend>負担メンバー・各負担額</legend>
<p class="caption">負担する人だけを選択してください。支払者を負担者に含めない登録もできます。</p>
<?php foreach($rows as $uid=>$row): ?><div class="room-expense-member" data-user-id="<?= $uid ?>" data-member-id="<?= (int)$row['member_id'] ?>" data-locked="<?= $row['locked']?'true':'false' ?>">
<label class="room-expense-select"><input type="checkbox" class="share-selected" <?= $row['selected']?'checked':'' ?> <?= $row['locked']?'disabled':'' ?>><span><?= e($row['label']) ?></span></label>
<label class="room-expense-input">負担額（円）<input type="number" inputmode="numeric" class="share-amount" min="0" max="999999999" step="1" value="<?= (int)$row['amount'] ?>" <?= $row['locked']?'readonly':'' ?> <?= !$row['selected']?'disabled':'' ?>></label>
</div><?php endforeach; ?>
<p class="caption">均等割りの端数は支払者を優先し、残りは参加者の登録順に配分します。利用終了の人の過去の負担額は固定し、残額を選択中の人で割ります。</p>
<button type="button" class="button secondary" id="room-expense-split">均等に割る</button>
<p id="room-expense-balance" role="status"></p></fieldset>
<label class="event-field event-wide">メモ（ルーム内で共有）<textarea name="note" maxlength="3000" rows="3"><?= e($expense['note']??'') ?></textarea></label>
<div class="event-wide"><p class="event-message" role="alert" tabindex="-1"></p><button type="submit" class="button primary">保存</button></div>
</form>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
