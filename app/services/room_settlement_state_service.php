<?php
/** room_settlement_state_service.php の役割：ルーム全体の精算完了状態を管理する。個人会計や送金額は保存しない。 */
declare(strict_types=1);
require_once __DIR__.'/event_service.php';
require_once __DIR__.'/room_settlement_service.php';

/** 画面で確認した支出と現在の支出が同じか確認する。金額コピーは保存しない。 */
function requireSettlementToken(string $current, mixed $received): void
{
    if (!is_string($received) || !hash_equals($current,$received)) {
        throw new ScheduleOperationException('共同支出または精算状態が更新されています。再読み込みして確認してください。',409);
    }
}

/** ownerだけが一括精算を確定する。終了済みルームでも確定・閲覧はできるが支出は変更できない。 */
function markRoomSettled(int $userId, int $roomId, mixed $token): void
{
    eventTransaction(function(PDO $pdo) use($userId,$roomId,$token): void {
        // 支出保存と同じrooms行を先にロックし、確認と確定の間に支出が変わることを防ぐ。
        requireRoom($pdo,$userId,$roomId,true,false,true);
        $summary=roomSettlementSummary($pdo,$userId,$roomId);
        requireSettlementToken($summary['confirmation_token'],$token);
        if (!$summary['balances']) throw new ScheduleOperationException('共同支出がないルームは精算できません。',409);
        if ($summary['settlement_status']==='settled') return;
        // prepare/executeを使い、利用者の値をSQLへ直接連結しない。
        eventQuery($pdo,"INSERT INTO room_settlements(room_id,settlement_status,settled_at,settled_by_user_id)
            VALUES(?,'settled',NOW(),?) ON DUPLICATE KEY UPDATE settlement_status='settled',settled_at=NOW(),settled_by_user_id=VALUES(settled_by_user_id)",[$roomId,$userId]);
    });
}

/** 精算済みの支出変更は、警告を確認した最新内容のみ許可する。フォームを開くだけでは呼ばない。 */
function requireSettlementChangeConfirmation(PDO $pdo, int $userId, int $roomId, mixed $token): void
{
    if (roomSettlementState($pdo,$roomId)['settlement_status']==='settled') {
        $summary=roomSettlementSummary($pdo,$userId,$roomId);
        requireSettlementToken($summary['confirmation_token'],$token);
    }
}

/** 支出の保存成功と同じトランザクション内で呼び、途中失敗なら状態解除も一緒に取り消す。 */
function resetRoomSettlement(PDO $pdo, int $roomId): void
{
    eventQuery($pdo,"UPDATE room_settlements SET settlement_status='unsettled',settled_at=NULL,settled_by_user_id=NULL WHERE room_id=?",[$roomId]);
}
