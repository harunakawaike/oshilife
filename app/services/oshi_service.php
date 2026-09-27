<?php
/** oshi_service.php の役割：推し作成・編集・追加・解除・メンバー追加を、安全な一連の手順としてまとめる。 */
declare(strict_types=1);
require_once __DIR__ . '/../repositories/oshi_repository.php';

/** 利用者に説明できるエラーを表す。SQLの詳細をAPIへ漏らさない。 */
class OshiOperationException extends RuntimeException {}

/** 推し本体と本人の登録を同時に確定する。一方だけできた状態は残さない。 */
function createAndFollowOshi(int $userId, array $input): int
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $id = insertOshi($pdo, $input, $userId);
        insertOshiFollow($pdo, $userId, $id);
        $pdo->commit();
        return $id;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/** 公開中の共有マスタを本人の一覧へ追加する。 */
function followOshi(int $userId, int $oshiId): void
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        if (!lockActiveOshi($pdo, $oshiId)) {
            throw new OshiOperationException('この推しは見つからないか、現在利用できません。', 404);
        }
        insertOshiFollow($pdo, $userId, $oshiId);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/** 解除する対象はセッション本人の関連だけ。マスタの作成者でも本体は削除しない。 */
function unfollowOshi(int $userId, int $oshiId): void
{
    deleteOshiFollow(database(), $userId, $oshiId);
}

/** 共有情報を勝手に変更されないよう、マスタ作成者だけにメンバー追加を許可する。 */
function createOshiMember(int $userId, int $oshiId, array $input): int
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $oshi = lockActiveOshi($pdo, $oshiId);
        if (!$oshi) throw new OshiOperationException('この推しは見つからないか、現在利用できません。', 404);
        if ((int) $oshi['created_by_user_id'] !== $userId) {
            throw new OshiOperationException('メンバーを追加できるのは、この推しを作成したユーザーです。', 403);
        }
        $id = insertOshiMember($pdo, $oshiId, $input);
        $pdo->commit();
        return $id;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/** 作成者だけが共有推しを編集する。画面の表示制御だけでなく、APIでも所有者を確認する。 */
function updateOshi(int $userId, int $oshiId, array $input): void
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        // 確認から保存までロックし、途中で無効化・所有者変更される競合を防ぐ。
        $oshi = lockActiveOshi($pdo, $oshiId);
        if (!$oshi) {
            throw new OshiOperationException('この推しは見つからないか、現在利用できません。', 404);
        }
        if ((int) $oshi['created_by_user_id'] !== $userId) {
            throw new OshiOperationException('推し情報を編集できるのは、この推しを作成したユーザーです。', 403);
        }
        updateOshiRecord($pdo, $oshiId, $input);
        $pdo->commit();
    } catch (Throwable $exception) {
        // 名前と種別が他の推しと重複した場合などは、変更を取り消す。
        $pdo->rollBack();
        throw $exception;
    }
}
