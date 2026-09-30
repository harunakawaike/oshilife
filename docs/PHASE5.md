# Phase 5 実装ガイド：ライブ・当落・遠征

## 1. 新規作成ファイルと役割

保存先は `/Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife`。Apacheの `/Applications/XAMPP/xamppfiles/htdocs/oshilife-v2` は同じフォルダを指すシンボリックリンクです。旧アプリ・旧DBは変更していません。

| ファイル | 役割 |
| --- | --- |
| `database/migrations/006_live_management.sql` | 既存DBへライブ関連7テーブルを追加 |
| `config/lives.php` | 開催・申込・当落・移動・予約状態の日本語とTODOテンプレート |
| `app/repositories/live_repository.php` | 共有公演と本人の管理情報だけをDBから取得 |
| `app/validators/live_validator.php` | 必須入力、日付順序、金額、URL、交通手段を検査 |
| `app/services/live_service.php` | 公演保存、本人の当落、TODO生成、遠征と予約の保存 |
| `app/helpers/live_api.php` | APIの共通認証・CSRF確認・処理の振り分け |
| `app/helpers/live_view.php` | 画面のログイン確認、入力欄、選択肢、共通TODO表示 |
| `api/lives/`、`api/trips/`、`api/venues/` | 下記API一覧の処理入口。各ファイルは固定の操作だけを呼ぶ |
| `public/api/lives/`、`public/api/trips/`、`public/api/venues/` | 上記と同名の公開入口。実処理は公開領域の外に置く |
| `public/live_form.php` | 公演作成・作成者の編集・会場追加 |
| `public/live_detail.php` | 共有公演、自分の当落、TODO、遠征作成への入口 |
| `public/trip_detail.php` | 本人の公演・交通・ホテル・TODO・会場を一画面で確認 |
| `public/travel_form.php` | 交通／宿泊の登録・編集 |
| `public/assets/js/lives.js` | フォーム送信、重複候補、TODO操作、検索、ホーム更新 |
| `public/assets/css/lives.css` | PCの複数列とスマホの一列表示。本人のテーマ色を利用 |
| `tests/phase5_smoke.py` | Apache・MySQL上で3人を分離した統合テストと一時データ清掃 |
| `tests/lives_ui.mjs` | JavaScriptの画面操作をDOMモデルで検証 |
| `docs/PHASE5.md` | この学習・確認ガイド |

## 2. 変更ファイル

| ファイル | 変更内容 |
| --- | --- |
| `database/schema.sql` | 新規インストール用にも7テーブルを追加 |
| `public/live.php` | 仮表示を公演検索・本人状況付き一覧へ変更 |
| `public/home.php` | 「次のライブ」の表示例を本人の実データへ変更 |
| `app/repositories/schedule_repository.php` | `live_event_id` と開催状態を共有予定の応答に追加 |
| `app/services/schedule_service.php` | 連携済み共有予定の編集・中止をライブ管理へ集約 |
| `app/services/feedback_service.php` | ライブへの修正提案でもカテゴリと開場順序を確認 |
| `public/assets/js/schedules.js` | カレンダー内にライブ管理リンク・延期表示を追加 |
| `public/schedule_detail.php` | ライブへの入口を追加。連携公演は物理的に消さず中止として管理 |
| `public/schedule_form.php` | 連携公演の共有編集をライブ編集画面へ案内。自分用編集は従来どおり |
| `README.md` | Phase 5の主要ファイル・処理の流れ・ガイドリンク |

このほか、直前の通知ポップアップとホームのミニダッシュボードの変更は維持しています。カレンダーの日付クリック登録、0件を表示しない件数表示、選択日の一重の色付き枠も変更していません。

## 3. 追加DBテーブル

| テーブル | 公開範囲・保存内容 |
| --- | --- |
| venues | 共有会場：名前、都道府県、住所、任意の緯度・経度 |
| live_events | 共有公演：対応する予定、会場、開場、開催状態 |
| user_live_status | 本人限定：申込、当落、近場／遠征、個人メモ |
| todos | 本人限定：タイトル、期限、完了、種類、テンプレート識別子、削除日時 |
| trips | 本人限定：公演に対応する遠征名、出発日、帰着日、メモ |
| transportations | 本人限定：遠征内の移動区間・手段・日時・金額・予約状況 |
| accommodations | 本人限定：遠征内のホテル・滞在日時・金額・予約状況・住所・URL |

