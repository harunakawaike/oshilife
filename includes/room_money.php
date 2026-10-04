<?php
/** room_money.php の役割：ルーム詳細内に共同支出一覧と開閉できる内訳を表示する。保存・認可は既存処理を再利用する。 */
$expenseCount=count(array_filter($expenses,fn($expense)=>$expense['status']==='active'));
?>
<section id="room-money" class="room-money" aria-labelledby="room-money-title">
<h2 id="room-money-title">お金</h2>
<?php require PROJECT_ROOT.'/includes/room_settlement.php'; ?>
<details class="room-money-panel" open><summary>共同支出 <?= $expenseCount ?>件</summary>
<p class="caption">個人のお金管理への反映と精算済みの記録は、まだ行いません。</p>
<p id="room-money-message" role="status" tabindex="-1"></p>
<button type="button" class="button secondary" data-refresh-money hidden>表示を更新</button>
<?php if ($active): ?><button type="button" class="button secondary" data-expense-form="">＋ 共同支出を追加</button><?php endif; ?>
<label class="room-cancelled-filter"><input type="checkbox" data-show-cancelled>取消済みも表示</label>
<?php if (!$expenseCount): ?><p>共同支出はまだありません。</p><?php endif; ?>
<div class="room-money-list">
<?php foreach($expenses as $expense): ?>
<details class="room-money-item" data-expense-id="<?= (int)$expense['id'] ?>" data-cancelled="<?= $expense['status']==='cancelled'?'true':'false' ?>" <?= $expense['status']==='cancelled'?'hidden':'' ?>>
<summary><span><?= e($expense['title']) ?><?php if ($expense['status']==='cancelled'): ?> <small>取消済み</small><?php endif; ?></span><strong>¥<?= number_format((int)$expense['total_amount']) ?></strong></summary>
<div class="room-money-content">
<?php renderRoomExpenseSummary($expense); ?>
<?php if ($expense['note']!==''): ?><h3>メモ</h3><p class="room-expense-note"><?= e($expense['note']) ?></p><?php endif; ?>
<?php if ($expense['can_edit']): ?><div class="room-expense-actions"><button class="button secondary" type="button" data-expense-form="<?= (int)$expense['id'] ?>">編集</button>
<button class="button secondary" type="button" data-cancel-expense="<?= (int)$expense['id'] ?>" data-version="<?= (int)$expense['version'] ?>">取り消す</button></div><?php endif; ?>
</div></details><?php endforeach; ?></div>
</details></section>
