<?php
/** money_service.php の役割：本人の積立・支出保存、遠征費の確認後反映、二重登録防止を担当する。 */
declare(strict_types=1);
require_once __DIR__.'/live_service.php';
require_once __DIR__.'/../repositories/money_repository.php';
require_once __DIR__.'/../validators/money_validator.php';
/** 年・推しの絞り込みをAPIで共通利用し、配列や他人の登録推しを拒否する。 */
function moneyFilters(int $userId,array $raw): array
{
    $year = moneyYear($raw['year']??date('Y'));
    $oshiId = ($raw['oshi_id']??'all')==='all' ? null : moneyOptionalId($raw['oshi_id']??null);
    validateMoneyOshi(database(),$userId,$oshiId,null);
    $page = positiveOshiId($raw['page']??1);
    if ($page===null || $page>10000) throw new ScheduleOperationException('ページ番号が正しくありません。',422);
    return [$year,$oshiId,$page];
}
/** 関連ライブと推し、本人の遠征とライブの組み合わせを確認する。 */
function validateMoneyLinks(PDO $pdo,int $userId,array $values,?array $existing): void
{
    validateMoneyOshi($pdo,$userId,$values['oshi_id'],$existing);
    if ($values['live_event_id']!==null) {
        $live = findLive($pdo,$userId,$values['live_event_id']);
        $unchanged = $existing && (int)$existing['live_event_id']===$values['live_event_id'] && $existing['oshi_id']==$values['oshi_id'];
        if (!$unchanged && (int)$live['oshi_id']!==$values['oshi_id']) throw new ScheduleOperationException('関連ライブと同じ推しを選んでください。',422);
    }
    if ($values['trip_id']!==null) {
        $trip = requireOwnTrip($pdo,$userId,$values['trip_id']);
        if ((int)$trip['live_event_id']!==$values['live_event_id']) throw new ScheduleOperationException('遠征に対応するライブを選んでください。',422);
    }
}
/** 入力だけでは支出は作らない。保存ボタンのPOSTで本人と元データを再検証して確定する。 */
function saveMoneyRecord(int $userId,string $table,array $raw,?int $id): int
{
    $values = validateMoneyInput($raw,$table);
    try {
        return liveTransaction(function(PDO $pdo) use($userId,$table,$raw,$id,$values) {
            $existing = $id===null ? null : findMoneyRecord($pdo,$table,$userId,$id,true);
            if ($table==='savings') {
                validateMoneyOshi($pdo,$userId,$values['oshi_id'],$existing);
            } else {
                $sourceType = $existing['source_type'] ?? ($raw['source_type']??'manual');
                if (!in_array($sourceType,['manual','live_ticket','transportation','accommodation'],true)) throw new ScheduleOperationException('支出の反映元が正しくありません。',422);
                if ($sourceType==='manual') {
                    if (!empty($raw['source_id']) || !empty($raw['room_expense_id'])) throw new ScheduleOperationException('手入力の支出に反映元IDは指定できません。',422);
                    validateMoneyLinks($pdo,$userId,$values,$existing);
                    $values += ['source_type'=>'manual','source_id'=>null,'room_expense_id'=>null];
                } else {
                    // 編集時は登録時の関連を維持する。元予約が消えても保存した支出は編集できる。
                    $source = $existing ?? moneySource($pdo,$userId,$sourceType,positiveOshiId($raw['source_id']??null)??throw new ScheduleOperationException('反映元を指定してください。',422),true);
                    if ($existing === null) {
                        if ($source['expense_id'] !== null) throw new ScheduleOperationException('すでにお金管理に反映済みです。',409);
                        if (!$source['can_import']) throw new ScheduleOperationException($source['import_reason'],409);
                        if (moneyCents($values['amount']) <= 0) throw new ScheduleOperationException('反映する金額は0円より大きくしてください。',422);
                    }
                    foreach (['oshi_id','live_event_id','trip_id','category'] as $key) {
                        if ((string)$values[$key] !== (string)$source[$key]) throw new ScheduleOperationException('反映元の推し・ライブ・遠征・カテゴリは変更できません。',422);
                    }
                    $expectedEligible = $sourceType==='live_ticket' ? 1 : 0;
                    if ($values['special_effect_eligible'] !== $expectedEligible) throw new ScheduleOperationException('チケットは特効対象、交通・ホテルは対象外で登録してください。',422);
                    $values += ['source_type'=>$sourceType,'source_id'=>$source['source_id'],'room_expense_id'=>null];
                }
            }
            $values['user_id'] = $userId;
            $savedId = writeLiveRecord($pdo,$table,$values,$id);
            if ($table==='expenses' && $id===null && $values['source_type']!=='manual') {
                linkMoneySource($pdo,$values['source_type'],(int)$values['source_id'],$savedId);
            }
            return $savedId;
        });
    } catch (PDOException $error) {
        // 2つのタブから同時に押されても、DBのUNIQUE制約で二重反映を止める。
        if ((int)($error->errorInfo[1]??0)===1062) throw new ScheduleOperationException('この予約はすでにお金管理へ反映されています。登録済みの支出を編集してください。',409);
        throw $error;
    }
}
/** 削除も本人確認を行う。遠征由来の支出を消しても元予約は変更しない。 */
function deleteMoneyRecord(int $userId,string $table,int $id): void
{
    liveTransaction(function(PDO $pdo) use($userId,$table,$id) {
        findMoneyRecord($pdo,$table,$userId,$id,true);
        // 元のexpense_idは外部キーのON DELETE SET NULLで同時に解除される。
        // 支払済み状態と金額は残るため、条件を満たせばもう一度確認して反映できる。
        liveQuery($pdo,"DELETE FROM $table WHERE id=? AND user_id=?",[$id,$userId]);
    });
}