**タイトル・推し・公演日・開演・終了・作成者・共有メモは `schedules` に一元化しています。** `live_events.schedule_id` の参照先から読むため、同じ項目を2テーブルへコピーしません。APIでは `title`、`oshi_id`、`event_date`、`start_time`、`end_time`、`created_by_user_id`、`note` として返します。ツアー全体ではなく1日1公演が1行です。

## 4. migration内容

`006_live_management.sql` を **oshilife_v2** に適用済みです。追加のみで、既存テーブルのDROP・既存行のUPDATE・DELETEはありません。既存の予定を自動でライブ化する処理もありません。

別環境のPhase 4 DBへ移す場合はバックアップ後に006を1回実行してください。今回の環境で再実行する必要はありません。`schema.sql` は空のDBへ新規構築するときだけ使用します。共有予定の取得も新テーブルを参照するため、コード利用前に006を適用します。

## 5. INDEX / UNIQUE / 外部キー

INDEXは検索を速くする目印、UNIQUEは同じ組み合わせを二重登録させないDBのルールです。外部キーは「存在する親のIDだけを参照できる」ルールです。

| 対象 | ルール |
| --- | --- |
| venues | 名前＋都道府県＋住所のUNIQUE |
| live_events | schedule_idのUNIQUE、venue_id、status＋schedule_idのINDEX |
| schedules | 公演の推し・日付は既存の予定側のINDEXを利用 |
| user_live_status | user_id＋live_event_idのUNIQUE、live_event_idのINDEX |
| trips | user_id＋live_event_idのUNIQUE、live_event_idのINDEX、所有者を含む識別子のUNIQUE |
| todos | user_id＋live_event_id＋template_keyのUNIQUE。本人＋公演＋削除状態＋完了状態、live_event_id、trip_id＋本人＋公演のINDEX |
| transportations / accommodations | trip_id＋出発日時／チェックイン日時のINDEX |

TODOと遠征は、本人＋公演の組み合わせで `user_live_status` に結び付きます。TODOのtrip_idも本人・公演を含めて照合する外部キーです。他人の遠征へ誤って紐付ける操作をDB側でも防ぎます。任意追加TODOのtemplate_keyはNULLで、自由に複数作成できます。

## 6. API一覧

以下はアプリ内のパスです。実際の公開URLは `/oshilife-v2/public/api/...`。GETは取得、POSTは保存・変更。すべてログイン必須、POSTはCSRF必須です。

| メソッド | API | 用途・主な入力 |
| --- | --- | --- |
| GET | `/api/lives/list.php` | 公演一覧：q、oshi_id、period=all/upcoming/past、page（30件ずつ） |
| GET | `/api/lives/detail.php` | 共有公演と本人の状況：id |
| POST | `/api/lives/create.php` | 公演作成：oshi_id、title、venue_id、event_date、open_time、start_time、end_time、status、note |
| POST | `/api/lives/update.php` | 作成者の公演編集：同上＋id |
| GET | `/api/lives/duplicate-check.php` | 似た公演：oshi_id、title、venue_id、event_date |
| GET | `/api/lives/status.php` | 本人の管理状況：live_event_id |
| POST | `/api/lives/status/update.php` | 本人の申込・当落：live_event_id、application_status、lottery_status、trip_type、note |
| GET | `/api/lives/next.php` | 本人の直近公演：任意のoshi_id |
| GET | `/api/lives/todos/list.php` | 本人TODO：live_event_id |
| POST | `/api/lives/todos/create.php` | 任意追加：live_event_id、title、due_date |
| POST | `/api/lives/todos/update.php` | 編集：id、title、due_date |
| POST | `/api/lives/todos/toggle.php` | 完了：id、is_completed（JSONのtrue/false） |
| POST | `/api/lives/todos/delete.php` | 削除：id |
| GET | `/api/trips/detail.php` | 本人の遠征と子データ：id |
| POST | `/api/trips/create.php` | 遠征作成：live_event_id、trip_name、departure_date、return_date、note |
| POST | `/api/trips/update.php` | 遠征編集：id、trip_name、departure_date、return_date、note |
| POST | `/api/trips/transport/create.php` | 交通登録：trip_id＋交通フォームの各項目 |
| POST | `/api/trips/transport/update.php` | 交通編集：上記＋id |
| POST | `/api/trips/transport/delete.php` | 交通削除：trip_id、id |
| POST | `/api/trips/accommodation/create.php` | 宿泊登録：trip_id＋宿泊フォームの各項目 |
| POST | `/api/trips/accommodation/update.php` | 宿泊編集：上記＋id |
| POST | `/api/trips/accommodation/delete.php` | 宿泊削除：trip_id、id |
| GET | `/api/venues/list.php` | 会場選択肢 |
| POST | `/api/venues/create.php` | 会場作成：name、prefecture、address、任意のlatitude、longitude |

