<?php
/** feedback_service.php の役割：他人の直接更新を防ぎ、提案・承認・感謝を安全な取引として処理する。 */
declare(strict_types=1);
require_once __DIR__ . '/schedule_service.php';
require_once __DIR__ . '/../repositories/feedback_repository.php';
require_once __DIR__ . '/../repositories/notification_repository.php';

/** 非公開や削除済み予定をIDで指定されても内容を漏らさない。 */
function requireFeedbackSchedule(?array $schedule, bool $activeOnly = false): array
{
    if (!$schedule || $schedule['visibility'] !== 'public' || $schedule['status'] === 'deleted' || ($activeOnly && $schedule['status'] !== 'active')) {
        throw new ScheduleOperationException('対象の公開予定が見つかりません。', 404);
    }
    return $schedule;
}

/** 時間の秒を除き、DBのnullと入力の空文字を比較できる形にする。 */
function correctionValue(array $schedule, string $field): ?string
{
    $value = $schedule[$field];
    if (in_array($field, ['start_time', 'end_time'], true)) return $value === null || $value === '' ? null : substr($value, 0, 5);
    return (string) $value;
}

/** 単一の変更を最新の元予定へ仮適用し、日時の前後関係など既存の検証を再利用する。 */
function validateCorrectionValue(array $schedule, string $field, mixed $value): ?string
{
    if (!isset(CORRECTION_FIELDS[$field]) || (!is_string($value) && $value !== null)) throw new ScheduleOperationException('修正項目と入力内容を確認してください。', 422);
    if ((bool) $schedule['is_all_day'] && in_array($field, ['start_time','end_time'], true)) throw new ScheduleOperationException('終日予定の時刻は提案できません。投稿者に終日の設定を確認してください。', 422);
    $candidate = $schedule;
    foreach (['start_time','end_time'] as $time) $candidate[$time] = correctionValue($schedule, $time) ?? '';
    $candidate[$field] = $value ?? '';
    [$normalized, $errors] = validateScheduleInput($candidate);
    if ($errors) throw new ScheduleOperationException(implode(' ', $errors), 422);
    // ライブの日時は同じ予定を参照する。カテゴリ固定と開場順序も承認直前に再確認する。
    $live = scheduleQuery(database(), 'SELECT open_time FROM live_events WHERE schedule_id=:id', ['id'=>$schedule['id']])->fetch();
    if ($live && ($normalized['category'] !== 'live' || ($live['open_time'] !== null && $normalized['start_time'] !== null && substr($live['open_time'],0,5) > $normalized['start_time']))) {
        throw new ScheduleOperationException('ライブのカテゴリと開場・開演の順序を確認してください。',422);
    }
    return $normalized[$field];
}

/** 提案時はschedulesを変更しない。元予定をロックすることで同一提案の同時送信も直列化する。 */
function createCorrection(int $userId, int $scheduleId, array $raw): int
{
    $field = inputString($raw, 'field_name');
    $reason = trim(inputString($raw, 'reason'));
    $url = trim(inputString($raw, 'source_url'));
    if (!array_key_exists('new_value', $raw) || !isset(CORRECTION_FIELDS[$field]) || !validOshiText($reason, 1000)) throw new ScheduleOperationException('修正項目・修正後の内容・理由（1〜1000文字）を入力してください。', 422);
    if (isset($raw['source_url']) && !is_string($raw['source_url'])) throw new ScheduleOperationException('根拠URLの形式が正しくありません。', 422);
    $parts = parse_url($url);
    if ($url !== '' && (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['http','https'], true) || isset($parts['user']) || isset($parts['pass']))) throw new ScheduleOperationException('根拠URLはhttp://またはhttps://で入力してください。', 422);
    $pdo = database(); $pdo->beginTransaction();
    try {
        $schedule = requireFeedbackSchedule(lockSchedule($pdo, $scheduleId), true);
        if ((int) $schedule['created_by_user_id'] === $userId) throw new ScheduleOperationException('自分の予定は「予定を編集」から変更してください。', 403);
        $old = correctionValue($schedule, $field);
        $new = validateCorrectionValue($schedule, $field, $raw['new_value']);
        if ($old === $new) throw new ScheduleOperationException('現在の内容と異なる提案を入力してください。', 422);
        // <=>はNULL同士も等しいと判定する。空時刻への提案も重複を防ぐ。
        $statement = $pdo->prepare("SELECT id FROM correction_requests WHERE schedule_id=:id AND field_name=:field AND new_value <=> :value AND status='pending' LIMIT 1");
        $statement->execute(['id' => $scheduleId, 'field' => $field, 'value' => $new]);
        if ($statement->fetch()) throw new ScheduleOperationException('同様の修正提案がすでにあります。', 409);
        $statement = $pdo->prepare('INSERT INTO correction_requests (schedule_id,requested_by_user_id,field_name,old_value,new_value,reason,source_url) VALUES (:schedule,:user,:field,:old,:new,:reason,:url)');
        $statement->execute(['schedule' => $scheduleId, 'user' => $userId, 'field' => $field, 'old' => $old, 'new' => $new, 'reason' => $reason, 'url' => $url ?: null]);
        $id = (int) $pdo->lastInsertId();
        // 提案と通知を同じ取引で確定し、通知だけが届く状態を防ぐ。
        createFeedbackNotification($pdo, (int) $schedule['created_by_user_id'], $scheduleId, $userId, 'correction', $id);
        $pdo->commit();
        return $id;
    } catch (Throwable $exception) { $pdo->rollBack(); throw $exception; }
}

