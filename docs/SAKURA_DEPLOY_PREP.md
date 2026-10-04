# Oshilife v2 さくらデプロイ準備Step

作成日：2026-10-04。対象HEAD：`58d71f5`（仕様書棚卸し）。

> 以下は作成日時点の準備記録。2026-10-05にはユーザー報告で本番DBへの31表importが完了し、分離配置のコード修正もローカル検証済み。現行の転送先と手順は[SAKURA_FILEZILLA_DEPLOY.md](SAKURA_FILEZILLA_DEPLOY.md)を参照。

**準備文書・秘密情報を含まないテンプレートは完成。本番作業はまだ開始不可。** 環境情報、MySQL復元検証、バックアップ、デモ実データ、本番資格情報が未準備である。今回はアプリコード変更、migration・DB更新、seed作成・実行、秘密情報保存、本番接続、公開、Git commit/pushをしていない。

監査根拠は[SAKURA_DEPLOY_AUDIT.md](SAKURA_DEPLOY_AUDIT.md)。本書はその実施準備版で、未確認の外部環境を推測で確定しない。

## 1. 開始状態と確認範囲

- HEAD：58d71f5。
- 追跡済みファイルの未コミット差分：なし。
- 開始時の未追跡：前回作成の`docs/SAKURA_DEPLOY_AUDIT.md`。そのまま保持。
- `.env`のキー：APP_URL、APP_ENV、APP_BASE_PATH、DB_HOST、DB_PORT、DB_NAME、DB_USER、DB_PASSWORD。実値・秘密は本書へ転記しない。
- `.env.example`は同じキーの空欄テンプレート。
- public、config、database/migrations、scripts、docs、testsの存在と構成を確認。migrationは002〜016の15ファイル、001はない。
- 現行schema.sqlは31 CREATE TABLE。読み取り専用で実DB `oshilife_v2`も31テーブルを再確認。
- PHP／JS／API／configのlocalhost固定文字列を再検索。製品の呼出先は共通URL生成を使用。例外はDB_HOST既定値127.0.0.1、説明文のlocalhost例。開発用CLIとschemaの固定USEは移行対象と区別する。
- 現行require/includeはPROJECT_ROOTと相対親階層を前提とする。配置だけを分離してpublicのみ移すことはできない。
- ローカルでDockerコマンドの存在は確認したが、検証可能なMySQLサーバーは確定していない。コンテナ起動・ソフトウェア導入・復元試験は実施していない。

## 2. 本番環境確認票

すべて**ユーザー確認待ち**。パスワードや秘密鍵をチャットへ送る必要はない。

| 項目 | 確認する内容 | 状態 |
|---|---|---|
| 契約プラン | MySQL利用可能なプランか | ユーザー確認待ち |
| PHP | Web版の選択バージョン・実行モード。8.2以上を基本とする | ユーザー確認待ち |
| MySQL | 正確な版。CHECKを維持するため8.0.16以上、実際の版と同系統で検証 | ユーザー確認待ち |
| DBホスト | 指定ホスト名・ポート。localhostを推測で使わない | ユーザー確認待ち |
| DB名・ユーザー | 割当名称、提出用DBを分離できるか | ユーザー確認待ち |
| 公開URL | HTTPSの正規URL、サブフォルダー有無 | ユーザー確認待ち |
| 独自ドメイン | 有無、使う独自／サブ／初期ドメイン | ユーザー確認待ち |
| HTTPS | 証明書の利用可否・現在状態、HTTP転送設定 | ユーザー確認待ち |
| DocumentRoot | 対象ドメインのWeb公開フォルダーと初期ドメインからの経路 | ユーザー確認待ち |
| SSH | 契約での利用可否のみ確認。今回は接続しない | ユーザー確認待ち |
| SFTP | 利用可否と許可されたアップロード手段。SSHから利用可と決めつけない | ユーザー確認待ち |
| 管理方法 | DB import・export手段、容量・権限、バックアップの保管場所 | ユーザー確認待ち |

契約環境に関する一般仕様の出典は監査書に記載した。一般仕様が利用中サーバーの実設定と一致するかは別途確認する。

## 3. 本番.envとproduction設定

新規テンプレート：[.env.production.example](../.env.production.example)。実値未設定のため、そのまま動かすファイルではない。

