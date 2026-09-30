# Phase 4：修正提案と感謝のフィードバック

現在のVS Codeワークスペース `/Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife` に追加しました。公開URLは引き続き `http://localhost/oshilife-v2/public/` です。旧Oshilifeには変更していません。

## できるようになったこと

公開予定の詳細から、他ユーザーがタイトル・日付・開始時間・終了時間・カテゴリ・メモの修正を提案できます。投稿者がマイページの「届いた修正提案」で承認すると、共有元だけが更新されます。却下しても提案履歴は残ります。

公開予定には「助かった！」「ありがとう！」をそれぞれ1つずつ付けられ、もう一度押すと解除できます。投稿者本人には、カレンダーに追加している人数と感謝の件数を表示します。他の人の名前やメールアドレス、誰がカレンダーへ追加したかの一覧は公開しません。

ホームの「最近のありがとう」は、選択している推しに関する当月の追加・感謝を実データで表示します。0件では案内文を表示します。順位・ランキング・信頼度スコアはありません。

これまで調整した日付からの登録、件数の控えめな表示、日付と件数をまとめた背景、黒い二重枠を出さない表示、好きな配色設定を維持しています。固定の淡い配色には戻していません。

## 新規作成ファイルと主要ファイルの役割

| ファイル | 役割 |
| --- | --- |
| `database/migrations/004_feedback.sql` | 既存データを残して2テーブルを追加するSQL。ローカルDBは適用済み |
| `config/feedback.php` | 修正できる6項目・提案状態・リアクション名の許可リスト |
| `app/repositories/feedback_repository.php` | 届いた提案・感謝状態・予定別集計・本人の月次集計のSQL |
| `app/services/feedback_service.php` | 提案の検証、所有者確認、承認／却下、同時処理のロック、toggle |
| `app/helpers/feedback_api.php` | Phase 4 APIの共通起動。既存の認証・CSRF・例外処理を再利用 |
| `includes/schedule_feedback.php` | 詳細画面の情報元件数・提案状態・感謝ボタン・共有状況・提案フォーム |
| `public/corrections.php` | 本人に届いた提案を確認する画面 |
| `public/assets/js/feedback.js` | 感謝の切替と修正提案フォームの送信 |
| `public/assets/js/corrections.js` | 届いた提案の一覧・承認・却下・履歴・ページ送り |
| `public/assets/js/monthly_summary.js` | ホームの当月集計と推し切替・読み込み失敗時の再試行 |
| `public/assets/css/feedback.css` | 感謝・提案の画面。スマホ1列、PCで変更前後の比較を2列にする |
| `api/schedules/corrections/{create,list,approve,reject}.php` | 修正提案の4 API本体 |
| `api/schedules/reactions/{toggle,status}.php` | 感謝の2 API本体 |
| `api/schedules/stats.php` | 投稿者本人向けの共有状況API |
| `api/reflections/monthly-summary.php` | 本人の指定月の集計API |
| `public/api/` 内の同じ8パス | 上記APIへアクセスする公開入口。内部処理を読み込むだけ |
| `tests/phase4_smoke.py` | 3ユーザーで実際のHTTP・DBを使う統合テスト |
| `tests/feedback_ui.mjs` | DOMモデルによる感謝・提案・承認操作のテスト |
| `docs/PHASE4.md` | 今回の実装・学習・検証ガイド（この文書） |

## 変更ファイル

| ファイル | 変更内容 |
| --- | --- |
| `config/database.php` | PDO接続のセッション時間帯を+09:00へ設定。日本時間の月境界を統一（DBサーバー全体は変更しない） |
| `config/schedules.php` | 情報元ラベルを「TV局 / 番組公式」に統一 |
| `database/schema.sql` | 新規環境用としてPhase 4の2テーブルを追記 |
| `public/schedule_detail.php` | 公開予定に共通フィードバックUIを追加、情報元リンクを「元情報を見る」に統一 |
| `public/profile.php` | 「届いた修正提案」への導線を追加 |
| `public/home.php` | ダミーの感謝件数を当月集計カードへ置換 |
| `README.md` | ファイルの役割、処理フロー、初心者向け説明と確認手順へのリンク |

## DB・INDEX・UNIQUE

`users` から `user_schedules` までの既存9テーブル・データを保持し、新規テーブルだけを追加しました。DROPや作り直しはしていません。別環境のPhase 3 DBへは `004_feedback.sql` のみを一度適用します。現在のローカルDBへ再適用する必要はありません。

