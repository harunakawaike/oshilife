# Phase 3：スケジュール共有・カレンダー同期

保存先は `/Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife`。指定のVS Codeワークスペースを変更しました。旧Oshilifeや別DBは変更していません。

## 実装した画面と操作

- ホーム：実データの「今日の予定」「新着の公開予定」。推し切替と連動し、取り込み後はカードと件数を再取得します。
- カレンダー：月移動、今日に戻る、推し絞り込み、日付の予定件数、選択日の一覧と予定追加。
- 見つける：公開・有効な予定の検索。タイトルのキーワード、推し、カテゴリ、日付で絞り込み、20件ずつ追加表示します。
- 予定登録・編集：複数メンバー、終日、日時、公開範囲、情報元最大5件、メモ。情報元は公開時のみ表示し、入力を推奨します。
- 予定詳細：情報元リンク、作成者、更新日時、同期状態、所有者の編集・中止・削除、他ユーザーの追加・取り込み解除・自分用編集。

スマホでは1列と既存の固定下部ナビ、768px以上ではフォームと公開カードを2列、1200px以上では月間カレンダーと日別予定を横並び、公開カードを3列にします。配色と画面最大幅は既存の共通CSSを使います。カレンダーの日別カードは「💎🩷🖤 19:00 TV出演」の形式です。

## 主要ファイルの役割・新規ファイル

| ファイル | 役割 |
| --- | --- |
| `config/schedules.php` | カテゴリ・情報元・状態の内部値と日本語表示を一元管理 |
| `database/migrations/003_schedules.sql` | 既存データを消さず4テーブルを追加。現在のローカルDBは適用済み |
| `app/validators/schedule_validator.php` | 実在する日付、時刻の順序、URL、文字数、IDなどを検証 |
| `app/repositories/schedule_repository.php` | DBの検索・保存と、共有値／個人値の選択 |
| `app/services/schedule_service.php` | 所有者の確認、重複候補、まとめて保存、取り込み、同期解除 |
| `app/helpers/schedule_api.php` | API共通の認証・CSRF・入力取得・エラー応答 |
| `api/schedules/*.php` | 下表の12 APIの処理本体 |
| `public/api/schedules/*.php` | 同名の12 APIへの公開用入口。内部処理を読み込む短いファイル |
| `public/schedule_form.php` | 新規登録・所有者編集・自分用編集のフォーム |
| `public/schedule_detail.php` | 閲覧権限のある予定の詳細・操作ボタン |
| `includes/schedule_source_fields.php` | 情報元入力行の共通部品 |
| `public/assets/js/schedules.js` | 予定カード、短い表示、取り込みボタンを共通化 |
| `public/assets/js/calendar.js` | 月間表示、選択日、年月・推しの切替 |
| `public/assets/js/discover.js` | 公開検索と次ページ読み込み |
| `public/assets/js/schedule_form.js` | メンバー取得、情報元の行追加、入力送信、重複・同期解除確認 |
| `public/assets/js/schedule_detail.js` | 詳細画面の追加・解除・論理削除操作 |
| `public/assets/css/schedules.css` | 予定画面のレスポンシブ表示 |
| `scripts/create_demo_users.php` | ローカル専用A/Bアカウント作成CLI。ランダムな認証情報を非公開保存 |
| `tests/phase3_smoke.py` | 一時ユーザー2人によるHTTP・DB統合検証と限定した後片付け |
| `docs/PHASE3.md` | 今回の実装・学習・確認手順（この文書） |

変更ファイルは `.gitignore`（認証情報を除外）、`database/schema.sql`（新規環境向け4テーブル追加）、`includes/header.php`（追加CSS）、`public/home.php`・`calendar.php`・`discover.php`（実データ化）、`public/assets/js/home.js`（ホーム読み込み）、`public/assets/js/common.js`（重複候補のエラー情報）、`README.md`（Phase 3の概要・役割・処理フロー）です。

## DBテーブル・INDEX・UNIQUE

