<?php
/** schedule_detail.php の役割：閲覧権限を確認し、予定・情報元・同期状態と本人ができる操作を表示する。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
require_once PROJECT_ROOT . '/app/repositories/schedule_repository.php';
require_once PROJECT_ROOT . '/app/validators/schedule_validator.php';
require_once PROJECT_ROOT . '/app/repositories/feedback_repository.php';
$id = positiveOshiId($_GET['id'] ?? null);
$schedule = $id === null ? null : findScheduleDetail(database(), $id, (int) $user['id']);
if (!$schedule) http_response_code(404);
$pageTitle = $schedule ? $schedule['title'] : '予定が見つかりません';
$hasFeedback = $schedule && $schedule['visibility'] === 'public' && $schedule['status'] !== 'deleted';
$extraStyles = ['feedback'];
$pageStyle = 'schedules'; $pageScripts = $schedule ? ['schedules', 'schedule_detail'] : []; $activePage = 'calendar';
if ($hasFeedback) $pageScripts[] = 'feedback';
require PROJECT_ROOT . '/includes/header.php';
?>
<a class="page-back" href="<?= e(appUrl('calendar.php')) ?>">← カレンダー</a>
<?php if (!$schedule): ?><h1>予定が見つかりません</h1><p>予定が存在しないか、閲覧できない公開範囲になっています。</p>
<?php else: ?>
<article id="schedule-detail" class="card schedule-detail-card" data-id="<?= $schedule['id'] ?>">
<p class="eyebrow"><?= e($schedule['category_label']) ?></p><h1><?= e($schedule['oshi_emoji'] . implode('', $schedule['member_hearts']) . ' ' . $schedule['title']) ?></h1>
<?php if ($schedule['status'] === 'cancelled'): ?><p class="notice" role="status">中止になった予定です。</p><?php elseif ($schedule['status'] === 'deleted'): ?><p class="notice" role="status">共有元の予定は削除されました。カレンダーから外すことができます。</p><?php endif; ?>
<?php if ($schedule['source_type'] === 'customized'): ?><p class="tag">自分用に編集済み・日時などの同期は解除されています</p><?php elseif ($schedule['sync_enabled']): ?><p class="tag lavender">共有予定と同期中</p><?php endif; ?>
<?php if ($schedule['event_id']): ?><p><a class="button secondary small" href="<?= e(appUrl('event_detail.php?id='.$schedule['event_id'])) ?>">イベント管理・当落・遠征を見る →</a></p><?php if ($schedule['event_status']==='postponed'): ?><p class="notice">延期された公演です。</p><?php endif; endif; ?>
<dl class="schedule-details">
<dt>日時</dt><dd><?= e($schedule['date']) ?>　<?= $schedule['is_all_day'] ? '終日' : e($schedule['start_time'] ?? '時間未定') ?><?= $schedule['end_time'] ? '〜' . e($schedule['end_time']) : '' ?></dd>
<dt>推し</dt><dd><?= e($schedule['oshi_emoji'] . ' ' . $schedule['oshi_name']) ?></dd>
<dt>メンバー</dt><dd><?php if (!$schedule['members']): ?>グループ全体<?php endif; ?><?php foreach ($schedule['members'] as $member): ?><span class="member-name"><?= e($member['heart_emoji'] . ' ' . $member['name']) ?></span><?php endforeach; ?></dd>
<dt>カテゴリ</dt><dd><?= e($schedule['category_label']) ?></dd>
<dt>公開範囲</dt><dd><?= $schedule['visibility'] === 'public' ? 'みんなに公開' : '自分だけ（非公開）' ?></dd>
<dt>登録者</dt><dd><?= e($schedule['creator_name']) ?></dd><dt>共有元の更新日時</dt><dd><?= e($schedule['updated_at']) ?></dd>
<dt>メモ</dt><dd class="multiline"><?= e($schedule['note'] ?: 'メモはありません。') ?></dd>
<dt>情報元</dt><dd><?php if (!$schedule['sources']): ?>情報元は登録されていません。<?php endif; ?><?php foreach ($schedule['sources'] as $source): ?><div class="source-detail"><strong><?= e($source['label']) ?></strong><?php if ($source['source_url']): ?> <a href="<?= e($source['source_url']) ?>" target="_blank" rel="noopener noreferrer">元情報を見る ↗</a><?php endif; ?><p><?= e($source['note']) ?></p></div><?php endforeach; ?></dd>
</dl>
<?php if ($schedule['source_type'] === 'customized'): ?><details class="original-schedule"><summary>共有元の内容を確認</summary><p><?= e($schedule['original']['title']) ?></p><p><?= e($schedule['original']['date']) ?> <?= $schedule['original']['is_all_day'] ? '終日' : e($schedule['original']['start_time'] ?? '時間未定') ?></p><p class="multiline"><?= e($schedule['original']['note']) ?></p></details><?php endif; ?>
<div class="schedule-actions">
<?php if ($schedule['is_owner'] && $schedule['status'] !== 'deleted'): ?>
<a class="button primary small" href="<?= e(appUrl('schedule_form.php?id=' . $id)) ?>">予定を編集・中止にする</a><?php if (!$schedule['event_id']): ?><button id="delete-schedule" class="button secondary small" type="button">予定を削除</button><?php endif; ?>
<?php elseif (!$schedule['is_owner'] && $schedule['is_added']): ?>
<span class="tag">✓ カレンダーに追加済み</span>
<?php if ($schedule['status'] !== 'deleted'): ?><a class="button secondary small" href="<?= e(appUrl('schedule_form.php?id=' . $id . '&mode=custom')) ?>">自分用に編集</a><?php endif; ?>
<button id="remove-schedule" class="button secondary small" type="button">カレンダーから外す</button>
<?php elseif (!$schedule['is_owner'] && $schedule['status'] === 'active'): ?>
<button id="add-schedule" class="button primary small" type="button">自分のカレンダーに追加</button>
<?php endif; ?>
</div><p id="schedule-detail-message" class="form-message" role="alert"></p>
<?php if ($hasFeedback) require PROJECT_ROOT . '/includes/schedule_feedback.php'; ?>
</article>
<?php endif; ?>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
