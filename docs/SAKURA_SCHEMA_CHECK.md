# さくら向け schema-only SQL 確認書

作成日：2026-10-04。対象HEAD：`58d71f5`（仕様書棚卸し）。

> 本書はSQL作成時点の検査記録。2026-10-05にユーザーからMySQL 8.0.45へimportし、31テーブルを確認したと報告を受けた。本番DBへの接続・制約・アプリ動作はCodex側では未確認。

**構造SQLの準備・静的確認は完了。MySQL 8.0への実importは未検証であり、本番適用の最終合格ではない。** 今回はローカルDBのメタデータを読み取っただけで、本番接続、DB更新、migration、実データ抽出、アプリ変更、Git commit/pushは行っていない。

成果物：[deploy/schema_mysql8.sql](../deploy/schema_mysql8.sql)。空の選択済みDB専用。ユーザーデータを移すバックアップではなく、空の31テーブルを構築するDDL（構造定義）である。

## 1. 開始状態・作成方法

- ローカル：XAMPP MariaDB `10.4.28-MariaDB`、`oshilife_v2`。実テーブル31。
- 開始時の追跡済み差分なし。既存未追跡の `.env.production.example`、`docs/SAKURA_DEPLOY_AUDIT.md`、`docs/SAKURA_DEPLOY_PREP.md` はそのまま保持。
- 確認資料：[監査](SAKURA_DEPLOY_AUDIT.md)、[準備](SAKURA_DEPLOY_PREP.md)、`database/schema.sql`、全15 migration（002〜016）。
- PDOでSHOW FULL TABLES / SHOW TABLES、SHOW CREATE TABLEを取得。テーブルの行をSELECTしておらず、氏名・メール・金額・Chat・招待トークン等の実値は出力していない。
- migration管理テーブルはない。適用履歴を推測して実行せず、連番SQLの構造変更と現在の定義を照合した。

| 方法 | 評価 |
|---|---|
| A：mysqldump --no-data | 定義抽出には使えるが、既定のDROP、採番位置、dump固有の設定等を除去する必要がある。利用するならskip-eventsも必要 |
| B：migration再構成 | 001がなく、途中の改名・データ変換・旧名称がある。単純連結は不適切 |
| **C：SHOW CREATE TABLEを基準に生成（採用）** | 実際の列・生成列・自動補完された索引・現在の制約を保持できる。変更履歴との照合を併用 |

## 2. migration照合

| ファイル | 現在の構造との対応 |
|---|---|
| 002_oshi_management.sql | oshis / members / user_oshis |
| 003_schedules.sql | schedules / schedule_members / schedule_sources / user_schedules |
| 004_feedback.sql | correction_requests / reactions |
| 005_notifications.sql | notifications。履歴投入SQLは今回含めない |
| 006_live_management.sql | venues、現在のevents / user_event_status、trips / transportations / accommodations / todos |
| 007_money_management.sql | savings / expenses。room_expense_id互換列を保持 |
| 008_payment_import.sql | チケット・交通・ホテルの支払列、expense参照、一意制約。データ更新は含めない |
| 009_event_management.sql | live系テーブル・列・索引・FKの改名、event_typeとCHECK |
| 010_event_participation.sql | 受付・参加・販売区分・legacy列、lottery_status既定値変更、5 CHECK |
| 011_event_participants.sql | 個人同行者、本人一意制約、管理範囲の複合FK |
| 012_rooms.sql | rooms / room_members / room_invitations / room_events、2生成列 |
| 013_room_invite_links.sql | 招待リンク、ascii_binのハッシュ列 |
| 014_room_expenses.sql | room_expenses / room_expense_members、範囲・金額CHECK |
| 015_room_settlements.sql | room_settlements、精算状態CHECK |
| 016_room_messages.sql | room_messages、状態・文字数CHECK |

users / user_settingsは初期schema由来。002〜016を順に読んだ結果、構造変更の到達点は実DBの31表と対応している。統合schemaとの機械比較でも、31テーブル名・79制約名（FK54＋CHECK25）・29 UNIQUE名に不足・余分なし。全migrationを検証DBへ再実行した比較ではない。

