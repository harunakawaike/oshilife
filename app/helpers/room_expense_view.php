<?php
/** room_expense_view.php の役割：共同支出画面の共通設定と、登録者・支払者・負担内訳の表示をまとめる。 */
require_once __DIR__.'/room_view.php';
require_once PROJECT_ROOT.'/app/services/room_expense_service.php';
$extraStyles=['rooms','room_expenses'];$pageScripts=['room_expenses'];

/** 名前やメモをHTMLとして実行させないため、保存時の表示名も必ずe（htmlspecialchars）で変換する。 */
function renderRoomExpenseSummary(array $expense): void
{
    echo '<div class="room-expense-heading"><span class="tag">'.e(ROOM_EXPENSE_CATEGORIES[$expense['category']]).'</span>';
    if ($expense['status']==='cancelled') echo '<span class="tag">取消済み</span>';
    echo '</div><h2>'.e($expense['title']).'</h2><p class="room-expense-total">¥'.number_format((int)$expense['total_amount']).'</p>';
    echo '<dl class="room-expense-facts"><div><dt>支払</dt><dd>'.e($expense['paid_by_name_snapshot']).'</dd></div><div><dt>登録</dt><dd>'.e($expense['created_by_name_snapshot']).'</dd></div><div><dt>支払日</dt><dd>'.e($expense['expense_date']).'</dd></div></dl>';
    echo '<h3>負担内訳</h3><ul class="room-expense-shares">';
    foreach($expense['shares'] as $share) {
        echo '<li><span>'.e($share['display_name_snapshot']).(!$share['selectable']?' <small>利用終了</small>':'').'</span><strong>¥'.number_format((int)$share['share_amount']).'</strong></li>';
    }
    echo '</ul>';
}
