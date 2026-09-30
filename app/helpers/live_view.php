<?php
/** live_view.php の役割：ライブ各画面の認証・安全な入力欄・選択肢・エラー画面を共通化する。 */
declare(strict_types=1);
require_once __DIR__.'/../../middleware/auth.php';
$user=requireAuth();
require_once PROJECT_ROOT.'/app/services/live_service.php';
require_once PROJECT_ROOT.'/app/repositories/oshi_repository.php';
$pageTitle='ライブ管理';$pageStyle='lives';$activePage='live';$pageScripts=['lives'];
/** ページIDも検証し、他人の遠征は存在しない場合と同じ画面にする。 */
function livePageId(string $key='id'): int
{
    $id=positiveOshiId($_GET[$key]??null);
    if ($id===null) throw new ScheduleOperationException('対象を選んでください。',404);
    return $id;
}
set_exception_handler(function(Throwable $error): void {
    http_response_code($error instanceof ScheduleOperationException?$error->getCode():500);
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>ライブ管理</title><p>'.e($error instanceof ScheduleOperationException?$error->getMessage():'画面を読み込めませんでした。').'</p><a href="'.e(appUrl('live.php')).'">ライブ一覧へ</a></html>';
});
/** HTMLへ表示する入力値はhtmlspecialcharsの共通関数eで文字に変換し、タグ実行を防ぐ。 */
function liveField(string $name,string $label,mixed $value='',string $type='text',bool $required=false): void
{
    echo '<label class="live-field">'.e($label).'<input name="'.e($name).'" type="'.e($type).'" value="'.e((string)($value??'')).'"'.($required?' required':'').($type==='text'?' maxlength="150"':'').($type==='number'?' min="0" step="0.01"':'').'></label>';
}
/** 選択値の日本語はconfig/lives.phpから読み、画面とAPIで同じ状態を使用する。 */
function liveSelect(string $name,string $label,array $choices,mixed $value='',bool $empty=false): void
{
    echo '<label class="live-field">'.e($label).'<select name="'.e($name).'">';
    if ($empty) echo '<option value="">未設定</option>';
    foreach ($choices as $key=>$text) echo '<option value="'.e((string)$key).'"'.((string)$value===(string)$key?' selected':'').'>'.e($text).'</option>';
    echo '</select></label>';
}
/** メモは共有か個人かをラベルで明示する。 */
function liveMemo(mixed $value='',string $label='メモ（自分だけ）'): void
{
    echo '<label class="live-field live-wide">'.e($label).'<textarea name="note" rows="3" maxlength="3000">'.e((string)($value??'')).'</textarea></label>';
}
/** フォームの送信状態を読み上げ可能な位置にまとめる。 */
function liveSubmit(string $label='保存する'): void
{
    echo '<div class="live-wide"><p class="live-message" role="status"></p><button class="button primary" type="submit">'.e($label).'</button></div>';
}
/** 保存済みの本人TODOを全画面で共通表示する。編集は同じAPIを使う。 */
function liveTodoSection(int $userId,int $liveId): void
{
    require_once __DIR__.'/payment_view.php';
    $todos=findLiveTodos(database(),$userId,$liveId);
    $live=findLive(database(),$userId,$liveId);
    $hasPaymentTodo=false;
    echo '<section class="card live-section"><h2>準備TODO <small>自分だけ</small></h2><div class="todo-list">';
    if (!$todos) echo '<p>当選後、近場／遠征を選んで保存すると準備リストが作られます。自分で追加もできます。</p>';
    foreach ($todos as $todo) {
        echo '<div class="todo-row"><label class="todo-check"><input type="checkbox"'.($todo['template_key']==='payment'?' data-ticket-payment="true"':'').' data-todo-toggle="'.(int)$todo['id'].'"'.($todo['is_completed']?' checked':'').'>完了</label><form class="live-form todo-edit" data-api="lives/todos/update" data-reload="true"><input type="hidden" name="id" value="'.(int)$todo['id'].'">';
        liveField('title','TODO',$todo['title'],'text',true);liveField('due_date','期限',$todo['due_date'],'date');
        echo '<div class="live-actions"><button class="button secondary small">変更を保存</button><button type="button" class="button secondary small" data-delete-api="lives/todos/delete" data-id="'.(int)$todo['id'].'">削除</button></div><p class="live-message" role="status"></p>';
        // 入金確認からそのまま反映できるよう、同じTODO内に金額・反映状態を置く。
        if ($todo['template_key']==='payment') {
            $hasPaymentTodo=true;
            renderTicketPayment($userId,$live);
        }
        echo '</form></div>';
    }
    echo '</div>';
    // 入金TODOを削除していても、反映済みの支出への入口はTODO欄内に残す。
    if (!$hasPaymentTodo && !empty($live['ticket_expense_id'])) renderTicketPayment($userId,$live);
    echo '<details><summary>＋ TODOを追加</summary><form class="live-form live-fields" data-api="lives/todos/create" data-reload="true"><input type="hidden" name="live_event_id" value="'.$liveId.'">';
    liveField('title','TODO','','text',true);liveField('due_date','期限','','date');liveSubmit('追加する');echo '</form></details></section>';
}
