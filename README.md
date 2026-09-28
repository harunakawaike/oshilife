# Oshilife v2 — Phase 2

推し活の予定・ライブ・遠征・お金を、自分の推しを中心にまとめるWebアプリです。
Phase 1の認証基盤に、Phase 2の推し検索・新規作成・編集・自分への登録/解除・メンバーとハートカラー・ホーム推し切替・レスポンシブ対応を追加しました。

## 今すぐ開く

**XAMPPでApacheとMySQLを起動して、<http://localhost/oshilife-v2/public/> を開きます。**

- 未ログインのindex.php → `/oshilife-v2/public/login.php`
- ログイン済みのindex.php → `/oshilife-v2/public/home.php`
- ホームやマイページ → **推し管理** → 検索 → 追加、または新しい推しを登録
- 推し詳細 → メンバー追加（共有マスター作成者のみ）

今の環境ではDB・テーブル・Apacheからのアクセスは設定済みです。schema.sqlの再実行は不要です。

## 保存先・ワークスペース・公開範囲

実際に編集するソースは、ユーザーが指定した現在のVS Codeワークスペースです。

```text
/Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife
```

ApacheがURLから参照する場所は、同じソースへのシンボリックリンク（別名の入口）です。

```text
/Applications/XAMPP/xamppfiles/htdocs/oshilife-v2
    → /Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife
```

ファイルは二重管理していません。現在のVS Codeで編集すると指定のlocalhost URLへ反映されます。Gitは現在のワークスペースのものを維持し、設定・履歴・インデックスを変更していません。他の旧Oshilifeプロジェクトや既存DBには変更していません。

以前の別コピーは削除せず `/private/tmp/oshilife-v2-phase1-apache-backup-20260928` に退避しました。このコピーは開発に使いません。一時領域なので恒久バックアップではありません。

Apacheの全体設定は変更せず、既存のFollowSymLinksとAllowOverride設定を利用します。プロジェクト直下の `.htaccess` でアクセスを拒否し、`public/.htaccess`だけで公開を許可します。`.env`、`.git`、config、database、storage、scriptsは直接取得できないことをHTTPで確認済みです。

本番では可能ならDocumentRootをpublicに設定し、非公開コードを公開領域の外に配置します。

## 使用技術・必要環境

- HTML / CSS Grid・Flexbox / JavaScript。外部CDN・フレームワークなし
- PHP 8.2以上、PDO / pdo_mysql / mbstring / session
- MySQL 8系またはMariaDB 10.4以上、InnoDB / utf8mb4
- ローカル：Mac、XAMPP Apache 2.4、MySQL
- 検証用：Python 3。CSV取込はPHP CLI

## .envとURL設定

`.env`はGit対象外の環境設定です。新規環境でのみ `.env.example` をコピーして作成します。現在の `.env` を上書きしないでください。

```dotenv
APP_URL=http://localhost/oshilife-v2/public
APP_ENV=local
APP_BASE_PATH=
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=oshilife_v2
DB_USER=root
DB_PASSWORD=
```

DBのroot・パスワードなしは、ユーザーが選んだXAMPPローカル専用設定です。本番では専用DBユーザーとパスワードを設定します。

`APP_URL`から公開パスを取り出し、PHPのappUrl()とJSのappUrl()、Cookieのpathへ同じ値を使います。CSS・JavaScript・API・フォーム成功後の遷移をバラバラに直す必要はありません。Hostヘッダーを信用してURLを作りません。

本番例：`APP_URL=https://example.com/v2`。本番がルート公開なら `https://example.com`。末尾の `/` はあっても取り除きます。本番はHTTPSと `APP_ENV=production` が必要です。DB接続情報も本番専用DBへ変更します。`APP_BASE_PATH`はPhase 1との互換用で、APP_URLがある場合には使用しません。