表記上の差は、MariaDBの整数表示幅、NULL既定値、CURRENT_TIMESTAMPの括弧、RESTRICTの省略、FK用に自動補完された索引、統合schemaの列順（events等）。今回の出力は実DBの列順・索引を正とした。構造の整理や既存列の削除はしない。

`database/schema.sql`は先頭のStep表記が古く、固定USEもあるが、内容は31表まで含む。旧ファイルは変更せず、本番では今回のSQLを使う。今回のSQLに002〜016を重ねて適用してはいけない。

## 3. 31テーブル・キー一覧

PK＝行を識別する主キー。UNIQUE＝重複を防ぐ一意キー。FK＝参照先の存在を保証する外部キー。下表は実DBの全PK・全UNIQUE・全FKを示す。「—」相当の空欄は該当なし。

| テーブル | PK | UNIQUE（名前・列） | FK（列 → 親の列） |
|---|---|---|---|
| `users` | `id` | `users_email_unique` (`email`) |  |
| `user_settings` | `id` | `user_settings_user_unique` (`user_id`) | (`user_id`) → `users` (`id`) ON DELETE CASCADE |
| `oshis` | `id` | `oshis_name_type_unique` (`name`,`oshi_type`) | (`created_by_user_id`) → `users` (`id`) ON DELETE SET NULL |
| `members` | `id` | `members_oshi_name_unique` (`oshi_id`,`name`) | (`oshi_id`) → `oshis` (`id`) |
| `user_oshis` | `id` | `user_oshis_user_oshi_unique` (`user_id`,`oshi_id`) | (`oshi_id`) → `oshis` (`id`)<br>(`user_id`) → `users` (`id`) ON DELETE CASCADE |
| `schedules` | `id` |  | (`oshi_id`) → `oshis` (`id`)<br>(`created_by_user_id`) → `users` (`id`) |
| `schedule_members` | `id` | `schedule_members_unique` (`schedule_id`,`member_id`) | (`member_id`) → `members` (`id`)<br>(`schedule_id`) → `schedules` (`id`) ON DELETE CASCADE |
| `schedule_sources` | `id` |  | (`schedule_id`) → `schedules` (`id`) ON DELETE CASCADE |
| `user_schedules` | `id` | `user_schedules_unique` (`user_id`,`schedule_id`) | (`schedule_id`) → `schedules` (`id`)<br>(`user_id`) → `users` (`id`) ON DELETE CASCADE |
| `correction_requests` | `id` |  | (`schedule_id`) → `schedules` (`id`)<br>(`requested_by_user_id`) → `users` (`id`) |
| `reactions` | `id` | `reactions_unique` (`schedule_id`,`user_id`,`reaction_type`) | (`schedule_id`) → `schedules` (`id`)<br>(`user_id`) → `users` (`id`) ON DELETE CASCADE |
| `notifications` | `id` | `notifications_event` (`recipient_user_id`,`event_key`) | (`actor_user_id`) → `users` (`id`) ON DELETE CASCADE<br>(`correction_id`) → `correction_requests` (`id`) ON DELETE CASCADE<br>(`recipient_user_id`) → `users` (`id`) ON DELETE CASCADE<br>(`schedule_id`) → `schedules` (`id`) ON DELETE CASCADE |
| `venues` | `id` | `venues_identity` (`name`,`prefecture`,`address`) |  |
| `events` | `id` | `event_schedule_unique` (`schedule_id`) | (`schedule_id`) → `schedules` (`id`)<br>(`venue_id`) → `venues` (`id`) |
| `user_event_status` | `id` | `user_event_unique` (`user_id`,`event_id`)<br>`ticket_expense_once` (`ticket_expense_id`) | (`ticket_expense_id`) → `expenses` (`id`) ON DELETE SET NULL<br>(`event_id`) → `events` (`id`)<br>(`user_id`) → `users` (`id`) ON DELETE CASCADE |
| `trips` | `id` | `trips_owner_identity` (`id`,`user_id`,`event_id`)<br>`trips_user_event` (`user_id`,`event_id`) | (`user_id`, `event_id`) → `user_event_status` (`user_id`, `event_id`) |
| `transportations` | `id` | `transportation_expense_once` (`expense_id`) | (`trip_id`) → `trips` (`id`) ON DELETE CASCADE<br>(`expense_id`) → `expenses` (`id`) ON DELETE SET NULL |
| `accommodations` | `id` | `accommodation_expense_once` (`expense_id`) | (`expense_id`) → `expenses` (`id`) ON DELETE SET NULL<br>(`trip_id`) → `trips` (`id`) ON DELETE CASCADE |
| `todos` | `id` | `todo_template_once` (`user_id`,`event_id`,`template_key`) | (`user_id`, `event_id`) → `user_event_status` (`user_id`, `event_id`)<br>(`trip_id`, `user_id`, `event_id`) → `trips` (`id`, `user_id`, `event_id`) |
| `savings` | `id` |  | (`oshi_id`) → `oshis` (`id`)<br>(`user_id`) → `users` (`id`) ON DELETE CASCADE |
| `expenses` | `id` | `expense_source_once` (`user_id`,`source_type`,`source_id`) | (`event_id`) → `events` (`id`)<br>(`oshi_id`) → `oshis` (`id`)<br>(`trip_id`) → `trips` (`id`)<br>(`user_id`) → `users` (`id`) ON DELETE CASCADE |
| `event_participants` | `id` | `participant_scope` (`id`,`user_id`,`event_id`)<br>`participant_self_once` (`user_id`,`event_id`,`self_marker`) | (`user_id`, `event_id`) → `user_event_status` (`user_id`, `event_id`) |
| `rooms` | `id` | `room_owner_pair` (`id`,`owner_user_id`) | (`owner_user_id`) → `users` (`id`) |
| `room_members` | `id` | `room_member_once` (`room_id`,`user_id`)<br>`room_single_owner` (`room_id`,`owner_marker`) | (`room_id`, `room_owner_user_id`) → `rooms` (`id`, `owner_user_id`)<br>(`user_id`) → `users` (`id`) |
| `room_invitations` | `id` | `room_invitation_pending_once` (`room_id`,`invited_user_id`,`pending_marker`) | (`room_id`, `inviter_user_id`) → `rooms` (`id`, `owner_user_id`)<br>(`invited_user_id`) → `users` (`id`) |
| `room_events` | `id` | `room_event_once` (`room_id`,`event_id`) | (`event_id`) → `events` (`id`)<br>(`room_id`) → `rooms` (`id`) |
| `room_invite_links` | `id` | `room_invite_token` (`token_hash`) | (`room_id`, `created_by_user_id`) → `rooms` (`id`, `owner_user_id`) |
| `room_expenses` | `id` | `room_expense_room_pair` (`id`,`room_id`) | (`room_id`, `created_by_user_id`) → `room_members` (`room_id`, `user_id`)<br>(`room_id`, `paid_by_user_id`) → `room_members` (`room_id`, `user_id`) |
| `room_expense_members` | `id` | `room_expense_member_once` (`room_expense_id`,`user_id`) | (`room_expense_id`, `room_id`) → `room_expenses` (`id`, `room_id`)<br>(`room_id`, `user_id`) → `room_members` (`room_id`, `user_id`) |
| `room_settlements` | `room_id` |  | (`room_id`, `settled_by_user_id`) → `room_members` (`room_id`, `user_id`)<br>(`room_id`) → `rooms` (`id`) |
| `room_messages` | `id` |  | (`room_id`, `user_id`) → `room_members` (`room_id`, `user_id`) |

