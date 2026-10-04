# Oshilife v2 さくらのレンタルサーバ向けデプロイ調査

調査日：2026-10-04／対象HEAD：`58d71f5`（仕様書棚卸し）

> これは2026-10-04時点の監査記録。2026-10-05にはユーザー報告でMySQL 8.0.45への31表importが完了し、公開・非公開分離用のコードもローカル検証済み。現在の配置手順は[SAKURA_FILEZILLA_DEPLOY.md](SAKURA_FILEZILLA_DEPLOY.md)を参照。

対象：`/Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife`、ローカルDB `oshilife_v2`。
本書以外のファイル変更、DB更新、migration、データ削除、Git commit/push、本番接続・アップロード・コントロールパネル操作は行っていない。

## 1. 結論

**B：軽微な移行対応後にデプロイ可能（条件付き）。**

PHP＋MySQL＋通常のHTTP通信で完結し、専用常駐サーバーやWebSocketを必要としないため、さくらの共用サーバーへ移す構成として適合する見込み。ただし現在の`.env`を含めたフォルダーの丸ごとアップロードは不可。必要なのは主として配置・本番設定・移行SQLの準備とデモデータの分離である。

**アプリ機能の書き直しが必須と確定した箇所は0件。公開前の必須対応は5項目**（23章）。MySQL復元テストは未実施で、互換性と本番稼働を保証する判定ではない。試験で制約不一致等が見つかった場合は修正内容・判定を更新する。