引用符で囲んだ設定値に対応しています。行末コメント・変数展開には対応しません。コメントは独立した行に `#` で書きます。サーバーで同名の環境変数が定義されていれば、その値を優先します。

## Apacheのファイル権限

このMacではApacheがdaemonユーザーで動くため、ACL（ユーザー単位のアクセス許可）で `.env` の読み取りと `storage/logs`・`storage/sessions`・`storage/rate_limits` への保存をdaemonにだけ許可しました。ソース全体を777にはしていません。

別環境へ配置するときは、そのサーバーのPHP実行ユーザーに同等の読み取り・保存権限を付けます。権限エラー時はApacheのログも確認してください。

従来のPHP内蔵8082番サーバーは停止しました。通常の確認にはXAMPP Apacheを使ってください。内蔵サーバーを使う場合はAPP_URLを `http://127.0.0.1:8082` へ変更して `php -S 127.0.0.1:8082 -t public` とし、Apacheに戻す際はAPP_URLも戻します。

## DBセットアップと更新

現在の `oshilife_v2` は、users / user_settingsにPhase 2の3テーブルを追加済みです。既存ユーザー1件を維持しました。

新規環境では未使用の専用DB名で作成します。既存OshilifeのDBに実行しないでください。

```sql
CREATE DATABASE oshilife_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

新規DBだけに、全体スキーマを実行します。

```sh
/Applications/XAMPP/xamppfiles/bin/mysql --protocol=TCP -h 127.0.0.1 -P 3306 -u root oshilife_v2 < database/schema.sql
```

別環境でPhase 1まで作成済みなら、全体ではなく追加分だけを一度実行します。

```sh
/Applications/XAMPP/xamppfiles/bin/mysql --protocol=TCP -h 127.0.0.1 -P 3306 -u root oshilife_v2 < database/migrations/002_oshi_management.sql
```

既存テーブルがあれば停止する設計です。DROPや既存テーブル再作成は行いません。MySQLのDDL（テーブル作成）は通常のデータ更新と異なり全体をrollbackできないため、実行前にバックアップとテーブル有無を確認します。さくらの接頭辞付きDB名へ移す場合は `.env` と新規用schema.sqlの `USE` を変更します。

## 実装範囲

Phase 1：新規登録、ログイン、ログアウト、ログイン状態取得、認証・CSRF、セッション、共通UI、各画面の仮UI。パスワードは10文字以上・72バイト以内。登録後はログイン画面へ案内します。

Phase 2：

- 共有推し検索（名前の部分一致、1ページ50件）と登録済み表示
- 推し新規作成と自分への自動登録、複数絵文字、推し種別
- 自分の推し一覧、確認ダイアログ付き解除、共有情報の維持
- メンバー追加・一覧、11種類のハート選択、カラー名・HEX保存
- ホームの選択肢をuser_oshisと連動。選択状態の表示まで実装
- スマホ・タブレット・PCを同一HTMLとCSS media queryで切替
- CSV取込の確認・明示実行の土台、日本語解説、HTTP統合テスト

作成済みの共有マスターのメンバー追加は**そのマスター作成者だけ**に限定しました。他ユーザーは検索・閲覧・自分への登録/解除ができます。推しの名前・種別・絵文字も作成者だけが詳細画面から編集できます。共同編集や無効化の管理画面は次Phaseで権限設計とともに追加します。

## レスポンシブ表示

| 幅 | レイアウト | ナビ |
| --- | --- | --- |
| 767px以下 | 画面幅を使った1列、カード縦並び | 固定下部ナビ |
| 768〜1199px | 余白を増やし、ホーム・一覧・詳細を2列 | 固定下部ナビ |
| 1200px以上 | 本文最大1360px、ホーム2列・推し一覧3列 | ヘッダー内の横並びナビ |

スマホとPCでHTMLを複製せず、ナビも1つだけです。ログイン/登録フォームは読みやすい幅を保ち、横いっぱいには伸ばしません。柔らかい色・角丸・薄い影は維持しています。

## 主要ファイルの役割

| ファイル / ディレクトリ | 役割 |
| --- | --- |
| public/ | 表示画面とCSS・JS、公開APIの入口 |
| api/ | JSON APIの本体。HTMLではなくデータを返す |
| app/services/ | 処理手順・トランザクション・権限判断 |
| app/repositories/ | PDOとSQLによるDB読み書き |
| app/validators/ | APIとCSVの入力検証 |
| app/helpers/ | 設定読込、URL、HTMLエスケープ、JSON応答など |
| middleware/ | 認証とCSRFの共通チェック |
| config/app.php | URL・セッション・共通起動設定 |
| config/database.php | .envの専用MySQLへ接続 |
| database/schema.sql | 新規環境用の全5テーブル |
| database/migrations/002_oshi_management.sql | Phase 1からの追加3テーブル |
| database/seeds/ | 初期マスター用CSVテンプレート |
| includes/ | 共通ヘッダー・ナビ・フッター |
| scripts/import_masters.php | 公開しないCSV取込CLI |
| storage/ | 非公開の実行時データ |

個別の主要ファイル：

| ファイル | 役割 |
| --- | --- |
| public/index.php | ログイン状態で最初の移動先を振り分ける |
| public/login.php / register.php | 認証フォームの表示 |
| public/home.php | 本人の推しを取得し、切替とホームの表示例を出す |
| public/oshis.php | 自分の推し一覧、検索、新規作成の画面 |
| public/oshi_detail.php | 推し詳細、ハート付きメンバー、作成者向け追加フォーム |
| public/profile.php | 本人情報、推し管理リンク、ログアウト |
| public/calendar.php / discover.php / live.php | 次Phaseの機能の表示例 |
| public/assets/js/common.js | 同一公開パスでのAPI通信、CSRF、ログアウト |
| public/assets/js/auth.js | 認証フォーム送信と項目エラー表示 |
| public/assets/js/oshis.js | 検索・追加・確認付き解除・作成・ハート選択の送信 |
| public/assets/js/home.js | 選択した推しを案内。予定絞り込みは未対応 |
| public/assets/css/common.css | 全体レイアウトと3段階のレスポンシブ切替 |
| public/assets/css/oshis.css | 推しカード、詳細、ハートの選択状態 |
| public/assets/css/各画面.css | 画面固有の装飾 |
| app/repositories/oshi_repository.php | 共有推し、メンバー、本人の登録をJOINで取得・保存 |
| app/services/oshi_service.php | 作成と登録の同時確定、権限確認、解除 |
| app/validators/oshi_validator.php | 種別、名前、絵文字、ハート、HEX検証 |
| app/helpers/oshi_api.php | 推しAPIの認証・ID検証・日本語エラーの共通化 |
| api/oshis/*.php / members/create.php | 推し管理の10個の処理入口 |
| public/api/oshis/ | 上記の処理を呼ぶ公開用の小さなPHP |
| app/services/auth_service.php | パスワード生成・照合、ログイン状態の管理 |
| app/repositories/user_repository.php | ユーザー検索と設定の同時保存 |
| app/validators/auth_validator.php | 登録入力のサーバー側検証 |
| app/helpers/env.php / view.php | .env読込 / 安全なHTML表示とURL生成 |
| app/helpers/response.php / api_bootstrap.php | JSON統一、API例外処理、Apacheの419対応 |
| app/helpers/rate_limit.php | 認証への短時間リクエスト制限 |
| middleware/auth.php / csrf.php | ログイン確認 / CSRF照合 |
| includes/header.php / bottom_nav.php / footer.php | 共通UI。ナビはheader内に一度だけ出力 |
| tests/smoke.py | Phase 1の認証回帰テスト |
| tests/phase2_smoke.py | 2ユーザー・権限・共有・URLのHTTP検証 |
| tests/csv_smoke.php | CSVのdry-run・投入・再実行・拒否の検証 |

全ファイルの一覧とPhase 2変更区分は [docs/FILES.md](docs/FILES.md) を参照してください。

## 処理の流れ

矢印は「次に処理が進む先」を表します。APIはHTMLを返さず、JSONというデータを返します。

### 新規登録

```text
public/register.php（登録画面）
    ↓ フォームに入力して送信