| テーブル | 保存する内容 | INDEX / UNIQUE |
| --- | --- | --- |
| `correction_requests` | 対象予定、提案者、項目、提案時の値、提案値、理由、根拠URL、pending/approved/rejected、確認・作成・更新日時 | `(schedule_id,status,created_at)`、`(requested_by_user_id,created_at)`、`(status,created_at)` |
| `reactions` | 対象予定、送信者、helped/thanks、作成日時 | UNIQUE `(schedule_id,user_id,reaction_type)`、INDEX `(user_id,created_at)`、`(reaction_type,created_at)`、`(schedule_id,created_at)` |

各テーブルにid主キー・外部キーを設定。提案や感謝がある予定の物理削除をDBでも禁止し、既存の論理削除（状態だけをdeletedへ変更）を使います。提案の同一pendingチェックは元予定の行ロック内で行います。NULLの時刻も同じ値として重複判定します。

## API一覧

すべてログイン必須。POSTはJSONとCSRFトークンが必要です。本人IDはセッションから取得し、リクエストのuser_idで切り替わりません。

| メソッド / パス（APP_URLの後） | 入力 | 結果・権限 |
| --- | --- | --- |
| POST `/api/schedules/corrections/create.php` | schedule_id、field_name、new_value、reason、任意source_url | 他人のpublicかつactive予定へ提案。201、data.correction_id |
| GET `/api/schedules/corrections/list.php` | status（pending/approved/rejected/all）、page | 本人が作成した予定への提案だけ。data.corrections/total/page/has_more。20件ずつ |
| POST `/api/schedules/corrections/approve.php` | correction_id | 投稿者のみ承認。200、data.status=approved |
| POST `/api/schedules/corrections/reject.php` | correction_id | 投稿者のみ却下。200、data.status=rejected |
| POST `/api/schedules/reactions/toggle.php` | schedule_id、reaction_type | public・非deleted・他人の予定だけ。data.reaction_type/active/count |
| GET `/api/schedules/reactions/status.php` | schedule_id | public・非deletedの感謝件数と本人の選択状態、確認待ち提案件数 |
| GET `/api/schedules/stats.php` | schedule_id | 投稿者本人のpublic予定だけ。calendar_added_count/helped_count/thanks_count/pending_correction_count |
| GET `/api/reflections/monthly-summary.php` | year、month、任意oshi_id=allまたはID | 本人の公開予定の月次集計。省略年月は日本時間の当月 |

月次APIは `data` に `year/month/shared_schedule_count/calendar_added_count/calendar_added_user_count/helped_count/thanks_count` を返します。月は9・09の両方に対応。年は1000〜9998、月は1〜12です。

主な失敗応答は未ログイン401、CSRF不正419、入力不正422、自分自身への提案／感謝403、非公開・権限のない対象404、重複提案・確認済み・提案後の変更との競合409です。内部SQLや接続情報は返しません。

## 今回追加したコードを理解するための解説

### 修正を直接UPDATEさせない理由

UPDATEはDBの既存行を書き換えるSQLです。他人がすぐに共有元を書き換えられると、未確認の情報が同期先全員へ伝わります。そこで `correction_requests` に「変更の候補」と「根拠」を保存し、元投稿者が確認する段階を設けています。

```text
Bさん → 公開予定の「修正を提案」 → create API
  → 認証・CSRF・公開状態・入力を検証
  → correction_requestsへpendingとして保存（元予定は変更しない）
Aさん（投稿者） → マイページ → 届いた修正提案
  → 根拠と最新予定を確認 → 承認API
  → 投稿者本人・pending・元の値が変わっていないか確認
  → schedulesの許可された1項目を更新
  → 提案をapprovedにしreviewed_atを保存
  → 両方成功した場合のみ確定
```

「トランザクション」は関連する変更を全部まとめて成功・失敗させる仕組みです。元予定だけ変更されて履歴がpendingのまま残ることを防ぎます。「行ロック」は処理中に別の更新が割り込まないよう、その予定の順番待ちを作ります。承認時も日時の前後関係などを再検証します。

提案後に対象項目が書き換わっていた場合は、409で承認を止めます。投稿者が最新の予定を確認し、古い提案を却下して再提案を依頼します。却下は `rejected` と確認日時の保存だけで、予定は変えず履歴も削除しません。非公開・中止・削除へ変わった予定の提案は承認できませんが、投稿者は確認・却下できます。

### 承認後の同期と自分用編集

```text
承認 → schedules更新
  ├ sync_enabled=1 → 次のカレンダー取得で共有元の最新値を表示
  └ sync_enabled=0 → 個人のcustom_*を引き続き表示
```

