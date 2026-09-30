<?php
/** schedule_validator.php の役割：予定の日時・URL・メンバーIDなどをサーバー側で検証する。 */
declare(strict_types=1);
require_once __DIR__ . '/oshi_validator.php';
require_once __DIR__ . '/../../config/schedules.php';

/** 存在しない日付（2月30日等）や形式違いを除き、1000〜9999年の日付を許可する。 */
function validScheduleDate(string $value): bool
{
    if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/', $value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

/** フォームとJSONからの0/1/真偽値だけを受け付ける。 */
function scheduleBoolean(mixed $value): ?bool
{
    if (in_array($value, [true, 1, '1'], true)) return true;
    if (in_array($value, [false, 0, '0'], true)) return false;
    return null;
}

/** 個人編集でも共有編集でも同じ日時ルールを使う。nullの時間は「時間未定」を表す。 */
function validateScheduleInput(array $raw, bool $custom = false): array
{
    $errors = [];
    $input = [
        'title' => normalizeOshiText(inputString($raw, 'title')),
        'schedule_date' => inputString($raw, 'schedule_date'),
        'start_time' => inputString($raw, 'start_time'),
        'end_time' => inputString($raw, 'end_time'),
        'is_all_day' => scheduleBoolean($raw['is_all_day'] ?? false),
        'note' => trim(inputString($raw, 'note')),
    ];
    if (!validOshiText($input['title'], 150)) $errors['title'] = 'タイトルは1〜150文字で入力してください。';
    if (!validScheduleDate($input['schedule_date'])) $errors['schedule_date'] = '正しい日付を選択してください。';
    if ($input['is_all_day'] === null) $errors['is_all_day'] = '終日の選択が正しくありません。';
    if (mb_strlen($input['note'], 'UTF-8') > 3000 || str_contains($input['note'], "\0")) $errors['note'] = 'メモは3000文字以内で入力してください。';
    foreach (['start_time', 'end_time'] as $key) {
        if (isset($raw[$key]) && !is_string($raw[$key])) $errors[$key] = '時間の形式が正しくありません。';
        if ($input['is_all_day']) $input[$key] = '';
        if ($input[$key] !== '' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $input[$key])) $errors[$key] = '時間を正しく入力してください。';
        if ($input[$key] === '') $input[$key] = null;
    }
    if ($input['end_time'] !== null && ($input['start_time'] === null || $input['end_time'] <= $input['start_time'])) {
        $errors['end_time'] = '終了時間は開始時間より後にしてください。日をまたぐ予定は日ごとに登録してください。';
    }
    if ($custom) return [$input, $errors];
    $input['oshi_id'] = positiveOshiId($raw['oshi_id'] ?? null);
    $input['category'] = inputString($raw, 'category');
    $input['visibility'] = inputString($raw, 'visibility');
    $input['status'] = inputString($raw, 'status') ?: 'active';
    if ($input['oshi_id'] === null) $errors['oshi_id'] = '登録済みの推しを選んでください。';
    if (!isset(SCHEDULE_CATEGORIES[$input['category']])) $errors['category'] = 'カテゴリを選んでください。';
    if (!in_array($input['visibility'], ['public', 'private'], true)) $errors['visibility'] = '公開範囲を選んでください。';
    if (!in_array($input['status'], ['active', 'cancelled'], true)) $errors['status'] = '予定あり、または中止を選んでください。';
    $input['member_ids'] = [];
    $ids = $raw['member_ids'] ?? [];
    if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100) {
        $errors['member_ids'] = 'メンバーの選択が正しくありません。';
    } else {
        foreach ($ids as $value) {
            $id = positiveOshiId($value);
            if ($id === null) $errors['member_ids'] = 'メンバーIDが正しくありません。';
            else $input['member_ids'][] = $id;
        }
        $input['member_ids'] = array_values(array_unique($input['member_ids']));
    }
    $input['sources'] = [];
    $sources = $raw['sources'] ?? [];
    if (!is_array($sources) || !array_is_list($sources) || count($sources) > 5) {
        $errors['sources'] = '情報元は5件以内で指定してください。';
    } elseif ($input['visibility'] === 'public') {
        foreach ($sources as $source) {
            // readJson()は入れ子をstdClassとして返すため、配列へ揃える。
            if ($source instanceof stdClass) $source = (array) $source;
            if (!is_array($source)) { $errors['sources'] = '情報元の形式が正しくありません。'; continue; }
            $type = inputString($source, 'source_type');
            $url = trim(inputString($source, 'source_url'));
            $note = trim(inputString($source, 'note'));
            if (!isset(SCHEDULE_SOURCE_TYPES[$type])) $errors['sources'] = '情報元の種類を選んでください。';
            // URLはリンクとして表示するだけ。サーバーは取得しない。javascript:等の実行を防ぐ。
            $parts = parse_url($url);
            if ($url !== '' && (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']))) {
                $errors['sources'] = '情報元URLはhttp://またはhttps://で始まるURLにしてください。';
            }
            if (mb_strlen($note, 'UTF-8') > 500) $errors['sources'] = '情報元の補足は500文字以内で入力してください。';
            $input['sources'][] = ['source_type' => $type, 'source_url' => $url ?: null, 'note' => $note];
        }
    }
    return [$input, $errors];
}