public/assets/js/auth.js（入力をJSONへ変換）
    ↓ common.jsがCSRF付きPOSTを送信
public/api/auth/register.php（公開入口）
    ↓
api/auth/register.php
    ↓ メソッド・CSRF・送信頻度・JSON形式を確認
app/validators/auth_validator.php（入力チェック）
    ↓
app/services/auth_service.php（password_hashで変換）
    ↓
app/repositories/user_repository.php
    ↓ PDOのprepare + execute
usersへ保存 ＋ user_settingsへ初期設定保存
    ↓ 両方成功すれば確定、失敗なら両方取消
JSONレスポンス {success: true, data: {user_id: ...}}
    ↓ JavaScriptが読み取る
login.phpへ移動して「登録が完了しました」と表示
```

入力エラーなら422、重複emailなら409をJSONで返し、画面の該当欄に理由を表示します。

### ログイン

```text
public/login.php
    ↓ auth.js → common.js（CSRF付きPOST）
public/api/auth/login.php → api/auth/login.php
    ↓ auth_service → user_repository
emailからユーザー検索（deleted_at IS NULLのみ）
    ↓
password_verify（入力したパスワードと保存ハッシュを照合）
    ├ 不一致 → 401のJSON → 画面に日本語エラー
    ↓ 一致