| 項目 | 必要な設定・現行動作 |
|---|---|
| APP_ENV | production。Secure Cookieを有効にする |
| APP_URL | 確定したhttps://の公開URL。末尾スラッシュは共通処理で除去 |
| APP_BASE_PATH | APP_URLから算出するため通常は空欄 |
| DB_HOST/PORT/NAME/USER/PASSWORD | 本番専用値。DB名・ユーザーは割当を使用、rootや空パスワードを流用しない |
| APP_DEBUG | 現行コードは参照しない。テンプレートへ追加しない |
| SESSION_SECURE_COOKIE | 現行コードは参照しない。APP_ENV=productionで制御する |
| display_errors | アプリでも0。本番PHP設定でもOff |
| display_startup_errors | 本番PHP設定でOff |
| log_errors | On。Webから見えないstorage/logsへ保存 |
| session | OSHILIFE_V2_SESSION、storage/sessions、HttpOnly、SameSite=Lax、2時間の無操作期限 |
| timezone | PHPはAsia/Tokyo、DB接続は+09:00。現行の固定設定を維持 |

.envはGit管理対象外。環境変数が既にあればそちらを優先する実装なので、最終的な実効値を確認する。秘密値は本番配置時に安全な手段で入力する。ローカル.envは変更しない。

機能コードの修正が必要と確定した箇所は現時点でない。仮に配置先で相対参照変更が必要になった場合は、その理由と対象を別途提示し、今回変更しない。

## 4. 推奨配置と公開ファイル

現在の参照構造を保つ配置例。ACCOUNTやURLは未確定の説明用記号。

```text
/home/ACCOUNT/www/oshilife-v2/
  .htaccess                 # 親領域はRequire all denied
  .env                      # 本番専用、未作成
  app/
  api/                      # API処理本体、直接公開しない
  config/
  middleware/
  includes/
  storage/
    sessions/               # 空から用意
    logs/
    rate_limits/
  public/                   # 対象ドメインのWeb公開先
    .htaccess
    *.php
    rooms/
    api/                    # 公開入口。非公開apiを読み込む
    assets/
```

- 対象ドメインのWeb公開先をpublicにする。独自ドメイン等の設定可否は確認待ち。
- 初期ドメインから親フォルダーに入れる経路も拒否されることを試験する。publicの設定だけで.env保護を完了扱いしない。
- 現行ルート.htaccessはRequire all denied。publicはOptions -Indexes、Require all granted、DirectoryIndex index.php。継承が配置先で動くか確認する。
- publicだけを`www/oshilife/`へ移し、appを別の親階層へ置く例は、現在の`../config`等を壊す。assetUrlもPROJECT_ROOT/publicを参照するため、無修正では採用しない。
- www外に非公開側を完全分離する案は将来の配置調整候補。今回はコード無変更の上記案を採用し、アクセス拒否を必須条件にする。
- MacのシンボリックリンクやXAMPP絶対パスはアップロードしない。

### 配備許可リスト

実行用：`public/`、`app/`、`api/`、`config/`、`middleware/`、`includes/`、ルート`.htaccess`、本番専用`.env`、空のstorage各ディレクトリ。

除外：`.git/`、ローカル.env、docs、README、tests、開発scripts、database全体、SQL dump／backup、スクリーンショット、一時フォルダー、ログ・セッション・認証回数記録、demo-accounts.txt、IDE設定、.DS_Store、node_modules、vendor（現行の実行依存なし）。

移行用SQLはWeb公開外の管理資材として別に扱う。過去migrationを本番Web領域へ置く必要はない。現行公開API互換入口や一見未使用の製品ファイルを勝手に削って梱包しない。

## 5. DB方式の決定

**C：現行31表の構造＋最低限の合成デモデータを推奨。**

| 案 | 判断 |
|---|---|
| A 開発DB全件dump/import | ローカル保護用には必要。本番用には私的支出・Chat・token・既存認証情報が混ざるため不採用 |
| B migrationを001から順番 | 001がなく、既存データ変換と改名履歴もある。最新schemaと重ねる事故を避けるため不採用 |
| C 現行schema＋最小デモ | 公開する内容を選び、機能を短時間で見せられる。新規の提出用DBに限定して構築する |

現行schemaのコピーから`USE oshilife_v2;`を除いた移行資材を後続で用意する。本番DBは接続時に明示選択する。元schema・過去migrationは変更せず、移行用コピーの差分・対象版・ハッシュを記録する。今回はSQL資材やseedの生成・実行はしていない。