同じ会場・日付・推しで公演名が似ている場合、作成APIも候補と409を返します。画面で既存の公演を開くか、「別の公演として登録する」を選べます。明示的に選んだ場合だけ `allow_duplicate: true` で再送します。同じツアー名の別日公演は作れます。

## 7. live_eventsとuser_live_statusの関係

```text
共有の live_events 1件（例：5月3日の東京公演）
  ├─ Aさんの user_live_status（申込済み・当選・近場）
  └─ Bさんの user_live_status（申込中・結果待ち・未設定）
```

1対多とは「公演1件に、ユーザーごとの管理行が複数付く」という関係です。他人の当落を共有情報に入れません。公演作成者には未申込の管理行を作ります。他の人は既存公演の「自分の管理」を保存すると、自分の行が作られます。

## 8. 当選時TODO生成

```text
live_detail.phpで申込・当落・移動を選ぶ
  ↓ lives.jsがフォームをJSONで送る
public/api/lives/status/update.php
  ↓ 共通APIでログイン・CSRF確認
saveLiveStatus（service）
  ↓ 本人のuser_live_statusを保存
当選＋近場 → LOCAL_TODOS 6項目
当選＋遠征 → TRIP_TODOS 8項目
当選＋未設定 → 移動選択後の保存まで生成を待つ
  ↓ 一組の変更を確定 → JSON応答 → 画面を更新
```

トランザクションとは「複数のDB変更を全部成功・全部取り消しのどちらかにする」仕組みです。当落だけ保存されて準備リストが半端になるのを防ぎます。

## 9. TODO重複防止

項目名そのものではなく `payment`、`hotel` のような固定の `template_key` で管理します。利用者がタイトルを変更しても識別子は変わりません。

DBのUNIQUEと `ON DUPLICATE KEY UPDATE id=id` により、既存項目を変更せず不足分だけ追加します。当選→結果待ち→当選でも同じTODOは増えません。削除時は `deleted_at` に日時を保存し、画面から隠します。識別子を残すため、削除したテンプレートも勝手に復活しません。

## 10. 近場／遠征切替

近場→遠征では交通・ホテルなど不足分だけ追加します。共通項目のタイトル、期限、完了状態は維持します。遠征→近場でもTODO・既存遠征・交通・ホテルは消しません。利用者が不要なTODOを削除できます。当選以外の状態での切替はTODOを増やさず、次に当選で保存したときに不足分を追加します。

## 11. tripsの仕組み

移動を遠征にして保存すると「遠征まとめを作成」が表示されます。本人×公演で1遠征のみ。DBのUNIQUEと保存前チェックで二重作成を防ぎます。作成時に既存TODOをそのtrip_idへ結び付け、新しいTODOも同じ遠征へ結び付けます。

## 12. 交通・宿泊との関係

```text
本人のuser_live_status 1件 → trips 最大1件
                             ├─ transportations 複数（往路・復路・乗継など）
                             ├─ accommodations 複数（連泊やホテル変更など）
                             └─ todos 複数
```

「その他」の交通を選んだ場合だけ自由入力を表示し、PHPでも必須入力を検査します。到着が出発より前、チェックアウトがチェックイン以前、帰着日が出発日より前の入力は保存しません。予約状態は検討中／予約済み／キャンセルです。

金額はDECIMAL（小数を正確に保持するDBの型）で保存します。金額未定はNULL、0円は0として区別。年間お金管理や支出テーブルへは反映しません。ホテルURLはhttp/httpsだけを許可し、新しいタブのリンクには `rel="noopener noreferrer"` を付けます。

## 13. カレンダーとの連携

```text
live_form.php → 公演作成API
  → schedulesに公開ライブ予定を1件作成
  → live_events.schedule_idでその予定を参照
  → 作成者のカレンダーに表示

別ユーザーが「自分の管理」を保存
  → user_live_statusに本人の行
  → user_schedulesに本人と共有予定の関連（重複時は既存を維持）
  → カレンダーに1件表示
```

