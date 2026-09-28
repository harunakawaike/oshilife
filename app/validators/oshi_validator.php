<?php
/** oshi_validator.php の役割：推し・メンバーの入力を検証する。APIとCSVで同じルールを使う。 */
declare(strict_types=1);
require_once __DIR__ . '/auth_validator.php';
const OSHI_TYPES = ['group' => 'グループ', 'solo' => 'ソロアーティスト', 'actor' => '俳優 / タレント', 'other' => 'その他'];
// ハートを選ぶだけで色名と保存用の色が決まる。利用者にコードの入力を求めない。
const MEMBER_COLOR_PRESETS = [
    '❤️' => ['name' => '赤', 'color' => '#E85D67'],
    '🧡' => ['name' => 'オレンジ', 'color' => '#F29A50'],
    '💛' => ['name' => '黄色', 'color' => '#F2CC4D'],
    '💚' => ['name' => '緑', 'color' => '#63AA75'],
    '🩵' => ['name' => '水色', 'color' => '#85CDE5'],
    '💙' => ['name' => '青', 'color' => '#5B85D6'],
    '💜' => ['name' => '紫', 'color' => '#A07AC5'],
    '🩷' => ['name' => 'ピンク', 'color' => '#E7A6C0'],
    '🤍' => ['name' => '白', 'color' => '#FFFFFF'],
    '🖤' => ['name' => '黒', 'color' => '#333333'],
    '🤎' => ['name' => '茶色', 'color' => '#9B735A'],
];

/** 前後の半角・全角空白を除き、同じ見た目の名前が重複しにくくする。 */
function normalizeOshiText(string $value): string
{
    return preg_replace('/^\s+|\s+$/u', '', $value) ?? '';
}

/** 必須文字列の長さと制御文字を確認する。絵文字の複数コードポイントも保存できる。 */
function validOshiText(string $value, int $max): bool
{
    return $value !== '' && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= $max && !preg_match('/[\x00-\x1F\x7F]/u', $value);
}

/** APIとCSVのどちらからでも同じ形に正規化する。 */
function normalizeOshiInput(array $input): array
{
    $normalized = [];
    foreach (['name', 'oshi_type', 'emoji'] as $key) {
        $normalized[$key] = normalizeOshiText(inputString($input, $key));
    }
    return $normalized;
}

/** 推し名・種別・複数絵文字を確認する。色はメンバーだけで管理する。 */
function validateOshi(array $input): array
{
    $errors = [];
    if (!validOshiText($input['name'], 100)) $errors['name'] = '推し名は1〜100文字で入力してください。';
    if (!array_key_exists($input['oshi_type'], OSHI_TYPES)) $errors['oshi_type'] = '推し種別を選択してください。';
    if (!validOshiText($input['emoji'], 32)) $errors['emoji'] = '絵文字・記号を1〜32文字で入力してください。';
    return $errors;
}

/** メンバーの文字列を正規化する。ハートはバリエーションセレクターも含めて保持する。 */
function normalizeMemberInput(array $input): array
{
    $normalized = [];
    foreach (['name', 'color_name', 'heart_emoji', 'hex_color'] as $key) {
        $normalized[$key] = normalizeOshiText(inputString($input, $key));
    }
    $normalized['hex_color'] = strtoupper($normalized['hex_color']);
    return $normalized;
}

/** メンバー名・カラー名・許可されたハート・HEX色を確認する。 */
function validateMember(array $input): array
{
    $errors = [];
    if (!validOshiText($input['name'], 100)) $errors['name'] = '名前は1〜100文字で入力してください。';
    if (!validOshiText($input['color_name'], 40)) $errors['color_name'] = 'カラー名は1〜40文字で入力してください。';
    if (!array_key_exists($input['heart_emoji'], MEMBER_COLOR_PRESETS)) $errors['heart_emoji'] = '候補のハートから選択してください。';
    if (!preg_match('/^#[0-9A-F]{6}$/', $input['hex_color'])) $errors['hex_color'] = '色見本からメンバーカラーを選び直してください。';
    return $errors;
}

/** 配列や負数をIDとして扱わず、正の整数だけを許可する。 */
function positiveOshiId(mixed $value): ?int
{
    if (!is_string($value) && !is_int($value)) return null;
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}
