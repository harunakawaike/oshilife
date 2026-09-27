<?php
/** register.php の役割：POSTされた登録情報を検証し、新しいユーザーを作るJSON API。登録後はログイン画面へ案内する。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/api_bootstrap.php';
requireMethod('POST');
requireCsrf();
limitAuthRequests();
$input = readJson();
$errors = validateRegistration($input);
if ($errors !== []) {
    apiError('入力内容を確認してください。', 422, $errors);
}
try {
    $id = registerUser($input);
} catch (PDOException $exception) {
    // 事前検索だけでは同時登録の競合を防げないため、DBのUNIQUE制約で確実に重複を検出する。
    if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
        apiError('このメールアドレスは既に登録されています。', 409, ['email' => '別のメールアドレスを入力するか、ログインしてください。']);
    }
    throw $exception;
}
apiSuccess(['user_id' => $id], 201);