カレンダー追加済みの予定なら同じ関連を利用するため二重表示されません。公開予定の「自分用に編集」は従来どおり使え、`sync_enabled=0` の個人内容はライブ編集・修正提案の承認で上書きしません。

共有元の編集・中止はライブ管理から行います。連携済み予定を通常APIで非公開化・削除する操作は409で拒否します。ライブの中止は共有予定にも中止として反映し、延期はライブ状態を表示します。データは残します。修正提案は引き続き利用でき、承認した公演名・日時はライブにも反映されます。ライブのカテゴリ変更と開場後より早い開演への提案は拒否します。

本人がカレンダーから取り込みを外しても当落・遠征は消えません。「自分の管理」をもう一度保存すると再追加します。カレンダー上の個人日時と、ライブ詳細の正式な公演日時は、同期解除後には異なることがあります。

## 14. ホーム「次のライブ」

`lives/next.php` は本人の `user_live_status` がある公演のうち、今日以降で中止・終了以外を日付→開始時刻→ID順に1件取得します。推し選択にも連動します。延期の場合は延期と明示し、共有情報に残っている公演日を基準にします。

日本時間の「今日」と公演日の暦の日数を比較し、当日は「今日！」、翌日以降は「あとN日」。未完了TODOは削除済みを除いて本人分だけ数えます。過去公演はホームから外れ、ライブ一覧の「過去」で確認できます。

## 15. 個人情報へのアクセス制御と初心者向け解説

- セッションは、ログイン済みの本人をサーバーが覚えておく仕組みです。ブラウザは識別用Cookieを送ります。本人のuser_idは入力欄やURLではなく、既存の認証middleware（ページ/APIの前に行う共通チェック）から取得します。
- 遠征は `id AND user_id` で確認してから交通・宿泊を読みます。予約IDだけで取得・更新しません。自分のtrip_idと他人の予約IDを組み合わせても404です。
- 公演編集は元予定の `created_by_user_id` を照合します。他人は直接変更できず、従来の修正提案を使います。
- PDOプリペアドステートメントはSQL命令と入力値を分ける仕組みです。入力をSQLへ埋め込まず、SQLインジェクションを防ぎます。
- CSRFトークンは、このアプリの画面から送られた操作か確認する文字列です。Cookieだけの認証に加え、更新時に送ります。
- `htmlspecialchars` を使う `e()` とJavaScriptの `textContent` は、入力の `<script>` などを実行せず文字として表示します。
- `.env` は接続設定・APP_URLなど環境ごとの値を置く非公開ファイルです。Gitに含めません。URLは既存のappUrl経由、静的ファイルはassetUrl経由で作り、設置場所の違いを吸収します。
- ログインは既存の `password_hash` で保存されたパスワード要約と `password_verify` で照合します。パスワード原文は保存しません。成功時の `session_regenerate_id` はセッション識別子を更新して乗っ取りを防ぎ、`$_SESSION` のuser_idが今回の本人確認につながります。

今回のAPIはJavaScriptから呼ぶPHPのHTTP入口です。表示担当のページと保存担当のAPIを分離すると、同じ処理をホーム・詳細から共用できます。

```text
ページを開く → middlewareでログイン確認
  → PHP画面 → repository → PDO → MySQL → HTML
フォーム操作 → lives.js → public/api → api → 共通認証・CSRF
  → validator → service → repository / PDO → MySQL
  → JSON（データ交換用の文字列形式）→ lives.jsが画面へ反映
```

機能を増やす場所：状態・TODOテンプレートは `config/lives.php`、入力ルールはvalidator、保存手順はservice、検索条件はrepository、画面操作は `lives.js`。新しいDB項目は次のmigrationとして追加し、適用済みmigrationを書き換えて再実行しません。

## 16. PC / スマホUI

PCは入力欄・公演カードを2列、640px以下では1列。交通・ホテルは時系列で並べ、TODOを同じ遠征まとめ内に置きます。フォームには日本語ラベル、保存結果の読み上げ領域、削除確認を用意しました。本人が選んだ背景色・メインカラーを継承します。

