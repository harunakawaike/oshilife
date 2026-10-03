<?php
/** room_expenses.php の役割：ルーム共同支出のカテゴリと日本円の上限を画面・APIで共通化する。 */
declare(strict_types=1);
const ROOM_EXPENSE_CATEGORIES = [
    'ticket'=>'チケット・入場料', 'transportation'=>'交通', 'accommodation'=>'宿泊',
    'food'=>'食事', 'goods'=>'グッズ', 'sightseeing'=>'観光', 'other'=>'その他',
];
const ROOM_EXPENSE_MAX_YEN = 999999999;
