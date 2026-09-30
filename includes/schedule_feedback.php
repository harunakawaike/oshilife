<?php
/** schedule_feedback.php の役割：公開予定の根拠・感謝・本人の共有状況・修正提案フォームを表示する。 */
$feedbackStats = scheduleFeedbackStats(database(), (int) $schedule['id']);
$reactionData = reactionStatus(database(), (int) $schedule['id'], (int) $user['id']);
$correctionOriginal = $schedule['original'] + ['schedule_date' => $schedule['original']['date'], 'category' => $schedule['category']];
?>
<section id="schedule-feedback" class="feedback-section" data-schedule-id="<?= (int) $schedule['id'] ?>">
<h2>この予定の情報</h2>
<p class="feedback-facts">情報元 <?= count($schedule['sources']) ?>件 · 最終更新 <?= e($schedule['updated_at']) ?></p>
<p id="pending-corrections" class="caption"><?= $feedbackStats['pending_correction_count'] ? '確認待ちの修正提案が' . $feedbackStats['pending_correction_count'] . '件あります。承認までは現在の予定を表示します。' : '確認待ちの修正提案はありません。' ?></p>
<?php if ($schedule['is_owner']): ?>
<h2>あなたの共有が届いています</h2>
<dl class="feedback-stats">
<div><dt>カレンダー追加</dt><dd><?= $feedbackStats['calendar_added_count'] ?><small>人</small></dd></div>
<div><dt>助かった！</dt><dd><?= $feedbackStats['helped_count'] ?><small>件</small></dd></div>
<div><dt>ありがとう！</dt><dd><?= $feedbackStats['thanks_count'] ?><small>件</small></dd></div>
</dl>
<a class="button secondary small" href="<?= e(appUrl('corrections.php')) ?>">届いた修正提案を確認</a>
<?php else: ?>
<h2>共有してくれた人へ</h2>
<div class="reaction-actions">
<?php foreach ($reactionData['reactions'] as $type => $reaction): ?>
<button type="button" class="reaction-button" data-reaction="<?= e($type) ?>" aria-pressed="<?= $reaction['active'] ? 'true' : 'false' ?>"><span><?= e($reaction['label']) ?></span> <span data-count><?= $reaction['count'] ?></span></button>
<?php endforeach; ?>
</div><p class="caption">もう一度押すと解除できます。</p>
<p id="reaction-message" class="status-text" role="status" aria-live="polite"></p>
<?php if ($schedule['status'] === 'active'): ?>
<details class="correction-form-panel"><summary>修正を提案</summary>
<p class="caption">共有元への提案です。投稿者が承認するまでは、元の予定も自分用の予定も変わりません。</p>
<form id="correction-form" class="feedback-form" novalidate>
<label>修正したい項目<select id="correction-field" name="field_name">
<?php foreach (CORRECTION_FIELDS as $field => $label): ?>
<option value="<?= e($field) ?>" data-current="<?= e(correctionDisplay($field, $correctionOriginal[$field])) ?>" data-value="<?= e($correctionOriginal[$field]) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
</select></label>
<div><span class="form-label">現在の共有元の内容</span><p id="correction-current" class="correction-value multiline"></p></div>
<label id="correction-input-label">修正後の内容<input id="correction-value" name="new_value" maxlength="150"></label>
<label id="correction-note-label" hidden>修正後のメモ<textarea id="correction-note" rows="5" maxlength="3000"></textarea></label>
<label id="correction-category-label" hidden>修正後のカテゴリ<select id="correction-category"><?php foreach (SCHEDULE_CATEGORIES as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>修正理由<textarea name="reason" rows="3" maxlength="1000" required placeholder="例：公式サイトで放送時間の変更を確認しました。"></textarea></label>
<label>根拠URL（任意）<input type="url" name="source_url" maxlength="2048" placeholder="https://"></label>
<p class="caption">時刻とメモは空欄にする提案もできます。終日の設定・メンバー・情報元自体の変更は、投稿者が編集します。</p>
<button type="submit" class="button primary small">修正提案を送る</button>
<p id="correction-message" class="status-text" role="status" aria-live="polite"></p>
</form></details>
<?php endif; ?>
<?php endif; ?>
</section>
