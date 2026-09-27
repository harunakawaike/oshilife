<?php
/** my.php の役割：ログイン本人が登録した推しだけを返す。入力されたuser_idは使用しない。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/oshi_api.php';
$user = startOshiApi('GET');
apiSuccess(['oshis' => findMyOshis(database(), (int) $user['id'])]);
