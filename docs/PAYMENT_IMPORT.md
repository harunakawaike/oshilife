# 支払い完了 → 確認 → お金管理への反映

今回の変更は支払い連携に限定しています。既存のsavings・expensesの金額、年間残高・月別／カテゴリ別／推し別集計、特効換算の計算式は変更していません。

## 1. 変更ファイル

| ファイル | 変更内容 |
| --- | --- |
| `config/lives.php` | 予約状況とは別の支払い状態unpaid／paidの日本語表示 |
| `app/repositories/live_repository.php` | ログイン本人のチケット金額・支払い・反映IDを取得 |
| `app/services/live_service.php` | チケット金額保存、固定キーpaymentのTODOとの支払い連動、交通・宿泊の支払い保存 |
| `app/repositories/money_repository.php` | 本人の反映元取得、支払い条件、反映済みID、金額差の判定、IDの紐付け |
| `app/services/money_service.php` | 保存直前の条件再確認、チケット対応、反映済み拒否、元データのID保存 |
| `app/helpers/money_api.php` | 既存source APIにlive_ticketを追加 |
| `app/helpers/live_view.php` | 入金TODOのチェックボックスへ識別用属性を追加 |
| `public/live_detail.php` | 個人のチケット代入力、支払い・反映状態の表示 |
| `public/travel_form.php` | 交通・ホテルの予約状況と支払い状況を別々に入力 |
| `public/trip_detail.php` | 支払い状態、条件付き反映ボタン、反映済み表示、金額差警告 |
| `public/expense_form.php` | 未完了候補を拒否、チケットの確認画面、反映済みなら既存支出へ案内 |
| `public/assets/js/lives.js` | 入金TODO変更後に最新の反映案内を再表示 |
| `public/assets/js/money.js` | チケットの固定された特効対象trueを正しく送信 |
| `database/schema.sql` | 新規構築時にも追加列を作成 |
| `tests/phase6_smoke.py` | 既存の反映テストを新しい支払い条件に合わせる |
| `tests/lives_ui.mjs`・`tests/money_ui.mjs` | 入金TODO案内・チケット確認時の送信を検証 |
| `README.md`・`docs/PHASE6.md` | 新しい反映条件と説明を追加・更新 |

## 2. 新規ファイル

| ファイル | 役割 |
| --- | --- |
| `database/migrations/008_payment_import.sql` | 必要な列と外部キーを追加し、既存支出を紐付け |
| `app/helpers/payment_view.php` | 未完了／反映可能／反映済みと金額差の共通表示 |
| `tests/payment_import_smoke.py` | 2人の一時ユーザーで支払い連携と権限を検証 |
| `docs/PAYMENT_IMPORT.md` | この報告と学習ガイド |

ソースは `/Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife` に保存しています。旧Oshilifeは変更していません。

## 3. migration内容

既存DBの列を確認し、同名の列がないことを確認してから、008を **oshilife_v2** に適用しました。この環境で再実行は不要です。DBのDROP、支出の作り直し、既存金額の上書きはありません。

既存の交通・宿泊由来expensesは、本人・source_type・source_idを照合して元予約のexpense_idへ紐付けました。既存支出を再登録しません。過去の予約について支払い完了とは推測せず、payment_statusは未完了の初期値です。反映済みIDがあれば、支払い状態にかかわらず「反映済み」を優先して表示します。

既存の完了済み入金TODOは、固定キーpaymentかつ未削除であることを確認し、ticket_payment_statusをpaidへ引き継ぎます。過去の正確な支払日は分からないためNULLのままです。移行によって元のupdated_atは変更しません。

## 4. 追加カラム

| テーブル | 追加列 |
| --- | --- |
| user_live_status | ticket_amount：DECIMAL(12,2)、NULL可 |
| user_live_status | ticket_payment_status：unpaid／paid |
| user_live_status | ticket_paid_date：支払完了日、NULL可 |
| user_live_status | ticket_expense_id：反映済みexpenses.id、NULL可 |
| transportations | payment_status、paid_date、expense_id |
| accommodations | payment_status、paid_date、expense_id |

金額は個人管理に持ち、TODOには持たせません。支払日は初めてpaidにした日を記録し、paidのままの再保存では日を変えません。未完了へ戻すと支払日はNULLになり、再完了時に当日を記録します。

expense_id系の列にはUNIQUEとexpensesへの外部キーを追加しました。すべて **ON DELETE SET NULL** です。