| テーブル | 役割 | 主な制約・検索用INDEX |
| --- | --- | --- |
| `schedules` | 作成者が管理する共有元。非公開予定も保存 | `(created_by_user_id,schedule_date)`、`(visibility,status,schedule_date)`、`(oshi_id,schedule_date,category)` |
| `schedule_members` | 予定と複数メンバーを結ぶ中間テーブル | UNIQUE `(schedule_id,member_id)`、INDEX `(member_id)` |
| `schedule_sources` | 予定の複数情報元 | INDEX `(schedule_id)` |
| `user_schedules` | 誰がどの予定を取り込んだか、個人編集値 | UNIQUE `(user_id,schedule_id)`、INDEX `(schedule_id)`、`(user_id,sync_enabled,custom_date)` |

全テーブルにid主キーと外部キーがあります。外部キーは存在しない親を参照させない仕組みです。共有元の物理削除は取り込み参照がある限りDBでも禁止します。アプリの削除操作は `status=deleted` へ変える「論理削除」です。既存5テーブルは変更していません。

`user_schedules` に `custom_is_all_day` も追加しました。「自分用に終日へ変更したのに元の時間が戻る」ことを防ぐためです。

## API一覧

公開URLはすべて `APP_URL + /api/schedules/ファイル名`。一覧は既存のJSON形式に合わせ `{"success":true,"data":{"schedules":[]}}`、詳細は `data.schedule`、検索はさらに `total/page/has_more` を返します。

| メソッド | ファイル | 入力・動作 |
| --- | --- | --- |
| GET | `calendar.php` | year、month、oshi_id=allまたはID。本人の月間予定 |
| GET | `list.php` | date（省略時は日本時間の今日）、oshi_id。本人の日別予定 |
| GET | `detail.php` | id。閲覧できる予定の詳細 |
| GET | `public.php` | q、oshi_id、category、date、page。公開・active |
| GET | `unadded.php` | 同上。自分の推し・他人の公開予定・未追加のみ |
| POST | `create.php` | title、schedule_date、oshi_id、category、visibility、日時・終日・メモ・member_ids・sources。作成 |
| POST | `update.php` | 作成と同じ項目＋schedule_id。所有者のみ共有元を編集 |
| POST | `delete.php` | schedule_id。所有者のみ論理削除 |
| POST | `add-to-calendar.php` | schedule_id。公開・activeを本人へ取り込む |
| POST | `remove-from-calendar.php` | schedule_id。本人の取り込み行のみ削除 |
| POST | `customize.php` | schedule_id、title、schedule_date、start_time、end_time、is_all_day、note。同期解除 |
| GET | `duplicate-check.php` | title、oshi_id、category、schedule_date、任意の時刻。公開予定の重複候補 |

POSTはJSONと `X-CSRF-Token` が必要です。本人IDはセッションから取得し、送られたuser_idを信用しません。未ログイン401、CSRF不正419、入力不正422、見られない予定404、重複409です。createは201。重複警告の候補は応答の `duplicates` に入り、確認後の再送に `allow_duplicate:true` を付けると作成できます。

## 今回追加したコードを理解するための解説

### ページからDBまで

PHPページが認証middleware（共通のログイン検査）を実行し、HTMLと共通JavaScriptを返します。JavaScriptがAPI（画面から呼べるPHPの受付）へリクエストし、入力検証→service（処理の手順）→repository（SQLの実行）→MySQLの順に進みます。結果はJSON（データを文字で表す形式）となって戻り、JavaScriptがカードやカレンダーへ反映します。画面とAPIを分けることで、検索や追加のたびにページ全体を開き直さずに済みます。

ログイン時はメールアドレスでユーザーを検索し、`password_verify` が入力パスワードとDBのハッシュを照合します。成功後に `session_regenerate_id` で照合用IDを替え、サーバーの `$_SESSION` にuser_idを保存します。セッションは「このブラウザでログインした本人」を覚える仕組みです。予定APIも同じセッションを使うので、フォームから他人のIDを送っても他人として保存できません。

