<?php
/** room_view.php の役割：ルーム画面の共通設定と、認証付きAPIへ送るフォームをまとめる。 */
require_once __DIR__.'/event_view.php';
require_once PROJECT_ROOT.'/app/services/room_link_service.php';
$pageStyle='events';$extraStyles=['rooms'];$pageScripts=['rooms'];$activePage='events';

/** 操作フォームのhidden値もエスケープする。所有者・役割は送らず、サーバーが本人を判定する。 */
function roomAction(string $route, array $values, string $label, string $confirm = '', string $redirect = ''): void
{
    echo '<form class="room-form room-action" data-api="'.e($route).'"';
    if ($confirm!=='') echo ' data-confirm="'.e($confirm).'"';
    if ($redirect!=='') echo ' data-redirect="'.e($redirect).'"';
    echo '>';
    foreach($values as $key=>$value) echo '<input type="hidden" name="'.e($key).'" value="'.e((string)$value).'">';
    echo '<button class="button secondary" type="submit">'.e($label).'</button><p class="event-message" role="status"></p></form>';
}
