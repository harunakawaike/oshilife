<?php
/** payment_view.php の役割：支払い候補の未完了・反映可能・反映済みと金額差を共通表示する。 */
declare(strict_types=1);
require_once __DIR__.'/../repositories/money_repository.php';
/** 金額は日本円で表示する。未設定と0円を区別し、端数も失わない。 */
function paymentYen(?string $value): string
{
    if ($value===null) return '未設定';
    $decimal = moneyCents($value)%100===0 ? 0 : 2;
    return '¥'.number_format((float)$value,$decimal);
}
/** 状態が変わっても自動登録しない。確認フォームへ進むリンクだけを表示する。 */
function renderPaymentImport(array $source): void
{
    echo '<div class="payment-import">';
    if ($source['expense_id']!==null) {
        echo '<p>✓ お金管理に反映済み</p>';
        if ($source['amount_changed']) {
            echo '<p class="notice">お金管理へ反映済みの金額と現在の金額が異なります。</p>';
            echo '<p>現在：'.e(paymentYen($source['amount'])).' ／ お金管理：'.e(paymentYen($source['expense_amount'])).'</p>';
        }
        echo '<a class="text-button" href="'.e(appUrl('expense_form.php?id='.$source['expense_id'])).'">お金管理の支出を'.($source['amount_changed']?'更新':'確認・編集').' →</a>';
    } elseif ($source['can_import']) {
        echo '<p class="caption">お金管理に反映できます。内容を確認してから登録してください。</p>';
        echo '<a class="button secondary small" href="'.e(appUrl('expense_form.php?source_type='.$source['source_type'].'&source_id='.$source['source_id'])).'">お金管理に反映</a>';
    } else {
        echo '<p class="caption">'.e($source['import_reason']).'</p>';
    }
    echo '</div>';
}
/** チケットは共有公演ではなく本人の管理IDを使うので、金額・支払い状況を共有しない。 */
function renderTicketPayment(int $userId,array $live): void
{
    if (empty($live['user_live_status_id'])) return;
    $source = moneySource(database(),$userId,'live_ticket',(int)$live['user_live_status_id']);
    echo '<section class="card live-section"><h2>チケットのお支払い <small>自分だけ</small></h2>';
    echo '<p>チケット代：'.e(paymentYen($source['amount'])).'</p>';
    echo '<p>'.e(PAYMENT_STATUSES[$source['payment_status']]).'（入金・支払い確認TODOと連動）</p>';
    renderPaymentImport($source);
    echo '</section>';
}