## 6. 最小デモデータ設計（データ未作成）

| 対象 | 最小構成案 | 確認できる価値 |
|---|---|---|
| ユーザー | デモオーナーA、デモメンバーBの2人 | 共有と本人限定の違い |
| 推し・メンバー | 架空の推し1件、メンバー2件、色あり | 推し登録・推し別表示 |
| 会場 | 架空の会場1件 | イベントと遠征の関連 |
| 公開予定 | B作成でAが未取り込みの予定1件 | 見つける→追加→カレンダー |
| 個人予定 | Aの非公開予定1件 | 公開範囲の違い |
| イベント | 提出日より後の日付1件、A/Bが管理 | 次のイベント・共有情報と個人状態 |
| 参加・TODO | Aを参加確定＋遠征。TODOは通常処理で生成 | 準備の見える化 |
| 遠征 | Aの遠征1件、交通・ホテル各1件 | 予約と支払管理 |
| 個人会計 | Aの架空積立1件、グッズ支出1件 | 残高・特効。後述の共同費用と重複させない |
| ルーム | A owner、B memberの進行中ルーム1件 | イベント・会話・費用をまとめる |
| 共同支出 | A支払ホテル20,000円、B支払交通12,000円。どちらも半分ずつ負担 | B→Aへ4,000円という精算案 |
| 精算 | 初期は未精算 | ownerが確認後に精算済み→本人会計へ反映 |
| Chat | 「集合場所を確認しましょう」等、架空の短文2件 | 会話履歴・追加投稿 |

遠征の同じホテル／交通を個人会計へ従来連携で先に反映しない。共同支出の本人負担との二重計上を避けるため、どちらの経路を見せるデモか説明を付ける。

招待リンクは初期データへ生tokenを埋めず、必要時に通常UIで発行する。古いroom_invitationsや開発token hashは移さない。全6種類のイベント・多数のルームは最小デモには不要。別の確認ケースとして追加する場合も架空情報だけにする。

## 7. デモアカウント設計

- 主な案内はowner A。1アカウントでホームから精算済み・本人会計反映まで確認できる。
- member Bは任意の追加確認用。別ブラウザー／プライベートウィンドウで同時ログインし、Chat共有とowner限定操作を示す。2人分のDB記録は用意するが、審査者に必ず両方のログインを要求しない。
- メールは例示用ドメインの架空識別子。メール送信が未実装なのでメールボックスは不要。本物の氏名・住所・メール・会計を使わない。
- 初期パスワードは作成時にアカウントごとにランダム生成する。今回決めない・保存しない。DBにはpassword_hashの結果だけ保存し、共有先は審査者向け非公開案内に限定する。
- 開発用create_demo_users.phpはローカル専用のため、本番用として実行しない。
- パスワード変更画面は未実装。審査後はまずデモの公開・共有を終了し、承認された管理手順でパスワードハッシュ更新と既存セッション無効化を行う方針。パスワード更新だけでは既存セッションが直ちに失効する実装ではない点に注意する。
- 多数が同時に同じアカウントを操作すると状態が混ざる。審査枠を分けるか、必要ならデモデータ単位で別アカウントを設ける。

## 8. 5〜10分のデモシナリオ

| 所要目安 | 優先 | 操作 |
|---|---|---|
| 0〜1分 | 必須 | Aでログイン。ホームの予定・次のイベント・資金を見る |
| 1〜3分 | 必須 | 見つけるからBの公開予定を追加し、カレンダーで確認 |
| 3〜4分 | 必須 | イベント詳細で参加確定とTODOを見る。遠征の交通・ホテルを開く |
| 4〜7分 | 必須 | 連番ルームへ。Chat、2件の共同支出、B→Aに4,000円の精算案を見る |
| 7〜9分 | 推奨 | デモ上の完了確認として精算済みにし、ホテルのA負担10,000円だけを個人会計へ反映。資金画面で確認 |
| 9〜10分 | 任意 | BでChat投稿・Aで取得。Bには精算済み確定権限がないことを見る |

時間が短い場合、遠征詳細・Bログイン・新規支出入力は省略する。実際に送金するデモではない。説明では「総額を払った人」と「最終負担」を分ける。すでに誰かが操作済みなら無理に同じ初期状態のシナリオを続けず、管理者が状態を確認する。

