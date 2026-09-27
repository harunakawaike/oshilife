<?php
/** unfollow.php の役割：本人の推し登録を解除する。共有マスタは削除しない。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/oshi_api.php';
$user = startOshiApi('POST');
$input = readJson();
$id = requireOshiId($input['oshi_id'] ?? null);
oshiOperation(fn() => unfollowOshi((int) $user['id'], $id), 'この推しは既に登録されています。');
apiSuccess();