/** 承認は投稿者のみ。履歴と元予定を一緒に確定し、個人のcustom_*は更新しない。 */
function reviewCorrection(int $userId, int $correctionId, bool $approve): void
{
    $pdo = database(); $pdo->beginTransaction();
    try {
        $reference = scheduleQuery($pdo, 'SELECT schedule_id FROM correction_requests WHERE id=:id', ['id' => $correctionId])->fetch();
        if (!$reference) throw new ScheduleOperationException('修正提案が見つかりません。', 404);
        // 全ての書込みで「元予定→提案」の順にロックし、同時承認や提案作成との競合を防ぐ。
        $schedule = lockSchedule($pdo, (int) $reference['schedule_id']);
        if (!$schedule || (int) $schedule['created_by_user_id'] !== $userId) throw new ScheduleOperationException('確認できる修正提案が見つかりません。', 404);
        $request = scheduleQuery($pdo, 'SELECT * FROM correction_requests WHERE id=:id FOR UPDATE', ['id' => $correctionId])->fetch();
        if ($request['status'] !== 'pending') throw new ScheduleOperationException('この提案はすでに確認済みです。', 409);
        if ($approve) {
            if ($schedule['visibility'] !== 'public' || $schedule['status'] !== 'active') throw new ScheduleOperationException('公開中の有効な予定だけ承認できます。不要な提案は却下してください。', 409);
            $field = $request['field_name'];
            if (!isset(CORRECTION_FIELDS[$field])) throw new ScheduleOperationException('この項目は修正できません。', 422);
            // 提案後に元の値が変わった場合、古い情報をそのまま上書きしない。
            if (correctionValue($schedule, $field) !== $request['old_value']) throw new ScheduleOperationException('提案後に元の内容が変更されています。最新の予定を確認し、この提案を却下して再提案を依頼してください。', 409);
            $new = validateCorrectionValue($schedule, $field, $request['new_value']);
            // 列名は許可リストで検証済み。値はPDOへ別送しSQLへの直接埋め込みを防ぐ。
            $statement = $pdo->prepare('UPDATE schedules SET ' . $field . '=:value,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $statement->execute(['value' => $new, 'id' => $schedule['id']]);
        }
        scheduleQuery($pdo, 'UPDATE correction_requests SET status=:status,reviewed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id', ['status' => $approve ? 'approved' : 'rejected', 'id' => $correctionId]);
        $pdo->commit();
    } catch (Throwable $exception) { $pdo->rollBack(); throw $exception; }
}

/** toggleは「未登録なら追加、登録済みなら解除」。元予定ロックとUNIQUE制約で二重登録を防ぐ。 */
function toggleReaction(int $userId, int $scheduleId, string $type): array
{
    if (!isset(REACTION_LABELS[$type])) throw new ScheduleOperationException('リアクションの種類を確認してください。', 422);
    $pdo = database(); $pdo->beginTransaction();
    try {
        $schedule = requireFeedbackSchedule(lockSchedule($pdo, $scheduleId));
        if ((int) $schedule['created_by_user_id'] === $userId) throw new ScheduleOperationException('自分の予定にはリアクションできません。', 403);
        $params = ['schedule' => $scheduleId, 'user' => $userId, 'type' => $type];
        $existing = scheduleQuery($pdo, 'SELECT id FROM reactions WHERE schedule_id=:schedule AND user_id=:user AND reaction_type=:type FOR UPDATE', $params)->fetch();
        if ($existing) scheduleQuery($pdo, 'DELETE FROM reactions WHERE id=:id', ['id' => (int) $existing['id']]);
        else scheduleQuery($pdo, 'INSERT INTO reactions (schedule_id,user_id,reaction_type) VALUES (:schedule,:user,:type)', $params);
        if (!$existing) createFeedbackNotification($pdo, (int) $schedule['created_by_user_id'], $scheduleId, $userId, $type);
        $count = (int) scheduleQuery($pdo, 'SELECT COUNT(*) FROM reactions WHERE schedule_id=:schedule AND reaction_type=:type', ['schedule' => $scheduleId, 'type' => $type])->fetchColumn();
        $pdo->commit();
        return ['reaction_type' => $type, 'active' => !$existing, 'count' => $count];
    } catch (Throwable $exception) { $pdo->rollBack(); throw $exception; }
}