session_regenerate_id(true)（セッションIDを新しくする）
    ↓
$_SESSION['user_id']へID保存、CSRFトークンも更新
    ↓ JSON成功応答
JavaScriptがpublic/home.phpへ移動
```

### ログイン必須ページ

```text
home.phpへアクセス（他4画面も同様）
    ↓
middleware/auth.php の requireAuth()
    ↓
セッションにuser_idがあるか確認
    ↓ あればDBでユーザーが有効か確認
    ├ ログイン済み → 共通ヘッダー・ナビ → ページ本文を表示
    └ 未ログイン   → login.phpへ移動
```

ブラウザー側でリンクを隠すだけでは保護できません。URLを直接入力した場合もPHPの門番が実行されます。

## API仕様

| メソッド / URL | 応答 |
| --- | --- |
| POST `/api/auth/register.php` | 201、登録したuser_id |
| POST `/api/auth/login.php` | 200、userと新しいcsrf_token |
| POST `/api/auth/logout.php` | 200、空オブジェクト |
| GET `/api/auth/me.php` | 200、user（未ログインはnull）とcsrf_token |

POSTは `Content-Type: application/json` と `X-CSRF-Token` が必要です。Cookieを同時に送ります。CSRFは画面のmeta要素またはme APIから取得します。401は認証失敗、405はメソッド違い、409は重複、419はCSRF不一致、422は入力エラー、429は送信回数超過、500は内部障害です。JSON形式違反は400/415、大きすぎる本文は413です。

```json
{"success":true,"data":{}}
```

```json
{"success":false,"message":"入力内容を確認してください。","errors":{"email":"正しいメールアドレスを入力してください。"}}
```

## DBの関係：共有マスターと中間テーブル

```text
users（ユーザー）
  1人 ── 複数の user_oshis（誰がどの推しを登録したか）
                    複数 ── 1つの oshis（共有の推し）
                                         1つ ── 複数の members（メンバー）