さくらの公式仕様ではDBはスタンダード以上、MySQL 8.0・InnoDB・utf8mb4、PHP 8系を利用できる。契約プラン、実際のPHP／DBバージョン、公開URLは未確認。ライトプラン等、必要なDBを使えないプランは対象外とする。[公式基本仕様](https://help.sakura.ad.jp/rs/2251/)

## 2. Git状態

| 確認 | 結果 |
|---|---|
| HEAD | 58d71f5 仕様書棚卸し |
| 調査開始時git status --short | 空、working tree clean |
| 未コミット差分・未追跡ファイル | 開始時はなし |
| .env | git ls-filesでは対象外、git check-ignoreで除外を確認 |
| .env.example | 管理対象、値はすべて空欄 |
| 秘密ファイルの履歴 | .envとstorage/demo-accounts.txtについてgit log --allに履歴なし |
| Git内の実行時データ | storageはlogs／sessions／rate_limitsの.gitkeepのみ。生セッション・ログ・デモ認証情報は管理対象外 |
| 本番不要だがGit管理されるもの | docs、README、tests、database/schema.sql・migration、開発用scripts等 |

Gitの現行ファイル一覧と上記の履歴を確認した。全コミットの全文に対する専用シークレットスキャンではないため、「過去も含め秘密が絶対に存在しない」とは断言しない。`.gitignore`に入っていても、FinderやFTPでフォルダー全体を送れば含まれる点に注意する。

本書作成後の想定差分は`docs/SAKURA_DEPLOY_AUDIT.md`の追加だけ。

## 3. localhost依存

| 箇所 | 検出・判断 |
|---|---|
| ローカル.env | APP_URLはhttp://localhost/oshilife-v2/public、DB_HOSTは127.0.0.1、DB_NAMEはoshilife_v2、APP_ENVはlocal。DBユーザーはroot、パスワード空の設定であることだけ確認。持ち出さない |
| config/database.php | DB_HOST未指定時の既定値が127.0.0.1。本番では明示指定が必要 |
| config/app.php | エラー説明にlocalhostの例がある。移動先の固定指定ではない |
| scripts/create_demo_users.php | APP_ENV=localかつDB_NAME=oshilife_v2限定。本番用作成ツールとして流用しない |
| database/schema.sql | 冒頭に`USE oshilife_v2;`が固定。さくらの割当DB名にそのまま使えない |
| README・docs・tests | Mac/XAMPPのパス、localhost、過去バックアップ先・一時検証環境の記載あり。製品のルーティング依存ではない |
| 製品PHP／JS／API | 調査したURL生成・redirect・fetchにXAMPP絶対パスやlocalhost固定の呼出先は見つからなかった |
| CSSの:root | CSS変数用の構文で、DB rootユーザーとは無関係 |

`__DIR__`とPROJECT_ROOTによる相対参照が基本。Linux／FreeBSD系の大文字小文字区別、アップロード漏れは本番試験で確認する。

## 4. config / env

本番専用`.env`を新規に用意し、ローカル.envは保持する。以下は値の例であり、今回作成・適用していない。

```dotenv
APP_URL=https://example.jp
APP_ENV=production
APP_BASE_PATH=
DB_HOST=さくらが指定するDBホスト
DB_PORT=3306
DB_NAME=さくらで作成したDB名
DB_USER=さくらで割り当てられた接続ユーザー
DB_PASSWORD=本番専用の秘密値
```

- DB_HOST／PORT／NAME／USER／PASSWORDは設定で切替可能。ホスト名・認証情報をローカルから推測しない。
- APP_URLからパスを取得する。APP_URLを設定する場合、旧APP_BASE_PATHは空でよい。
- **APP_DEBUGは実装されていない。** `.env`にfalseを書くだけでデバッグ制御できるわけではない。
- セッション名・保存先・タイムアウトはconfig/app.phpで設定され、.envから個別変更する仕組みではない。現在のPROJECT_ROOT基準の保存先を維持すればMac依存はない。
- PHPはAsia/Tokyo、PDO接続時は`SET time_zone='+09:00'`。サーバー全体のタイムゾーンを変える必要はない。
- .env読込は既存の環境変数を優先する。サーバーに同名の環境変数がある場合は実効値を確認する。
- .envはPHP実行ユーザーだけが読める権限を基本にする。DBパスワードをブラウザー用設定やJavaScriptへ埋め込まない。

## 5. URL生成

PHPの`appUrl()`はAPP_BASE_PATH配下の同一サイトURLを返す。`assetUrl()`は同じURLにファイル内容のハッシュを付ける。HTMLのmetaからJSの`appUrl()`が同じパスを読み、`apiRequest()`は同一オリジンへfetchする。

画面フォームは共通JavaScriptからAPIへ送る。イベント・カレンダー・資金・ルームのリンクも共通関数を使う。ログイン後は通常home.php、招待時はセッションに保存した形式確認済みtokenから固定のrooms/join.phpへ戻す。任意のreturn_toを採用しない。

招待リンクはAPP_URLを使用して絶対URLを生成する。APP_URL未設定時の同一オリジン補完もあるが、本番では必ずHTTPSの正規URLを設定する。

**URL体系はAPP_URL一か所で切替できる構造。ただしファイル配置・公開フォルダー・HTTPS設定・DB認証まで自動で切り替わるわけではない。** パスに使える文字は英数字、ハイフン、アンダースコア。日本語やドットを含むサブディレクトリは現在の検証で拒否されるため使わない。

## 6. public構成

publicだけを単独でコピーすると、`../config`や`../app`の参照が切れる。**現在の親子構造を保つことが必要。** public/apiは非公開側apiを読み込む入口なので両方必要。

現行コードを変えずに設置する案：

```text
/home/ACCOUNT/www/oshilife-v2/
  .htaccess          # Require all denied
  .env               # 本番専用
  app/
  api/               # 処理本体、直接公開しない
  config/
  middleware/
  includes/
  storage/
    logs/
    sessions/
    rate_limits/
  public/            # 対象ドメインのWeb公開フォルダー
    .htaccess
    *.php
    rooms/
    api/             # 公開API入口
    assets/
```

さくらはドメイン設定からWeb公開フォルダーを設定する方式を案内している。独自ドメイン／サブドメインをpublicへ向ける案を第一候補にする。[Web公開フォルダー設定](https://help.sakura.ad.jp/purpose_beginner/2867/)

初期ドメインから親階層へアクセスできる可能性もあるため、ドメインの公開先設定だけに頼らず、親の拒否設定と全経路からのアクセス拒否を試験する。`https://初期ドメイン/oshilife-v2/public`で運用する代案ならAPP_URLにもそのパスを含める。

非公開コードをwww外へ完全分離する配置はさらに明確だが、public内PHPの相対参照とassetUrlの物理パスを合わせる調整が必要になる。今回は未実施。Macのhtdocsシンボリックリンクをそのまま移す方法は採用しない。

## 7. Apache / .htaccess

| ファイル | 現在の内容 |
|---|---|
| ルート.htaccess | Require all denied |
| public/.htaccess | Options -Indexes、Require all granted、DirectoryIndex index.php |

RewriteRuleやXAMPP固有のPHPハンドラー指定、FollowSymlinksの指定はない。さくらではOptionsのAll／FollowSymlinksが使えないが、現行ファイルにはその記述がない。[さくらのアクセス制御](https://help.sakura.ad.jp/rs/2214/)

Apacheの認可設定の継承・AllowOverride・PHP実行モードは配置先で確認する。publicが403／500になる場合はログを確認し、拒否設定全体を削除して回避しない。現行.htaccessにHTTPS強制転送はないため、本番のHTTPS設定と合わせてHTTPからHTTPSへの転送を準備する。

## 8. PHP互換性

ローカルのPHPは8.2.4。型宣言、mixed、match、str_contains／str_starts_with、コンストラクタのプロパティ昇格に加え、戻り値型never（PHP 8.1以降）を使う。単に「PHP 8なら可」ではない。**本番はREADMEに沿ってPHP 8.2以上を選び、その選択版で検証する。** さくらはPHP 8.3の提供を案内している。[PHP 8.3提供案内](https://www.sakura.ad.jp/corporate/information/announcements/2024/03/05/1968215069/)

必要機能：PDO、pdo_mysql、mbstring、session、JSON、hash、password_hash／password_verify、random_bytes、ファイルI/O・flock、putenv／getenv。hashやJSON等の標準機能も含め、実際のWeb版PHPで動作を確認する。CLIの設定だけで判定しない。64bit PHPを使い、整数による精算計算を確認する。

ComposerのvendorやNode.jsを製品実行時に読み込む構造は確認されない。Pythonやブラウザーテスト用環境は本番実行の必須条件ではない。

## 9. DB互換性

### 現在確認した状態

MariaDB 10.4.28、31テーブル、InnoDB／utf8mb4_unicode_ci。実DBのSHOW CREATE TABLEを読み取り、列・制約・INDEXを確認した。triggerは0件。アプリからstored routineを呼ぶ実装は見つからず、Event Schedulerへの実行依存もない。

```text
users user_settings oshis members user_oshis
schedules schedule_members schedule_sources user_schedules
correction_requests reactions notifications venues events user_event_status
event_participants trips todos transportations accommodations savings expenses
rooms room_members room_events room_invitations room_invite_links
room_expenses room_expense_members room_settlements room_messages
```

### 項目別判定

| 項目 | 調査結果・本番での確認 |
|---|---|
| PRIMARY／AUTO_INCREMENT | 通常の整数主キー。room_settlementsはroom_idが主キー。ID・次回採番値を保持する |
| UNIQUE／INDEX | 本人と元記録の二重反映、自分1件、owner1人等に必要。省略不可 |
| FOREIGN KEY | 単独・複合FK、RESTRICT／CASCADE／SET NULLを使用。型・符号・列順・参照先UNIQUEを維持する |
| ENUM | users以外の予定・反応等で使用。MySQLにも存在する型 |
| CHECK | 種別、状態、金額、本人／owner整合等を制約。MySQL 8.0.16以上が必要 |
| 生成列 | room_members.owner_marker、room_invitations.pending_markerはSTOREDの生成列＋UNIQUE。dumpのデータSQLで通常列同様の値を明示挿入しないことを確認 |
| 金額 | DECIMAL(12,2)と共同支出のINT UNSIGNED。FLOATへ置き換えない |
| 日付 | DATE／TIME／DATETIME、CURRENT_TIMESTAMP、ON UPDATE CURRENT_TIMESTAMP。日本時間で接続し、日付境界・期限を確認 |
| 照合順序 | utf8mb4_unicode_ci、token_hashのみascii_bin。MariaDB専用uca1400等は現行定義にない |
| SQLモード | 本番の厳格モード・ONLY_FULL_GROUP_BYで主要集計を試験。ローカルで通るだけでは保証しない |

MySQLは8.0.16より前ではCHECKを実質無視するため、古いMySQLへ落とす方法は不適切。[MySQL CHECK仕様](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html)

同仕様にはCHECK対象列とFK参照動作の制限がある。`room_members`のuser_id／room_owner_user_id、`room_invitations`の招待者／被招待者、`room_settlements`の操作者はCHECKとFKの双方に登場する。現行はRESTRICT相当で値を書き換えるCASCADE／SET NULLにはしていないが、**本番と同じMySQL版でこれらのCREATEと不正行拒否を必ず検証する。現時点で具体的なMySQLエラーを再現したわけではない。** CHECKやFKを一括削除して通すことは禁止する方針。

複合FKの参照先はrooms(id,owner_user_id)、room_members(room_id,user_id)、room_expenses(id,room_id)、user_event_status(user_id,event_id)、trips(id,user_id,event_id)等の一意キーを持つ。[MySQL外部キー仕様](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html)

### 全migrationの役割と注意

| SQL | 役割 |
|---|---|
| 002 | 推し・メンバー・本人登録 |
| 003 | 予定・対象メンバー・情報元・取り込み |
| 004 | 提案・反応 |
| 005 | 通知作成と既存情報からの初期通知 |
| 006 | 旧live系、会場・遠征・TODO・交通・宿泊 |
| 007 | 個人会計 |
| 008 | 支払い連携の列・FK、過去支出の関連付けUPDATE |
| 009 | live→event改名、列・INDEX・FK再定義 |
| 010 | 参加状態の列追加、既存当落の変換、CHECK |
| 011 | 個人同行者 |
| 012 | ルーム・メンバー・旧招待・イベント関連 |
| 013 | 招待リンク |
| 014 | 共同支出・負担内訳 |
| 015 | 精算状態 |
| 016 | Chat |

001のmigrationファイルはなく、users／user_settingsは初期schema由来。全SQLの構文・移行順序を確認したが、MySQLで実行していない。現在のschema.sqlは最新31表を含むのに先頭コメントは古いStep表記が残る。最新schemaへ002〜016を重ねて実行してはいけない。

MariaDBのdumpには製品固有のコメント・SET設定やバージョン依存の出力が含まれる可能性がある。dump用クライアントの版も固定し、実際に生成したファイルで互換性を確認する。現時点では「MariaDB固有構文による確定的な致命障害」は未確認、「互換性検証が残る」が正しい結論。

## 10. DB移行方法・バックアップ

| 方式 | 評価 |
|---|---|
| A 現在DB丸ごとdump/import | ローカルの保護・復元には適切。開発会計・Chat・認証情報まで本番へ持ち出すので提出用移行には非推奨 |
| B migrationを001から順に適用 | 001がなく初期状態の復元が必要。データ変換履歴も含み、最新schemaとの二重適用事故が起きやすい |
| C 現行構造＋必要な初期データ | **推奨**。移行用の検証済み31表構造と、作り直したデモデータだけを空の提出用DBへ投入 |

Cは現行構造を基準にする。新しい移行用コピーで固定USEを外し、さくらのDBを接続時に選択する。元schemaや適用済みmigrationを場当たり的に編集しない。必要なら生成列・FK作成順・dump固有出力を移行用コピーで調整し、その差分を記録する。デモデータのFKはデモユーザーから一貫して作り、既存usersだけ抜いた部分コピーはしない。

承認後に行うバックアップ計画：

1. ローカルの書き込みを止めた時間帯に、従来の`--single-transaction --skip-events --skip-routines --triggers --hex-blob`で別名保存。`--databases`を使わず、USE／CREATE DATABASEを含めない出力にする。コマンド終了0、非ゼロサイズ、31表、データSQL、SHA-256を確認。認証情報はコマンドへ直接書かない。
2. 新しいローカル検証DBへ復元し、件数・全行・主要ID・金額・FK・UNIQUE・INDEX・採番を比較する。生成列も比較する。
3. さらに本番と同系統のMySQL検証環境で構造とデモデータを試験する。MariaDBへの復元成功だけでは十分ではない。
4. 本番に既存データがある場合はimport前に本番DBのバックアップを取り、別の検証先へ復元可能か確認。新規空DBなら空であることを記録し、初期構築完了後の基準バックアップも取る。

本番用mysqldumpは本番と対応した版を用いる。権限に応じ`--no-tablespaces`等を検討し、GTIDの扱いも確認する。さくらのレンタルサーバDBは外部から直接接続できないため、Macから本番MySQLへ直接importする前提にしない。承認後、さくら側で利用可能な管理・import手段を使う。[DB接続の制限](https://help.sakura.ad.jp/rs/2251/)

今回は新規バックアップ・復元・SQL調整も未実施。バックアップはWeb公開外・アクセス制限付きで保管し、/private/tmpだけを恒久保管先にしない。

## 11. データ持ち出し確認

生のメールアドレス・パスワード・Chat本文・実名を報告書へ転記しない方針で、件数とデータ種別を確認した。

| 対象 | 今回の読み取り結果・判断 |
|---|---|
| users | 4件。4件ともexample.test／example.com／example.net／example.orgの予約例示ドメインに該当し、表示名もtest／テスト／デモ／確認のいずれかに該当。実在メールの混入はこの分類では検出されなかった |
| expenses | 7件。実支出かテスト支出かは金額・名称から断定せず、移行しない |
| event_participants | 2件。同行者名・メモを持つ。実名かは未判定、移行しない |
| trips | 1件。行程・メモ等の個人情報になり得るため移行しない |
| transportations／accommodations | いずれも0件 |
| room_expenses／room_expense_members | 2件／4件。支払・負担・保存時の名前を含む。移行しない |
| room_messages | 2件。本文の公開許諾は確認していないため移行しない |
| room_invitations／room_invite_links | 1件／2件。旧招待とtoken hashを含む。コピーせず本番で新発行する |
| notifications／reactions／correction_requests | 6件／6件／1件。行動・提案内容を含むためデモ用に作り直す |
| schedules／user_schedules／user_event_status／todos／savings等 | 私用日程・メモ・支払・積立・本人関連が入り得る。必要デモを新規作成し、全件持ち出ししない |
| oshis／members／events／venues | 推し3件、メンバー13件、イベント3件、会場2件。内容・権利・メモ・作成者FKを確認した必要部分だけをデモとして再構成 |

パスワードはhashを保存する構造で、平文パスワードの列はない。ただし既知の開発用パスワードをそのまま使わない。`storage/demo-accounts.txt`は平文の開発用接続情報を作るCLIの出力先なので、存在する場合も内容を移行しない。セッション・ログ・rate limitファイルにも機密や利用履歴があり得る。

「全件が架空で安全」との判定はしていない。実名・実支出・私的内容の意味判断を避け、開発データを移さないことで提出用の公開範囲を明確にする。今回削除は一切行っていない。

## 12. デモデータ

| データ方針 | 評価 |
|---|---|
| A 開発DB全部 | 最も準備は少ないが、私的内容・古いtoken・開発認証情報の流出リスクが高い |
| B 空DB | データ分離は明確だが、審査者が多数の登録をするまで主要機能を確認できない |
| C 最低限のデモ | **G's提出に推奨**。主要機能をすぐ確認でき、公開する情報を管理できる |

用意する案：架空名のデモA／B、例示ドメインのメール、専用のランダムパスワード、少数の推し・メンバー・会場・今後の日付のイベント・公開予定。本人の参加確定・TODO・遠征例、ルーム・数件の架空共同支出・短いデモ会話を用意する。未精算と精算済みの例を分けると確認しやすい。

審査者用と管理者用アカウントは分ける。必要なら審査者ごとにデモを分離し、共通アカウントの同時編集衝突を避ける。デモ資格情報は提出資料等の必要な相手へ渡し、公開Gitや通常画面のHTMLには置かない。デモを操作できる人はそのデモデータを変更できる前提にする。

既存create_demo_users.phpはローカル限定のため本番実行しない。承認後、検証済みのデモデータ作成手順を用意するか、登録画面で専用アカウントを作り必要データを入力する。リセットは提出用専用DBの基準バックアップと作業手順で管理し、現行アプリに自動リセット機能があるとは扱わない。

## 13. session / cookie・書き込み先

- 保存先：PROJECT_ROOT/storage/sessions。Cookie名OSHILIFE_V2_SESSION、Cookie有効期限はブラウザーセッション、最終アクセスから2時間で失効判定。
- HttpOnly=true、SameSite=Lax、strict mode、Cookieのみのセッション。ログイン成功時にID再生成。
- APP_ENV=productionまたはHTTPS判定時にSecure=true。本番はAPP_ENV=productionを必須にする。
- Cookie pathはAPP_URLのパス＋`/`。ドメイン直下公開なら`/`、サブフォルダー公開ならその範囲。
- Chatや通知のpollingもアクセスなので、画面を開いて通信が継続すると無操作2時間の期限は延長される。絶対2時間のログイン期限ではない。
- ローカルのセッションファイルをアップロードしない。新しい本番環境でログインし直す。

PHPの書き込み先はstorage/sessions、storage/logs/php-error.log、storage/rate_limits/*.json。書き込み可能な所有者・PHP実行ユーザーを確認し、同一所有者ならディレクトリ700／ファイル600を出発点に必要最小限で調整する。グループ運用なら適切な750／640等を検討し、777で解決しない。コードやassetsは通常PHPからの書き込み不要。

ユーザー画像・ファイルアップロードは未実装。製品の一般的なキャッシュ出力先も確認されない。CLIのdemo-accounts.txt作成は本番対象外。ログ肥大化・セッション清掃・rate limitファイルの保守方法は公開運用時に決める。

## 14. HTTPS

APP_URLをHTTPSにし、証明書・HTTP→HTTPS転送を本番側で用意する。コードのHTTPS判定だけに依存せずAPP_ENV=productionでSecure Cookieを確実に有効化する。現行コードはHTTP自体を拒否・転送しないため、設定だけでHTTPアクセスが残るとログイン不能やtoken露出につながる。

画面内通信は同一オリジンでHTTP固定ではない。公開予定の情報元としてhttp URLを入力できる仕様は、アプリの接続先がHTTP固定という意味ではない。正規ホスト・公開パスを一つに決め、サブドメイン間のCookie混在を避ける。

## 15. 招待リンク

APP_URLからHTTPS絶対URLを生成するのでlocalhost依存はない。32バイト乱数・SHA-256保存・7日有効、ownerによる無効化、closed時の無効化、確認後のPOST参加、ログイン後の固定パス復帰はそのまま利用する構成。

検証：発行URLが外部端末で開くこと、未ログイン復帰、CSRF付き参加、別アカウント参加、期限・無効化・closed拒否、既参加の重複防止。生URLは発行時のみ表示する。過去token hashを移すと既存リンクが本番でも利用可能になる余地があるのでコピーしない。

画面はno-referrer／no-storeを使用するが、URLのtokenがWebサーバーのアクセスログやブラウザー履歴に残り得る。ログ公開を防ぎ、公開資料に有効な招待URLを掲載しない。

## 16. Chat・メール

ChatはPHP・DB・短いHTTPリクエストで完結するため、方式上は通常の共用サーバーで動作する見込み。WebSocket化や常駐プロセスは不要。初回100件、after_id差分、取得完了から4秒後、非表示タブで停止する。

理論上、表示中のトーク画面N個で約N/4リクエスト毎秒（1画面で約15回／分）。通知取得や操作APIは別に加わる。10画面なら約2.5回／秒、50画面なら約12.5回／秒の目安だが、安全な同時人数を保証する数値ではない。通信時間分は間隔が延び、未取得が多い場合は追いつき取得もある。

投稿には全ルーム合計20件／30秒の制限があるが、これはGET pollingの負荷制限ではない。セッションファイルのロック、ルーム／ユーザー行のロック、DB接続、PHPワーカー、同時タブ数の影響を計測する。PHPタイムアウトやDB接続上限を推測で大きくせず、まず少人数の審査利用で応答時間・5xx・429・接続エラーを確認する。遅延があれば取得間隔等の変更を別途検討し、今回は変更しない。

**メール送信機能は確認されない。** 認証メール・パスワード再設定は未実装で、SMTP設定は現行デプロイの必須条件ではない。デモのメールはログイン識別子として使う。

## 17. security

実装済み：password_hash／verify、セッション再生成、認証middleware、更新時CSRF、PDOネイティブprepare、e()/htmlspecialcharsとtextContent、本人／room_idの認可、owner/member・active/left/closed検査、招待token hash、認証IP制限、Chat制限、CSP、nosniff、no-store。共同支出等はトランザクションとUNIQUEで整合を守る。

本番追加確認：

- .env、.git、app、api本体、config、storage、SQL、ログが全ドメイン経路で403／404になること。
- HTTPS、Secure／HttpOnly／SameSite／path、CSPが実際の応答に出ること。
- 他人の同行者・支出・ルーム・ChatがURL改変で見えないこと。
- さくらのWAF等でJSON APIやCSRFヘッダーが誤検知されないこと。問題時にWAF全体を無効化して回避しない。
- 認証制限はIP単位で15分30回。学校の共有IPで審査者の連続ログインが集中すると429になり得るため、テスト時に留意する。
- 本番DB用ユーザーはさくらの提供する権限管理の範囲で対象DBへ限定し、rootを使わない。

公開Webアプリは登録・投稿が可能なため、デモURLの共有範囲とデータ復旧担当を決める。未実装のメール確認・アカウント回復を実装済みと案内しない。

## 18. debug / error

config/app.phpはdisplay_errors=0、log_errors=1、独立したログ保存先を設定。画面／APIの例外処理は一般的な日本語文言を返し、通常のPDO例外のSQLやスタックを利用者へ返さない。業務例外の入力エラーは意図して表示する。

ただしini_setより前の読込エラーや構文エラーまでアプリ設定だけでは保証できない。**本番PHP設定でもdisplay_errors=Off、display_startup_errors=Off、log_errors=Onを指定し、error_reportingはログへ必要なエラーを記録する設定にする。** APP_DEBUGという.envキーは未対応。

公開phpinfoやDB疎通用スクリプトを置きっぱなしにしない。DB接続失敗・session書込み失敗の際にパス・認証情報・SQL詳細がレスポンスへ出ないことを確認する。ログはWeb公開せず容量を管理する。

## 19. 不要ファイル

| 本番実行に含める | 本番へ送らない／公開外の作業保管に限定 |
|---|---|
| public一式、app、api、config、middleware、includes、必要な.htaccess | .git、docs、README、tests、開発scripts、databaseのSQL・seeds、dump、バックアップ |
| 本番専用.env | ローカル.env、.env.exampleの不用意な公開、demo-accounts.txt |
| 空のstorage/logs・sessions・rate_limitsと適切な権限 | ローカルのログ・セッション・rate limit記録、スクリーンショット、OS一時ファイル |

移行SQLは管理者だけが参照できる作業領域で扱い、公開ディレクトリへ残さない。現在vendor／node_modulesは実行必須ではない。`/private/tmp`内の検証アプリやdumpはアップロード対象にしない。アップロードは除外リストだけに頼らず、必要ディレクトリの許可リストで梱包する。

## 20. デプロイ手順（次回承認後）

1. 契約プラン、PHP実行版、MySQL実版、ドメインとURL、利用可能な転送・DB管理手段を確認する。
2. 対象コミットとclean状態を確定。本調査資料を含めるかも区別し、実行用ファイル一覧を作る。
3. ローカルDBの最終バックアップと別DB復元比較を行う。ローカルの.env・DB・XAMPP設定はそのまま保つ。
4. 本番同系統のMySQLで、固定USEを含まない移行用31表構造と合成デモデータを予行する。制約・主要API・金額・生成列を検証する。
5. さくら側に提出用の新しいDBを作成し、割当ホスト・DB名・ユーザー・パスワードを確認する。一般的なCREATE USERが自由に使えるとは仮定しない。
6. 既存本番DBがあるなら先にバックアップする。対象が新規空DBか確認してから検証済み構造・デモだけをimport。全migrationを再実行しない。
7. 親子構造を保ったコードと本番専用.envを配置。公開フォルダーをpublicへ設定する。
8. storageの空ディレクトリ、所有者、書き込み権限、PHP拡張、エラー非表示を確認する。
9. HTTPS証明書と転送を設定し、APP_URL・APP_ENVを本番値へ合わせる。初期ドメイン等の別経路も保護する。
10. 外部から.env／ログ／SQL等が取得できないことを最優先で確認する。
11. 次章のP0→P1→P2を順に実施し、失敗時は公開案内を止める。
12. 確認済みデモの基準バックアップを取得し、URL・操作案内・専用資格情報を講師／メンターへ共有する。

今回は上記手順を実行していない。実デプロイの承認と環境情報を受けてから進める。

## 21. 本番テスト

| 優先度 | テストと合格条件 |
|---|---|
| P0 公開防御 | 正規HTTPS、HTTP転送、非公開パス拒否、エラー詳細非表示、DB接続、31表・制約が存在 |
| P0 基盤 | トップ→login/home、CSS/JS/APIが200で取得、登録・ログイン・ログアウト、Secure Cookie、直接homeアクセス拒否、CSRF拒否 |
| P0 権限 | A/B別アカウントで個人同行者・個人会計・非参加ルームへのアクセス拒否、owner限定操作 |
| P1 基本機能 | 推し追加、公開／非公開予定、月間・日別カレンダー、見つける、公開取り込み、元更新同期、自分用編集保持 |
| P1 イベント | 6種類、舞台表示、コピー、FC受付、参加確定TODO、0円支払不要、近場／遠征、交通・ホテル登録 |
| P1 個人会計 | 積立・支出・残高・推し/月/年別、特効、チケット・交通・ホテルの反映と二重拒否・削除後再反映 |
| P1 ルーム | 作成・複数イベント・HTTPS招待発行・別端末ログイン復帰・参加・期限/無効化/closed拒否・退出後拒否 |
| P1 Chat | A/B差分取得、100件、文字数・絵文字・本人削除・他人削除拒否、closed/退出、連投制限 |
| P1 共同会計 | 均等割り端数、合計不一致拒否、編集競合、取消、相殺金額、owner精算済み、変更時解除、本人負担だけの個人反映・二重拒否 |
| P2 表示・負荷 | PC／スマホ、招待コピー、dialog、テーマ、Chatスクロール、数人同時利用の応答・接続数・PHP時間・ログ容量 |
| P2 提出導線 | デモの推し・近い開催日・TODO・会計・ルームが分かりやすく、不要な私的データ・古いリンクがない |

各操作の期待値と実測結果を残す。金額テストは架空のデモデータだけで行い、実際の送金はしない。

## 22. rollback

- **ローカル**：本番用設定を別に作るため変更不要。調査や配備の都合で旧Oshilife・ローカルDB・XAMPPを巻き戻さない。
- **本番コード**：配備前のリリース一式と本番.envの安全な控えを保管。失敗時は公開案内・更新を止め、互換性のある前リリースへ切り替える。初回なら公開を止めて原因を修正する。
- **本番DB**：失敗時点のDBも先に保護する。DDLはトランザクションだけで全体復旧できないので、途中importを同じDBへ繰り返さず、新しい検証DBでやり直す。
- 既存利用者の更新があるDBへ古いdumpを一括上書きしない。復元先を別DBにし、復元検証・差分と利用者への影響を確認したうえで切替を決める。
- デモ初期化も対象DBを明確にし、講師等が操作中でない時間に行う。コードとDB構造の対応、招待URL、Cookie pathを再確認する。

## 23. 修正・準備が必要な項目

件数の数え方を固定する。**必須対応5項目、確定したアプリ機能コード修正0件。** 互換性試験で必要になった修正は別途追加する。

| # | 優先 | 必須対応 | 今回の判定 |
|---|---|---|---|
| 1 | 最優先 | MySQL用移行資材と復元検証 | 固定USEを避ける移行用SQLを用意。生成列・CHECK・複合FKと主要処理のMySQL試験が必要 |
| 2 | 最優先 | public限定の公開配置と除外梱包 | 親子構造保持、非公開領域拒否、初期ドメイン経由も試験 |
| 3 | 最優先 | 本番.env・HTTPS・PHP設定 | root/空パスワード/localhostを置換、production、Secure、エラー非表示 |
| 4 | 必須 | 合成デモデータ・専用アカウント | 開発DBの全件移行を避け、架空の操作例・新しい資格情報を準備 |
| 5 | 必須 | 書き込み権限・バックアップ・負荷／回帰確認 | 空storageと安全な権限、復旧手順、polling等を実測 |

契約プラン・ドメイン・PHP／MySQL実版は未提示。調査資料は用意できたが、これらが決まるまで配置先と移行資材の最終確定はできない。

## 24. 最終判定・確認資料

**B：軽微な移行対応後にデプロイ可能。** 公開を妨げるMac専用の製品ロジックや常駐サーバー依存は見つからない。ただし、ローカル設定・固定DB名・公開境界・個人データを処理せず「そのままアップロード可能」とは判定しない。

DBは既知の基本型・制約を使用しているが、MariaDB→MySQLの実復元は未実施。DB互換性の最終合格は本番同等版の復元・制約・回帰確認後に出す。次Stepは**移行準備とMySQL検証**であり、本番公開ではない。

ローカル確認資料：README、docs/GRADUATION_SPEC_AUDIT.md、EVENT_STEP1／2／3_1、ルーム・招待・共同支出・精算・Chat・個人反映の実装報告、database/schema.sql、002〜016のSQL、実DB31表のDDL、主要config/helper/API/JS、Git管理状態。個人値を出さず件数とメール分類を確認した。

公式情報の確認日：2026-10-04。契約サーバーの実設定は未確認。さくら公式の基本仕様・公開フォルダー・アクセス制御・PHP案内と、MySQL公式のCHECK／FK仕様を参照した。外部の管理画面操作やサーバーログインは行っていない。
