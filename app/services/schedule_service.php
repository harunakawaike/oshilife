<?php
/** schedule_service.php の役割：共有予定の作成・認可・同期解除・論理削除をまとめる。 */
declare(strict_types=1);
require_once __DIR__ . '/../repositories/schedule_repository.php';
require_once __DIR__ . '/../validators/schedule_validator.php';
class ScheduleOperationException extends RuntimeException {}
/** 重複候補を利用者が確認できるよう、候補情報を持つ例外にする。 */
class ScheduleDuplicateException extends ScheduleOperationException
{
    public function __construct(public array $candidates)
    {
        parent::__construct('似た予定がすでに登録されています。内容を確認してください。', 409);
    }
}

/** 所有者だけが元予定を変更できる。privateを他人が指定しても存在を知らせない。 */
function requireScheduleOwner(?array $schedule, int $userId): array
{
    if (!$schedule || (int) $schedule['created_by_user_id'] !== $userId) {
        throw new ScheduleOperationException('編集できる予定が見つかりません。', 404);
    }
    if ($schedule['status'] === 'deleted') throw new ScheduleOperationException('この予定は削除済みです。', 409);
    return $schedule;
}

/** 推しとメンバーが正しい組合せか、保存する直前に確認する。 */
function checkScheduleRelations(PDO $pdo, int $userId, array $input, ?array $existing): void
{
    $oshi = scheduleQuery($pdo, 'SELECT id FROM oshis WHERE id=:id AND is_active=1', ['id' => $input['oshi_id']])->fetch();
    if (!$oshi) throw new ScheduleOperationException('選択した推しは現在利用できません。', 422);
    // 登録解除後も自分の既存予定は編集可能。ただし別の推しへ変更するなら本人の登録が必要。
    if (!$existing || (int) $existing['oshi_id'] !== $input['oshi_id']) {
        $follow = scheduleQuery($pdo, 'SELECT id FROM user_oshis WHERE user_id=:user_id AND oshi_id=:oshi_id', ['user_id' => $userId, 'oshi_id' => $input['oshi_id']])->fetch();
        if (!$follow) throw new ScheduleOperationException('先に推し管理から、この推しを自分の一覧へ追加してください。', 422);
    }
    foreach ($input['member_ids'] as $id) {
        $member = scheduleQuery($pdo, 'SELECT id FROM members WHERE id=:id AND oshi_id=:oshi_id AND is_active=1', ['id' => $id, 'oshi_id' => $input['oshi_id']])->fetch();
        if (!$member) throw new ScheduleOperationException('選択した推しに所属する有効なメンバーを選んでください。', 422);
    }
}

/** Unicode文字単位の2文字組の一致率で簡易類似判定する。完全一致だけには限定しない。 */
function similarScheduleTitles(string $a, string $b): bool
{
    $normalize = fn(string $value) => preg_replace('/[\s\p{P}\p{S}]+/u', '', mb_strtolower($value, 'UTF-8'));
    $a = $normalize($a); $b = $normalize($b);
    if ($a === '' || $b === '') return false;
    if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) return true;
    $pairs = function (string $value): array {
        $letters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $result = [];
        for ($index = 0; $index < count($letters) - 1; $index++) $result[] = $letters[$index] . $letters[$index + 1];
        return array_unique($result);
    };
    $left = $pairs($a); $right = $pairs($b);
    return count($left) + count($right) > 0 && 2 * count(array_intersect($left, $right)) / (count($left) + count($right)) >= 0.5;
}

/** 同じ推し・日付・カテゴリ、開始時刻が1時間以内の公開予定から似たタイトルを探す。 */
function findScheduleDuplicates(PDO $pdo, int $userId, array $input): array
{
    if ($input['visibility'] !== 'public') return [];
    $rows = selectSchedules($pdo, $userId, "s.visibility='public' AND s.status='active' AND s.oshi_id=:oshi_id AND s.schedule_date=:date AND s.category=:category",
        ['oshi_id' => $input['oshi_id'], 'date' => $input['schedule_date'], 'category' => $input['category']], 's.id DESC', 200);
    $matches = [];
    foreach ($rows as $row) {
        // 個人編集後の値ではなく、公開された元予定で候補を比べる。
        $original = $row['original'];
        $near = true;
        if ($original['start_time'] !== null && $input['start_time'] !== null) {
            $minutes = fn(string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
            $near = abs($minutes($original['start_time']) - $minutes($input['start_time'])) <= 60;
        }
        if ($near && similarScheduleTitles($original['title'], $input['title'])) {
            $matches[] = ['id' => $row['id'], 'title' => $original['title'], 'date' => $original['date'], 'start_time' => $original['start_time']];
        }
        if (count($matches) >= 5) break;
    }
    return $matches;
}

/** 本体・メンバー・情報元を一緒に確定する。公開時の情報元は推奨で、未入力も許可する。 */
function saveSchedule(int $userId, array $input, ?int $id, bool $allowDuplicate): int
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $existing = $id === null ? null : requireScheduleOwner(lockSchedule($pdo, $id), $userId);
        if ($id !== null) requireUnlinkedSchedule($pdo, $id);
        checkScheduleRelations($pdo, $userId, $input, $existing);
        if ($id === null && !$allowDuplicate) {
            $duplicates = findScheduleDuplicates($pdo, $userId, $input);
            if ($duplicates) throw new ScheduleDuplicateException($duplicates);
        }
        $id = saveScheduleRecord($pdo, $userId, $input, $id);
        $pdo->commit();
        return $id;
    } catch (Throwable $exception) { $pdo->rollBack(); throw $exception; }
}

