<?php
/** room_repository.php の役割：ルーム参加状態を確認し、共有可能な項目だけを取得する。 */
declare(strict_types=1);
require_once __DIR__.'/event_repository.php';

/** ルーム全体の更新を直列にするため、更新時は最初にルーム行をロックする。 */
function roomRecord(PDO $pdo, int $roomId, bool $lock = false): array
{
    return eventQuery($pdo, 'SELECT * FROM rooms WHERE id=?'.($lock ? ' FOR UPDATE' : ''), [$roomId])->fetch()
        ?: throw new ScheduleOperationException('ルームが見つかりません。',404);
}

/** 画面のボタンを隠すだけでは防げないため、直接APIでも参加中の本人とowner権限を照合する。 */
function requireRoom(PDO $pdo, int $userId, int $roomId, bool $owner = false, bool $writable = false, bool $lock = false): array
{
    $room = roomRecord($pdo,$roomId,$lock);
    $member = eventQuery($pdo,"SELECT role FROM room_members WHERE room_id=? AND user_id=? AND status='active'",[$roomId,$userId])->fetch();
    if (!$member) throw new ScheduleOperationException('ルームが見つかりません。',404);
    if ($owner && ($member['role'] !== 'owner' || (int)$room['owner_user_id'] !== $userId)) throw new ScheduleOperationException('この操作はルームのownerだけが利用できます。',403);
    if ($writable && $room['status'] !== 'active') throw new ScheduleOperationException('終了したルームは変更できません。',409);
    $room['my_role'] = $member['role'];
    return $room;
}

/** 自分が参加中のルームだけを返す。退出履歴を一覧の閲覧権として扱わない。 */
function listRooms(PDO $pdo, int $userId): array
{
    return eventQuery($pdo,"SELECT r.id,r.name,r.status,m.role AS my_role,
        (SELECT COUNT(*) FROM room_members x WHERE x.room_id=r.id AND x.status='active') AS member_count,
        (SELECT COUNT(*) FROM room_events e WHERE e.room_id=r.id) AS event_count
        FROM rooms r JOIN room_members m ON m.room_id=r.id AND m.user_id=? AND m.status='active'
        ORDER BY r.status='active' DESC,r.id DESC",[$userId])->fetchAll();
}

/** 個人情報はdisplay_nameだけを共有し、メール・パスワード等をSELECTしない。 */
function roomMembers(PDO $pdo, int $userId, int $roomId): array
{
    requireRoom($pdo,$userId,$roomId);
    return eventQuery($pdo,"SELECT m.role,m.joined_at,(m.user_id=?) AS is_me,
        CASE WHEN u.deleted_at IS NULL THEN u.display_name ELSE '退会済みユーザー' END AS display_name
        FROM room_members m JOIN users u ON u.id=m.user_id WHERE m.room_id=? AND m.status='active'
        ORDER BY m.role='owner' DESC,m.joined_at,m.id",[$userId,$roomId])->fetchAll();
}

/** 共有イベントの基本情報だけを返す。本人の当落・金額・TODO・同行者をJOINしない。 */
function roomEvents(PDO $pdo, int $userId, int $roomId): array
{
    requireRoom($pdo,$userId,$roomId);
    // 公開取り消し・削除後は紐付けを残し、非公開になったタイトルや日時を表示しない。
    return eventQuery($pdo,"SELECT re.event_id,(s.id IS NOT NULL) AS available,s.title,s.schedule_date AS event_date,s.start_time,s.end_time,
        CASE WHEN s.id IS NOT NULL THEN e.event_type ELSE NULL END AS event_type,
        CASE WHEN s.id IS NOT NULL THEN v.name ELSE NULL END AS venue_name
        FROM room_events re JOIN events e ON e.id=re.event_id
        LEFT JOIN schedules s ON s.id=e.schedule_id AND s.visibility='public' AND s.status<>'deleted'
        LEFT JOIN venues v ON v.id=e.venue_id WHERE re.room_id=? ORDER BY s.schedule_date,s.start_time,re.id",[$roomId])->fetchAll();
}

/** owner自身が管理中の公開イベントを候補にする。追加しても個人カレンダーへ自動登録しない。 */
function roomAvailableEvents(PDO $pdo, int $userId, int $roomId): array
{
    requireRoom($pdo,$userId,$roomId,true,true);
    return eventQuery($pdo,"SELECT e.id,s.title,s.schedule_date AS event_date,s.start_time,v.name AS venue_name
        FROM user_event_status u JOIN events e ON e.id=u.event_id JOIN schedules s ON s.id=e.schedule_id JOIN venues v ON v.id=e.venue_id
        WHERE u.user_id=? AND s.visibility='public' AND s.status<>'deleted'
        AND NOT EXISTS(SELECT 1 FROM room_events re WHERE re.room_id=? AND re.event_id=e.id)
        ORDER BY s.schedule_date DESC,s.start_time,e.id",[$userId,$roomId])->fetchAll();
}

