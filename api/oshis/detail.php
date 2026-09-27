<?php
/** detail.php の役割：有効な推しの詳細・登録状態・管理権限を返す。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/oshi_api.php';
$user = startOshiApi('GET');
$id = requireOshiId($_GET['id'] ?? null);
$oshi = findOshiDetail(database(), $id, (int) $user['id']);
if (!$oshi) apiError('この推しは見つからないか、現在利用できません。', 404);
apiSuccess(['oshi' => $oshi]);