/** 元予定は物理削除せず、statusだけを更新する。取り込み先の参照を壊さない。 */
function deleteSchedule(int $userId, int $id): void
{
    $pdo = database(); $pdo->beginTransaction();
    try {
        requireScheduleOwner(lockSchedule($pdo, $id), $userId);
        requireUnlinkedSchedule($pdo, $id);
        scheduleQuery($pdo, "UPDATE schedules SET status='deleted',updated_at=CURRENT_TIMESTAMP WHERE id=:id", ['id' => $id]);
        $pdo->commit();
    } catch (Throwable $exception) { $pdo->rollBack(); throw $exception; }
}

/** 予定内容はコピーせず、本人と元schedule_idの関連だけを登録する。これが自動同期の基盤。 */
function addScheduleToCalendar(int $userId, int $id): void
{
    $pdo = database(); $pdo->beginTransaction();
    try {
        $schedule = lockSchedule($pdo, $id);
        if (!$schedule || $schedule['visibility'] !== 'public' || $schedule['status'] !== 'active') throw new ScheduleOperationException('追加できる公開予定が見つかりません。', 404);
        if ((int) $schedule['created_by_user_id'] === $userId) throw new ScheduleOperationException('自分で作成した予定は、すでにカレンダーに表示されています。', 409);
        scheduleQuery($pdo, 'INSERT INTO user_schedules (user_id,schedule_id,sync_enabled) VALUES (:user_id,:schedule_id,1)', ['user_id' => $userId, 'schedule_id' => $id]);
        $pdo->commit();
    } catch (Throwable $exception) { $pdo->rollBack(); throw $exception; }
}

/** 本人の取り込みだけを削除する。元予定が非公開・削除済みでも解除できる。 */
function removeScheduleFromCalendar(int $userId, int $id): void
{
    scheduleQuery(database(), 'DELETE FROM user_schedules WHERE user_id=:user_id AND schedule_id=:id', ['user_id' => $userId, 'id' => $id]);
}

/** sync_enabled=falseにし、個人の入力を全項目保存。nullや空メモも意図した値として保存する。 */
function customizeSchedule(int $userId, int $id, array $input): void
{
    $pdo = database(); $pdo->beginTransaction();
    try {
        $schedule = lockSchedule($pdo, $id);
        if (!$schedule || $schedule['visibility'] !== 'public' || $schedule['status'] === 'deleted') throw new ScheduleOperationException('自分用に編集できる公開予定が見つかりません。', 404);
        $link = scheduleQuery($pdo, 'SELECT id FROM user_schedules WHERE user_id=:user_id AND schedule_id=:id FOR UPDATE', ['user_id' => $userId, 'id' => $id])->fetch();
        if (!$link || (int) $schedule['created_by_user_id'] === $userId) throw new ScheduleOperationException('先に自分のカレンダーへ追加してください。', 409);
        $statement = $pdo->prepare('UPDATE user_schedules SET sync_enabled=0,custom_title=:title,custom_date=:schedule_date,
            custom_start_time=:start_time,custom_end_time=:end_time,custom_is_all_day=:is_all_day,custom_note=:note WHERE id=:id');
        $input['is_all_day'] = (int) $input['is_all_day'];
        $statement->execute($input + ['id' => $link['id']]);
        $pdo->commit();
    } catch (Throwable $exception) { $pdo->rollBack(); throw $exception; }
}

/** 連携公演の公開範囲や開催状態が食い違わないよう、共有元の変更はイベント管理へ集約する。 */
function requireUnlinkedSchedule(PDO $pdo, int $id): void
{
    if (scheduleQuery($pdo,'SELECT id FROM events WHERE schedule_id=:id',['id'=>$id])->fetch()) {
        throw new ScheduleOperationException('この予定はイベント管理から編集・中止にしてください。',409);
    }
}