## 9. デモ初期化の方針

推奨は**完成した提出用デモDBの基準バックアップを、新しい提出用DBへ復元して検証後に切り替える方法**。既存DBに無条件でrestoreする手順にしない。

1. 操作のない時間帯を決め、提出用アプリへの書き込みを止める。
2. 現在のデモDBも退避し、審査中のデータを捨ててよい範囲を確認する。
3. 基準バックアップを別の空DBへ復元し、31表・関連・金額・ログインを確認する。
4. 承認後に接続先を切り替え、旧セッションを持ち越さず再ログインする。古い招待URLも持ち越さない。
5. 元の提出用DBは検証完了まで保管する。ローカルDB・実ユーザーDBには適用しない。

繰り返し用seedは便利だが、ID・支出・精算・Chat・生成列の整合を保つ実装とテストが必要。今回はseed／初期化scriptを作らない。一般ユーザー向け「リセット」ボタンも設けない。基準バックアップに含むデモパスワードが審査後に再有効化されないよう、資格情報の世代と復元日を管理する。

## 10. MySQL互換性の重点確認

行番号は現在のdatabase/schema.sqlに基づく。実行エラーが確認されたという意味ではなく、移行試験の重点箇所。

| ファイル・箇所 | 理由・合格条件 |
|---|---|
| schema.sql:3 | USE oshilife_v2固定。移行用コピーでは除き、対象DBを明示指定 |
| schema.sql:214、229〜233／009・010 | event_type等のCHECK。MySQLで実際に不正状態を拒否すること |
| schema.sql:386〜389／011 | 自分1件、NULLの同行者複数、複合FK、selfの利用終了禁止 |
| schema.sql:416、425〜432／012 | STORED生成列owner_marker、UNIQUE、CHECKとFKの併用。同一roomのowner一意と不正owner拒否 |
| schema.sql:441、448〜452／012 | 旧招待のpending_marker生成列とCHECK。使用停止中でも31表の構造として保持 |
| schema.sql:474、485〜487／013 | ascii_binのtoken hash一意、owner FK、期限関連の状態 |
| schema.sql:514〜519、536〜538／014 | 複合FK、金額範囲、内訳一意。合計一致はサービス側も検証 |
| schema.sql:550〜555／015 | 精算状態と日時・操作者のCHECK、同ルーム操作者FK |
| schema.sql:572〜574／016 | 投稿者の複合FKと文字数CHECK。絵文字が保存・表示できること |
| schema.sql:337以降／008 | 支払元→expensesのUNIQUE・SET NULL。支出削除後の再反映を維持 |
| 全表 | PRIMARY、AUTO_INCREMENT、UNIQUE、INDEX、ENUM、DECIMAL、DATE/TIME/DATETIME、CURRENT_TIMESTAMP／ON UPDATE、InnoDB、utf8mb4_unicode_ciを維持 |

CHECKとFK動作の組合せ、生成列へのINSERT、厳格SQLモードは実際のMySQL版で検査する。文字列を小文字化したり、FK／CHECKを一括削除して通す対応はしない。datetimeの日付境界・7日期限はPHP／DBとも日本時間で検証する。時刻型は主にDATETIMEで、サーバーの自動タイムゾーン変換を期待しない。

生成列の値は復元時に再計算させる。MariaDB dumpがその列へ通常の数値をINSERTしていないかを実ファイルで確認する。列の値を保つことと生成列へ値を直書きすることは異なる。

## 11. 復元テスト用資材・手順（未実行）

本Stepではmigration実行・秘密保存が禁止されているため、下記は次回承認後の実行計画。コマンド中の大文字の記号は実体・検証用値へ置き換える。本番ホストを指定しない。

### 作る資材

1. ローカル全体保護用`local-before-deploy-YYYYMMDD.sql`。私的データを含むため本番投入用ではない。
2. `current-structure-YYYYMMDD.sql`（no-data）。実DB定義との照合用。
3. 本番同等MySQLで検証する、固定USEなしの`deploy-schema.sql`。元schemaからの差分を記録。
4. 承認後に作成する合成デモデータと、その完成状態の`demo-baseline.sql`。
5. 対象版・コマンド終了コード・サイズ・SHA-256・31表・行数・制約・検証結果を記す記録。

