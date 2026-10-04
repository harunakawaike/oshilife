<?php
/** rooms.php の役割：本人が参加中のルーム・明示的なルーム作成フォームを表示する。 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_PRIVATE_ROOT . '/app/helpers/room_view.php';
$rooms=listRooms(database(),(int)$user['id']);
$pageTitle='連番ルーム';
require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('events.php')) ?>">← イベント一覧</a>
<h1>連番ルーム</h1>
<p>Oshilifeユーザーとイベント情報を共有する場所です。個人用の同行者とは別に管理します。</p>
<p class="caption">招待された方は、受け取った招待リンクを開いて参加してください。</p>
<section><h2>参加しているルーム</h2>
<?php if (!$rooms): ?><p class="card">まだ参加しているルームはありません。</p><?php endif; ?>
<div class="room-grid">
<?php foreach($rooms as $room): ?><article class="card room-card">
<h3><?= e($room['name']) ?></h3><p><?= $room['status']==='closed'?'終了済み · ':'' ?><?= $room['my_role']==='owner'?'owner（作成者）':'メンバー' ?></p>
<p>メンバー <?= (int)$room['member_count'] ?>人 · イベント <?= (int)$room['event_count'] ?>件</p>
<a class="button secondary" href="<?= e(appUrl('room_detail.php?room_id='.$room['id'])) ?>">ルームを見る →</a>
</article><?php endforeach; ?></div></section>
<section class="card event-section"><h2>連番ルームを作成</h2>
<form class="room-form event-fields" data-api="create" data-create="true">
<?php eventField('name','ルーム名','','text',true);eventSubmit('ルームを作成'); ?>
</form><p class="caption">作成後にイベントを追加し、招待リンクを作成して共有できます。</p></section>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
