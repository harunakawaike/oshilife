# Oshilife v2 本番.env設定ガイド

確認日：2026-10-05。対象は現在の作業ツリー。MySQL 8.0.45へのschema import・31テーブル作成はユーザーから完了報告あり。今回はサーバーへ接続して再確認していない。

**接続先・URLは環境変数で切り替える。** 2026-10-05に別途、公開・非公開の分離配置に必要なPHP参照パスを修正済み。HTTPS、公開フォルダー、PHP、storageの権限も整っていることが条件。.envだけでサーバーの設定が完成するわけではない。

本書の初版作成時は実.env、テンプレート、コード、DB、既存の移行資料を変更していない。その後の分離配置対応でも本番接続・アップロード・秘密情報入力・migrationは未実施。

## 1. 本番.envの役割

本番用DB接続先と公開URLを、機能コードから分離して設定するファイル。名前は本番でも **`.env`** とする。`.env.production`という名前のファイルは現在のコードでは自動読込されない。

`config/app.php:17`がプロジェクト直下の.envを読み込む。public/の中には置かない。app/、api/、config/、includes/、middleware/、public/、storage/の相対配置を維持する。publicだけを別の階層へ移すとPHPの読み込み先が壊れる。

読み込み処理は`app/helpers/env.php:10`。サーバーのプロセスに同名環境変数が既にある場合はそちらが優先される。設定変更が反映されない場合も秘密値を表示せず、この優先関係を確認する。

## 2. 実際に参照する環境変数

製品PHPと開発スクリプトを検索した結果、キーは次の **8項目だけ**。現在の.env、.env.example、.env.production.exampleも同じ8キー。

| 項目 | 本番での扱い | 読み取り箇所 |
|---|---|---|
| APP_ENV | `production`固定。大文字小文字もこの通り | config/app.php:55 |
| APP_URL | 確定したHTTPSの公開基準URL | config/app.php:21 |
| APP_BASE_PATH | 通常は空欄。APP_URL未設定時だけの互換用 | config/app.php:30 |
| DB_HOST | さくらの接続先ホスト名 | config/database.php:14 |
| DB_PORT | 通常3306。接続情報が異なればその指定 | config/database.php:15 |
| DB_NAME | 作成した本番DBの完全な名称 | config/database.php:12 |
| DB_USER | そのDB専用の接続ユーザー | config/database.php:13 |
| DB_PASSWORD | そのDBの接続パスワード | config/database.php:21 |

**利用者が個別に埋めるのは通常5項目**：APP_URL、DB_HOST、DB_NAME、DB_USER、DB_PASSWORD。残りはAPP_ENV=production、DB_PORT=3306、APP_BASE_PATH空欄。ポートに別指定がある場合は個別設定が1項目増える。

APP_DEBUG、SESSION_SECURE_COOKIE、SESSION_SAMESITE、SESSION_COOKIE_NAMEは参照されていない。追加しても効果はないため、サンプルに入れない。

## 3. さくら情報との対応表

実値は本書へ記入しない。表の例は入力場所を示す記号であり、そのまま使用しない。

| .env項目 | 入れる内容 | さくら側で確認する場所 | 例・記入方針 |
|---|---|---|---|
| APP_ENV | 実行環境 | アプリ側の固定設定 | `production` |
| APP_URL | アプリの公開URL。ページ名を除く | ドメイン/SSLと対象ドメインのWeb公開フォルダー | `https://＜公開ホスト＞` または `https://＜公開ホスト＞/＜公開パス＞` |
| APP_BASE_PATH | URLのパスの互換設定 | 確認不要。APP_URLから算出 | 空欄 |
| DB_HOST | 接続用ホスト名。https://やパスを付けない | Webサイト/データ → データベース → 対象DBの設定 → 接続先/削除 | `＜接続先に表示されたホスト名＞` |
| DB_PORT | MySQL接続ポート | 対象DBの接続情報 | 通常`3306` |
| DB_NAME | 接頭辞を含めた本番DB名 | 対象のMySQL 8.0 DB一覧・接続先 | `＜本番DBの完全な名前＞` |
| DB_USER | 対象DBの専用ユーザー名 | 同じDBの接続先 | `＜対象DBのユーザー名＞` |
| DB_PASSWORD | 対象DB作成時に設定した接続パスワード | 手元の安全な保管先。必要時は対象DBのパスワード設定を確認 | 実値は本番.envだけに入力 |

