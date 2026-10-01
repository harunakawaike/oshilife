# イベント名称統一 Step 1：実装・学習・検証報告

## バックアップと復元

対象は `/Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife`。READMEのOshilife v2、Phase 1〜6の実装、`.env`のDB_NAME=oshilife_v2を照合した。旧Oshilife、my.cnf、Event Scheduler、MariaDBシステムテーブルは変更していない。

使用実体は `/Applications/XAMPP/xamppfiles/bin/mysqldump`。`mariadb-dump`も同じ実体へのリンク。

```sh
/Applications/XAMPP/xamppfiles/bin/mysqldump \
  --protocol=TCP --host=127.0.0.1 --user=root \
  --single-transaction --skip-events --skip-routines --triggers --hex-blob \
  oshilife_v2 \
  --result-file=/private/tmp/oshilife-v2-before-event-step1-20261001-225527-valid.sql
```

- 終了コード0、37,917バイト、CREATE TABLE 21個、INSERT INTO 19個。
- users、schedules、live_events、user_live_status、trips、todos、expensesを含む全21テーブルを確認。
- Event Scheduler定義は明示的に除外。アプリコード・schemaにstored routine利用はなく、routineも除外。
- triggerを含める指定で取得し、実DBのtriggerが0件であることを確認。
- `--databases`を指定しないため、SQLに元DBを選択するUSEやCREATE DATABASEはない。
- 新規DB `oshilife_v2_backup_test_20261001_225527` へ復元成功。
- 復元直後、全行・主要ID・全テーブルのSHOW CREATE TABLEが元DBと完全一致。外部キー・INDEX・UNIQUEを含む。
- 検証結果：`/private/tmp/oshilife-v2-backup-verification-20261001-225527.json`。バックアップと検証結果は所有者のみ読み書き可能。
- 対象としたアプリデータ・テーブル構造について復元可能と確認。失敗した旧dumpは使用していない。

検証DBはその後migration試行に使用した。索引改名構文がこの環境で拒否されたため、同じ列・一意性での索引再定義へ修正。別の新規DB `oshilife_v2_event_rehearsal_20261001_225527` でもバックアップ復元＋修正済みmigrationの最初からの実行が成功した。両DBとも削除していない。XAMPP全体の修復は行っていない。

## 移行結果

新規migrationは `database/migrations/009_event_management.sql`。006〜008は未変更。検証後にoshilife_v2へ適用済み。再実行しない。

| 移行前 | 移行後 | 行数 | 維持した主要ID |
|---|---|---:|---|
| live_events | events | 1 → 1 | 7 |
| user_live_status | user_event_status | 2 → 2 | 32, 34 |
| trips | trips | 1 → 1 | 5 |
| todos | todos | 16 → 16 | 132〜139、375〜382 |
| expenses | expenses | 3 → 3 | 12, 13, 29 |

本人管理・trips・todos・expensesの `live_event_id` を `event_id` へ変更した。名称差を正規化して移行前後の全21テーブルの全行ハッシュを比較し、既存の値・ユーザーID・関連先・金額・source_type/source_id・日時が同一であることを確認した。

`events.event_type` はVARCHAR(30)、NOT NULL、DEFAULT 'live'。6種類に制限するCHECKを追加した。既存行はDEFAULTでliveになり、既存updated_atは変わらない。新規登録もDBのデフォルトでliveになる。種類を選ぶUIや新たな受付方式は未実装。

外部キー38本を維持。名前だけを変更したものも含め、列順・参照先・ON DELETE/UPDATEの規則を比較。INDEXの列順・一意性・種類も移行前と一致した。

- events.schedule_idのUNIQUEを維持。
- user_event_statusの(user_id,event_id)のUNIQUEを維持。
- tripsの(user_id,event_id)、(id,user_id,event_id)のUNIQUEを維持。
- todosの(user_id,event_id,template_key)のUNIQUEを維持。
- todosから本人管理・遠征への複合外部キーを維持。
- expensesの(user_id,source_type,source_id)のUNIQUEを維持。
- 各支払い元から支出へのUNIQUEとON DELETE SET NULLを維持。

## 主要ファイルと処理の読み方

画面は `events.php` / `event_detail.php` / `event_form.php`。`events.js` がAPIへ送信し、`event_api.php` が認証・CSRF・経路を確認する。`event_validator.php` が入力を検証し、`event_service.php` が保存順序を制御する。`event_repository.php` はDBを検索する場所。`event_view.php` は入力欄とTODOの共通表示を担当する。お金管理もこの共通部品とevents.cssを使用する。

セッションはログイン本人をサーバー側で識別する仕組みで、今回変更していない。APIはJavaScriptがJSON形式で情報を受け渡しするPHPの入口を指す。SQLへの値はプリペアドステートメントで渡し、本人IDは入力値ではなくセッションから取得する。

