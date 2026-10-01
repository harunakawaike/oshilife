<?php
/** unadded.php の役割：公開予定を絞り込む。ホーム新着では自分の推しと未追加条件も付ける。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/schedule_api.php';
$userId = startScheduleApi('GET');
$filters = scheduleFilters();
// 分類をSQLの件数集計・ページ分割より先に適用し、各欄の件数を正しく保つ。
$kind = $_GET['kind'] ?? 'all';
if (!in_array($kind, ['all', 'schedule', 'event', 'live'], true)) apiError('新着情報の種類が正しくありません。', 422);
// 旧kind=liveも受け付けるが、内部の分類名はeventへ統一する。
$filters['kind'] = $kind === 'live' ? 'event' : $kind;
apiSuccess(findPublicSchedules(database(), $userId, $filters, true));
