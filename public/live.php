<?php
/** live.php の役割：共有ライブを検索し、本人の当落と一緒に一覧表示する。 */
require_once __DIR__.'/../app/helpers/live_view.php';
$oshis=findMyOshis(database(),(int)$user['id']);
require PROJECT_ROOT.'/includes/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">LIVE & TRIP</p><h1>ライブ</h1></div><a class="button primary" href="<?= e(appUrl('live_form.php')) ?>">＋ 公演を登録</a></div>
<form id="live-search" class="live-fields card">
<?php liveField('q','公演名を検索');liveSelect('oshi_id','推し',array_column($oshis,'name','id'),'',true);liveSelect('period','期間',['all'=>'すべて','upcoming'=>'今後','past'=>'過去'],'upcoming'); ?>
<div class="live-actions"><button class="button secondary">検索する</button></div></form>
<p id="live-list-message" role="status"></p><div id="live-list" class="live-grid"></div><button id="live-more" class="button secondary" hidden>さらに表示</button>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
