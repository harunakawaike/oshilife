<?php
/** lives.php の役割：ライブ管理の状態名とTODOテンプレートを共通管理する。 */
declare(strict_types=1);
const LIVE_STATUSES = ['scheduled'=>'開催予定','postponed'=>'延期','cancelled'=>'中止','completed'=>'終了'];
const APPLICATION_STATUSES = ['not_applied'=>'未申込','applying'=>'申込中','applied'=>'申込済み'];
const LOTTERY_STATUSES = ['pending'=>'結果待ち','won'=>'当選','lost'=>'落選','general_sale'=>'一般販売','production_release'=>'制作開放席','other'=>'その他'];
const TRIP_TYPES = ['local'=>'近場','trip'=>'遠征'];
const TRANSPORT_TYPES = ['shinkansen'=>'新幹線','airplane'=>'飛行機','highway_bus'=>'高速バス','car'=>'車','local_train'=>'在来線','other'=>'その他'];
const RESERVATION_STATUSES = ['considering'=>'検討中','reserved'=>'予約済み','cancelled'=>'キャンセル'];
const LOCAL_TODOS = ['payment'=>'入金 / 支払い確認','ticket'=>'チケット確認','entry'=>'座席 / 入場方法確認','packing'=>'持ち物準備','battery'=>'モバイルバッテリー','route'=>'会場までの経路確認'];
const TRIP_TODOS = ['payment'=>'入金 / 支払い確認','transport'=>'交通手段予約','hotel'=>'ホテル予約','ticket'=>'チケット確認','entry'=>'座席 / 入場方法確認','packing'=>'荷造り','battery'=>'モバイルバッテリー','route'=>'会場までの経路確認'];
