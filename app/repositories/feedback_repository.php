<?php
/** feedback_repository.php の役割：修正提案履歴・リアクション・本人の月次集計をSQLで取得する。 */
declare(strict_types=1);
require_once __DIR__ . '/schedule_repository.php';
require_once __DIR__ . '/../../config/feedback.php';

/** COUNTは行数、COUNT DISTINCTは重複しない人数。JOINで件数を掛け合わせないよう別々に集計する。 */
function scheduleFeedbackStats(PDO $pdo, int $scheduleId): array
{
    $params = ['id' => $scheduleId];
    $added = scheduleQuery($pdo, 'SELECT COUNT(DISTINCT user_id) FROM user_schedules WHERE schedule_id=:id', $params)->fetchColumn();
    $pending = scheduleQuery($pdo, "SELECT COUNT(*) FROM correction_requests WHERE schedule_id=:id AND status='pending'", $params)->fetchColumn();
    $stats = ['calendar_added_count' => (int) $added, 'pending_correction_count' => (int) $pending, 'helped_count' => 0, 'thanks_count' => 0];
    $rows = scheduleQuery($pdo, 'SELECT reaction_type,COUNT(*) AS total FROM reactions WHERE schedule_id=:id GROUP BY reaction_type', $params)->fetchAll();
    foreach ($rows as $row) $stats[$row['reaction_type'] . '_count'] = (int) $row['total'];
    return $stats;
}

/** 本人が押した種類だけを返す。他の人のユーザーIDやメールは公開しない。 */
function reactionStatus(PDO $pdo, int $scheduleId, int $userId): array
{
    $stats = scheduleFeedbackStats($pdo, $scheduleId);
    $mine = scheduleQuery($pdo, 'SELECT reaction_type FROM reactions WHERE schedule_id=:id AND user_id=:user_id', ['id' => $scheduleId, 'user_id' => $userId])->fetchAll(PDO::FETCH_COLUMN);
    $result = [];
    foreach (REACTION_LABELS as $type => $label) $result[$type] = ['label' => $label, 'active' => in_array($type, $mine, true), 'count' => $stats[$type . '_count']];
    return ['reactions' => $result, 'pending_correction_count' => $stats['pending_correction_count']];
}

/** 所有者だけに提案本文を返す。非公開へ変更した後も本人は履歴の確認・却下ができる。 */
function listCorrectionRequests(PDO $pdo, int $ownerId, string $status, int $page): array
{
    $where = 's.created_by_user_id=:owner';
    $params = ['owner' => $ownerId];
    if ($status !== 'all') { $where .= ' AND cr.status=:status'; $params['status'] = $status; }
    $from = ' FROM correction_requests cr JOIN schedules s ON s.id=cr.schedule_id JOIN users u ON u.id=cr.requested_by_user_id WHERE ' . $where;
    $total = (int) scheduleQuery($pdo, 'SELECT COUNT(*)' . $from, $params)->fetchColumn();
    $rows = scheduleQuery($pdo, 'SELECT cr.*,s.title AS schedule_title,s.visibility,s.status AS schedule_status,u.display_name AS requester_name' . $from . ' ORDER BY cr.created_at DESC,cr.id DESC LIMIT :limit OFFSET :offset', $params + ['limit' => 20, 'offset' => ($page - 1) * 20])->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id']; $row['schedule_id'] = (int) $row['schedule_id'];
        unset($row['requested_by_user_id']);
        $row['field_label'] = CORRECTION_FIELDS[$row['field_name']];
        $row['status_label'] = CORRECTION_STATUSES[$row['status']];
        $row['old_display'] = correctionDisplay($row['field_name'], $row['old_value']);
        $row['new_display'] = correctionDisplay($row['field_name'], $row['new_value']);
        $row['can_approve'] = $row['status'] === 'pending' && $row['visibility'] === 'public' && $row['schedule_status'] === 'active';
    }
    unset($row);
    return ['corrections' => $rows, 'total' => $total, 'page' => $page, 'has_more' => $page * 20 < $total];
}

/** 未入力・カテゴリ内部値も、画面では読める言葉に揃える。 */
function correctionDisplay(string $field, ?string $value): string
{
    if ($value === null || $value === '') return '未入力';
    return $field === 'category' ? (SCHEDULE_CATEGORIES[$value] ?? $value) : $value;
}

/** 月初以上・翌月初未満の半開区間で、月末の時刻・年越しを正しく扱う。 */
function monthlyFeedbackSummary(PDO $pdo, int $userId, int $year, int $month, ?int $oshiId = null): array
{
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), new DateTimeZone('Asia/Tokyo'));
    $params = ['owner' => $userId, 'from_date' => $start->format('Y-m-d H:i:s'), 'to_date' => $start->modify('+1 month')->format('Y-m-d H:i:s')];
    // 削除済み・中止も「共有した実績」として数える。現在非公開の予定は集計対象外。
    $where = "s.created_by_user_id=:owner AND s.visibility='public'";
    if ($oshiId !== null) { $where .= ' AND s.oshi_id=:oshi'; $params['oshi'] = $oshiId; }
    $shared = scheduleQuery($pdo, 'SELECT COUNT(*) FROM schedules s WHERE ' . $where . ' AND s.created_at>=:from_date AND s.created_at<:to_date', $params)->fetchColumn();
    $added = scheduleQuery($pdo, 'SELECT COUNT(*) AS total,COUNT(DISTINCT us.user_id) AS people FROM user_schedules us JOIN schedules s ON s.id=us.schedule_id WHERE ' . $where . ' AND us.added_at>=:from_date AND us.added_at<:to_date', $params)->fetch();
    $result = ['year' => $year, 'month' => $month, 'shared_schedule_count' => (int) $shared, 'calendar_added_count' => (int) $added['total'], 'calendar_added_user_count' => (int) $added['people'], 'helped_count' => 0, 'thanks_count' => 0];
    $rows = scheduleQuery($pdo, 'SELECT r.reaction_type,COUNT(*) AS total FROM reactions r JOIN schedules s ON s.id=r.schedule_id WHERE ' . $where . ' AND r.created_at>=:from_date AND r.created_at<:to_date GROUP BY r.reaction_type', $params)->fetchAll();
    foreach ($rows as $row) $result[$row['reaction_type'] . '_count'] = (int) $row['total'];
    return $result;
}
