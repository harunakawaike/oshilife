<?php
/** events.php の役割：共有イベントを検索し、本人の当落と一緒に一覧表示する。 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_PRIVATE_ROOT . '/app/helpers/event_view.php';
$oshis=findMyOshis(database(),(int)$user['id']);
require PROJECT_ROOT.'/includes/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">EVENT & TRIP</p><h1>イベント</h1></div><a class="button primary" href="<?= e(appUrl('event_form.php')) ?>">＋ イベントを登録</a></div>
<p><a class="button secondary" href="<?= e(appUrl('rooms.php')) ?>">連番ルーム →</a></p>
<form id="event-search" class="event-fields card">
<?php eventField('q','イベント名を検索');eventSelect('oshi_id','推し',array_column($oshis,'name','id'),'',true);eventSelect('period','期間',['all'=>'すべて','upcoming'=>'今後','past'=>'過去'],'upcoming'); ?>
<div class="event-actions"><button class="button secondary">検索する</button></div></form>
<p id="event-list-message" role="status"></p><div id="event-list" class="event-grid"></div><button id="event-more" class="button secondary" hidden>さらに表示</button>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
