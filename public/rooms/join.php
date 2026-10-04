<?php
/** join.php の役割：招待リンクを確認し、本人が参加ボタンを押すまでメンバーを作らない。 */
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once APP_PRIVATE_ROOT . '/middleware/auth.php';
// URLのトークンを他ページへのRefererに流さず、ブラウザーのキャッシュにも残さない。
header('Referrer-Policy: no-referrer');
$token=$_GET['token']??null;
$validFormat=is_string($token) && preg_match('/\A[a-f0-9]{64}\z/',$token);
if (currentUser()===null) {
    // 任意URLのreturn_toは受け付けない。ログイン後は固定の招待ページだけに戻す。
    unset($_SESSION['room_join_token']);
    if ($validFormat) $_SESSION['room_join_token']=$token;
    redirectTo('login.php');
}
require_once APP_PRIVATE_ROOT . '/app/helpers/room_view.php';
$pageTitle='連番ルームへの招待';$invite=null;$error=null;
try { $invite=previewRoomInviteLink(database(),(int)$user['id'],$token); }
catch (ScheduleOperationException $exception) { $error=$exception->getMessage();http_response_code($exception->getCode()); }
require PROJECT_ROOT.'/includes/header.php';
?>
<section class="card room-card room-join"><h1>連番ルームへの招待</h1>
<?php if ($error!==null): ?><p role="alert"><?= e($error) ?></p><a class="button secondary" href="<?= e(appUrl('rooms.php')) ?>">ルーム一覧へ</a>
<?php elseif ($invite['already_joined']): ?><p>すでにこのルームに参加しています</p><a class="button primary" href="<?= e(appUrl('room_detail.php?room_id='.$invite['room_id'])) ?>">ルームを見る</a>
<?php else: ?>
<p><?= e($invite['owner_name']) ?>さんから</p><h2>「<?= e($invite['room_name']) ?>」</h2><p>へ招待されています。参加しますか？</p>
<p class="caption">参加すると、あなたの表示名がルームのメンバーに表示されます。個人の同行者・TODO・支払い情報は共有されません。</p>
<?php roomAction('links/join',['token'=>$token],'参加する','',appUrl('room_detail.php?room_id='.$invite['room_id'])); ?>
<a class="button secondary" href="<?= e(appUrl('rooms.php')) ?>">キャンセル</a>
<?php endif; ?></section>
<?php require PROJECT_ROOT.'/includes/footer.php'; ?>
