<?php
/**
 * import_masters.php の役割：初期マスターCSVを検証し、明示したときだけ専用DBへ投入するCLI。
 * 通常はdry-run（確認のみ）。利用中に増える個人の登録データはCSVでは扱わない。
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
const PROJECT_ROOT = __DIR__ . '/..';
require_once PROJECT_ROOT . '/app/helpers/env.php';
require_once PROJECT_ROOT . '/app/validators/oshi_validator.php';
require_once PROJECT_ROOT . '/app/repositories/oshi_repository.php';
require_once PROJECT_ROOT . '/app/repositories/user_repository.php';

/** ヘッダーとUTF-8を確認してCSVを読む。行ごとの列数違いも黙って無視しない。 */
function readMasterCsv(string $path, array $expected): array
{
    $file = fopen($path, 'r');
    if ($file === false) throw new RuntimeException('CSVを開けません: ' . $path);
    try {
        $header = fgetcsv($file, 0, ',', '"', '');
        if (!$header) throw new RuntimeException('CSVヘッダーがありません: ' . $path);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        if ($header !== $expected) throw new RuntimeException('CSVヘッダーが違います: ' . $path);
        $rows = [];
        $line = 1;
        while (($values = fgetcsv($file, 0, ',', '"', '')) !== false) {
            $line++;
            if ($values === [null]) continue;
            if (count($values) !== count($header)) throw new RuntimeException("{$path}: {$line}行目の列数が違います。");
            foreach ($values as $value) {
                if (!mb_check_encoding($value, 'UTF-8')) throw new RuntimeException("{$path}: {$line}行目をUTF-8で保存してください。");
            }
            $rows[] = array_combine($header, $values);
            if (count($rows) > 5000) throw new RuntimeException('初期投入は1ファイル5000行までに分けてください。');
        }
        return $rows;
    } finally {
        fclose($file);
    }
}

/** DBとCSVを照合して投入計画を作る。dry-runではINSERTやUPDATEを一切行わない。 */
function planMasterImport(PDO $pdo, array $oshiRows, array $memberRows, int $creatorId): array
{
    $newOshis = [];
    $newMembers = [];
    $knownByName = [];
    $keys = [];
    $skipped = 0;
    foreach ($oshiRows as $index => $raw) {
        $input = normalizeOshiInput($raw);
        $errors = validateOshi($input);
        if ($errors) throw new RuntimeException('oshis.csv ' . ($index + 2) . '行目: ' . implode(' ', $errors));
        $key = mb_strtolower($input['name'], 'UTF-8') . ':' . $input['oshi_type'];
        if (isset($keys[$key])) throw new RuntimeException('CSV内の推しが重複しています: ' . $input['name']);
        $keys[$key] = true;
        $statement = $pdo->prepare('SELECT id, name, oshi_type, emoji, theme_color, is_active, created_by_user_id FROM oshis WHERE name = :name AND oshi_type = :type');
        $statement->execute(['name' => $input['name'], 'type' => $input['oshi_type']]);
        $existing = $statement->fetch();
        if ($existing) {
            if (!$existing['is_active'] || $existing['emoji'] !== $input['emoji'] || strtoupper($existing['theme_color']) !== $input['theme_color']) {
                throw new RuntimeException('既存データと異なるため上書きしません: ' . $input['name']);
            }
            $skipped++;
        } else {
            $newOshis[$key] = $input;
        }
        $knownByName[$input['name']][$key] = $existing ?: ['id' => null, 'created_by_user_id' => $creatorId, 'is_active' => 1, 'oshi_type' => $input['oshi_type']];
    }
    $memberKeys = [];
    foreach ($memberRows as $index => $raw) {
        $input = normalizeMemberInput($raw);
        $oshiName = normalizeOshiText($raw['oshi_name']);
        $errors = validateMember($input);
        if (!validOshiText($oshiName, 100)) $errors['oshi_name'] = '推し名を指定してください。';
        if ($errors) throw new RuntimeException('members.csv ' . ($index + 2) . '行目: ' . implode(' ', $errors));
        // members.csvは推し名で関連付ける。同名・別種別が複数あるなら誤結合せず停止する。
        $statement = $pdo->prepare('SELECT id, name, oshi_type, created_by_user_id, is_active FROM oshis WHERE name = :name');
        $statement->execute(['name' => $oshiName]);
        $candidates = $knownByName[$oshiName] ?? [];
        foreach ($statement->fetchAll() as $existing) {
            $key = mb_strtolower($existing['name'], 'UTF-8') . ':' . $existing['oshi_type'];
            $candidates[$key] = $existing;
        }
        if (count($candidates) !== 1) throw new RuntimeException('推し名を一意に特定できません: ' . $oshiName);
        $key = array_key_first($candidates);
        $oshi = $candidates[$key];
        if (!$oshi['is_active']) throw new RuntimeException('無効な推しには追加できません: ' . $oshiName);
        $memberKey = $key . ':' . mb_strtolower($input['name'], 'UTF-8');
        if (isset($memberKeys[$memberKey])) throw new RuntimeException('CSV内のメンバーが重複しています: ' . $input['name']);
        $memberKeys[$memberKey] = true;
        $existingMember = false;
        if ($oshi['id'] !== null) {
            $statement = $pdo->prepare('SELECT color_name, heart_emoji, hex_color, is_active FROM members WHERE oshi_id = :id AND name = :name');
            $statement->execute(['id' => $oshi['id'], 'name' => $input['name']]);
            $existingMember = $statement->fetch();
        }
        if ($existingMember) {
            if (!$existingMember['is_active'] || $existingMember['color_name'] !== $input['color_name'] || $existingMember['heart_emoji'] !== $input['heart_emoji'] || strtoupper($existingMember['hex_color']) !== $input['hex_color']) {
                throw new RuntimeException('既存メンバーと異なるため上書きしません: ' . $input['name']);
            }
            $skipped++;
            continue;
        }
        if ((int) $oshi['created_by_user_id'] !== $creatorId) throw new RuntimeException('他の作成者の推しにはメンバーを追加できません: ' . $oshiName);
        $newMembers[] = ['key' => $key, 'oshi_id' => $oshi['id'], 'input' => $input];
    }
    return ['oshis' => $newOshis, 'members' => $newMembers, 'skipped' => $skipped];
}

