<?php
/** user_repository.php の役割：usersとuser_settingsへのSQLを集約する。画面のHTMLはここでは作らない。 */
declare(strict_types=1);

/** メールアドレスに一致するユーザーを探す。削除済みユーザーはログイン対象外。 */
function findUserByEmail(PDO $pdo, string $email): ?array
{
    // SQLに入力値を直接連結するとSQLインジェクション（SQLの書き換え）の危険がある。
    // prepareでSQLの形を固定し、executeで値を別送する。
    $statement = $pdo->prepare('SELECT id, display_name, email, password_hash FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1');
    $statement->execute(['email' => $email]);
    return $statement->fetch() ?: null;
}

/** セッションのIDから、表示してよいユーザー情報だけを取り出す。 */
function findUserById(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT id, display_name, email FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $statement->execute(['id' => $id]);
    return $statement->fetch() ?: null;
}

/** ユーザーと初期設定を一緒に保存する。片方が失敗したら両方取り消す（トランザクション）。 */
function createUser(PDO $pdo, string $name, string $email, string $passwordHash): int
{
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('INSERT INTO users (display_name, email, password_hash) VALUES (:name, :email, :password_hash)');
        $statement->execute(['name' => $name, 'email' => $email, 'password_hash' => $passwordHash]);
        $id = (int) $pdo->lastInsertId();
        $settings = $pdo->prepare('INSERT INTO user_settings (user_id) VALUES (:user_id)');
        $settings->execute(['user_id' => $id]);
        $pdo->commit();
        return $id;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
