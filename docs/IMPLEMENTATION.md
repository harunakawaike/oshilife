# Phase 1 実装レポート（当時の記録）

現在の起動URL・DB構成・検証結果は [Phase 2レポート](PHASE2.md) と [README](../README.md) を参照してください。以下の0件という行数や8082番URLはPhase 1完了当時の履歴です。

## 納品先と分離

- 新規フォルダ：`/Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife`
- Git：ユーザー指定の現在のワークスペースにある既存 `.git` をそのまま使用。別の `.git` はコピーせず、設定・履歴・インデックスは変更していません。コミット・外部pushは未実施。
- DB：`oshilife_v2`。同名DBが存在しないことを確認してから新規作成。
- ユーザーの保存先変更指示により、`.git` 以外にファイルがなかった現在のワークスペースへ配置しました。既存ファイルの上書きはありません。他のOshilifeフォルダや既存DBは変更せず、専用DB `oshilife_v2` を引き続き使用します。
- 接続：ユーザー指定のXAMPP標準設定。`.env`はGit除外対象。

## 作成したファイル・テーブル

全ファイルは [FILES.md](FILES.md)、個別の役割は [README.md](../README.md#主要ファイルの役割) を参照してください。

実DBに作成済みのテーブルは `users` と `user_settings` のみです。utf8mb4を確認済み。テスト終了後の行数はどちらも0件です。初版スキーマの再実行は不要です。

## ログインの流れ

login.php → auth.js/common.js → 公開API入口 → api/auth/login.php → 入力とCSRFチェック → emailで検索 → password_verify → session_regenerate_id(true) → セッションにuser_idを保存 → JSON成功 → home.php → 認証middlewareを通って表示。

## セキュリティ

PDOプリペアドステートメント、ハッシュ保存、CSRF、HTMLエスケープ、セッションID更新、HttpOnly/SameSite Cookie、本番Secure Cookie、無操作期限、ログアウト時破棄、認証middleware、IPごとの回数制限、CSP、秘密設定の非公開・Git除外を実装しました。理由は各PHP/JSのコメントとREADMEで説明しています。

## 検証結果

- PHP 8.2.4：全31 PHPファイルの構文チェック成功。
- JavaScript：全2 JSファイルの構文チェック成功。
- tests/smoke.py：実MySQLとローカルPHPサーバーで30件のHTTPチェック成功。
- 登録・重複email・不正JSON・必須項目・CSRF欠落／不一致・不正メソッド・不正ログイン・SQL攻撃形式の入力を確認。
- ログイン前後のセッションID変更、me API、5画面の認証前後、表示名の絵文字とHTMLエスケープを確認。
- .env・schema・DB設定が公開URLから取得できないことを確認。
- DBでハッシュ照合、初期設定の同時作成、CASCADE削除を確認。
- テスト用の一時ユーザーのみ削除済み。
- 実ブラウザーの見た目・操作検証、Apache/さくら環境での本番動作検証は未実施。

## 次に行う操作

1. XAMPPでMySQLが起動していることを確認します。
2. ターミナルで新規プロジェクトへ移動します。
3. `/Applications/XAMPP/xamppfiles/bin/php -S 127.0.0.1:8082 -t public` で起動します。すでに同URLで起動中ならそのまま開けます。
4. ブラウザーで `http://127.0.0.1:8082` を開き、新規登録します。
5. 登録後にログインし、5つのナビとマイページのユーザー情報を確認します。
6. `docs/LEARNING.md`とREADMEの矢印図を見ながら、login.phpから処理を追ってください。

## 未実装とPhase 2の注意

カレンダー操作・予定CRUD・公開予定共有・イベント管理・遠征・お金・連番ルーム・テーマ変更・CSV取込などの本機能は未実装です。ホームのサンプル値はDBにつながっていません。メール確認・パスワード再設定も範囲外です。

Phase 2ではテーブルを変更用SQLで追加し、各APIに本人のデータかを調べる権限確認を実装します。ホームの公開予定は未追加の予定だけを表示し、追加済みを除きます。リアクションはhelped/thanksを想定します。今のCookie認証をそのままスマホアプリ認証とみなさず、方式を別途設計してください。

SitesはPHP/MySQLの実行先と一致しないため使用して公開していません。さくら向けの公開領域分離と本番設定はREADMEに記載しました。
