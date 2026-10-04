<?php
/** event_companions.php の役割：本人だけの同行者一覧・追加・編集・利用終了を表示する。 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_PRIVATE_ROOT . '/app/helpers/event_view.php';
require_once PROJECT_ROOT.'/app/repositories/participant_repository.php';
$eventId = eventPageId('event_id');
$participants = listParticipants(database(), (int)$user['id'], $eventId);
$event = findEvent(database(), (int)$user['id'], $eventId);
$pageTitle = '同行者';
$active = array_values(array_filter($participants, fn(array $row): bool => $row['archived_at'] === null));
$archived = array_values(array_filter($participants, fn(array $row): bool => $row['archived_at'] !== null));
$hasSelf = count(array_filter($participants, fn(array $row): bool => $row['self_marker'] !== null)) > 0;
// 閲覧(GET)ではDBを書き換えない。最初の追加・自分の編集の保存時に実際の行を作る。
if (!$hasSelf) {
    array_unshift($active, ['id' => null, 'name' => '自分', 'note' => '', 'self_marker' => 1, 'archived_at' => null]);
}

/** 同行者の表示・編集欄を共通化する。e()でHTMLを無害な文字にし、メモ内のタグ実行を防ぐ。 */
function renderParticipantCard(array $participant, int $eventId): void
{
    $self = $participant['self_marker'] !== null;
    $ended = $participant['archived_at'] !== null;
    ?>
    <article class="travel-card participant-card" data-participant-id="<?= (int)$participant['id'] ?>">
        <h3><?= e($participant['name']) ?><?php if ($self && $participant['name'] !== '自分'): ?> <small>自分</small><?php endif; ?></h3>
        <?php if ($participant['note'] !== ''): ?><p class="event-note"><?= e($participant['note']) ?></p><?php endif; ?>
        <?php if (!$ended): ?>
        <details><summary><?= $self ? '自分の名前・メモを編集' : '編集' ?></summary>
            <form class="event-form event-fields" data-api="events/companions/<?= $participant['id'] === null ? 'self' : 'update' ?>" data-reload="true">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($participant['id'] !== null): ?><input type="hidden" name="id" value="<?= (int)$participant['id'] ?>"><?php endif; ?>
                <?php eventField('name', '名前', $participant['name'], 'text', true); eventMemo($participant['note']); eventSubmit('変更を保存'); ?>
            </form>
        </details>
        <?php endif; ?>
        <?php if (!$self): ?>
        <form class="event-form" data-api="events/companions/<?= $ended ? 'restore' : 'archive' ?>" data-reload="true">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <input type="hidden" name="id" value="<?= (int)$participant['id'] ?>">
            <p class="event-message" role="status"></p>
            <button class="button secondary small" type="submit"><?= $ended ? '利用を再開' : '利用終了・非表示にする' ?></button>
        </form>
        <?php endif; ?>
    </article>
    <?php
}
require PROJECT_ROOT.'/includes/header.php';
?>
<a href="<?= e(appUrl('event_detail.php?id='.$eventId)) ?>">← イベント詳細</a>
<section class="card event-section">
    <h1>同行者 <small>自分だけ</small></h1>
    <p class="event-note"><?= e($event['title']) ?> · <?= e($event['event_date']) ?></p>
    <p class="caption">名前・メモは自分のアカウント内だけに保存されます。同行者や他のユーザーには共有されません。</p>
    <div class="travel-list" id="active-participants">
        <?php foreach ($active as $participant) renderParticipantCard($participant, $eventId); ?>
    </div>
    <details id="add-companion"><summary>＋ 同行者を追加</summary>
        <form class="event-form event-fields" data-api="events/companions/create" data-reload="true">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <?php eventField('name', '同行者名', '', 'text', true); eventMemo(); eventSubmit('同行者を追加'); ?>
        </form>
    </details>
</section>
<?php if ($archived): ?>
<section class="card event-section"><details id="archived-participants"><summary>利用終了した同行者</summary>
    <p class="caption">一覧から非表示にしています。記録は残り、必要なときに再開できます。</p>
    <?php foreach ($archived as $participant) renderParticipantCard($participant, $eventId); ?>
</details></section>
<?php endif; require PROJECT_ROOT.'/includes/footer.php'; ?>
