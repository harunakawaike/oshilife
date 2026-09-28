<?php
/** oshi_repository.php の役割：共有推し・メンバー・本人の登録をPDOで読み書きする。 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';

/** JOINで共有マスタと本人の登録を結び、各推しに登録済みかどうかを付ける。 */
function findOshis(PDO $pdo, int $userId, string $query = '', int $offset = 0): array
{
    // user_idはセッションから渡す。LOCATEにより検索語の%や_も普通の文字として扱う。
    $statement = $pdo->prepare('SELECT o.id, o.name, o.oshi_type, o.emoji,
        CASE WHEN uo.id IS NULL THEN 0 ELSE 1 END AS is_followed
        FROM oshis o LEFT JOIN user_oshis uo ON uo.oshi_id = o.id AND uo.user_id = :user_id
        WHERE o.is_active = 1 AND LOCATE(:query, o.name) > 0 ORDER BY o.name, o.id LIMIT 51 OFFSET :offset');
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->bindValue(':query', $query, PDO::PARAM_STR);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

/** 中間テーブルuser_oshisをたどり、本人が登録した有効な推しだけを返す。 */
function findMyOshis(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare('SELECT o.id, o.name, o.oshi_type, o.emoji, uo.registered_at
        FROM user_oshis uo INNER JOIN oshis o ON o.id = uo.oshi_id
        WHERE uo.user_id = :user_id AND o.is_active = 1 ORDER BY uo.registered_at DESC, uo.id DESC');
    $statement->execute(['user_id' => $userId]);
    return $statement->fetchAll();
}

/** 詳細を読み、本人の登録状態と作成者かどうかを返す。個人のメール等は返さない。 */
function findOshiDetail(PDO $pdo, int $id, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT o.id, o.name, o.oshi_type, o.emoji,
        CASE WHEN uo.id IS NULL THEN 0 ELSE 1 END AS is_followed,
        CASE WHEN o.created_by_user_id = :creator_id THEN 1 ELSE 0 END AS can_manage
        FROM oshis o LEFT JOIN user_oshis uo ON uo.oshi_id = o.id AND uo.user_id = :user_id
        WHERE o.id = :id AND o.is_active = 1');
    $statement->execute(['creator_id' => $userId, 'user_id' => $userId, 'id' => $id]);
    return $statement->fetch() ?: null;
}

/** 無効なメンバーは除き、ハートとHEX色をそのまま取得する。 */
function findOshiMembers(PDO $pdo, int $oshiId): array
{
    $statement = $pdo->prepare('SELECT id, oshi_id, name, color_name, heart_emoji, hex_color
        FROM members WHERE oshi_id = :oshi_id AND is_active = 1 ORDER BY id');
    $statement->execute(['oshi_id' => $oshiId]);
    return $statement->fetchAll();
}

/** 推しマスタを保存する。トランザクションの開始・確定はserviceまたはCSV側が担当する。 */
function insertOshi(PDO $pdo, array $input, int $creatorId): int
{
    $statement = $pdo->prepare('INSERT INTO oshis (name, oshi_type, emoji, created_by_user_id)
        VALUES (:name, :oshi_type, :emoji, :creator)');
    $statement->execute($input + ['creator' => $creatorId]);
    return (int) $pdo->lastInsertId();
}

/** 本人と推しの関連だけを追加する。UNIQUEにより同時送信でも二重登録できない。 */
function insertOshiFollow(PDO $pdo, int $userId, int $oshiId): void
{
    $statement = $pdo->prepare('INSERT INTO user_oshis (user_id, oshi_id) VALUES (:user_id, :oshi_id)');
    $statement->execute(['user_id' => $userId, 'oshi_id' => $oshiId]);
}

/** メンバーを保存する。同じ推しの同じ名前はDBのUNIQUE制約で拒否される。 */
function insertOshiMember(PDO $pdo, int $oshiId, array $input): int
{
    $statement = $pdo->prepare('INSERT INTO members (oshi_id, name, color_name, heart_emoji, hex_color)
        VALUES (:oshi_id, :name, :color_name, :heart_emoji, :hex_color)');
    $statement->execute($input + ['oshi_id' => $oshiId]);
    return (int) $pdo->lastInsertId();
}

/** 有効なマスタをロックし、確認後に別処理で無効化される競合を防ぐ。 */
function lockActiveOshi(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT id, created_by_user_id FROM oshis WHERE id = :id AND is_active = 1 FOR UPDATE');
    $statement->execute(['id' => $id]);
    return $statement->fetch() ?: null;
}

/** 自分の関連だけを削除する。oshisとmembers、他ユーザーの登録は触らない。 */
function deleteOshiFollow(PDO $pdo, int $userId, int $oshiId): void
{
    $statement = $pdo->prepare('DELETE FROM user_oshis WHERE user_id = :user_id AND oshi_id = :oshi_id');
    $statement->execute(['user_id' => $userId, 'oshi_id' => $oshiId]);
}

/** 推しの表示情報だけを更新する。ID・作成者・登録者・メンバーの関連は変えない。 */
function updateOshiRecord(PDO $pdo, int $oshiId, array $input): void
{
    // 入力値はSQLへ連結せず、プリペアドステートメントで別に渡す。
    $statement = $pdo->prepare('UPDATE oshis SET name = :name, oshi_type = :oshi_type,
        emoji = :emoji WHERE id = :id');
    $statement->execute($input + ['id' => $oshiId]);
}
