<?php
/** participant_repository.php の役割：同行者を所有ユーザーとイベントの範囲内で取得する。 */
declare(strict_types=1);
require_once __DIR__.'/event_repository.php';

/** 共有イベントが見えるだけでは許可しない。本人の管理情報があることを確認する。 */
function requireParticipantManagement(PDO $pdo, int $userId, int $eventId, bool $lock = false): void
{
    // prepare + executeを使うeventQueryへ値を渡し、SQLインジェクションを防ぐ。
    $row = eventQuery($pdo, 'SELECT id FROM user_event_status WHERE user_id=? AND event_id=?'.($lock ? ' FOR UPDATE' : ''), [$userId, $eventId])->fetch();
    if (!$row) {
        throw new ScheduleOperationException('自分の管理に登録したイベントを選んでください。', 404);
    }
}

/** 終了済みも含めて本人の一覧を返す。画面側では終了済みを折りたたんで表示する。 */
function listParticipants(PDO $pdo, int $userId, int $eventId): array
{
    requireParticipantManagement($pdo, $userId, $eventId);
    return eventQuery($pdo, 'SELECT id,event_id,name,note,self_marker,archived_at FROM event_participants WHERE user_id=? AND event_id=? ORDER BY self_marker DESC,archived_at IS NOT NULL,id', [$userId, $eventId])->fetchAll();
}

/** ID単独では検索しない。他人・別イベントのIDも未登録と同じ404にする。 */
function findParticipant(PDO $pdo, int $userId, int $eventId, int $id): array
{
    $row = eventQuery($pdo, 'SELECT * FROM event_participants WHERE id=? AND user_id=? AND event_id=? FOR UPDATE', [$id, $userId, $eventId])->fetch();
    if (!$row) {
        throw new ScheduleOperationException('同行者が見つかりません。', 404);
    }
    return $row;
}
