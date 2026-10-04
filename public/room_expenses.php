<?php
/** room_expenses.php の役割：参加中のルームメンバーへ同じ共同支出一覧を表示する。個人会計は集計しない。 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_PRIVATE_ROOT . '/app/helpers/room_expense_view.php';
$roomId=eventPageId('room_id');$userId=(int)$user['id'];$room=requireRoom(database(),$userId,$roomId);
$include=($_GET['include_cancelled']??'0')==='1';$expenses=listRoomExpenses(database(),$userId,$roomId,$include);
$pageTitle='ルームのお金';require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('room_detail.php?room_id='.$roomId)) ?>">← <?= e($room['name']) ?></a>
<h1>共同支出</h1><p>誰が支払い、誰がいくら負担するかをルーム内で共有します。</p>
<p class="caption">個人のお金管理への反映と精算は、まだ行いません。</p>
<div class="room-expense-actions">
<?php if ($room['status']==='active'): ?><a class="button primary" href="<?= e(appUrl('room_expense_form.php?room_id='.$roomId)) ?>">＋ 共同支出を追加</a><?php else: ?><p>終了したルームの履歴です。追加・編集・取消はできません。</p><?php endif; ?>
<a href="<?= e(appUrl('room_expenses.php?room_id='.$roomId.'&include_cancelled='.($include?'0':'1'))) ?>"><?= $include?'通常の一覧へ':'取消済みも表示' ?></a></div>
<?php if (!$expenses): ?><p class="card">共同支出はまだありません。</p><?php endif; ?>
<div class="room-grid">
<?php foreach($expenses as $expense): ?><article class="card room-card">
<?php renderRoomExpenseSummary($expense); ?>
<a class="button secondary" href="<?= e(appUrl('room_expense_detail.php?room_id='.$roomId.'&id='.$expense['id'])) ?>">詳細</a>
</article><?php endforeach; ?></div>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
