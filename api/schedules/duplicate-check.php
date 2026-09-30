<?php
/** duplicate-check.php の役割：公開予定の簡易重複候補を返す。非公開の内容は候補にしない。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('GET');
$raw = $_GET + ['visibility' => 'public', 'status' => 'active'];
[$input, $errors] = validateScheduleInput($raw);
if ($errors) apiError('予定のタイトル・日付・推し・カテゴリを入力してください。', 422, $errors);
apiSuccess(['duplicates' => findScheduleDuplicates(database(), $userId, $input)]);
