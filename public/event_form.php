<?php
/** event_form.php の役割：1公演の共有基本情報と会場の登録・編集・コピー用フォームを表示する。 */
require_once __DIR__.'/../app/helpers/event_view.php';
$copying = isset($_GET['copy_from']);
if ($copying && isset($_GET['id'])) throw new ScheduleOperationException('コピーと編集は同時に指定できません。',422);
$editing = isset($_GET['id']);
$event = ($editing || $copying) ? findEvent(database(),(int)$user['id'],eventPageId($copying?'copy_from':'id')) : null;
// コピーはフォームの初期値だけ。元のIDを送らず通常の新規APIで別イベントを作る。
if ($copying && $event) $event['status']='scheduled';
if ($event && !$event['is_owner']) throw new ScheduleOperationException('編集できるイベントが見つかりません。',404);
$oshis=findMyOshis(database(),(int)$user['id']);$options=array_column($oshis,'name','id');
if ($event) $options[$event['oshi_id']]=$event['oshi_name'];
$venues=eventQuery(database(),'SELECT id,name,prefecture FROM venues ORDER BY name')->fetchAll();
$venueOptions=[];foreach($venues as $venue) $venueOptions[$venue['id']]=$venue['name'].'（'.$venue['prefecture'].'）';
require PROJECT_ROOT.'/includes/header.php';
?>
<div class="page-heading"><h1><?= $copying?'イベントをコピーして登録':($editing?'イベントを編集':'イベントを登録') ?></h1><a href="<?= e(appUrl('events.php')) ?>">イベント一覧へ</a></div>
<p>1日・1回ずつ登録してください。展覧会は自分が行く日を登録します。この情報は他のユーザーにも共有されます。</p>
<?php if ($copying): ?><p class="caption">日付・時間・会場を変更して保存すると、別のイベントとして登録されます。元のイベントは変更されません。同行者・申込状況・TODO・支払い情報は引き継ぎません。</p><?php endif; ?>
<form class="event-form event-fields card" data-api="events/<?= $editing?'update':'create' ?>" data-target="event_detail.php" data-result="event">
<?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$event['id'] ?>"><?php endif; ?>
<?php eventSelect('event_type','イベント種別',EVENT_TYPES,$event['event_type']??'live');eventSelect('oshi_id','推し',$options,$event['oshi_id']??'');eventField('title','イベント名',$event['title']??'','text',true);eventSelect('venue_id','会場',$venueOptions,$event['venue_id']??'');eventField('event_date','開催日',$event['event_date']??'','date',true);eventField('open_time','開場',$event['open_time']??'','time');eventField('start_time','開始',$event['start_time']??'','time');eventField('end_time','終了予定',$event['end_time']??'','time');eventSelect('status','開催状況',EVENT_STATUSES,$event['status']??'scheduled');eventMemo($event['note']??'','共有メモ');eventSubmit(); ?>
<div class="event-duplicates event-wide" hidden></div></form>
<details class="card event-section"><summary>会場が一覧にないとき：会場を登録</summary><form class="event-form event-fields" data-api="venues/create" data-venue="true">
<?php eventField('name','会場名','','text',true);eventField('prefecture','都道府県','','text',true);eventField('address','住所');eventField('latitude','緯度（任意）');eventField('longitude','経度（任意）');eventSubmit('会場を追加'); ?>
</form></details>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