確認集計：

- CREATE TABLE：31、列：287、PK：31。
- UNIQUE索引（PKを除く）：29、通常索引：56。PK含む索引総数：116。
- FK：54、CHECK：25、STORED生成列：2。
- AUTO_INCREMENT列：30。room_settlementsだけroom_idをPKとし、自動採番しない。
- 全FKの参照列に対応するPK／UNIQUEが存在し、列数・整数型・符号が一致。
- 全識別子はバッククォートで囲む。最長30文字で64文字以内。FK／CHECKの制約名に重複なし。

## 4. MySQL 8.0互換性と出力時の調整

対象は**MySQL 8.0.16以上**。それ以前はCHECKを同等に扱えない。[MySQL CHECK仕様](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html)

| 項目 | 対応・確認結果 |
|---|---|
| ENGINE | 全31表InnoDBを保持 |
| 文字コード | 全表utf8mb4 / utf8mb4_unicode_ci。0900系へ変更しない |
| ENUM | 予定公開範囲・状態・反応等の値と順序を保持 |
| CHECK | 25個保持。決定的な比較・IN・CASE相当の条件・CHAR_LENGTH。副問合せやAUTO_INCREMENT列参照なし |
| DATETIME / TIMESTAMP | 現行日時列はDATETIME。TIMESTAMP型へ置換しない。CURRENT_TIMESTAMP()とON UPDATEを保持 |
| DATE / TIME | 予定・支払日、開場／開始終了等の型・NULLを保持 |
| AUTO_INCREMENT | 列属性を保持。テーブルオプションの開発用次回番号だけ除去。空DBは通常1から採番 |
| 整数 | bigint(20)→bigint、int(10)→int、tinyint(3)/(4)→tinyint。非推奨の表示幅のみ除去し範囲・UNSIGNEDは維持。真偽用途のtinyint(1)は保持 |
| DECIMAL | 金額12,2、緯度経度10,7をそのまま保持。浮動小数点へ変更しない |
| NULL / DEFAULT | 必須・任意、文字列／数値既定値を保持。nullable TEXTのDEFAULT NULLはTEXT NULLへ同義表記に整理 |
| 生成列 | owner_marker / pending_markerのGENERATED ALWAYS AS … STOREDとUNIQUEを保持 |
| FK | 全54個を後段ALTERに移動。複合FKの列順、CASCADE / SET NULLを保持。元定義で省略されたRESTRICT相当をそのまま維持 |
| 名前 | 列・テーブル・制約の名称を変更しない。引用して予約語衝突を避ける |
| 実データ等 | データ文、DB作成／選択、DROP、DEFINER、MariaDB専用dumpコメント、イベント定義は含めない |

