<?php
/** room_settlement.php の役割：送金案を共同支出一覧より上へ表示する。送金・精算済みの操作は設けない。 */
require_once PROJECT_ROOT.'/app/services/room_settlement_service.php';
$settlement=null;
try {$settlement=roomSettlementSummary(database(),$userId,$roomId);}
catch (RoomSettlementDataException $error) { /* 不整合時は精算不要や部分計算を表示せず、この領域だけエラーにする。 */ }
?>
<section class="room-settlement" aria-labelledby="room-settlement-title"><h3 id="room-settlement-title">精算サマリー</h3>
<?php if ($settlement===null): ?><p role="alert">共同支出の整合性を確認できないため、精算サマリーを表示できません。</p>
<?php else: $names=[];foreach($settlement['balances'] as $balance)$names[$balance['user_id']]=$balance['display_name']; ?>
<?php if (!$settlement['transfers']): ?><p>現在、精算が必要な金額はありません</p>
<?php else: ?><ul class="room-settlement-transfers">
<?php foreach($settlement['transfers'] as $transfer): ?><li>
<span><?= e((int)$transfer['from_user_id']===$userId?'あなた':$names[$transfer['from_user_id']]) ?> → <?= e((int)$transfer['to_user_id']===$userId?'あなた':$names[$transfer['to_user_id']]) ?>に支払う</span>
<strong>¥<?= number_format($transfer['amount']) ?></strong>
</li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($settlement['balances']): ?><details class="room-settlement-breakdown"><summary>支払額・負担額の内訳</summary>
<?php foreach($settlement['balances'] as $balance): ?><div class="room-settlement-person"><h4><?= e((int)$balance['user_id']===$userId?'あなた':$balance['display_name']) ?></h4>
<dl><div><dt>支払った額</dt><dd>¥<?= number_format($balance['paid_total']) ?></dd></div><div><dt>本人の負担</dt><dd>¥<?= number_format($balance['share_total']) ?></dd></div><div><dt>差額</dt><dd><?= $balance['balance']>0?'+':($balance['balance']<0?'−':'') ?>¥<?= number_format(abs($balance['balance'])) ?><?= $balance['balance']>0?'（受取）':($balance['balance']<0?'（支払）':'（精算不要）') ?></dd></div></dl>
</div><?php endforeach; ?></details><?php endif; ?>
<p class="caption">登録中の共同支出から計算した金額です。送金状況・精算済みの記録は含みません。</p>
<?php endif; ?></section>