タイトル・推し・日時はschedulesに一元化したまま。event_typeとschedules.categoryは別概念。関係図・登録と支払いの処理フローはREADMEのStep 1章を参照。

## API・旧URL互換

`api/events/` と `public/api/events/` の13入口を整備した。

| GET | POST |
|---|---|
| list.php / detail.php / duplicate-check.php | create.php / update.php |
| status.php / next.php / todos/list.php | status/update.php |
| | todos/create.php / update.php / toggle.php / delete.php |

新APIはevent_id、event、events、event_status、user_event_status_idという名称を使用する。遠征・お金・予定APIの参照も追従した。trips・money・venuesのURLは維持。

旧 `/api/lives/` は削除せず、`legacy_event_api.php` で入出力キーだけを変換して同じevents処理へ渡す。POSTをリダイレクトしないため、本文とCSRFを保持する。旧live_event_idを受け付け、旧live/lives等のJSONキーで応答する。認証・保存ロジックは二重化していない。

旧live.php / live_detail.php / live_form.phpはクエリのID等を保持し302で新画面へ移動する。現在のJavaScript・HTMLリンクは新URLを使用する。新着APIのkind=liveも互換で受け付け、新画面はkind=eventを使用する。

ナビはホーム／カレンダー／見つける／イベント／マイページ。開始時刻は「開始」と表示する。ホームは「次のイベント」「新着のイベント情報」、お金管理は「関連イベント」「チケット・入場料」。LIVE種類ラベルを共通設定からカードへ表示する。

## 支払い・TODO・既存仕様の維持

申込・当落の値、落選をホームの次のイベントから除外する条件、当選＋移動区分でのTODO生成、削除済みテンプレートの再生成防止を維持した。近場／遠征、予約・支払いの分離も同じ。

チケット反映のsource_idはuser_event_status.idのまま。event_idではない。live_ticketの保存値、特効対象判定、金額計算は変更していない。入金TODO欄の反映ボタンと、反映後は登録済み支出を編集する導線を維持した。

## 回帰検証

- Phase 5：166 HTTP確認。新API、旧API互換、旧ページ移動、作成編集、申込当落、当選TODO、遠征、カレンダー同期、修正提案、他ユーザー拒否、落選・中止・終了のホーム条件を検証。
- Phase 6：167 HTTP確認。支出・積立・年間残高・推し別集計・特効換算・交通ホテル連携を検証。21テーブルの元データ不変。
- 支払い連携：151 HTTP確認。チケット・交通・ホテル、支払い取消、二重登録409、所有者チェック、金額変更時の案内、反映済み支出へのリンクを検証。21テーブルの元データ不変。
- JavaScript：イベント・カレンダー・新着分類・お金管理・リアクション・通知のDOMモデル検証。
- Chrome：一時検証ユーザーでPC 1440px／スマホ390px。イベント一覧・詳細・ホーム・遠征・お金管理の10画面。横はみ出しなし、JS例外なし、リソースエラーなし。画像も確認。
- PHP 252ファイルの構文チェック、変更差分の空白チェック：合格。
- 既存本人管理2件の読み取り確認：イベント・TODO・遠征・チケット反映元の取得成功。
- テスト完了後も全21テーブルの既存行ハッシュが移行前と一致。

HTTPテストは専用の一時ユーザーのみを作成・清掃する。既存ユーザーのパスワードやデータを変更しない。ブラウザ確認は `OSHILIFE_BROWSER_TEST=1 python3 tests/phase5_smoke.py`。通常の `python3 tests/phase5_smoke.py` はブラウザを起動しない。

## 意図して残したlive系名称と次Step

- event_typeのlive：LIVEという正規の種類。
- schedules.categoryのlive：既存カレンダーカテゴリ。event_typeとは別。
- live_ticket：支出のカテゴリ・反映元保存値。重複防止のため維持。
- 旧lives API・旧ページ・旧キー：互換層のみ。
- kind=live：旧新着APIの互換値。
- 006等の適用済みmigration名・SQL：履歴なので変更しない。
- 特効の「ライブ演出」という説明：計算の意味を変えない。
- aria-live：画面読み上げ用のHTML属性で、ライブ管理とは無関係。
- 未使用の初期画面用live.css、過去の検証説明・テスト変数：実処理のDB参照ではない。

次Stepは受付方式・申込不要・抽選対象外・参加確定の設計と、それに応じたTODO／チケット反映条件の変更。今回は未実装。展覧会の期間・複数イベント遠征・連番ルーム・特効計算の変更も行っていない。

## 変更ファイル一覧

Mは更新、Dは旧実装から新ファイルへ移したもの、Aは追加。旧APIと旧画面の入口は互換用途で残す。

