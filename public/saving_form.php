<?php
/** saving_form.php の役割：共通または推し別の積立を本人が登録・編集・削除する画面。 */
require_once __DIR__.'/../app/helpers/money_view.php';
$record = isset($_GET['id'])?findMoneyRecord(database(),'savings',(int)$user['id'],eventPageId()):null;
$pageTitle = $record?'積立を編集':'積立を登録';
require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('money.php')) ?>">← お金管理</a><h1><?= e($pageTitle) ?></h1>
<form class="money-form event-fields card" data-kind="savings" data-action="<?= $record?'update':'create' ?>">
<?php if ($record): ?><input type="hidden" name="id" value="<?= (int)$record['id'] ?>"><?php endif; ?>
<?php
eventField('amount','金額（円）',$record['amount']??'','number',true);
eventField('saving_date','積立日',$record['saving_date']??date('Y-m-d'),'date',true);
eventSelect('oshi_id','積立の対象',moneyOshiOptions((int)$user['id'],$record),$record['oshi_id']??'');
eventMemo($record['note']??'');moneySubmit();
?></form>
<?php if ($record): ?><button class="button secondary" data-money-delete="savings" data-id="<?= (int)$record['id'] ?>" data-year="<?= e(substr($record['saving_date'],0,4)) ?>">この積立を削除</button><?php endif; ?>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
