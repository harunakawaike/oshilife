<?php
/** schedule_source_fields.php の役割：既存情報元と追加テンプレートで共通の入力欄を表示する。 */
$sourceValue = $sourceValue ?? ['source_type' => '', 'source_url' => '', 'note' => ''];
?>
<div class="source-row">
<label>情報元<select name="source_type"><option value="">種類を選択</option><?php foreach (SCHEDULE_SOURCE_TYPES as $value => $label): ?><option value="<?= e($value) ?>" <?= $sourceValue['source_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>URL（任意）<input type="url" name="source_url" maxlength="2048" value="<?= e($sourceValue['source_url']) ?>" placeholder="https://"></label>
<label>情報元の補足（任意）<input name="source_note" maxlength="500" value="<?= e($sourceValue['note']) ?>" placeholder="公式メールで確認、など"></label>
<button type="button" class="text-button source-remove">この情報元を外す</button>
</div>
