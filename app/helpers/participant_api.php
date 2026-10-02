<?php
/** participant_api.php の役割：同行者APIの認証・CSRF・入力確認・JSON応答を共通化する。 */
declare(strict_types=1);
require_once __DIR__.'/schedule_api.php';
require_once PROJECT_ROOT.'/app/services/participant_service.php';

/** 画面とAPIを分離し、同じ権限確認をすべての操作に適用する。 */
function runParticipantApi(string $action): void
{
    if (!in_array($action, ['list', 'create', 'update', 'self', 'archive', 'restore'], true)) {
        apiError('APIが見つかりません。', 404);
    }
    $read = $action === 'list';
    // middlewareで本人を確認。更新時はCSRFトークンを確認し、他サイトからの意図しない操作を防ぐ。
    $userId = startScheduleApi($read ? 'GET' : 'POST');
    $raw = $read ? $_GET : readJson();
    $eventId = scheduleId($raw['event_id'] ?? null);
    if ($read) {
        apiSuccess(['participants' => listParticipants(database(), $userId, $eventId)]);
    }
    $id = saveParticipant($userId, $eventId, $action, $raw);
    apiSuccess(['id' => $id], $action === 'create' ? 201 : 200);
}