`user_schedules` にコピーを配り直す処理はありません。Phase 3の「共有元を参照する」設計をそのまま使います。自分用のタイトル・日付・時刻・終日・メモは保持します。カテゴリ・メンバー・情報元・中止／削除状態はPhase 3どおり共有元を参照します。開きっぱなしの画面へ即時配信する方式ではなく、次の画面表示・再読み込みで反映します。

### 感謝とtoggle

```text
公開予定 → 助かった！ / ありがとう！ → toggle API
  ├ 同じ本人・予定・種類がない → reactionsへINSERT
  └ 登録済み → その行だけDELETE
  → 種類別にCOUNT → 件数と選択状態を画面へ返す
```

toggleはオン／オフの切替です。helpedとthanksは別々の行として保持できます。UNIQUE制約は「同じ組み合わせをDBが二重に保存しない」約束です。元予定のロックと組み合わせ、同時送信でも重複行ができないようにします。画面でも通信中はボタンを無効化します。通信が途切れた際は、再読み込みで状態を確認してから再操作してください。

### COUNT・COUNT DISTINCT・月次集計

`COUNT(*)` は該当する行の数、`COUNT(DISTINCT user_id)` は同じ人を一度だけ数えた人数です。予定1件の追加人数は `user_schedules` からDISTINCTで取得します。月全体では、1人が2予定を追加したら「2件・1人」です。人数と件数を取り違えないよう月次APIに両方を用意しました。

```text
user_schedules.added_at → 指定月に追加され、今も残る取り込み件数・人数
reactions.created_at → 指定月に付けられ、今も残る助かった！・ありがとう！
schedules.created_at → 指定月に作成され、現在公開している予定の件数
  → 投稿者本人・任意の推しで絞り込み
  → ホーム「最近のありがとう」／将来の月次カード
```

集計期間は日本時間の「月初00:00以上、翌月初00:00未満」です。たとえば12月31日23:59:59は12月、1月1日00:00:00は1月に入ります。予定が開催される月ではなく、共有・取り込み・感謝が行われた月で数えます。複数の中間テーブルを一度にJOINして行を掛け合わせないよう、取り込みと感謝は別々に集計します。

今回は操作履歴を保存する台帳ではなく、**現在残る行の集計**です。解除で件数は減り、再追加は再追加した月に入ります。非公開へ変更した予定は対象外です。publicのまま中止・論理削除した予定の既存の追加・感謝は共有実績として含めます。将来、確定した振り返りカードが必要になったら月次の保存データを別途設計します。

### なぜランキングを持たないか

集計は本人へ「共有が使われた」と伝えるためです。投稿者どうしを比較する取得API・順位付け・人気順表示は作りません。提案一覧は作成日時順で表示し、反応の多さでは並べ替えません。

### 情報元と信頼性

詳細には、共通ラベルによる情報元、元情報へのリンク、登録された情報元の件数、最終更新日時、確認待ちの修正提案があるかを表示します。URLのない公式メールなどは補足を読めます。外部リンクは別タブで開き、`noopener noreferrer` で元画面への干渉を防ぎます。

未承認の提案を「確定した予定」として表示せず、実際に確認できる情報だけを表示します。「信頼度90%」のような根拠のない数値は使いません。

### セキュリティとページからDBまで

PHPページが認証middleware（ログイン状態を共通検査する処理）を通して画面を返します。JavaScriptはJSON API（画面から呼べる処理窓口）へ必要項目を送り、serviceが権限・公開状態・入力を検査してrepositoryまたはPDOでDBを読み書きします。結果はJSONで返して画面を更新します。

セッションはログイン本人をサーバー側で覚える仕組みです。ログイン済みでも更新にはCSRFトークンを要求します。提案のold_valueはDBから読み、利用者が送る値を信用しません。承認時に変更できるSQLの列名は6項目の許可リストに限定し、値はprepare/executeで別送します。URLはhttp/httpsのみ、ユーザー名・パスワード付きURLやjavascript:は拒否し、サーバーからURLを取りに行く処理はありません。

PHPでは `e()`（htmlspecialchars）、JavaScriptではtextContentを使い、提案や理由に入ったHTMLを実行させません。フォームはタイトル150文字・メモ3000文字・理由1000文字・URL2048文字以内。日時やカテゴリのルールは既存validatorを再利用します。

### 今後追加する場所

修正項目や日本語ラベルは `config/feedback.php`、認可・承認ルールはservice、集計条件はrepository、画面の操作は対応するJS、見た目は `feedback.css` です。新しい提案項目には必ず検証と承認後の保存先を追加してください。将来の月次カードはmonthly-summary APIを入口にできますが、過去値の固定保存は別途必要です。

