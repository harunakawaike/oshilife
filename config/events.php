<?php
/** events.php の役割：イベント管理の状態名とTODOテンプレートを共通管理する。 */
declare(strict_types=1);
const EVENT_STATUSES = ['scheduled'=>'開催予定','postponed'=>'延期','cancelled'=>'中止','completed'=>'終了'];
const APPLICATION_STATUSES = ['not_applied'=>'未申込','applying'=>'手続き中','applied'=>'申込済み','not_required'=>'申込不要'];
const LOTTERY_STATUSES = ['not_applicable'=>'抽選対象外','pending'=>'結果待ち','won'=>'当選','lost'=>'落選'];
const ENTRY_METHODS = ['lottery'=>'抽選','first_come'=>'先着','reservation'=>'予約','no_application'=>'申込不要','unknown'=>'未確認'];
const PARTICIPATION_STATUSES = ['considering'=>'検討中','confirmed'=>'参加確定','not_attending'=>'不参加','cancelled'=>'取消'];
const SALES_TYPES = ['fanclub'=>'FC','general_sale'=>'一般販売','production_release'=>'制作開放','official_presale'=>'公式先行','other'=>'その他','none'=>'なし'];
const TRIP_TYPES = ['local'=>'近場','trip'=>'遠征'];
const TRANSPORT_TYPES = ['shinkansen'=>'新幹線','airplane'=>'飛行機','highway_bus'=>'高速バス','car'=>'車','local_train'=>'在来線','other'=>'その他'];
const RESERVATION_STATUSES = ['considering'=>'検討中','reserved'=>'予約済み','cancelled'=>'キャンセル'];
const LOCAL_TODOS = ['payment'=>'入金 / 支払い確認','ticket'=>'チケット / 入場方法確認','entry'=>'日時 / 座席確認','packing'=>'持ち物準備','battery'=>'モバイルバッテリー','route'=>'会場までの経路確認'];
const TRIP_TODOS = ['payment'=>'入金 / 支払い確認','transport'=>'交通手段予約','hotel'=>'ホテル予約','ticket'=>'チケット / 入場方法確認','entry'=>'日時 / 座席確認','packing'=>'荷造り','battery'=>'モバイルバッテリー','route'=>'会場までの経路確認'];

// 予約状況と支払い状況は別。予約済みでも未払いの場合がある。
const PAYMENT_STATUSES = ['unpaid'=>'支払い未完了','paid'=>'支払い済み'];

// 予定カテゴリとは別。登録・編集画面とAPIで同じ許可値を使用する。
// 既存データ・APIとの互換性のためsportsという保存値は維持し、表示名を「舞台」にする。
const EVENT_TYPES = ['live'=>'LIVE','performance'=>'公演','event'=>'イベント','sports'=>'舞台','exhibition'=>'展覧会','other'=>'その他'];
