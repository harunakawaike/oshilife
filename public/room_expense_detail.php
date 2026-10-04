<?php
/** room_expense_detail.php の役割：共同支出の共有内訳と保存時の名前を表示し、権限がある本人に編集・取消を案内する。 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_PRIVATE_ROOT . '/app/helpers/room_expense_view.php';
$roomId=eventPageId('room_id');$id=eventPageId();$expense=findRoomExpense(database(),(int)$user['id'],$roomId,$id);
$pageTitle='共同支出の詳細';require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('room_expenses.php?room_id='.$roomId)) ?>">← 共同支出一覧</a>
<h1>共同支出の詳細</h1><section class="card room-card room-expense-detail">
<?php renderRoomExpenseSummary($expense); ?>
<?php if ($expense['note']!==''): ?><h3>メモ</h3><p class="room-expense-note"><?= e($expense['note']) ?></p><?php endif; ?>
<p class="caption">名前は登録時の表記です。個人のお金管理には反映されていません。</p>
<?php if ($expense['can_edit']): ?><div class="room-expense-actions">
<a class="button primary" href="<?= e(appUrl('room_expense_form.php?room_id='.$roomId.'&id='.$id)) ?>">編集する</a>
<form id="room-expense-cancel" data-room-id="<?= $roomId ?>" data-id="<?= $id ?>" data-version="<?= (int)$expense['version'] ?>">
<button type="submit" class="button secondary">共同支出を取り消す</button><p class="event-message" role="status"></p></form></div>
<?php endif; ?></section>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