```

例えばAさんとBさんが同じ推しを登録しても、oshisは1行、user_oshisは2行です。この関連だけを持つ表を「中間テーブル」と呼びます。Aさんが解除しても、Aさんの関連1行が消えるだけで、Bさんや推し本体は残ります。

- **JOIN**：別々の表をIDでつなぎ、一緒に読み取るSQL。名前・絵文字をユーザーごとにコピーする必要がなくなります。
- **共有マスター**：皆が同じ情報を参照する元データ。今回のoshisとmembersが該当します。
- **UNIQUE**：DBが重複を拒否するルール。同時に2回送信しても `user_id + oshi_id` が同じ登録はできません。
- oshis：name + oshi_typeがUNIQUE。作成者、is_active、複数絵文字を保持。作成者ユーザーが削除されてもマスターは残します。
- members：oshi_id + nameがUNIQUE。ハートは❤️ 🧡 💛 💚 🩵 💙 💜 🩷 🤍 🖤 🤎から選択。
- user_oshis：本人と共有推しのIDのみを関連付け、registered_atを保持。
- 全テーブルutf8mb4、InnoDB。既存users / user_settingsの定義は変更しません。

## 推し登録・作成の処理フロー

```text
マイページ → oshis.php（推し管理）
   ↓ oshis.jsから検索GET
api/oshis/search.php
   ↓ 認証 → 入力確認 → repositoryのJOIN検索
JSONで共有推し＋自分の登録状態
   ├ 見つかった → 追加 → follow.php（POST＋CSRF）
   │                 ↓ セッションから本人IDを取得
   │               user_oshisだけをINSERT
   │
   └ 見つからない → 新規フォーム → create.php（POST＋CSRF）
                         ↓ 入力検証・password等の情報は受け取らない
                       serviceのトランザクション
                         ↓ oshis作成＋user_oshis追加を一緒に確定
                       詳細へ移動
                         ↓ 作成者だけメンバー追加が可能
                       members/create.php → 検証・所有者確認 → members保存
```

解除は確認ダイアログ → unfollow.php → セッション本人のuser_oshisだけDELETEです。フォームのuser_idを送っても使用しません。

## Phase 2 API一覧

下記のURLはすべて公開URLの配下です。例：`http://localhost/oshilife-v2/public/api/oshis/search.php?q=SixTONES`。
全APIでログインが必要です。POSTではJSONとX-CSRF-Tokenを送ります。

| メソッド / URL | 入力 | 成功データ |
| --- | --- | --- |
| GET /api/oshis/list.php | page（任意） | oshis、page、has_more |
| GET /api/oshis/search.php | q、page（任意） | oshis、page、has_more |
| POST /api/oshis/create.php | name、oshi_type、emoji | oshi（201） |
| POST /api/oshis/update.php | oshi_id、name、oshi_type、emoji | 更新後のoshi（作成者のみ） |
| POST /api/oshis/follow.php | oshi_id | 空オブジェクト |
| POST /api/oshis/unfollow.php | oshi_id | 空オブジェクト |
| GET /api/oshis/my.php | なし | 本人のoshis |
| GET /api/oshis/detail.php | id | oshi、is_followed/can_manageを含む |
| GET /api/oshis/members.php | id | members |
| POST /api/oshis/members/create.php | oshi_id、name、color_name、heart_emoji、hex_color | member_id（201） |

JSONの封筒はPhase 1と同じsuccess/dataまたはsuccess/message/errorsです。無権限403、対象なし404、重複409、入力不備422。無効化済み推しは検索・一覧・詳細・メンバー取得・新規追加の対象外です。

## CSVの扱い

`database/seeds/oshis.csv` と `members.csv` は正しいヘッダーだけを持つテンプレートです。実マスターの大量投入はしていません。

```csv
name,oshi_type,emoji
```

```csv
oshi_name,name,color_name,heart_emoji,hex_color
```

UTF-8で作成します。CSVは初期共有マスターだけを対象にし、user_oshisや利用中に増える個人データは変更しません。

確認のみ（creator-idは実在する自分のユーザーIDに置き換える）：