```sh
# ローカルの保護用。LOCAL_DUMP_BIN等は環境確認後の実体へ置換。
# -pで対話入力する場合もパスワードを引数やファイルへ直書きしない。
LOCAL_DUMP_BIN --host=LOCAL_HOST --user=LOCAL_USER -p \
  --single-transaction --skip-events --skip-routines --triggers --hex-blob \
  oshilife_v2 --result-file=PRIVATE_DIR/local-before-deploy-YYYYMMDD.sql

# 構造だけ。--databasesを付けず、CREATE DATABASE/USE混入を確認する。
LOCAL_DUMP_BIN --host=LOCAL_HOST --user=LOCAL_USER -p \
  --no-data --skip-events --skip-routines --triggers \
  oshilife_v2 --result-file=PRIVATE_DIR/current-structure-YYYYMMDD.sql
```

実行順序：

1. ローカルで書き込みを止めた時点の保護dumpを取得し、終了0、サイズ、31表、データSQL、ハッシュを確認。Event Schedulerは有効化しない。
2. 新規のMariaDB検証DBへ保護dumpを復元し、既存ID・金額・全行・採番・定義を比較する。これでローカルの復旧可能性を確認する。
3. 本番同等MySQLの**専用空検証DB**を用意する。この段階の作成は次回承認後。バージョン、sql_mode、文字コードを記録する。
4. deploy-schema.sqlをそのDBへ投入し、31表とFK・UNIQUE・CHECK・生成列・INDEXを確認する。
5. 合成デモを別途作成して投入し、主要API・権限・支払い・精算・Chatを試験する。
6. 完成した検証用デモDBを対応MySQLクライアントでdumpし、**さらに別の空MySQL DB**へ復元する。全行・ID・金額・定義を比較する。構造作成だけを「データの復元テスト成功」と呼ばない。

```sh
# 検証専用DBが既に空であることを確認してから、次回承認後に実行。
MYSQL_BIN --host=VERIFY_HOST --user=VERIFY_USER -p \
  --database=oshilife_deploy_verify < PRIVATE_DIR/deploy-schema.sql

# MySQLで完成した架空デモの基準dump（本番アクセスではない）。
MYSQL_DUMP_BIN --host=VERIFY_HOST --user=VERIFY_USER -p \
  --single-transaction --skip-events --skip-routines --triggers --hex-blob \
  --no-tablespaces --set-gtid-purged=OFF \
  oshilife_deploy_verify --result-file=PRIVATE_DIR/demo-baseline.sql

# 既存データのない第2の検証DBへ復元する。
MYSQL_BIN --host=VERIFY_HOST --user=VERIFY_USER -p \
  --database=oshilife_deploy_restore_verify < PRIVATE_DIR/demo-baseline.sql
```

`--set-gtid-purged`等はMySQLクライアント用で、MariaDBクライアントへ無条件に付けない。使用実体と対応オプションを先に確認する。dumpにUSE、DEFINER、MariaDB専用設定、生成列の明示値などがある場合は止めて確認し、エラーを無視するオプションは使わない。今回はこれらのコマンドを実行していない。

## 12. HTTPS・招待URL・session

- 公開URL確定後、APP_URLをHTTPSにする。サブフォルダー運用ならそのパスまで含める。
- APP_ENV=productionでSecure Cookie。HTTPS証明書・HTTP転送は別途サーバー側に必要。
- 招待URLはAPP_URL＋rooms/join.phpとtokenから生成。localhost直書きはない。
- login returnはセッションへ保存したtokenから固定パスを生成し、任意return_toを受け付けない。
- CSS／JS／APIは共通パス・同一オリジン。ブラウザーのmixed content、誤ったサブパス、404を確認する。
- inviteの7日・無効化・closed・確認後参加を別アカウントで試験する。古いtokenは移さない。
- CookieのSecure／HttpOnly／SameSite／pathを実応答で確認する。HTTP側ではCookieが送られないため必ずHTTPSへ転送する。
- storageは空から作り、PHP実行ユーザーに必要な読み書き権限だけ与える。ログやセッションをWebから取得できないことを確認する。

## 13. Chat負荷

少人数の提出デモでは現行の4秒short pollingを維持して検証する方針。常駐プロセスは不要。ただし「無条件に問題なし」とは保証しない。

表示中1画面で約15取得／分、2画面で約30取得／分が目安。通知API等は別に加算される。通信完了後に次回を予約し、非表示タブは停止する。after_idと最大100件で取得量を限定している。