```text
M	README.md
M	api/lives/create.php
M	api/lives/detail.php
M	api/lives/duplicate-check.php
M	api/lives/list.php
M	api/lives/next.php
M	api/lives/status.php
M	api/lives/status/update.php
M	api/lives/todos/create.php
M	api/lives/todos/delete.php
M	api/lives/todos/list.php
M	api/lives/todos/toggle.php
M	api/lives/todos/update.php
M	api/lives/update.php
M	api/schedules/unadded.php
M	api/trips/accommodation/create.php
M	api/trips/accommodation/delete.php
M	api/trips/accommodation/update.php
M	api/trips/create.php
M	api/trips/detail.php
M	api/trips/transport/create.php
M	api/trips/transport/delete.php
M	api/trips/transport/update.php
M	api/trips/update.php
M	api/venues/create.php
M	api/venues/list.php
D	app/helpers/live_api.php
D	app/helpers/live_view.php
M	app/helpers/money_api.php
M	app/helpers/money_view.php
M	app/helpers/payment_view.php
D	app/repositories/live_repository.php
M	app/repositories/money_repository.php
M	app/repositories/schedule_repository.php
M	app/services/feedback_service.php
D	app/services/live_service.php
M	app/services/money_service.php
M	app/services/schedule_service.php
D	app/validators/live_validator.php
M	app/validators/money_validator.php
D	config/lives.php
M	config/money.php
M	database/schema.sql
M	docs/FILES.md
M	docs/IMPLEMENTATION.md
M	docs/PAYMENT_IMPORT.md
M	docs/PHASE2.md
M	docs/PHASE3.md
M	docs/PHASE4.md
M	docs/PHASE5.md
M	docs/PHASE6.md
M	includes/bottom_nav.php
M	public/api/lives/create.php
M	public/api/lives/detail.php
M	public/api/lives/duplicate-check.php
M	public/api/lives/list.php
M	public/api/lives/next.php
M	public/api/lives/status.php
M	public/api/lives/status/update.php
M	public/api/lives/todos/create.php
M	public/api/lives/todos/delete.php
M	public/api/lives/todos/list.php
M	public/api/lives/todos/toggle.php
M	public/api/lives/todos/update.php
M	public/api/lives/update.php
M	public/api/trips/accommodation/create.php
M	public/api/trips/accommodation/delete.php
M	public/api/trips/accommodation/update.php
M	public/api/trips/create.php
M	public/api/trips/detail.php
M	public/api/trips/transport/create.php
M	public/api/trips/transport/delete.php
M	public/api/trips/transport/update.php
M	public/api/trips/update.php
M	public/api/venues/create.php
M	public/api/venues/list.php
M	public/assets/css/home.css
M	public/assets/css/live.css
D	public/assets/css/lives.css
M	public/assets/css/money.css
M	public/assets/css/theme.css
M	public/assets/js/home.js
D	public/assets/js/lives.js
M	public/assets/js/schedules.js
M	public/expense_form.php
M	public/home.php
M	public/live.php
M	public/live_detail.php
M	public/live_form.php
M	public/money.php
M	public/saving_form.php
M	public/schedule_detail.php
M	public/schedule_form.php
M	public/travel_form.php
M	public/trip_detail.php
M	tests/home_feeds_ui.mjs
D	tests/lives_ui.mjs
M	tests/payment_import_smoke.py
M	tests/phase2_smoke.py
M	tests/phase5_smoke.py
M	tests/phase6_smoke.py
A	api/events/create.php
A	api/events/detail.php
A	api/events/duplicate-check.php
A	api/events/list.php
A	api/events/next.php
A	api/events/status.php
A	api/events/status/update.php
A	api/events/todos/create.php
A	api/events/todos/delete.php
A	api/events/todos/list.php
A	api/events/todos/toggle.php
A	api/events/todos/update.php
A	api/events/update.php
A	app/helpers/event_api.php
A	app/helpers/event_view.php
A	app/helpers/legacy_event_api.php
A	app/repositories/event_repository.php
A	app/services/event_service.php
A	app/validators/event_validator.php
A	config/events.php
A	database/migrations/009_event_management.sql
A	docs/EVENT_STEP1.md
A	public/api/events/create.php
A	public/api/events/detail.php
A	public/api/events/duplicate-check.php
A	public/api/events/list.php
A	public/api/events/next.php
A	public/api/events/status.php
A	public/api/events/status/update.php
A	public/api/events/todos/create.php
A	public/api/events/todos/delete.php
A	public/api/events/todos/list.php
A	public/api/events/todos/toggle.php
A	public/api/events/todos/update.php
A	public/api/events/update.php
A	public/assets/css/events.css
A	public/assets/js/events.js
A	public/event_detail.php
A	public/event_form.php
A	public/events.php
A	tests/event_existing_readonly.php
A	tests/events_browser.mjs
A	tests/events_ui.mjs
```
