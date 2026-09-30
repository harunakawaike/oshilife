<?php
/** schedule_repository.php の役割：共有予定と本人の取り込みをJOINし、同期状態に応じた最新値を返す。 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/schedules.php';

/** LEFT JOINは「まだ取り込んでいない予定」も残す結合。viewerで本人IDを一度だけ束縛する。 */
function scheduleJoins(): string
{
    return ' FROM schedules s JOIN oshis o ON o.id=s.oshi_id
        JOIN users author ON author.id=s.created_by_user_id
        CROSS JOIN (SELECT :viewer AS id) viewer
        LEFT JOIN live_events linked_live ON linked_live.schedule_id=s.id
        LEFT JOIN user_schedules us ON us.schedule_id=s.id AND us.user_id=viewer.id ';
}

/** 個人編集時のみcustom_dateを使う。カレンダーの日付絞り込みにも表示と同じ条件を使う。 */
function scheduleDateExpression(): string
{
    return 'CASE WHEN us.sync_enabled=0 AND s.created_by_user_id<>viewer.id THEN us.custom_date ELSE s.schedule_date END';
}

/** 値は必ずprepare/executeで渡す。SQL断片はこのファイル内の固定文字列のみ。 */
function scheduleQuery(PDO $pdo, string $sql, array $parameters): PDOStatement
{
    $statement = $pdo->prepare($sql);
    foreach ($parameters as $key => $value) {
        $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->execute();
    return $statement;
}

/** 取得済み予定のメンバーと情報元をまとめて取得し、予定ごとのSQL乱発を避ける。 */
function hydrateSchedules(PDO $pdo, array $rows, int $userId): array
{
    if (!$rows) return [];
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $memberStatement = $pdo->prepare('SELECT sm.schedule_id, m.id, m.name, m.heart_emoji, m.color_name
        FROM schedule_members sm JOIN members m ON m.id=sm.member_id
        WHERE sm.schedule_id IN (' . $placeholders . ') ORDER BY m.id');
    $memberStatement->execute($ids);
    $members = [];
    foreach ($memberStatement->fetchAll() as $member) {
        $scheduleId = (int) $member['schedule_id'];
        unset($member['schedule_id']);
        $members[$scheduleId][] = $member;
    }
    $sourceStatement = $pdo->prepare('SELECT schedule_id, source_type, source_url, note FROM schedule_sources
        WHERE schedule_id IN (' . $placeholders . ') ORDER BY id');
    $sourceStatement->execute($ids);
    $sources = [];
    foreach ($sourceStatement->fetchAll() as $source) {
        $scheduleId = (int) $source['schedule_id'];
        unset($source['schedule_id']);
        $source['label'] = SCHEDULE_SOURCE_TYPES[$source['source_type']] ?? 'その他';
        $sources[$scheduleId][] = $source;
    }
    $result = [];
    foreach ($rows as $row) {
        $own = (int) $row['created_by_user_id'] === $userId;
        $added = $row['link_id'] !== null;
        $custom = !$own && $added && (int) $row['sync_enabled'] === 0;
        $original = [
            'title' => $row['title'], 'date' => $row['schedule_date'],
            'start_time' => $row['start_time'] === null ? null : substr($row['start_time'], 0, 5),
            'end_time' => $row['end_time'] === null ? null : substr($row['end_time'], 0, 5),
            'is_all_day' => (bool) $row['is_all_day'], 'note' => $row['note'],
        ];
        $effective = $original;
        if ($custom) {
            // COALESCEは使わない。個人設定のnullは「時間を消した」という意味なので元値へ戻さない。
            $effective = [
                'title' => $row['custom_title'], 'date' => $row['custom_date'],
                'start_time' => $row['custom_start_time'] === null ? null : substr($row['custom_start_time'], 0, 5),
                'end_time' => $row['custom_end_time'] === null ? null : substr($row['custom_end_time'], 0, 5),
                'is_all_day' => (bool) $row['custom_is_all_day'], 'note' => $row['custom_note'],
            ];
        }
        $result[] = $effective + [
            'live_event_id' => $row['live_event_id'] === null ? null : (int) $row['live_event_id'], 'live_status' => $row['live_status'],
            'id' => (int) $row['id'], 'oshi_id' => (int) $row['oshi_id'], 'oshi_name' => $row['oshi_name'],
            'oshi_emoji' => $row['oshi_emoji'], 'category' => $row['category'],
            'category_label' => SCHEDULE_CATEGORIES[$row['category']] ?? 'その他',
            'visibility' => $row['visibility'], 'status' => $row['status'],
            'status_label' => SCHEDULE_STATUSES[$row['status']], 'creator_name' => $row['creator_name'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            'is_owner' => $own, 'is_added' => $added || $own,
            'sync_enabled' => !$own && $added && !$custom,
            'source_type' => $own ? 'own' : ($custom ? 'customized' : ($added ? 'synced' : 'public')),
            'members' => $members[$row['id']] ?? [],
            'member_hearts' => array_column($members[$row['id']] ?? [], 'heart_emoji'),
            'sources' => $sources[$row['id']] ?? [], 'original' => $original,
        ];
    }
    return $result;
}

/** 絞り込み後の行だけを取得する。同期中の予定内容はコピーせず常にschedulesを参照する。 */
function selectSchedules(PDO $pdo, int $userId, string $where, array $parameters = [], string $order = 's.schedule_date,s.start_time,s.id', ?int $limit = null, int $offset = 0): array
{
    $sql = 'SELECT s.*, linked_live.id AS live_event_id, linked_live.status AS live_status, o.name AS oshi_name, o.emoji AS oshi_emoji, author.display_name AS creator_name,
        us.id AS link_id, us.sync_enabled, us.custom_title, us.custom_date, us.custom_start_time,
        us.custom_end_time, us.custom_is_all_day, us.custom_note ' . scheduleJoins() . ' WHERE ' . $where . ' ORDER BY ' . $order;
    if ($limit !== null) {
        $sql .= ' LIMIT :row_limit OFFSET :row_offset';
        $parameters['row_limit'] = $limit;
        $parameters['row_offset'] = $offset;
    }
    $parameters['viewer'] = $userId;
    return hydrateSchedules($pdo, scheduleQuery($pdo, $sql, $parameters)->fetchAll(), $userId);
}

/** privateは作成者だけ。削除済みの公開予定は作成者・取り込み済みユーザーだけに返す。 */
function findScheduleDetail(PDO $pdo, int $id, int $userId): ?array
{
    $rows = selectSchedules($pdo, $userId,
        's.id=:id AND (s.created_by_user_id=viewer.id OR (s.visibility=\'public\' AND (s.status<>\'deleted\' OR us.id IS NOT NULL)))', ['id' => $id]);
    return $rows[0] ?? null;
}

/** 自分の作成分＋自分の取り込み分。元が非公開に変わった予定は取り込み済みでも返さない。 */
function findCalendarSchedules(PDO $pdo, int $userId, string $from, string $to, ?int $oshiId): array
{
    $dateSql = scheduleDateExpression();
    $where = "((s.created_by_user_id=viewer.id AND s.status<>'deleted') OR (us.id IS NOT NULL AND s.visibility='public'))
        AND {$dateSql} >= :from_date AND {$dateSql} <= :to_date";
    $parameters = ['from_date' => $from, 'to_date' => $to];
    if ($oshiId !== null) { $where .= ' AND s.oshi_id=:oshi_id'; $parameters['oshi_id'] = $oshiId; }
    $rows = selectSchedules($pdo, $userId, $where, $parameters, $dateSql . ',s.id');
    usort($rows, fn(array $a, array $b) => [$a['date'], $a['is_all_day'] ? '' : ($a['start_time'] ?? '99:99'), $a['id']] <=> [$b['date'], $b['is_all_day'] ? '' : ($b['start_time'] ?? '99:99'), $b['id']]);
    return $rows;
}

/** 公開一覧とホーム新着。NOT EXISTSは「自分の登録が一件もない」予定を探す条件。 */
function findPublicSchedules(PDO $pdo, int $userId, array $filters, bool $unadded): array
{
    $where = "s.visibility='public' AND s.status='active'";
    $parameters = ['viewer' => $userId];
    if ($unadded) {
        $where .= ' AND s.created_by_user_id<>viewer.id
            AND EXISTS (SELECT 1 FROM user_oshis uo WHERE uo.user_id=viewer.id AND uo.oshi_id=s.oshi_id)
            AND NOT EXISTS (SELECT 1 FROM user_schedules mine WHERE mine.user_id=viewer.id AND mine.schedule_id=s.id)';
    }
    foreach (['oshi_id' => 's.oshi_id', 'category' => 's.category', 'date' => 's.schedule_date'] as $key => $column) {
        if (!empty($filters[$key])) { $where .= ' AND ' . $column . '=:' . $key; $parameters[$key] = $filters[$key]; }
    }
    if ($filters['q'] !== '') { $where .= ' AND LOCATE(:query,s.title)>0'; $parameters['query'] = $filters['q']; }
    $total = (int) scheduleQuery($pdo, 'SELECT COUNT(*) ' . scheduleJoins() . ' WHERE ' . $where, $parameters)->fetchColumn();
    $page = $filters['page'];
    unset($parameters['viewer']);
    $rows = selectSchedules($pdo, $userId, $where, $parameters, $unadded ? 's.created_at DESC,s.id DESC' : 's.schedule_date,s.start_time,s.id', 20, ($page - 1) * 20);
    return ['schedules' => $rows, 'total' => $total, 'page' => $page, 'has_more' => $page * 20 < $total];
}

/** 更新前に共有予定をロックし、公開状態・所有者確認と保存を一つの取引内で行う。 */
function lockSchedule(PDO $pdo, int $id): ?array
{
    return scheduleQuery($pdo, 'SELECT * FROM schedules WHERE id=:id FOR UPDATE', ['id' => $id])->fetch() ?: null;
}

/** 予定本体を保存し、関連メンバー・情報元も同じトランザクションで差し替える。 */
function saveScheduleRecord(PDO $pdo, int $userId, array $input, ?int $id): int
{
    $values = [];
    foreach (['oshi_id', 'title', 'category', 'schedule_date', 'start_time', 'end_time', 'is_all_day', 'visibility', 'status', 'note'] as $key) $values[$key] = $input[$key];
    $values['is_all_day'] = (int) $values['is_all_day'];
    if ($id === null) {
        $statement = $pdo->prepare('INSERT INTO schedules (created_by_user_id,oshi_id,title,category,schedule_date,start_time,end_time,is_all_day,visibility,status,note)
            VALUES (:owner,:oshi_id,:title,:category,:schedule_date,:start_time,:end_time,:is_all_day,:visibility,:status,:note)');
        $statement->execute($values + ['owner' => $userId]);
        $id = (int) $pdo->lastInsertId();
    } else {
        $statement = $pdo->prepare('UPDATE schedules SET oshi_id=:oshi_id,title=:title,category=:category,schedule_date=:schedule_date,
            start_time=:start_time,end_time=:end_time,is_all_day=:is_all_day,visibility=:visibility,status=:status,note=:note,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute($values + ['id' => $id]);
        scheduleQuery($pdo, 'DELETE FROM schedule_members WHERE schedule_id=:id', ['id' => $id]);
        scheduleQuery($pdo, 'DELETE FROM schedule_sources WHERE schedule_id=:id', ['id' => $id]);
    }
    foreach ($input['member_ids'] as $memberId) {
        scheduleQuery($pdo, 'INSERT INTO schedule_members (schedule_id,member_id) VALUES (:schedule_id,:member_id)', ['schedule_id' => $id, 'member_id' => $memberId]);
    }
    foreach ($input['sources'] as $source) {
        $statement = $pdo->prepare('INSERT INTO schedule_sources (schedule_id,source_type,source_url,note) VALUES (:id,:source_type,:source_url,:note)');
        $statement->execute($source + ['id' => $id]);
    }
    return $id;
}
