<?php
/** room_personal_money.php の役割：共有支出の中に本人の負担額・反映状態・明示操作だけを表示する。 */
?>
<?php if ($personal['has_share'] || $personal['existing']): ?>
<div class="room-personal-money">
<?php if ($personal['has_share']): ?><p>あなたの負担 <strong>¥<?= number_format($personal['share_amount']) ?></strong></p><?php endif; ?>
<?php if ($personal['existing']): ?>
<p>✅ お金管理に反映済み <span>¥<?= number_format((float)$personal['existing']['amount'],2) ?></span></p>
<?php if ($personal['source_status']==='cancelled'): ?><p role="status">⚠ この共同支出は取消されています。お金管理には反映済みです。</p>
<?php elseif ($personal['differences']): ?><p role="status">⚠ 反映済みのお金管理と現在の共同支出に差があります。</p><?php endif; ?>
<?php if ($personal['settlement_status']!=='settled'): ?><p class="caption">精算内容が変更されたため、個人のお金管理に反映済みの内容を確認してください。更新は再精算後に行えます。</p><?php endif; ?>
<a href="<?= e(appUrl('expense_form.php?id='.$personal['existing']['id'])) ?>">自分のお金管理を見る</a>
<?php elseif ($personal['share_amount']>0 && $personal['source_status']==='active' && $personal['settlement_status']!=='settled'): ?><p class="caption">精算済みになった後、自分のお金管理に反映できます。</p><?php endif; ?>
<div class="room-expense-actions">
<?php foreach(['create'=>'自分のお金管理に反映','update'=>'お金管理を更新','delete'=>'お金管理から削除'] as $action=>$label): if($personal['can_'.$action]): ?>
<button type="button" class="button secondary" data-personal-action="<?= e($action) ?>" data-personal-id="<?= (int)$expense['id'] ?>"><?= e($label) ?></button>
<?php endif; endforeach; ?>
</div></div>
<?php endif; ?>
