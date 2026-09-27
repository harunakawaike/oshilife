<?php
/** create.php の役割：入力を検証し、共有推し作成と本人の登録を行う。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/oshi_api.php';
$user = startOshiApi('POST');
$input = normalizeOshiInput(readJson());
$errors = validateOshi($input);
if ($errors) apiError('入力内容を確認してください。', 422, $errors);
$id = oshiOperation(fn() => createAndFollowOshi((int) $user['id'], $input), '同じ名前・種別の推しが既にあります。検索して追加してください。');
apiSuccess(['oshi' => findOshiDetail(database(), $id, (int) $user['id'])], 201);