```sh
/Applications/XAMPP/xamppfiles/bin/php scripts/import_masters.php --creator-id=1
```

確認結果を見て、投入する場合のみ `--apply` を追加します。

```sh
/Applications/XAMPP/xamppfiles/bin/php scripts/import_masters.php --creator-id=1 --apply
```

別ファイルは `--oshis=/path/oshis.csv --members=/path/members.csv` で指定できます。既定はdry-runなのでDBに書き込みません。入力ルールはAPIと共通です。全体検証後にトランザクションで投入し、途中失敗時は取消します。同じ内容はスキップし、既存値と異なる場合は上書きせずエラーにします。

メンバーCSVは推し名で対応づけます。同名・別種別の推しが複数ある場合は、誤って紐付けないよう停止します。別作成者のマスターにはメンバーを追加できません。1ファイル5000行までです。

## セキュリティ対策と理由

| 処理 | 必要な理由 |
| --- | --- |
| PDO prepare + execute | 入力をSQLの命令と混ぜず、SQLインジェクションを防ぐ |
| password_hash / password_verify | 平文を保存せず照合する。復号して比較する仕組みではない |
| session_regenerate_id(true) | ログイン前の既知のIDを攻撃者に使い回されないようにする |
| CSRFトークン + SameSite | 外部サイトから意図しない登録・ログイン・ログアウトを実行させない |
| htmlspecialchars / textContent | ユーザーの文字をHTMLやJavaScriptとして実行しない |
| 認証middleware | 各保護ページの入口で確認を統一し、確認漏れを減らす |
| .env + public分離 + .gitignore | 認証情報を公開ファイルやGit履歴へ入れない |
| HttpOnly / Secure / strict mode | JSからCookieを読み取れなくし、本番HTTPSで送信し、不正なIDを受け付けない |
| セッション2時間の無操作期限 | 放置されたログイン状態を無期限に残さない |
| IP単位の30回/15分制限 | パスワード総当たりや大量登録を抑える（Cookie削除で回避不可） |
| CSP / no-store | 外部スクリプト等の実行を制限し、認証ページのキャッシュを抑える |
| 汎用エラー応答 | SQLや接続情報を利用者へ漏らさない |

回数制限は単一サーバー向けの簡易版です。共有IPでは利用者が同じ枠を使い、複数IPの攻撃は防げません。storage/rate_limitsの期限切れファイルは定期保守で整理してください。本番ではアクセス監視・共有ストア型制限などを検討します。

## 検証方法と結果

XAMPP Apache / MySQLを起動し、プロジェクトルートから実行します。

```sh
python3 tests/smoke.py --base http://localhost/oshilife-v2/public
python3 tests/phase2_smoke.py
/Applications/XAMPP/xamppfiles/bin/php tests/csv_smoke.php
```

テストは固有名の一時ユーザーと推しだけを作り、最後にそのデータだけを削除します。実ユーザーは削除しません。認証APIの回数制限に達した場合は15分待ちます。PHP/JS構文、Apache上の認証30件・Phase 2 HTTP109件を確認済みです。

実ブラウザーの操作権限が許可されなかったため、PC/スマホの見た目・ハート選択・確認ダイアログ・JavaScriptによる実際の画面遷移は未確認です。HTTPでのCSS/JS配信・API・遷移先と、JavaScript構文検証とは区別しています。

手動で確認する操作：

1. 指定URLを開き、ログイン画面とCSSを確認する。
2. 新規登録 → ログイン → ホームへ移動する。
3. マイページ → 推し管理 → 名前で検索する。
4. 見つからなければ新規作成（例：絵文字👑🐃）。
5. 詳細でメンバーを追加し、🩷や🖤の選択状態と一覧を確認する。
6. ホームの推し切替に作成した推しが出ることを確認する。
7. 登録解除の確認でキャンセル/実行を試し、検索結果には共有推しが残ることを確認する。
8. 別ユーザーでも検索・追加でき、作成者以外にはメンバー追加フォームが出ないことを確認する。
9. 幅390px、900px、1280px以上でナビ・カード配置・横スクロールを確認する。
10. ログアウトし、home.phpへ直接アクセスするとlogin.phpへ戻ることを確認する。

