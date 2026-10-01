<?php
/** detail.php の役割：旧ライブAPIから新イベントAPIへ渡す互換入口。 */
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/legacy_event_api.php';
require __DIR__ . '/../events/detail.php';
