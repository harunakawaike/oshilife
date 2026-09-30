<?php
/** schedule_api.php の役割：予定API共通の認証・入力パラメータ・日本語例外応答を用意する。 */
declare(strict_types=1);
require_once __DIR__ . '/api_bootstrap.php';
require_once PROJECT_ROOT . '/app/services/schedule_service.php';
set_exception_handler(function (Throwable $exception): void {
    if ($exception instanceof ScheduleDuplicateException) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage(), 'duplicates' => $exception->candidates], 409);
    }
    if ($exception instanceof ScheduleOperationException) apiError($exception->getMessage(), $exception->getCode());
    if ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062) apiError('この予定はすでにカレンダーに追加されています。', 409);
    error_log('Schedule API: ' . get_class($exception) . ' code=' . $exception->getCode());
    apiError('予定の処理に失敗しました。時間をおいてお試しください。', 500);
});

/** GETもPOSTも認証し、更新にはCSRFを必須にする。 */
function startScheduleApi(string $method): int
{
    requireMethod($method);
    $user = requireAuth(true);
    if ($method !== 'GET') requireCsrf();
    return (int) $user['id'];
}

/** IDに配列・負数・不正な値を渡されても処理しない。 */
function scheduleId(mixed $value): int
{
    $id = positiveOshiId($value);
    if ($id === null) apiError('予定IDを正しく指定してください。', 422);
    return $id;
}

/** allは絞り込みなし。特定の推しIDは正の整数だけを許可する。 */
function scheduleOshiFilter(mixed $value): ?int
{
    if ($value === null || $value === '' || $value === 'all') return null;
    $id = positiveOshiId($value);
    if ($id === null) apiError('推しの指定が正しくありません。', 422);
    return $id;
}

/** 検索条件を共通化し、配列型などを空条件に変えて黙って通さない。 */
function scheduleFilters(): array
{
    foreach (['q','date','category'] as $key) {
        if (isset($_GET[$key]) && !is_string($_GET[$key])) apiError('検索条件が正しくありません。', 422);
    }
    $filters = [
        'q' => trim(inputString($_GET, 'q')), 'date' => inputString($_GET, 'date'),
        'category' => inputString($_GET, 'category'), 'oshi_id' => scheduleOshiFilter($_GET['oshi_id'] ?? null),
        'page' => positiveOshiId($_GET['page'] ?? 1),
    ];
    if (mb_strlen($filters['q'], 'UTF-8') > 150 || ($filters['date'] !== '' && !validScheduleDate($filters['date'])) || ($filters['category'] !== '' && !isset(SCHEDULE_CATEGORIES[$filters['category']])) || $filters['page'] === null || $filters['page'] > 10000) apiError('検索条件が正しくありません。', 422);
    return $filters;
}
