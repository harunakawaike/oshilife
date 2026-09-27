<?php
/** oshi_api.php の役割：推しAPI共通の起動・ID検証・重複エラーの変換をまとめる。 */
declare(strict_types=1);
require_once __DIR__ . '/api_bootstrap.php';
require_once PROJECT_ROOT . '/app/validators/oshi_validator.php';
require_once PROJECT_ROOT . '/app/services/oshi_service.php';

/** メソッドとログインを確認し、書き込みだけCSRFも検証する。 */
function startOshiApi(string $method): array
{
    requireMethod($method);
    $user = requireAuth(true);
    if ($method !== 'GET') requireCsrf();
    return $user;
}

/** 入力からIDを取得し、不正な型や値ならJSONで422を返す。 */
function requireOshiId(mixed $value): int
{
    $id = positiveOshiId($value);
    if ($id === null) apiError('推しIDを正しく指定してください。', 422);
    return $id;
}

/** DB制約違反は日本語へ変換し、その他の例外は共通の500応答へ委ねる。 */
function oshiOperation(callable $operation, string $duplicateMessage): mixed
{
    try {
        return $operation();
    } catch (OshiOperationException $exception) {
        apiError($exception->getMessage(), $exception->getCode());
    } catch (PDOException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            apiError($duplicateMessage, 409);
        }
        throw $exception;
    }
}

/** 全件・検索をページ単位で返す。51件取得して次の50件があるか判断する。 */
function oshiListResponse(int $userId, bool $search): never
{
    $query = normalizeOshiText(inputString($_GET, 'q'));
    if (($search && !validOshiText($query, 100)) || (!$search && $query !== '')) {
        apiError('検索語は1〜100文字で入力してください。', 422);
    }
    $page = positiveOshiId($_GET['page'] ?? 1);
    if ($page === null || $page > 10000) apiError('ページ番号が正しくありません。', 422);
    $items = findOshis(database(), $userId, $query, ($page - 1) * 50);
    $hasMore = count($items) > 50;
    apiSuccess(['oshis' => array_slice($items, 0, 50), 'page' => $page, 'has_more' => $hasMore]);
}