## ブラウザで確認する手順

1. XAMPPのApache・MySQLを起動し、指定のlocalhostへアクセス。確認用A/Bのログイン情報はVS Codeの `storage/demo-accounts.txt` にあります。Cも必要なら新規登録で確認用ユーザーを作ってください。既存の認証情報は変更していません。
2. Aで対象の推しを登録し、公開予定を19:00〜21:00で作成。情報元を追加して保存します。
3. Bへ切り替え、同じ予定を自分のカレンダーへ追加。詳細の「修正を提案」で開始時間20:00・理由・根拠URLを送信。まだ19:00であることを確認します。
4. Cへ切り替え、同じ予定を取り込み「自分用に編集」でタイトル・日付・時刻・メモを変更します。
5. Aへ戻り、マイページ→届いた修正提案→根拠を見る→承認。Bのカレンダーを開き直すと20:00、Cの個人内容は変わらないことを確認します。
6. Bで別の提案を送信し、Aで却下。予定は変わらず、一覧の「却下」「すべての履歴」に残ることを確認します。
7. Bで助かった！とありがとう！を押し、両方選択できることを確認。それぞれもう一度押すと解除され、件数が減ります。
8. Aの同じ予定詳細で、追加人数と感謝の件数を確認。ホームの「最近のありがとう」にも当月分が出ます。推し切替にも連動します。
9. Aでprivate予定を作成し、その詳細URLをBで開くと見えないことを確認。公開から非公開へ変えた予定にも提案・リアクションはできません。
10. スマホ幅390px・タブレット768px・PC1440pxで、提案の長い理由、情報元URL、感謝ボタン、変更前後の比較を確認。ライト／ダークや任意配色も試してください。

## 実施した検証

2026-09-30、実際のXAMPP Apache/MySQLへ接続して実施しました。

- Phase 4：**162件のHTTP検証に合格**。3ユーザーによる提案→承認→同期、Cの個人編集維持、却下履歴、同一提案の重複拒否、元内容変更後の承認拒否、6項目の承認、自己操作・他人の承認・非公開アクセスの拒否、感謝の追加／解除と両方保持、月次の本人限定・年越し境界・推し絞り込み、ページ・アセットの配信を確認。
- 全11テーブルのテスト前後の内容をハッシュで照合し、既存データが同一であることを確認。一時ユーザー3名と関連データは清掃済み。
- Phase 3：**162件のHTTP回帰検証に合格**。予定登録・非公開制御・同期・削除・日付の引き継ぎ・最新版アセット配信を確認。
- JavaScriptのDOMモデル検証：感謝の独立切替、提案送信時の項目と現在値の維持、承認確認・一覧再取得、既存カレンダーの日付リンク・削除・件数更新に合格。
- PHP／JavaScriptの構文検査、変更差分の空白検査を実施。

**実ブラウザの描画・タップ操作は未確認です。** この環境では以前Chrome操作が許可されず、ブラウザ操作の代替経路で回避していません。HTTPとDOMモデルの検証を、スマホ／PCの実画面確認済みとは扱っていません。上の手順による最終表示確認が残ります。

## 未実装・Phase 5前の確認事項

今回の対象外であるライブ本機能、遠征、会場周辺スポット、お金管理、特効換算、連番ルーム、Chat、年末振り返り本実装、月次カードの固定保存は未実装です。既存の表示例は表示例のままです。

修正提案は指定の必須6項目に対応。任意項目の「情報元URLそのものの修正提案」、複数フィールド一括提案、メンバー変更提案、提案者への返信／結果通知は追加していません。受信者向けの画面内通知は、その後の追加対応で実装しました。根拠URLは提案に保存し、承認時にも情報元テーブルへ自動追加しません。

Phase 5の前には、実ブラウザでの表示・操作確認、月次集計を現在残る行で数える仕様、同期解除してもカテゴリ・中止状態などは共有元を参照する仕様を確認してください。利用者数が増える段階では、提案・リアクションの頻度制限、監視、大量データでの一覧と集計の性能測定も検討してください。


## 追加対応：受信通知とダッシュボード

通知テーブル `notifications` を追加し、感謝・修正提案の受信者へ画面内ポップアップ、未読バッジ、最新30件の一覧を表示します。表示済みと既読は分けて保存します。既存の感謝・確認待ち提案も通知へ移行済みです。

ホームの「最近のありがとう」は、月と3つの数値を中心にしたダッシュボードへ変更しました。詳しいファイル一覧・処理フロー・検証結果は [READMEの追加対応](../README.md#受信通知とホームのミニダッシュボード) を参照してください。
