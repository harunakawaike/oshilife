<?php
/** participant_service.php の役割：自分の一意性を守り、同行者の保存・利用終了を一つの取引で行う。 */
declare(strict_types=1);
require_once __DIR__.'/event_service.php';
require_once __DIR__.'/../repositories/participant_repository.php';

/** 初回の保存時だけ「自分」を作る。呼び出し前に本人管理行をロックする。 */
function ensureSelfParticipant(PDO $pdo, int $userId, int $eventId): int
{
    $id = eventQuery($pdo, 'SELECT id FROM event_participants WHERE user_id=? AND event_id=? AND self_marker=1', [$userId, $eventId])->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }
    eventQuery($pdo, 'INSERT INTO event_participants (user_id,event_id,name,note,self_marker) VALUES (?,?,?, ?,1)', [$userId, $eventId, '自分', '']);
    return (int)$pdo->lastInsertId();
}

/** 同行者だけを変更する。参加状況・TODO・支出など既存テーブルには書き込まない。 */
function saveParticipant(int $userId, int $eventId, string $action, array $raw): int
{
    $name = '';
    $note = '';
    if (in_array($action, ['create', 'update', 'self'], true)) {
        $name = eventText($raw, 'name', '名前', 150, true);
        $note = eventText($raw, 'note', 'メモ', 3000);
    }
    $id = null;
    if (in_array($action, ['update', 'archive', 'restore'], true)) {
        $id = positiveOshiId($raw['id'] ?? null);
        if ($id === null) {
            throw new ScheduleOperationException('同行者IDを正しく指定してください。', 422);
        }
    }
    // 自分フラグをクライアントから変更させない。自分は専用処理だけで作る。
    foreach (['user_id', 'self_marker', 'is_self', 'archived_at'] as $reserved) {
        if (array_key_exists($reserved, $raw)) {
            throw new ScheduleOperationException('変更できない項目が指定されています。', 422);
        }
    }
    $pdo = database();
    $pdo->beginTransaction();
    try {
        // 同じ本人・イベントの保存を直列にし、同時操作でも自分が重複しないようにする。
        requireParticipantManagement($pdo, $userId, $eventId, true);
        if ($action === 'create' || $action === 'self') {
            $selfId = ensureSelfParticipant($pdo, $userId, $eventId);
            if ($action === 'self') {
                eventQuery($pdo, 'UPDATE event_participants SET name=?,note=? WHERE id=? AND user_id=? AND event_id=?', [$name, $note, $selfId, $userId, $eventId]);
                $id = $selfId;
            } else {
                // 同名でも別人を登録できるよう、名前にはUNIQUEを付けない。
                eventQuery($pdo, 'INSERT INTO event_participants (user_id,event_id,name,note) VALUES (?,?,?,?)', [$userId, $eventId, $name, $note]);
                $id = (int)$pdo->lastInsertId();
            }
        } else {
            $participant = findParticipant($pdo, $userId, $eventId, $id);
            if ($action === 'update') {
                if ($participant['archived_at'] !== null) {
                    throw new ScheduleOperationException('利用を再開してから編集してください。', 409);
                }
                eventQuery($pdo, 'UPDATE event_participants SET name=?,note=? WHERE id=? AND user_id=? AND event_id=?', [$name, $note, $id, $userId, $eventId]);
            } else {
                if ($participant['self_marker'] !== null) {
                    throw new ScheduleOperationException('自分を利用終了にはできません。', 422);
                }
                // 将来の金銭履歴を失わないよう、DELETEせず日時で利用終了を表す。
                $sql = $action === 'archive'
                    ? 'UPDATE event_participants SET archived_at=COALESCE(archived_at,CURRENT_TIMESTAMP) WHERE id=? AND user_id=? AND event_id=?'
                    : 'UPDATE event_participants SET archived_at=NULL WHERE id=? AND user_id=? AND event_id=?';
                eventQuery($pdo, $sql, [$id, $userId, $eventId]);
            }
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