実ブラウザ操作が許可されていないため、**PC／スマホの実描画・実操作は未確認**です。レスポンシブCSS、HTTPでのページ出力、DOMモデルのJavaScript操作は確認済みですが、これを実画面での確認の代わりとはしていません。

## 17. README追加内容

主要ファイルの役割、DBと個人情報の分離、フォームからDBまでの流れ、当選TODO、遠征のまとめ、カレンダー同期、次のライブ、ブラウザでの確認手順へのリンクを追加しました。PHP/JavaScriptの各新規ファイル冒頭と主要関数には日本語コメントを付けています。

## 18. ブラウザで確認する手順

Apache・MySQLを起動し、`http://localhost/oshilife-v2/public/` を開きます。

1. Aさんでログイン。ライブ → 公演を登録。会場がなければフォーム下の「会場を登録」を開いて追加します。公演を保存し、カレンダーから同じライブへ移動できることを確認します。
2. 別ブラウザまたは別プロファイルでBさんをログイン。既存公演を開き「自分の管理」で申込済み・当選・近場を保存。TODO6件を確認します。
3. TODOの名前・期限を変え、完了にします。当選→結果待ち→当選にしても増えないこと、削除したTODOが復活しないことを確認します。
4. 遠征に変更して保存。交通・ホテルのTODOだけ追加され、近場に戻しても既存項目が消えないことを確認します。
5. 遠征に戻して「遠征まとめを作成」。交通を往路・復路の2件、ホテルを1件以上登録。その他交通の入力、予約状態、ホテルの別タブURL、金額を確認します。
6. 遠征まとめで交通→ホテル→TODO→会場を確認。変更・削除も操作します。AさんでBさんのtrip_detailのURLを開いても取得できないことを確認します。
7. ホームで「次のライブ」の当落、移動、残り日数、未完了TODOを確認。今日の公演なら「今日！」。推し切替でも内容が切り替わることを確認します。
8. Aさんが共有公演を編集してBさんのカレンダーへの同期を確認。自分用に編集したユーザーの内容は保たれること、修正提案の承認・感謝・通知も動くことを確認します。
9. Aさんが延期・中止に変更し表示を確認。中止・終了公演はホームの次のライブから外れます。
10. PCの横幅とスマホ相当の幅390px・320pxで、横にはみ出さないこと、入力・削除ボタン・TODO操作・ナビを確認してください。

### 実施済みテスト（2026-09-30）

| 確認 | 結果 |
| --- | --- |
| 全PHP構文検査 | 183ファイル、エラーなし |
| Phase 5 HTTP・DB | 131リクエスト検証合格。3人の権限分離、TODO重複防止、予約複数件、金額、入力拒否、カレンダー・修正提案連携、ホーム今日／18日／過去／中止／終了 |
| データ維持 | テスト前後で全19テーブルの件数と内容ハッシュが一致。一時データ清掃済み |
| Phase 3回帰 | 176 HTTP確認合格、既存DB件数維持 |
| Phase 4回帰 | 192 HTTP確認合格、既存12テーブルの内容ハッシュ維持 |
| JavaScript | ライブ保存・重複確認・会場追加・その他交通・TODO・削除確認・ホーム・検索のDOMモデル合格 |
| 既存JavaScript | カレンダー、感謝、修正提案、ミニダッシュボード、通知のDOMモデル合格 |
| 実ブラウザの見た目 | 未確認。上の手順で確認が必要 |

再検証は `python3 tests/phase5_smoke.py`、`node tests/lives_ui.mjs`。HTTPテストはlocalhost専用で、一時アカウントと専用データだけを生成・清掃します。認証APIの試行制限があるため短時間に繰り返し過ぎないでください。

## 19. 未実装部分

Phase 5の指定機能は実装済みです。残っている確認は実ブラウザでのPC／スマホ描画と操作です。今回の範囲外である地図API、会場周辺おすすめ、年間お金管理、特効換算、連番ルーム、共同精算、チャット、年末振り返りは追加していません。ホームのお金に関する既存表示例も実データ化していません。

## 20. Phase 6へ進む前に

上記のPC／スマホ確認を済ませ、当落・遠征が自分だけに表示されることと、自分用カレンダー編集が保持されることを確認してください。交通・宿泊の金額は保存済みですが、支出へいつ反映し、変更・キャンセル・削除時にどう整合させるかはPhase 6で決めます。既存予約を二重に支出化しないため、連携先に元の交通／宿泊IDを保持する設計が必要です。
