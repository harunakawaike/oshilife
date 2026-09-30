<?php
/** schedule_form.php の役割：新規登録・作成者の編集・取り込んだ予定の個人編集フォームを共通化する。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
require_once PROJECT_ROOT . '/app/repositories/schedule_repository.php';
require_once PROJECT_ROOT . '/app/repositories/oshi_repository.php';
require_once PROJECT_ROOT . '/app/validators/schedule_validator.php';
$id = positiveOshiId($_GET['id'] ?? null);
$mode = isset($_GET['id']) ? (($_GET['mode'] ?? '') === 'custom' ? 'custom' : 'edit') : 'create';
$schedule = $id === null ? null : findScheduleDetail(database(), $id, (int) $user['id']);
$allowed = $mode === 'create' || ($schedule && $schedule['status'] !== 'deleted' && ($mode === 'edit' ? $schedule['is_owner'] : (!$schedule['is_owner'] && $schedule['is_added'])));
if (!$allowed) http_response_code(404);
$myOshis = findMyOshis(database(), (int) $user['id']);
$initialDate = inputString($_GET, 'date');
$values = $schedule ?? ['title' => '', 'date' => validScheduleDate($initialDate) ? $initialDate : date('Y-m-d'), 'start_time' => '', 'end_time' => '', 'is_all_day' => false, 'note' => '', 'oshi_id' => $myOshis[0]['id'] ?? '', 'category' => 'other', 'visibility' => 'private', 'status' => 'active', 'members' => [], 'sources' => []];
if ($schedule && !in_array($schedule['oshi_id'], array_map('intval', array_column($myOshis, 'id')), true)) {
    $myOshis[] = ['id' => $schedule['oshi_id'], 'name' => $schedule['oshi_name'], 'emoji' => $schedule['oshi_emoji']];
}
$pageTitle = ['create' => '予定を登録', 'edit' => '予定を編集', 'custom' => '自分用に編集'][$mode];
$pageStyle = 'schedules'; $pageScripts = $allowed ? ['schedules', 'schedule_form'] : []; $activePage = 'calendar';
require PROJECT_ROOT . '/includes/header.php';
?>
<a class="page-back" href="<?= e(appUrl($schedule ? 'schedule_detail.php?id=' . $id : 'calendar.php')) ?>">← <?= $schedule ? '予定詳細' : 'カレンダー' ?></a>
<h1><?= e($pageTitle) ?></h1>
<?php if (!$allowed): ?><p class="notice">この予定は見つからないか、編集できません。</p>
<?php else: ?>
<?php if ($mode === 'custom'): ?><p class="notice">この予定は共有元との自動同期が解除されます。タイトル・日時・メモが自分専用になり、共有元は変更されません。</p><?php elseif ($mode === 'edit' && $values['visibility'] === 'public'): ?><p class="notice">同期中のユーザーにも変更が反映されます。非公開にすると他のユーザーの画面には表示されなくなります。</p><?php endif; ?>
<?php if (!$myOshis): ?><p class="notice">先に<a href="<?= e(appUrl('oshis.php')) ?>">推し管理</a>で推しを登録してください。</p><?php endif; ?>
<form id="schedule-form" class="card schedule-edit-form" data-mode="<?= e($mode) ?>" data-id="<?= $id ?? '' ?>" novalidate>
<label>タイトル<input name="title" maxlength="150" required value="<?= e($values['title']) ?>" aria-describedby="schedule-error-title"></label><p id="schedule-error-title" class="field-error"></p>
<div class="schedule-form-grid"><div><label>日付<input type="date" name="schedule_date" required value="<?= e($values['date']) ?>" aria-describedby="schedule-error-schedule_date"></label><p id="schedule-error-schedule_date" class="field-error"></p></div>
<div><label class="check-label"><input type="checkbox" name="is_all_day" <?= $values['is_all_day'] ? 'checked' : '' ?>> 終日</label><p id="schedule-error-is_all_day" class="field-error"></p></div>
<div><label>開始時間（任意）<input type="time" name="start_time" value="<?= e($values['start_time']) ?>" aria-describedby="schedule-error-start_time"></label><p id="schedule-error-start_time" class="field-error"></p></div>
<div><label>終了時間（任意）<input type="time" name="end_time" value="<?= e($values['end_time']) ?>" aria-describedby="schedule-error-end_time"></label><p id="schedule-error-end_time" class="field-error"></p></div></div>
<?php if ($mode !== 'custom'): ?>
<label>推し<select id="schedule-oshi" name="oshi_id" required aria-describedby="schedule-error-oshi_id"><option value="">推しを選択</option><?php foreach ($myOshis as $oshi): ?><option value="<?= (int) $oshi['id'] ?>" <?= (int) $values['oshi_id'] === (int) $oshi['id'] ? 'selected' : '' ?>><?= e($oshi['emoji'] . ' ' . $oshi['name']) ?></option><?php endforeach; ?></select></label><p id="schedule-error-oshi_id" class="field-error"></p>
<fieldset><legend>メンバー（複数選択可）</legend><p class="caption">グループ全体の予定なら、選択せずに登録できます。</p><div id="schedule-members" class="schedule-member-options">
<?php $selectedMembers = array_map('intval', array_column($values['members'], 'id')); ?>
<?php foreach ($values['oshi_id'] ? findOshiMembers(database(), (int) $values['oshi_id']) : [] as $member): ?><label class="check-label"><input type="checkbox" name="member_ids" value="<?= (int) $member['id'] ?>" <?= in_array((int) $member['id'], $selectedMembers, true) ? 'checked' : '' ?>><?= e($member['heart_emoji'] . ' ' . $member['name']) ?></label><?php endforeach; ?>
</div><p id="schedule-error-member_ids" class="field-error"></p></fieldset>
<div class="schedule-form-grid"><div><label>カテゴリ<select name="category"><?php foreach (SCHEDULE_CATEGORIES as $value => $label): ?><option value="<?= e($value) ?>" <?= $values['category'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><p id="schedule-error-category" class="field-error"></p></div>
<div><label>公開範囲<select id="schedule-visibility" name="visibility"><option value="private" <?= $values['visibility'] === 'private' ? 'selected' : '' ?>>自分だけ（非公開）</option><option value="public" <?= $values['visibility'] === 'public' ? 'selected' : '' ?>>みんなに公開</option></select></label><p id="schedule-error-visibility" class="field-error"></p></div></div>
<?php if ($mode === 'edit'): ?><label>開催状況<select name="status"><option value="active" <?= $values['status'] === 'active' ? 'selected' : '' ?>>予定あり</option><option value="cancelled" <?= $values['status'] === 'cancelled' ? 'selected' : '' ?>>中止</option></select></label><p id="schedule-error-status" class="field-error"></p><?php endif; ?>
<fieldset id="schedule-sources" <?= $values['visibility'] !== 'public' ? 'hidden' : '' ?>><legend>情報元</legend><p class="caption">公開する予定には、情報元を1つ以上添えることをおすすめします。URLのない公式メール等は補足に記載できます。</p><div id="source-rows">
<?php foreach ($values['sources'] as $sourceValue) { require PROJECT_ROOT . '/includes/schedule_source_fields.php'; } ?>
</div><button id="add-source" type="button" class="button secondary small">＋ 情報元を追加</button><p id="schedule-error-sources" class="field-error"></p></fieldset>
<template id="source-template"><?php $sourceValue = null; require PROJECT_ROOT . '/includes/schedule_source_fields.php'; ?></template>
<?php endif; ?>
<label>メモ（任意）<textarea name="note" rows="4" maxlength="3000" aria-describedby="schedule-error-note"><?= e($values['note']) ?></textarea></label><p id="schedule-error-note" class="field-error"></p>
<div id="duplicate-warning" class="notice" hidden><strong>似た予定がすでに登録されています</strong><div id="duplicate-links"></div><button type="button" id="confirm-duplicate" class="button secondary small">このまま登録する</button></div>
<p id="schedule-form-message" class="form-message" role="alert" tabindex="-1"></p><button id="save-schedule" type="submit" class="button primary"><?= $mode === 'create' ? '予定を登録' : '変更を保存' ?></button>
</form>
<?php endif; ?>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
