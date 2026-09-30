<?php
/** schedules.php の役割：予定カテゴリ・情報元・状態の日本語表示を一か所で管理する。 */
declare(strict_types=1);
const SCHEDULE_CATEGORIES = [
    'tv' => 'TV', 'radio' => 'ラジオ', 'youtube' => 'YouTube', 'live' => 'LIVE',
    'event' => 'イベント', 'music' => 'CD・音楽', 'magazine' => '雑誌',
    'streaming' => '配信', 'other' => 'その他',
];
const SCHEDULE_SOURCE_TYPES = [
    'official_site' => '公式サイト', 'official_sns' => '公式SNS', 'fanclub' => 'ファンクラブ',
    'official_mail' => '公式メール', 'tv_station' => 'テレビ局',
    'magazine_official' => '雑誌公式', 'other' => 'その他',
];
const SCHEDULE_STATUSES = ['active' => '予定あり', 'cancelled' => '中止', 'deleted' => '削除済み'];
