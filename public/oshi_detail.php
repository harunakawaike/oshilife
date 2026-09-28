<?php
/** oshi_detail.php の役割：共有推しの詳細とメンバーを表示し、作成者へ推し編集・メンバー追加フォームを提供する。 */
declare(strict_types=1);
require_once __DIR__ . '/../middleware/auth.php';
$user = requireAuth();
require_once PROJECT_ROOT . '/app/validators/oshi_validator.php';
require_once PROJECT_ROOT . '/app/repositories/oshi_repository.php';
$id = positiveOshiId($_GET['id'] ?? null);
$oshi = $id === null ? null : findOshiDetail(database(), $id, (int) $user['id']);
if (!$oshi) http_response_code(404);
$pageTitle = $oshi ? $oshi['name'] : '推しが見つかりません';
$pageStyle = 'oshis';
$pageScripts = $oshi ? ['oshis'] : [];
$activePage = 'profile';
require PROJECT_ROOT . '/includes/header.php';
?>
<a class="page-back" href="<?= e(appUrl('oshis.php')) ?>">← 推し管理</a>
<?php if (!$oshi): ?>
<section class="empty-state"><h1>推しが見つかりません</h1><p>推し管理の検索から、もう一度探してください。</p></section>
<?php else: ?>
<div id="oshi-detail" data-oshi-id="<?= (int) $oshi['id'] ?>">
    <section class="oshi-detail-hero">
        <span class="detail-emoji" aria-hidden="true"><?= e($oshi['emoji']) ?></span>
        <div><p class="eyebrow">MY OSHI NOTES</p><h1><?= e($oshi['name']) ?></h1><span class="tag lavender"><?= e(OSHI_TYPES[$oshi['oshi_type']]) ?></span></div>
    </section>
    <div class="detail-follow"><button class="button secondary small" id="detail-follow" type="button" data-followed="<?= (int) $oshi['is_followed'] ?>"><?= $oshi['is_followed'] ? '自分の推しから解除' : '＋ 自分の推しに追加' ?></button><p class="caption">解除しても、共有されている推しやメンバー情報は残ります。</p></div>
    <p id="oshi-message" class="status-text" role="status" tabindex="-1"></p>
    <?php if ($oshi['can_manage']): ?>
    <?php if (isset($_GET['updated'])): ?><p class="notice success" role="status">推し情報を更新しました。</p><?php endif; ?>
    <details id="oshi-edit-panel" class="card oshi-edit-panel">
        <summary>推し情報を編集</summary>
        <p class="caption">変更は、この推しを登録している他のユーザーにも反映されます。</p>
        <form id="oshi-edit" novalidate>
            <div class="form-grid">
                <div>
                    <label class="form-label" for="edit-name">推し名</label>
                    <input id="edit-name" name="name" value="<?= e($oshi['name']) ?>" maxlength="100" required aria-describedby="edit-error-name">
                    <p class="field-error" id="edit-error-name"></p>
                </div>
                <div>
                    <label class="form-label" for="edit-type">推し種別</label>
                    <select id="edit-type" name="oshi_type" required aria-describedby="edit-error-oshi_type">
                        <?php foreach (OSHI_TYPES as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $oshi['oshi_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-error" id="edit-error-oshi_type"></p>
                </div>
                <div>
                    <label class="form-label" for="edit-emoji">推しの絵文字</label>
                    <input id="edit-emoji" name="emoji" value="<?= e($oshi['emoji']) ?>" maxlength="64" required aria-describedby="edit-emoji-help edit-error-emoji">
                    <p id="edit-emoji-help" class="caption">複数の絵文字・記号を使えます（32文字以内）。</p>
                    <p class="field-error" id="edit-error-emoji"></p>
                </div>
            </div>
            <p id="edit-message" class="form-message" role="alert" tabindex="-1"></p>
            <div class="edit-actions">
                <button class="button primary small" type="submit">変更を保存</button>
                <button class="button secondary small" id="oshi-edit-cancel" type="button">キャンセル</button>
            </div>
        </form>
    </details>
    <?php else: ?>
    <p class="caption">推し情報を編集できるのは、この推しを作成したユーザーです。</p>
    <?php endif; ?>
    <div class="detail-columns">
        <section class="section">
            <div class="section-toolbar"><h2>メンバー</h2><?php if ($oshi['can_manage']): ?><a href="#member-name">＋ メンバーを追加</a><?php endif; ?></div>
            <div id="oshi-members" class="member-list" aria-live="polite">
                <?php $members = findOshiMembers(database(), $id); ?>
                <?php if (!$members): ?><p class="empty-state">メンバーはまだ登録されていません。</p><?php endif; ?>
                <?php foreach ($members as $member): ?>
                <article class="member-card"><span class="member-heart" aria-hidden="true"><?= e($member['heart_emoji']) ?></span><div><h3><?= e($member['name']) ?></h3><p><?= e($member['color_name']) ?></p></div></article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php if ($oshi['can_manage']): ?>
        <section class="section card member-form-card">
            <p class="eyebrow">MEMBER COLORS</p><h2>メンバーを追加</h2><p class="caption">みんなで使う情報のため、追加できるのは推しの作成者です。</p>
            <form id="member-create" novalidate>
                <label class="form-label" for="member-name">名前</label><input id="member-name" name="name" maxlength="100" required aria-describedby="member-error-name"><p class="field-error" id="member-error-name"></p>
                <fieldset class="heart-fieldset" aria-describedby="member-color-help member-error-heart_emoji">
                    <legend>メンバーカラー</legend>
                    <p id="member-color-help" class="caption">ハートを選ぶだけで色が決まります。</p>
                    <div class="heart-options">
                        <?php foreach (MEMBER_COLOR_PRESETS as $heart => $preset): ?>
                        <label>
                            <input type="radio" name="heart_emoji" value="<?= e($heart) ?>" data-color-name="<?= e($preset['name']) ?>" data-color-value="<?= e($preset['color']) ?>" <?= $heart === '🩷' ? 'checked' : '' ?> aria-label="<?= e($preset['name']) ?>">
                            <span aria-hidden="true"><?= e($heart) ?></span><small><?= e($preset['name']) ?></small>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="field-error" id="member-error-heart_emoji"></p>
                </fieldset>
                <p id="member-color-status" class="caption" role="status">🩷 ピンクを選択中</p>
                <details id="member-color-options" class="member-color-options">
                    <summary>色を細かく調整する（任意）</summary>
                    <label class="form-label" for="member-color">色味を選ぶ</label>
                    <div class="member-color-picker">
                        <input id="member-color" type="color" name="hex_color" value="#E7A6C0" aria-describedby="member-picker-help member-error-hex_color">
                        <p id="member-picker-help" class="caption">色見本を押すと、好きな色を選べます。</p>
                    </div>
                    <p class="field-error" id="member-error-hex_color"></p>
                    <label class="form-label" for="member-color-name">色の名前</label>
                    <input id="member-color-name" name="color_name" value="ピンク" placeholder="例：さくらピンク" maxlength="40" required aria-describedby="member-error-color_name">
                    <p class="field-error" id="member-error-color_name"></p>
                </details>
                <p id="member-message" class="form-message" role="alert" tabindex="-1"></p><button class="button primary" type="submit">メンバーを追加</button>
            </form>
        </section>
        <?php else: ?>
        <p class="caption">共有マスターのメンバー追加は、この推しを作成したユーザーが行えます。</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php require PROJECT_ROOT . '/includes/footer.php'; ?>