CHECKとFKの両方に使う列（room_members、room_invitations、room_settlements）には、CHECK対象値を書き換えるCASCADE / SET NULLを新設していない。既存の禁止条件・生成列・複合FKを削って通す対応はしない。**これらの組合せの受理と不正行拒否は、対象MySQL実版での検証が残る。** [MySQL外部キー仕様](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html)

今回の調整は構造SQL上の表記と実行順序だけ。MySQLで発生したエラーを修正したという意味ではなく、import自体は未実施である。

### 既存互換仕様

- events.event_typeの内部値sports＝画面の「舞台」を保持。6値を変更しない。
- expenses.room_expense_idは未使用でも保持。勝手にFKを追加しない。
- live_ticket等はアプリが扱うvarchar値であり、categoryをENUMに狭めない。
- source_type / source_idとexpense_source_onceを保持。反映元の二重登録防止を維持。
- room_invitations等の旧互換テーブル、legacy_lottery_status、oshis.theme_colorも保持。

## 5. 作成順序・charset

users等の基礎テーブルから列・PK・UNIQUE・索引・CHECKを作成し、**31表すべて作成後に54 FKを追加**する。expenses → trips → user_event_status → expensesという循環があるため、単純な親子順だけでは解消できない。

FOREIGN_KEY_CHECKSは1を明示し、0にはしない。親テーブルが揃った状態で制約を検査させる。設定を切って未検証の関係を残す必要はない。SQL全体はトランザクションで一括取り消しできないため、途中失敗は部分適用として扱う。

接続文字コードはSET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci。全表に同じcharset/collationを明示し、DB既定値へ依存させない。例外としてroom_invite_links.token_hashのascii / ascii_binを保持する。比較規則を変更するとメール・推し名等の一意判定に影響するため、現行照合順序を維持する。

## 6. 静的確認結果と限界

実施済み：

