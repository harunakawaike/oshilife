<?php
/** response.php の役割：APIの成功・失敗を、統一したJSON形式で返す。 */
declare(strict_types=1);

/** JSON（JavaScriptから読み取れるデータ形式）を返し、処理を終了する。 */
function jsonResponse(array $body, int $status = 200): never
{
    http_response_code($status);
    // 419は独自ステータスなので、Apache版PHPでも500へ変換されないよう理由句を明示する。
    if ($status === 419) {
        header('HTTP/1.1 419 Page Expired', true, 419);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

/** 成功データを共通の封筒に入れて返す。 */
function apiSuccess(array $data = [], int $status = 200): never
{
    jsonResponse(['success' => true, 'data' => $data ?: new stdClass()], $status);
}

/** 失敗理由と、必要なら項目ごとのエラーを返す。 */
function apiError(string $message, int $status, array $errors = []): never
{
    $body = ['success' => false, 'message' => $message];
    if ($errors !== []) {
        $body['errors'] = $errors;
    }
    jsonResponse($body, $status);
}

/** 対応しないHTTPメソッドで更新処理が走ることを防ぐ。 */
function requireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        apiError('この操作方法には対応していません。', 405);
    }
}

/** JSONオブジェクトを読む。配列や不正なJSON、巨大な本文を受け付けない。 */
function readJson(): array
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        apiError('JSON形式で送信してください。', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($raw) > 16384) {
        apiError('入力内容が長すぎます。', 413);
    }
    try {
        $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        apiError('送信データを読み取れませんでした。', 400);
    }
    if (!$decoded instanceof stdClass) {
        apiError('JSONオブジェクトを送信してください。', 400);
    }
    return (array) $decoded;
}
