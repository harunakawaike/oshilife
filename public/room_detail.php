<?php
/** room_detail.php の役割：参加中メンバーへイベント・コンパクトなメンバー・トーク・同ページの共同支出を表示する。 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_PRIVATE_ROOT . '/app/helpers/room_expense_view.php';
$roomId=eventPageId('room_id');$userId=(int)$user['id'];
$room=requireRoom(database(),$userId,$roomId);
$members=roomMembers(database(),$userId,$roomId);
$events=roomEvents(database(),$userId,$roomId);
$owner=$room['my_role']==='owner';$active=$room['status']==='active';
$links=$owner ? roomInviteLinks(database(),$userId,$roomId) : [];
$available=$owner && $active ? roomAvailableEvents(database(),$userId,$roomId) : [];
$expenses=listRoomExpenses(database(),$userId,$roomId,true);
$extraStyles=['rooms','room_expenses','room_workspace','room_chat'];$pageScripts=['rooms','room_expenses','room_workspace','room_chat'];
$pageTitle=$room['name'];
require PROJECT_ROOT.'/includes/header.php';
?>
<div class="room-workspace" data-room-id="<?= $roomId ?>">
<a href="<?= e(appUrl('rooms.php')) ?>">← 連番ルーム一覧</a>
<header class="room-workspace-heading"><p class="caption">連番ルーム</p><h1 class="room-name"><?= e($room['name']) ?></h1></header>
<?php if (!$active): ?><p class="notice">終了したルームです。メンバーとイベントの履歴を閲覧できます。</p><?php endif; ?>
<section class="room-events-top" aria-labelledby="room-events-title"><h2 id="room-events-title">イベント</h2>
<?php if (!$events): ?><p>イベントはまだ追加されていません。</p><?php endif; ?>
<div class="room-event-strip">
<?php foreach($events as $event): ?><article class="card room-event">
<?php if ($event['available']): ?>
<span class="tag"><?= e(EVENT_TYPES[$event['event_type']]??'イベント') ?></span><h3><?= e($event['title']) ?></h3>
<p><?= e($event['event_date']) ?> <?= e(substr($event['start_time']??'',0,5)) ?> · <?= e($event['venue_name']) ?></p>
<a href="<?= e(appUrl('event_detail.php?id='.$event['event_id'])) ?>">イベント詳細 →</a>
<?php else: ?><p>現在公開されていないイベントです。</p><?php endif; ?>
<?php if ($owner && $active): ?><details class="room-event-tools"><summary>イベントの管理</summary><?php roomAction('events/remove',['room_id'=>$roomId,'event_id'=>$event['event_id']],'ルームから外す','イベント自体は削除されません。ルームから外しますか？'); ?></details><?php endif; ?>
</article><?php endforeach; ?></div>
<?php if ($owner && $active): ?><details><summary>＋ イベントを追加</summary>
<?php if (!$available): ?><p>追加できるイベントはありません。イベント詳細の「自分の管理」を保存すると候補に表示されます。</p>
<?php else: $options=[];foreach($available as $event) $options[$event['id']]=$event['event_date'].' '.substr($event['start_time']??'',0,5).' '.$event['title'].' · '.$event['venue_name']; ?>
<form class="room-form event-fields" data-api="events/add"><input type="hidden" name="room_id" value="<?= $roomId ?>">
<?php eventSelect('event_id','自分が管理している公開イベント',$options);eventSubmit('イベントを追加'); ?></form>
<?php endif; ?></details><?php endif; ?>
</section>

<section class="room-members-compact" aria-label="メンバーと招待"><h2>メンバー <?= count($members) ?>人</h2>
<ul class="room-people">
<?php foreach($members as $member): ?><li><span><?= e($member['display_name']) ?><?= $member['is_me']?'（自分）':'' ?></span><?php if ($member['role']==='owner'): ?><small>owner</small><?php endif; ?></li><?php endforeach; ?>
</ul>
<?php if ($owner): ?>
<details class="room-invite-panel"><summary>＋ 招待</summary><div class="room-invite-content"><h3>招待リンク</h3>
<p class="caption">リンクを受け取ったOshilifeユーザーが、自分で参加を選べます。信頼できる相手に共有してください。</p>
<?php if ($active): ?>
<form class="room-form" data-api="links/create"><input type="hidden" name="room_id" value="<?= $roomId ?>">
<button class="button primary" type="submit">招待リンクを作成</button><p class="event-message" role="status"></p></form>
<div id="room-link-result" hidden><label>招待リンク<input id="room-invite-url" type="text" readonly></label>
<button class="button secondary" id="room-copy-link" type="button">コピー</button><p id="room-copy-message" role="status"></p>
<p class="caption">このURLは発行直後だけ表示します。必要な相手へ共有するか、コピーして控えてください。</p></div>
<?php endif; ?>
<div id="room-links">
<?php foreach($links as $link): ?><article class="room-link" data-link-id="<?= (int)$link['id'] ?>">
<p>発行：<?= e($link['created_at']) ?></p><p>有効期限：<?= e($link['expires_at']) ?>まで</p>
<p><?= e(['active'=>'有効','revoked'=>'無効化済み','expired'=>'期限切れ'][$link['effective_status']]) ?> · 参加利用 <?= (int)$link['used_count'] ?>回</p>
<?php if ($link['status']==='active') roomAction('links/revoke',['room_id'=>$roomId,'id'=>$link['id']],'このリンクを無効化','この招待リンクを無効化しますか？'); ?>
</article><?php endforeach; ?></div></div></details>
<?php endif; ?>

</section>
<?php require PROJECT_ROOT.'/includes/room_chat.php'; ?>
<?php require PROJECT_ROOT.'/includes/room_money.php'; ?>
<section class="room-management"><details><summary>ルーム管理</summary>
<?php if ($owner && $active): ?>
<form class="room-form event-fields" data-api="update"><input type="hidden" name="room_id" value="<?= $roomId ?>">
<?php eventField('name','ルーム名',$room['name'],'text',true);eventSubmit('名前を保存'); ?></form>
<?php roomAction('close',['room_id'=>$roomId],'ルームを終了','終了すると招待・イベント追加・編集ができなくなります。ルームを終了しますか？'); ?>
<?php elseif (!$owner): ?>
<?php roomAction('members/leave',['room_id'=>$roomId],'ルームを退出','退出するとルームを閲覧できなくなります。退出しますか？',appUrl('rooms.php')); ?>
<?php else: ?><p>このルームは終了しています。</p><?php endif; ?>
</details></section>
<dialog id="room-expense-dialog" aria-labelledby="room-expense-dialog-title"><header><h2 id="room-expense-dialog-title">共同支出</h2><button type="button" class="button secondary" data-close-expense>閉じる</button></header><p id="room-expense-load-message" role="status"></p><div id="room-expense-dialog-body"></div></dialog>
</div>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