try {
    loadEnv(PROJECT_ROOT . '/.env');
    $options = getopt('', ['apply', 'creator-id:', 'oshis:', 'members:']);
    $creatorId = positiveOshiId($options['creator-id'] ?? null);
    if ($creatorId === null) throw new RuntimeException('使い方: php scripts/import_masters.php --creator-id=1 [--apply] [--oshis=path] [--members=path]');
    $pdo = database();
    if (!findUserById($pdo, $creatorId)) throw new RuntimeException('有効な作成者ユーザーIDを指定してください。');
    $oshis = readMasterCsv($options['oshis'] ?? PROJECT_ROOT . '/database/seeds/oshis.csv', ['name', 'oshi_type', 'emoji', 'theme_color']);
    $members = readMasterCsv($options['members'] ?? PROJECT_ROOT . '/database/seeds/members.csv', ['oshi_name', 'name', 'color_name', 'heart_emoji', 'hex_color']);
    $plan = planMasterImport($pdo, $oshis, $members, $creatorId);
    $apply = array_key_exists('apply', $options);
    if ($apply) {
        $pdo->beginTransaction();
        try {
            $ids = [];
            foreach ($plan['oshis'] as $key => $input) $ids[$key] = insertOshi($pdo, $input, $creatorId);
            foreach ($plan['members'] as $member) {
                $id = $member['oshi_id'] ?? $ids[$member['key']];
                // 検証後の無効化や所有者変更を再確認してから保存する。
                $oshi = lockActiveOshi($pdo, (int) $id);
                if (!$oshi || (int) $oshi['created_by_user_id'] !== $creatorId) throw new RuntimeException('推しの状態が変わりました。再度確認してください。');
                insertOshiMember($pdo, (int) $id, $member['input']);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }
    echo ($apply ? '適用完了' : '確認のみ（DB変更なし）') . ': 推し ' . count($plan['oshis']) . '件、メンバー ' . count($plan['members']) . '件、既存一致 ' . $plan['skipped'] . "件\n";
    echo "user_oshisへの登録や既存データの上書きは行いません。\n";
} catch (Throwable $exception) {
    fwrite(STDERR, ($exception instanceof PDOException ? 'DB処理に失敗しました。接続設定・重複・変更競合を確認してください。' : $exception->getMessage()) . "\n");
    exit(1);
}