## 5. API

専用の新しい登録APIを重ねず、既存の認証・CSRF付きAPIを拡張しました。

| API | 用途 |
| --- | --- |
| POST `/api/lives/status/update.php` | 従来の当落・移動・メモに加え、任意のticket_amountを保存 |
| POST `/api/lives/todos/toggle.php` | 入金TODO完了／未完了とチケット支払い状態を同期。支出は作らない |
| POST `/api/lives/todos/delete.php` | 入金TODO削除時はチケット支払いを未完了へ戻す。支出は消さない |
| POST `/api/trips/transport/create.php`・`update.php` | payment_statusを追加。省略更新時は既存値を維持 |
| POST `/api/trips/accommodation/create.php`・`update.php` | 同上 |
| GET `/api/money/source.php` | source_type・source_idから確認内容とcan_import・expense_id・amount_changedを取得 |
| POST `/api/money/expenses/create.php` | 内容を確認して保存する既存API。反映条件と本人を再検証 |
| POST `/api/money/expenses/update.php` | 反映済みの支出を利用者が明示的に編集 |
| POST `/api/money/expenses/delete.php` | 支出を削除し、外部キーで元の反映IDも解除 |

source_typeは **live_ticket／transportation／accommodation**。チケットのsource_idは共有live_event_idではなく、**本人のuser_live_status.id** です。交通・ホテルはそれぞれ元予約のIDです。user_idは送信せずセッションで取得します。

実URLは `/oshilife-v2/public/api/...`。未完了や反映済みの直接POSTは409、入力・特効対象の改ざんは422、他人のデータは404、CSRF不備は419で拒否します。

## 6. ライブチケット → expenses

```text
本人のライブ管理でticket_amountを保存
  ↓
固定キーpaymentの「入金・支払い確認」TODOを完了
  → ticket_payment_status=paid、ticket_paid_dateを記録
  → この段階ではexpensesは増えない
  ↓
当選 ＋ 金額>0 ＋ 入金TODO完了 ＋ 未反映
  →「お金管理に反映できます」と反映ボタン
  ↓ 利用者が押す
支出名・金額・支払日・推し・ライブを確認
  ↓ 利用者が保存
expensesへlive_ticket・特効対象trueで登録
  ＋ ticket_expense_idへ作成した支出IDを保存
```

TODOの表示名を変更しても固定キーで識別します。他のTODOや、同じ名前の任意追加TODOは入金確認として扱いません。金額未設定・0円・落選・TODO未完了／削除済みなら反映できません。完了の取り消し後でも、反映済みの支出を自動削除しません。

確認画面の支出名は公演名＋公演日＋チケット。日付は支払完了日、過去の不明なものは当日を初期値にし、利用者が変更できます。関連推し・ライブ・カテゴリと特効対象は反映元に固定します。

## 7. 交通 → expenses

予約状況と支払い状況を別々に保存します。**予約済み＋支払い済み＋金額>0＋未反映** のときだけボタンを表示。予約済みだけでは出ません。

```text
本人の交通を予約済み・支払い済みにする
  → 反映可能になる（支出は増えない）
  → 反映ボタン → 金額・支払日等の確認 → 保存
  → expenses：transportation、特効対象false
  → transportations.expense_idを保存
```

確認時の支出名は交通手段＋出発地→到着地。支払日は変更可能です。画面を開いてから支払いを取り消した場合も、保存APIで最新状態を再確認して拒否します。

## 8. ホテル → expenses

交通と同じ条件で、ホテル名＋「宿泊」を支出名の初期値にします。

```text
本人のホテルを予約済み・支払い済みにする
  → 反映ボタン → 確認 → 保存
  → expenses：accommodation、特効対象false
  → accommodations.expense_idを保存
```

交通・ホテルは推し活を支える周辺費用なので、年間支出には含めますが、直接の推し活支出を表す特効換算には含めません。

## 9. 二重登録防止と金額変更

3種類とも元データをロックしてexpense_idを再確認し、IDがある場合は新規登録を拒否します。既存の `UNIQUE(user_id,source_type,source_id)` も維持し、二重防止をDBでも保証します。支出作成と元のID更新は同じトランザクション（一組の処理を全部成功／全部取り消しにする仕組み）です。