MySQL 8.0ではDB名がDBごとのユーザー名になる仕様。DB_NAMEとDB_USERは通常同じ値になるが、必ず対象DBの表示を確認する。「全体設定」の管理用ユーザー、FTPユーザー、会員IDやrootとは区別する。DB_HOSTは推測したmysql番号ではなく、接続先の表示を使う。[さくら公式：データベース設定](https://help.sakura.ad.jp/rs/2190/)

## 4. さくらで確認する場所

ここでは確認事項だけを示す。今回、コントロールパネルを操作したり設定を変更したりしていない。

| 画面 | 確認する内容 | .envとの関係 |
|---|---|---|
| Webサイト/データ → データベース → 対象DBの接続先 | DBホスト、完全なDB名、DBユーザー、MySQL版 | DB_HOST / DB_NAME / DB_USER |
| 対象DBのパスワード関連設定 | 作成時のDB接続用パスワードを安全に管理しているか | DB_PASSWORD。サーバーログインのパスワードではない |
| ドメイン/SSL → 対象ドメイン → 設定・基本設定 | 公開するホスト名とWeb公開フォルダー | APP_URLのホストとパスを確定 |
| 対象ドメインのSSL関連設定 | 証明書が有効、正しいホストでHTTPS表示、HTTPからHTTPSへの転送 | APP_URLはhttps://。証明書・転送は.envでは設定できない |
| スクリプト設定 → 言語バージョン設定 | Webで動くPHPの版・実行方式 | 環境変数ではない。前回準備方針のPHP 8.2以上で確認 |
| スクリプト設定 → php.ini設定 | display_errors / display_startup_errorsがOff | APP_DEBUGの代わりにPHP側でも非表示設定を確認 |
| Webサイト/データ → ファイルマネージャー | 配置先とstorageの書込権限、非公開ファイルの保護 | .envはプロジェクト直下、公開対象はpublic |

ドメインのWeb公開フォルダーはアプリのpublicを指す配置にする。たとえばpublicがドメイン直下に対応するならURLに/publicは付けない。初期ドメインなど別の入口から.envやstorageが見えないことも配置後に確認する。[さくら公式：Web公開フォルダー](https://help.sakura.ad.jp/purpose_beginner/2867/)

PHPの設定画面は[言語バージョン設定](https://help.sakura.ad.jp/rs/2241/)、エラー表示の設定画面は[php.ini設定](https://help.sakura.ad.jp/rs/2889/)を参照。サーバー全体への影響がある設定は、同居するサイトも考慮する。

## 5. 本番.envの完成形サンプル

次は説明用。山括弧の5箇所だけを、後の本番設定作業で本人が置き換える。今回は実ファイルを作成しない。

```dotenv
APP_ENV=production
APP_URL=https://＜公開ホストと必要な公開パス＞
APP_BASE_PATH=

DB_HOST=＜さくらの接続用DBホスト＞
DB_PORT=3306
DB_NAME=＜本番DB名＞
DB_USER=＜本番DBユーザー名＞
DB_PASSWORD="＜本番DB接続パスワード＞"
```

記入上の注意：

- APP_URLにlogin.php、index.php、クエリ文字列、フラグメントを付けない。末尾スラッシュは省略してよい。
- DB名・ユーザー名を省略しない。DB名は現行コードで英数字とアンダースコアのみ許可。ポートは数字のみ。
- パスワードは文字列のまま指定する。URLエンコード・ハッシュ化・シェルのエスケープを加えない。
- 現行の.env読み取りは両端の引用符を外すだけで、変数展開やバックスラッシュの解釈をしない。`$`、`#`、`=`等を勝手に変換しない。改行を含む値は扱わない。
- 値の後ろに行末コメントを書かない。コメントは別行の先頭#だけにする。
- ローカル.envを本番用の値で上書きしない。

## 6. ローカル.envとの差分

ローカルは値を確認したが、秘密値の転記はしていない。

| 項目 | 現在のローカル | 本番 |
|---|---|---|
| APP_ENV | local | productionへ変更 |
| APP_URL | http://localhost/oshilife-v2/public | 実際のHTTPS公開基準URLへ変更 |
| APP_BASE_PATH | 空欄 | 空欄を維持 |
| DB_HOST | 127.0.0.1 | さくらのDBホストへ変更 |
| DB_PORT | 3306 | 通常3306を維持、接続情報に従う |
| DB_NAME | oshilife_v2 | さくらで作成した完全なDB名へ変更 |
| DB_USER | root | そのDB専用ユーザーへ変更 |
| DB_PASSWORD | 空欄 | 対象DB接続用パスワードを設定 |

## 7. APP_URLと画面・API・招待リンク

APP_URLから公開パスを取り出し、APP_BASE_PATHを算出する（config/app.php:21–36）。APP_URLが設定されていれば.envのAPP_BASE_PATHは使わない。

| 用途 | 現行処理 | 判定 |
|---|---|---|
| PHPの画面リンク・redirect | app/helpers/view.php:13のappUrl、同:27のredirectTo | 公開パスを付けた同一サイト内URL |
| CSS / JS | 同ファイルのassetUrl | 公開パス＋assetsへのURL |
| JavaScript API / fetch | includes/header.php:13のmeta → public/assets/js/common.js:8、23 | 同一オリジンへ接続。Cookieもsame-origin |
| フォーム | JSがsubmitを処理し共通APIへ送信。data-api/data-redirect等を使用 | localhost固定のactionなし |
| ログイン後 | api/auth/login.php:20 → public/assets/js/auth.js:19 | 通常homeへ。招待トークンがあれば固定の招待ページへ復帰 |
| 招待URL | app/services/room_link_service.php:18 | APP_URL＋/rooms/join.php?token=… の絶対URL |
| 招待URLの画面表示 | public/assets/js/rooms.js:7 | 返されたURLを表示。APP_URL未設定時のみ現在のoriginを補う |

通常のredirectやfetchはAPP_URLのホストへ強制転送するのではなく、**現在アクセスしているホスト＋設定から算出したパス**を使う。したがって公開ホストを揃え、別ドメインやHTTPからの正規化はサーバー側で行う。招待URLのホストだけ正しくても、アクセス元が別ホストならCookieは共有されない。

公開URL例（いずれも説明用）：

- publicがドメイン直下：APP_URLは`https://example.invalid`。ログインは`/login.php`、APIは`/api/auth/login.php`。
- publicが/oshilifeに対応：APP_URLは`https://example.invalid/oshilife`。ログインは`/oshilife/login.php`。
- URL上で実際に/publicが必要な配置だけ、APP_URLにもそのパスを含める。

許可されるURLパスは英数字・ハイフン・アンダースコアの階層。日本語・空白・ドット入りの公開パスは現行チェックに通らない。URLはWeb公開フォルダーの実際の対応に合わせる。

招待以外の任意の未ログインページへの復帰機能は現行実装にない。今回それを追加したという意味ではない。

## 8. session / HTTPS / エラー表示

| 項目 | 現行動作・本番での設定 |
|---|---|
| Secure Cookie | APP_ENV=productionなら有効。ローカルでもHTTPSサーバー変数がon相当なら有効（config/app.php:55） |
| HTTPS判定 | `$_SERVER['HTTPS']`を使用。X-Forwarded-Protoは参照しない。productionでSecureを保証するが、HTTP転送は行わない |
| HttpOnly | 常にtrue。JSからセッションCookieを読ませない |
| SameSite | 常にLax。環境変数で変更する仕組みなし |
| Cookie名 | OSHILIFE_V2_SESSION固定 |
| Cookieパス | 公開パス＋/。APP_URLから算出 |
| Cookie寿命 | lifetime=0。セッションCookie |
| セッション保存 | プロジェクトのstorage/sessions。PHPに書込権限が必要 |
| 有効時間 | 無操作2時間でセッション情報を破棄 |
| session_regenerate_id | ログイン成功時にIDを更新（app/services/auth_service.php:27）。本番でも同じ |
| APP_DEBUG=false | この変数は存在しない。設定しても効果なし |
| エラー表示 | config/app.php:37でdisplay_errors=0。例外は画面／APIに内部詳細を出さない |
| PHP起動時等のエラー | コード実行前のエラーも防ぐため、PHP側のdisplay_errors・display_startup_errorsもOffを確認 |
| ログ・制限情報 | storage/logs、storage/rate_limitsも書込可能か確認。公開アクセスは禁止 |

HTTPS未設定のままproductionにすると、HTTPではSecure Cookieが送られずログイン状態やCSRF確認を維持できない。証明書・HTTPSアクセス・HTTP転送の順に整えてから動作確認する。APP_ENV=productionだけでは証明書は作成されない。

## 9. 秘密情報の扱い

- 本番.envはGit管理しない。現行.gitignoreの`/.env`による除外を確認済み。
- DB_PASSWORDをGitHubへ載せない。`git add -f`で除外を回避しない。
- 本番.envをdocsやチャットへ貼らない。実パスワードを質問への回答として送らない。
- .env.production.exampleには実値を入れない。
- .env.productionなど別名の秘密ファイルは現在の`/.env`ルールだけでは除外されない。今回そのようなファイルは作らない。
- publicの外に.envを置き、別のドメイン経由も含めWebから取得できないことを後で確認する。
- 現行ルート.htaccessは全拒否、public/.htaccessは公開許可。さくらで継承・拒否が機能するか配置後に確認する。

## 10. コード変更の必要性と次の作業

### 共通DB接続

製品のPDO生成はconfig/database.php:21に集約。ログイン・予定・イベント・お金管理・ルーム・Chat・招待リンクは共通のdatabase()を使う。DSNはDB_HOST / PORT / NAMEから生成し、ユーザー・パスワードも環境変数で渡す。文字コードはutf8mb4、接続セッションの時刻は+09:00。

公開API入口はpublic/api/、処理本体は非公開api/。たとえばpublic/api/auth/login.phpがapi/auth/login.phpを読み込み、共通起動処理を通る。Chatも同じHTTP API構成で別サーバー接続先の環境変数は不要。

### 固定値の検索結果

| ファイル・行 | 固定値と用途 | 本番接続への影響 |
|---|---|---|
| config/database.php:14 | 127.0.0.1はDB_HOST未設定時の既定値 | 本番DB_HOSTを必ず入れる。実接続先の強制固定ではない |
| config/app.php:25 | localhost/oshilife-v2/publicは設定エラーの説明例 | 接続・リンク生成には使用しない。今回修正なし |
| scripts/create_demo_users.php:10–11 | oshilife_v2固定はローカル専用CLIの実行防止条件 | 本番で使わない。アプリのDB接続処理ではない |
| database/schema.sql:3 | USE oshilife_v2 | 開発用構築SQL。本番へ再実行しない |

製品のPHP / JS / API内に、本番切替を妨げる固定localhost URL・固定DB名は見つからなかった。開発テストやドキュメントのlocalhost例は本番呼出先ではない。

**DB接続先とURLの値を切り替えるための機能コード変更は不要。分離配置のためのPHP参照パス変更は実施済み。** ローカルと一時分離配置でログイン・主要GET画面／APIを確認したが、さくら本番でのログイン・API成功はまだ実測していない。31テーブル作成済みという報告も、PHPの接続権限・セッション保存・各機能の本番確認まで完了した意味ではない。

次は公開URL・publicへの対応・PHP・HTTPS・書込権限を確定し、別途許可された作業で本番.envを本人が安全に設定、配置・動作確認を進める。DBは作成済みなのでschemaやmigrationを再実行しない。確認対象はログイン／ログアウト、予定・イベント、お金管理、ルーム、Chat、招待発行と未ログインからの復帰。今回これらの本番操作は行っていない。
