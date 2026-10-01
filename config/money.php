<?php
/** money.php の役割：支出カテゴリ、特効対象の初期値、遊びの換算基準を一元管理する。 */
declare(strict_types=1);
const MONEY_CATEGORIES = [
    'live_ticket'=>['label'=>'チケット・入場料','eligible'=>true],
    'goods'=>['label'=>'グッズ','eligible'=>true],
    'cd'=>['label'=>'CD','eligible'=>true],
    'dvd_bluray'=>['label'=>'DVD / Blu-ray','eligible'=>true],
    'fanclub'=>['label'=>'ファンクラブ','eligible'=>true],
    'paid_streaming'=>['label'=>'有料配信','eligible'=>true],
    'other_fandom'=>['label'=>'その他推し活','eligible'=>true],
    'fee'=>['label'=>'手数料','eligible'=>false],
    'transportation'=>['label'=>'交通','eligible'=>false],
    'accommodation'=>['label'=>'宿泊','eligible'=>false],
    'food'=>['label'=>'食事','eligible'=>false],
    'sightseeing'=>['label'=>'観光','eligible'=>false],
    'other_trip'=>['label'=>'その他遠征','eligible'=>false],
];
const MONEY_EFFECTS = [
    'fire'=>['label'=>'🔥 炎','unit_price'=>100,'description'=>'1台・1発の燃料相当'],
    'co2'=>['label'=>'💨 CO₂','unit_price'=>300,'description'=>'1台・約1秒噴射'],
    'silver_tape'=>['label'=>'✨ 銀テ','unit_price'=>4000,'description'=>'キャノン1台・1回発射（銀テープ約20〜25本）'],
];
const MONEY_EFFECT_NOTICE = '※特効換算は、炎の燃料・CO₂ガス・銀テープなどの消耗品価格を参考にしたOshilife独自の目安です。実際のライブ演出では、使用する機材や演出内容によって消耗量が異なるほか、人件費・機材費・運搬費・設営費等が別途必要となるため、実際のライブ演出費とは異なります。';