金額変更ではexpensesを勝手に更新しません。元金額と反映済み金額を比較し、異なる場合は「お金管理へ反映済みの金額と現在の金額が異なります」と両方の金額を表示します。「お金管理の支出を更新」から既存の編集画面へ進み、利用者が金額を入力して保存します。更新額を自動で送りません。

## 10. expenses削除時の連携解除

```text
本人が支出を削除
  → expensesの行をDELETE
  → 外部キーON DELETE SET NULL
  → ticket_expense_id／expense_idをNULLへ
  → 支払い済み等の条件を満たす場合、反映ボタンが再表示
```

外部キーは「参照先の行が存在することをDBが保証するルール」です。削除時に古いIDを残すと「反映済みなのに支出がない」状態になるため、DB側で同時に解除します。支払い状態と元金額は消しません。既存の金額変更・支出編集の仕組みは維持しています。

## 11. セキュリティ・検証結果

チケットはuser_live_status.user_id、交通・ホテルはtrip.user_idをセッション本人と照合します。共有公演を利用しているだけで他人のチケット金額や入金情報を取得することはできません。readonly表示や隠し入力だけを信用せず、PHPで本人・金額・支払い・当落・カテゴリ・特効対象を再確認します。

保存APIは既存のCSRF、PDOプリペアドステートメント、トランザクションを利用。画面の文字列はe()／textContentで扱います。ユーザーから送られたexpense_idで元データを紐付ける処理はありません。

| 検証 | 結果 |
| --- | --- |
| 支払い連携のHTTP・DB | 151項目合格。未完了の拒否、ボタン3状態、確認前に支出が増えない、チケット特効、二重拒否、削除・再反映、金額差、他人のID拒否 |
| 既存お金管理の回帰 | 167項目合格。年間／推し別／月別／カテゴリ／特効、積立・支出編集を維持 |
| 元データ維持 | 両テストとも全21テーブルのテスト前後の件数・内容ハッシュ一致。一時データ清掃済み |
| PHP構文 | 225ファイル、エラーなし |
| JavaScript | お金管理・ライブ・ホーム新着のDOMモデル合格。入金TODO後の再表示、チケット特効trueの送信も確認 |
| 実ブラウザ | 操作が許可されていないため未実施。下記手順での描画・操作確認が残っています |

再検証：`python3 tests/payment_import_smoke.py`、`python3 tests/phase6_smoke.py`、`node tests/lives_ui.mjs`、`node tests/money_ui.mjs`。

## 12. ブラウザで確認する手順

1. localhostでログインし、当選したライブの「自分の管理」にチケット代を入力して保存します。入金TODO未完了の間は反映ボタンがないことを確認します。
2. 入金TODOを完了すると表示が更新され、チケット欄に案内とボタンが出ます。この時点ではホームの支出額は増えません。
3. 反映ボタン→確認画面で支出日・金額等を確認→保存。live_ticket・特効対象でお金管理へ登録され、元の欄は「✓ お金管理に反映済み」になります。
4. 遠征の交通を予約済みにするだけでは反映ボタンが出ません。支払い状況を支払い済みにして保存すると、正の金額であれば反映できます。ホテルも同様に確認します。
5. 同じ費用をもう一度反映できないこと、チケットは特効対象、交通・ホテルは対象外であることを確認します。
6. 元の金額を変え、反映済みとの金額差の警告を確認。支出が勝手に変わらず、編集画面から明示的に修正できることを確認します。
7. お金管理で反映済み支出を削除し、元の反映済み表示が解除されることを確認します。支払い条件が揃っていれば再反映できます。
8. 別ブラウザのBさんでAさんのsourceのURLやIDを指定しても、チケット・交通・ホテルを取得・反映できないことを確認します。
9. PCとスマホ幅で、支払い状態・反映案内・金額差・ボタン・確認フォームの見た目を確認してください。

## 13. READMEへ追加した内容

支払い完了と会計登録を分ける理由、3種類の反映条件、確認を挟むフロー、expense_idとUNIQUEによる二重防止、削除時のNULL解除、特効対象の違い、元金額を自動同期しない理由を追記しました。

支払いが完了しただけで会計へ登録すると、すでに手入力している費用や支出日を確認できず、意図しない二重計上になる可能性があります。そのため「反映可能」と「反映済み」を分け、保存ボタンで本人が確認した支出だけを登録します。手入力支出との重複までは自動推測しないため、確認画面で既存記録も確認してください。