詳しい変更ファイルと結果は [Phase 2レポート](docs/PHASE2.md)、認証の基本は [学習ガイド](docs/LEARNING.md) にまとめました。

## 未実装とPhase 3へ進む前の確認

スケジュール本機能、公開予定共有、自動同期、修正提案、helped/thanksのリアクション、ライブ本機能、遠征、お金、連番ルーム、Chatは未実装です。ホームの推し選択肢は実データですが、予定や資金の絞り込みはまだ行いません。推しの無効化画面、メンバー編集・削除、ユーザーテーマ変更、メール確認・パスワード再設定も未実装です。

- 共有マスターの共同編集・修正提案・作成者退会後の管理権限を決める。
- 同名・別種別、誤登録、論理削除からの復帰ルールを決める。
- 予定には認証だけでなく本人が編集できるかの権限確認を入れる。
- ホームの公開予定は未追加だけ表示し、カレンダーへ追加後は除外する。
- 次のDB変更も新しいmigration SQLで追加する。schema.sqlの再実行はしない。
- スマホアプリ化では現在のCookieセッションからの認証方式・CORS・CSRFを別途設計する。

将来のテーブル：schedules、schedule_members、schedule_sources、user_schedules、correction_requests、reactions、live_events、user_live_status、trips、transportations、accommodations、todos、venues、venue_spots、savings、expenses、monthly_thanks、yearly_reviews、rooms、room_members、room_applications、room_application_members、room_todos、room_expenses、room_expense_members、room_settlements、room_messages。

## 登録した推し情報の編集

マイページ → 推し管理 → 詳細を見る → **推し情報を編集** → **変更を保存**で、名前・種別・絵文字を変更できます。現在の内容が入力済みで表示され、キャンセルすると元の値へ戻ります。

共有マスターなので、変更はその推しを登録している他のユーザーの一覧・検索・ホームにも次回取得時に反映されます。推しのIDを維持するため、登録関係やメンバー情報は残ります。編集は作成者本人だけが行えます。

処理は `oshi_detail.php → oshis.js → api/oshis/update.php → 入力検証 → oshi_service.phpで作成者確認 → oshi_repository.phpでUPDATE → 詳細を再表示` の順です。POST/CSRF、ログイン確認、PDOプリペアドステートメントを使い、重複名・種別は409として変更を取り消します。新しいDBテーブルやmigrationは不要です。

編集APIと権限・重複・無効化・既存関連の保持を含め、HTTP109件を確認しました。実ブラウザーの操作確認は未実施です。

## 推しテーマカラーの廃止

推し本体は名前・種別・絵文字だけを登録・編集します。新規登録、編集、詳細表示、API、推しCSVからテーマカラーを外しました。メンバーのカラー名・ハート・HEXは継続して使用します。既存データを削除しないため、DBのoshis.theme_colorは旧仕様との互換用に残していますが、アプリは読み書きしません。DB変更の実行は不要です。

## メンバーカラーの選び方

メンバー追加では、色名付きのハートを選ぶだけで登録できます。色名と保存用の色コードは自動で設定されるため、HEXを入力する必要はありません。「色を細かく調整する（任意）」を開けば、色見本から好きな色を選び、色名も変更できます。メンバー一覧はハートと色名を表示します。

実装では `oshi_validator.php` の `MEMBER_COLOR_PRESETS` をHTMLのdata属性へ渡し、`oshis.js` の `selectMemberColor()` が選択されたハートに合わせて入力値を更新します。DB/APIでは引き続きhex_colorを内部の保存形式として利用し、既存データの変換やDB変更は不要です。