### 共有と同期

`schedules` は予定本体、`user_schedules` は取り込み関係です。同じ内容をBさん用に複製すると、Aさんの修正がコピーに届きません。IDだけを結び、`sync_enabled=1` なら取得時に元の値を読む構造にしました。Aさんが更新すると、Bさんは次の画面表示・再読み込みで最新値を見られます。開いたままの画面へ即時配信する仕組みではありません。

`JOIN` は関連するテーブルを結んで一緒に読むSQLです。`LEFT JOIN` は取り込み行がない予定も残せます。`schedule_members` は「1予定に複数メンバー、1メンバーに複数予定」を結ぶ中間テーブルです。推し名・メンバー名を予定ごとにコピーしません。

### 自分用編集と公開範囲

確認ダイアログの後に `sync_enabled=0` としてcustom_*を保存します。以後、タイトル・日付・開始終了時刻・終日・メモはその個人値を使い、空メモや未定の時間もそのまま維持します。元schedule_idは残り、詳細で共有元を確認できます。カテゴリ・対象メンバー・情報元・中止／削除状態は共有元を参照します。

中止はカレンダーに残して「中止」と表示。削除済みの共有元も取り込み済みユーザーには警告付きで残し、取り込みを解除できます。公開から非公開に変えた場合は、取り込み済みでも他人へ内容を返しません。関係の行は残しますがカレンダーから非表示にします。再公開すると再び表示されます。

### 新着と重複候補

ホーム新着は「publicかつactive」「本人が登録した推し」「本人が作った予定ではない」「本人の取り込みがない」の全条件で選びます。`NOT EXISTS` は「該当する登録行が存在しない」を調べます。追加成功後はカードを消し、APIから件数を取り直します。「見つける」は追加済みも表示します。

重複警告は同じ推し・日付・カテゴリの公開active予定を最大200件比較し、開始時刻が両方ある場合は差60分以内、タイトルは記号・空白除去後の一致／部分一致／2文字の組の一致率50%以上で候補とします。最大5件を提示し、強制拒否せず「このまま登録する」を選べます。時間未定は時刻で除外しません。

### 安全に保存する工夫

入力をSQLへ直接埋め込まずPDOのprepareとexecuteで別送します。保存はトランザクション（全部成功したときだけ確定）で本体・メンバー・情報元をまとめます。元予定の更新時には行をロックし、公開範囲チェックと操作の途中で食い違うことを防ぎます。

PHP表示はhtmlspecialcharsの共通関数 `e()`、JavaScriptはtextContentを使い、入力されたHTMLを実行しません。情報元URLはhttp/httpsのみを許可し、サーバー側で取得しません。所有者と非公開閲覧はボタンだけでなくPHP・SQLでも検査します。DB接続など環境固有の設定は非公開の `.env` で管理します。

### 次に編集する場所

カテゴリや情報元を増やすなら `config/schedules.php`、入力ルールはvalidator、保存・認可のルールはservice、検索条件はrepository、見た目は各ページと `schedules.css`、画面の動きは対応するJSです。変更後はPhase 3テストで同期と閲覧制御を確認します。

## ブラウザでA/Bを切り替える確認手順

確認用2ユーザーは作成済みです。メール・パスワードは **`storage/demo-accounts.txt` をVS Codeで開いて**確認してください。GitとWeb公開の対象外です。スクリプトは再実行しても増殖させません。固定認証情報はコードへ埋め込んでいません。

