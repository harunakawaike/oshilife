<?php
/** live_form.php の役割：1公演の共有基本情報と会場の登録・編集フォームを表示する。 */
require_once __DIR__.'/../app/helpers/live_view.php';
$live=isset($_GET['id'])?findLive(database(),(int)$user['id'],livePageId()):null;
if ($live && !$live['is_owner']) throw new ScheduleOperationException('編集できるライブが見つかりません。',404);
$oshis=findMyOshis(database(),(int)$user['id']);$options=array_column($oshis,'name','id');
if ($live) $options[$live['oshi_id']]=$live['oshi_name'];
$venues=liveQuery(database(),'SELECT id,name,prefecture FROM venues ORDER BY name')->fetchAll();
$venueOptions=[];foreach($venues as $venue) $venueOptions[$venue['id']]=$venue['name'].'（'.$venue['prefecture'].'）';
require PROJECT_ROOT.'/includes/header.php';
?>
<div class="page-heading"><h1><?= $live?'公演を編集':'公演を登録' ?></h1><a href="<?= e(appUrl('live.php')) ?>">ライブ一覧へ</a></div>
<p>1日・1公演ずつ登録してください。この情報は他のユーザーにも共有されます。</p>
<form class="live-form live-fields card" data-api="lives/<?= $live?'update':'create' ?>" data-target="live_detail.php" data-result="live">
<?php if ($live): ?><input type="hidden" name="id" value="<?= (int)$live['id'] ?>"><?php endif; ?>
<?php liveSelect('oshi_id','推し',$options,$live['oshi_id']??'');liveField('title','公演名',$live['title']??'','text',true);liveSelect('venue_id','会場',$venueOptions,$live['venue_id']??'');liveField('event_date','公演日',$live['event_date']??'','date',true);liveField('open_time','開場',$live['open_time']??'','time');liveField('start_time','開演',$live['start_time']??'','time');liveField('end_time','終了予定',$live['end_time']??'','time');liveSelect('status','開催状況',LIVE_STATUSES,$live['status']??'scheduled');liveMemo($live['note']??'','共有メモ');liveSubmit(); ?>
<div class="live-duplicates live-wide" hidden></div></form>
<details class="card live-section"><summary>会場が一覧にないとき：会場を登録</summary><form class="live-form live-fields" data-api="venues/create" data-venue="true">
<?php liveField('name','会場名','','text',true);liveField('prefecture','都道府県','','text',true);liveField('address','住所');liveField('latitude','緯度（任意）');liveField('longitude','経度（任意）');liveSubmit('会場を追加'); ?>
</form></details>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
