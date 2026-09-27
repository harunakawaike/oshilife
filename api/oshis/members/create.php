<?php
/** create.php の役割：作成者の権限と入力を確認して、ハートカラー付きメンバーを追加する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../../app/helpers/oshi_api.php';
$user = startOshiApi('POST');
$raw = readJson();
$id = requireOshiId($raw['oshi_id'] ?? null);
$input = normalizeMemberInput($raw);
$errors = validateMember($input);
if ($errors) apiError('入力内容を確認してください。', 422, $errors);
$memberId = oshiOperation(fn() => createOshiMember((int) $user['id'], $id, $input), 'この推しには同じ名前のメンバーが既に登録されています。');
apiSuccess(['member_id' => $memberId], 201);