1. XAMPPのApache・MySQLを起動し、`http://localhost/oshilife-v2/public/` へアクセス。
2. Aでログイン。推し管理で対象の推しを自分へ追加。カレンダー→予定追加で今日19:00の「同期確認 TV出演」をpublicで作成。メンバーを複数選択し、情報元も入力。
3. Aで非公開予定も1件作り、その詳細URLを控える。マイページからログアウト。
4. Bでログイン。同じ推しを自分へ追加。ホーム新着に公開予定だけ出ること、非公開の詳細URLは404になることを確認。
5. Bがホームから公開予定を追加。カードが消えて件数が減り、今日の予定とカレンダーへ表示されることを確認。「見つける」には追加済みで残ることも確認。
6. Bをログアウト→Aでログインし、公開予定の開始を20:00へ変更→ログアウト。
7. Bでログインし、20:00へ同期したことを確認。詳細→自分用に編集→日付・タイトルを変更し、確認ダイアログに同意。
8. Aへ切り替えて共有元を再変更。Bへ戻り、個人の日時・タイトルが維持され、共有元の内容も詳細で確認できることを確認。
9. Aで中止にする→Bで「中止」を確認。Aで削除→Bで削除済みの警告を確認。Bで「カレンダーから外す」。
10. Aで似た公開予定を2件作り、警告・既存予定リンク・確認後の登録を試す。
11. ブラウザ幅390px／768px／1440pxで、下部／上部ナビ、月移動、日付選択、長いタイトル・情報元URL、フォームとカードの折返しを確認。

## 検証結果と残る確認

2026-09-28、実際のXAMPP ApacheとMySQLで `python3 tests/phase3_smoke.py` の108 HTTP検証に合格。登録・公開非公開・複数メンバー・取り込み・二重追加防止・新着除外・同期・個人編集・日付移動・空値保持・中止・論理削除・非公開への変更・CSRF・不正入力・所有者認可・画面とCSS/JSの取得を確認しました。テスト前後の9テーブル件数は一致し、一時データは清掃済みです。

既存Phase 2の115 HTTP検証、CSVの検証・投入・再投入・競合テストも合格。PHP 92ファイルとJavaScript 9ファイルの構文検査、絵文字・ハート・時刻の表示文字列検証も通過しています。

既存のユーザー1件・設定1件・推し1件・メンバー4件・推し登録1件を維持した後、手動確認用ユーザーA/Bだけを追加しました。

**Chrome操作が許可されなかったため、ブラウザでのJavaScript操作とスマホ／PCの描画確認は未実施です。** HTML・アセットのHTTP取得、PHP/JSの構文検査と、APIからの動作確認は実施済みです。ブラウザで確認済みとは扱わず、上の手順で最終確認が必要です。

Phase 4前には、実ブラウザの操作・表示、公開から非公開にした際の挙動、同期解除後も中止状態は伝える設計を確認してください。日時は日本時間、日またぎは日別登録、タイトル150文字・メモ3000文字・情報元5件、API本文は既存の16KiB制限です。卒制を超える大量データではカレンダーの取得件数と検索性能も測定してください。

## 今回の範囲外

修正提案、助かった！、ありがとう！、イベント管理、遠征、お金管理、連番ルーム、Chatは未実装です。ホームの関連する表示例は引き続きダミーと明記しています。通知・リアルタイム配信・同期再開専用ボタンも今回追加していません（取り込み解除→再追加で共有元へ戻せます）。

## 2026-09-30：カレンダーに予定が表示されない不具合の修正

画面は9月を `month=09` と送っていましたが、APIがID用の整数検証を流用していたため、先頭に0のある月を422エラーとして拒否していました。ホームの日別APIは別の入力形式なので表示できていました。

`api/schedules/calendar.php` で月専用の形式検証を行い、1〜12と01〜09の両方を受け付けます。公開範囲の条件は変更していません。`calendar.js` も取得失敗時は0件ではなく読み込み失敗を表示し、日付選択後もエラーと空一覧を区別します。

修正前に実際のHTTPリクエスト `year=2026&month=09` で不具合を再現。修正後、1〜12月のゼロ埋め有無、本人の非公開・終日予定、他人には非公開、無効な月の拒否を含む152件のPhase 3 HTTP検証に合格しました。テスト専用データは清掃済みで、既存データは維持しています。予定の再登録は不要です。