1. 実DBの31表とSQLのCREATE TABLE名が一致。
2. 全54 FKの参照先、対象列、型、参照先PK／UNIQUEを確認。
3. PK31、UNIQUE29、通常索引56、CHECK25、生成列2を保持。
4. 制約名重複なし、識別子長上限以内。
5. 87文＝SET2＋CREATE31＋ALTER54だけ。データ操作文・削除文・固定USEなし。
6. 開発DBのAUTO_INCREMENT現在値なし。構造のAUTO_INCREMENT属性あり。
7. 統合schemaのテーブル・制約・UNIQUE名と一致。
8. 既存追跡ファイルの変更なし。追加は指定のSQLと本書だけ。

未実施：MySQL 8.0による構文解析・import・制約違反テスト・アプリ回帰。本番／ローカルDBにこのSQLは実行していない。静的確認は実行成功の保証ではない。

## 7. import前後の手順（今回は未実施）

1. さくらの実MySQLバージョンが8.0.16以上であること、DB名・接続先・権限を確認。
2. 対象DBが新規空DBであることを確認。既存テーブル・ビューがあれば停止。本SQLはIF NOT EXISTSを使わず、既存表を黙って流用しない。
3. 同じMySQL版の検証用DBで全87文と制約動作を確認する。別途許可を得て行う。
4. CREATE、ALTER、REFERENCES等の必要権限、SQLモード、文字コードを確認。
5. 本番適用が許可された段階で対象DBを管理ツール側で選択し、UTF-8のSQLを一度だけimportする。mysql CLIを使う場合も--force等のエラー継続は使わない。
6. エラーが出たら停止し、文・エラー・作成済みオブジェクトを記録。部分作成状態に全SQLを再投入しない。勝手なDROPや初期化SQLは提供・実行しない。
7. 以下の確認SQLで構造・制約を確認。すべて空のため、ログイン用ユーザーやデモデータはこの作業では存在しない。データ準備は別工程。

既存データの移送やmigration再実行はこの手順に含めない。デプロイ全体の環境設定・HTTPS・公開領域等はSAKURA_DEPLOY_PREP.mdを併用する。

## 8. import後の確認SQL（今回は実行しない）

以下は接続先DBを管理ツール側で選択してから実行する読み取りSQL。import前にもVERSION/DATABASE/テーブル一覧は確認できる。

```sql
SELECT DATABASE() AS selected_database, VERSION() AS server_version,
       @@sql_mode AS sql_mode, @@foreign_key_checks AS foreign_key_checks;

-- BASE TABLEは31。空DB確認時は0。
SELECT COUNT(*) AS table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';

SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;

-- PK31、UNIQUE29、FOREIGN KEY54、CHECK25。CHECKはENFORCED=YES。
SELECT CONSTRAINT_TYPE, ENFORCED, COUNT(*) AS constraint_count
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
GROUP BY CONSTRAINT_TYPE, ENFORCED;

SELECT TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION, COLUMN_NAME,
       REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = DATABASE()
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME,
       UPDATE_RULE, DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

-- 1索引につき複数行あるため、そのままCOUNT(*)で索引数を数えない。
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
ORDER BY TABLE_NAME, INDEX_NAME;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
       EXTRA, GENERATION_EXPRESSION, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT CONSTRAINT_NAME, CHECK_CLAUSE
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
ORDER BY CONSTRAINT_NAME;

-- 循環する支払い連携と生成列・CHECK・複合FKの重点確認
SHOW CREATE TABLE expenses;
SHOW CREATE TABLE user_event_status;
SHOW CREATE TABLE trips;
SHOW CREATE TABLE room_members;
SHOW CREATE TABLE room_invitations;
SHOW CREATE TABLE room_settlements;
```

InnoDBのTABLE_ROWSは概算なのでデータ0件の厳密な証明には使わない。初回import直後、アプリが書き込む前に必要なら各表のCOUNT(*)を確認する。制約違反を実際に拒否するかの試験は読み取りSQLでは確定できないため、別途許可した検証環境で行う。

## 9. 判定

**schema-only成果物は準備済み。本番importはまだ実施せず、同等MySQLでの試験・対象DBと権限の確認を残す。** アプリ、既存schema/migration、既存データ、旧Oshilifeには変更を加えていない。
