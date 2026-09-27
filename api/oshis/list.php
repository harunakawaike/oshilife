<?php
/** list.php の役割：有効な共有推しをページ単位でJSONで返す。ログインが必要。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/oshi_api.php';
$user = startOshiApi('GET');
oshiListResponse((int) $user['id'], false);
