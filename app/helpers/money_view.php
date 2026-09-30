<?php
/** money_view.php の役割：お金画面の本人確認・入力欄・記録の選択肢をまとめる。 */
declare(strict_types=1);
require_once __DIR__.'/live_view.php';
require_once PROJECT_ROOT.'/app/services/money_service.php';
$pageTitle = 'お金管理';
$pageStyle = 'money';
$extraStyles = ['lives'];
$pageScripts = ['money'];
$activePage = 'profile';
set_exception_handler(function(Throwable $error): void {
    http_response_code($error instanceof ScheduleOperationException?$error->getCode():500);
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>お金管理</title><p>'.e($error instanceof ScheduleOperationException?$error->getMessage():'画面を読み込めませんでした。').'</p><a href="'.e(appUrl('money.php')).'">お金管理へ</a></html>';
});
/** 共通積立と推し別を1件ごとに選ぶ。登録解除した推しの過去記録も編集できる。 */
function moneyOshiOptions(int $userId,?array $record = null): array
{
    $options = [''=>'全推し共通・未指定'];
    foreach (findMyOshis(database(),$userId) as $oshi) $options[$oshi['id']] = $oshi['emoji'].' '.$oshi['name'];
    if (!empty($record['oshi_id']) && !isset($options[$record['oshi_id']])) {
        $oshi = liveQuery(database(),'SELECT name,emoji FROM oshis WHERE id=?',[$record['oshi_id']])->fetch();
        if ($oshi) $options[$record['oshi_id']] = $oshi['emoji'].' '.$oshi['name'].'（この記録の推し）';
    }
    return $options;
}
/** 遠征から反映する固定項目は表示とhidden入力に分け、内容が見える状態で確認する。 */
function moneyFixed(string $name,string $label,mixed $value,string $display): void
{
    echo '<div><p class="caption">'.e($label).'</p><p>'.e($display).'</p><input type="hidden" name="'.e($name).'" value="'.e((string)($value??'')).'"></div>';
}
/** 保存ボタンに読み上げ可能な状態表示を添える。 */
function moneySubmit(): void
{
    echo '<div class="live-wide"><p class="money-message" role="status"></p><button class="button primary" type="submit">登録内容を保存</button></div>';
}
