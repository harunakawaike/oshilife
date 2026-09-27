<?php
/** auth_validator.php の役割：登録・ログインの入力をPHP側で確認する。ブラウザーの検証だけには頼らない。 */
declare(strict_types=1);

/** 配列など不正な型を文字列として扱わず、文字列だけを取り出す。 */
function inputString(array $input, string $key): string
{
    return isset($input[$key]) && is_string($input[$key]) ? $input[$key] : '';
}

/** 登録内容を検査し、日本語の項目別エラーを返す。 */
function validateRegistration(array $input): array
{
    $errors = [];
    $name = trim(inputString($input, 'display_name'));
    $email = trim(inputString($input, 'email'));
    $password = inputString($input, 'password');
    if ($name === '' || mb_strlen($name, 'UTF-8') > 50) {
        $errors['display_name'] = '表示名は1〜50文字で入力してください。';
    }
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = '正しいメールアドレスを入力してください。';
    }
    // PASSWORD_DEFAULTのbcryptでは72バイト以降が切り捨てられるため、長さの上限も設ける。
    if (mb_strlen($password, 'UTF-8') < 10 || strlen($password) > 72 || str_contains($password, "\0")) {
        $errors['password'] = 'パスワードは10文字以上、72バイト以内にしてください（日本語は1文字約3バイト）。';
    }
    if ($password !== inputString($input, 'password_confirmation')) {
        $errors['password_confirmation'] = '確認用パスワードが一致しません。';
    }
    return $errors;
}