A/Bの2画面、必要なら数人同時に操作し、遅延・500／503・DB接続エラー・429・セッションロックを確認する。投稿の20件／30秒制限はpollingの取得回数制限ではない。不要なタブを閉じる案内で十分か実測し、必要以上の最適化やWebSocket化はしない。

## 14. 資材の準備状況

| 資材 | 状態 |
|---|---|
| 現行アプリソースと31表schema | 準備済み（MySQL移行試験は未実施） |
| public／親.htaccess、共通URL生成 | 準備済み（配置先での防御確認は未実施） |
| 監査・配置方針・配備許可リスト・デモ設計・シナリオ | 本書と監査書で準備済み |
| 秘密なし本番.envテンプレート | 準備済み |
| アップロード用パッケージ | 未準備。最終コミットから許可リストで作成する |
| ローカル最終backup／今回のrestore結果 | 未準備。過去のbackupを今回の最終版とは扱わない |
| MySQL検証環境・移行SQL・復元結果 | 未準備 |
| デモ実データ・デモ基準dump | 未準備。今回は設計だけ |
| デモログイン情報 | 未準備。今回は秘密値を生成しない |
| 本番環境情報・本番.env実値・DB接続情報 | ユーザー確認・準備待ち |
| 公開URL・HTTPS・公開フォルダー確定 | ユーザー確認待ち |

## 15. デプロイ前チェックリスト

- [x] 現行HEAD・Git状態・31表を確認した。
- [x] コード変更不要の配置方針と配備対象を整理した。
- [x] 現行コードが参照する.envテンプレートを用意した。
- [x] 合成デモ2アカウントと操作シナリオ、復旧方針を設計した。
- [ ] 本書・テンプレート・監査書を確認し、最終commitとGit cleanを確定した。
- [ ] プラン、PHP版・拡張、MySQL実版、DB接続情報を確認した。
- [ ] 正規URL、公開フォルダー、HTTPS、許可された転送／DB管理手段を確定した。
- [ ] ローカル最終backupと別DB復元比較を完了した。
- [ ] 本番同等MySQLで31表・制約・生成列・合成データ復元を検証した。
- [ ] デモ実データを作成し、5〜10分の操作が成立した。
- [ ] デモ基準backupと復元・初期化の担当／手順を確定した。
- [ ] 本番専用.env実値を安全に用意した（Git・公開領域に露出させない）。
- [ ] 許可リストの配備パッケージに秘密・ログ・SQL・テストがないと確認した。
- [ ] 本番に既存DBがある場合のbackupとロールバックを用意した。
- [ ] ユーザーから実際の本番作業の承認を得た。

## 16. 実際のデプロイ手順（まだ開始しない）

1. 上のチェックリストを完了し、作業対象コミット・提出専用DB・公開先を確定する。
2. 承認された本番管理手段で提出用DBを用意。既存DBがある場合は先にbackupを取る。
3. 検証済みの構造＋合成デモを空の提出用DBへ投入。全migrationを重ねない。
4. 許可リストのアプリを親子構造を保って配置し、本番専用.envと空storageを用意する。
5. PHP版・拡張・エラー非表示・所有者と権限・HTTPS・公開フォルダーを設定する。
6. 初期ドメインを含む全経路で非公開ファイル取得を拒否できることを確認する。
7. ログイン・Cookie・CSRF・静的ファイル・APIを確認。A/Bで本人／ルーム権限を検証する。
8. 予定取り込み、イベント・TODO・遠征、個人会計、招待、Chat、共同支出、精算、本人反映を確認する。
9. PC／スマホ・少人数同時利用を確認し、デモの基準状態をbackupする。
10. 合格後に審査者へURLと必要な専用資格情報・操作案内を渡す。

失敗時は公開案内と書き込みを止め、失敗時点のデータも保護する。既存DBへ古いdumpを無条件に上書きせず、別DBで復元比較してから切り替える。ローカルのOshilife v2、旧Oshilife、XAMPP設定は変更しない。

## 17. このStepの完了範囲

作成したものは本書と`.env.production.example`のみ。前回の未追跡監査書を保持し、機能コード・.env実値・DB・migration・デモデータを変更していない。

**次に必要なのは環境確認票の回答と、別途承認後のMySQL検証・デモ実データ作成。本番デプロイを開始してよい状態ではまだない。**
