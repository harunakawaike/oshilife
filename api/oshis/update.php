<?php
/** update.php の役割：作成者本人が推しの名前・種別・絵文字・テーマカラーを編集するJSON API。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/oshi_api.php';
$user = startOshiApi('POST');
$raw = readJson();
$id = requireOshiId($raw['oshi_id'] ?? null);
$input = normalizeOshiInput($raw);
$errors = validateOshi($input);
if ($errors) {
    apiError('入力内容を確認してください。', 422, $errors);
}
// フォームのuser_id/created_by_user_idは使わず、認証済み本人のIDで権限を確認する。
oshiOperation(
    fn() => updateOshi((int) $user['id'], $id, $input),
    '同じ名前・種別の推しが既にあります。別の名前または種別にしてください。'
);
apiSuccess(['oshi' => findOshiDetail(database(), $id, (int) $user['id'])]);
