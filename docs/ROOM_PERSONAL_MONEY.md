# 共同支出 → 本人の個人会計への反映（2026-10-04）

## 1. Git開始時状態

ユーザーによるChatコミット後に再確認。HEADは`fc3c11c チャット`、working treeはclean。今回の連携変更は未コミット。Chatコード・DB・migrationは変更していない。

## 2–4. 既存expenses・DB変更・識別方法

接続先oshilife_v2と実際のSHOW CREATE TABLEを確認した。expensesはDECIMAL(12,2)のamount、VARCHAR(30)のsource_type、nullable BIGINTのsource_id、UNIQUE(user_id,source_type,source_id)を持つ。oshi/event/tripには既存FKがあるがsource_idに予約テーブルへのFKはない。

既存チケットはuser_event_status.id、交通/宿泊は予約IDをsource_idにし、元側のexpense_id/ticket_expense_idも保存する。個人支出削除時はON DELETE SET NULLで元のリンクを解除。元金額が変わっても自動同期せず、既存支出を本人が明示編集する構成だった。

新しい連携は `source_type='room_expense'`、`source_id=room_expenses.id`。既存UNIQUEでユーザーごとの重複を防げるため、DB変更・新規migration・リンクテーブルは不要。既存の未使用nullable列room_expense_idはNULLのままにし、識別値を二重管理しない。

実DBの既存データに書き込む検証は行っていない。専用検証DB `oshilife_v2_rooms_verify_expenses_chat_20261004`とアプリコピー `/private/tmp/oshilife-personal-20261004/test-app`を使用した。

## 5–6. 本人負担と精算状態

room_expense_membersをroom_id・room_expense_id・セッションuser_idで検索し、share_amountだけをamountにする。総額や支払者の立替額を代用しない。本人が支払っていなくても正の負担があれば対象。

新規反映・元内容への更新は、active member・有効な共同支出・本人負担>0・settledを必須にした。イベント終了後に精算を確認してから個人会計を確定する運用に沿い、途中の内容を計上する事故を減らす。

closedルームでもactive member本人の個人会計への反映は可能。共有支出やChatを書き換える操作ではないため。退出後のルーム経由操作は不可だが、既に保存した本人の個人支出は通常のお金管理画面で編集・削除できる。

## 7–8. カテゴリと特効

| 共同支出 | 個人expenses | 特効 |
|---|---|---|
| ticket | live_ticket | 対象 |
| goods | goods | 対象 |
| transportation | transportation | 対象外 |
| accommodation | accommodation | 対象外 |
| food | food | 対象外 |
| sightseeing | sightseeing | 対象外 |
| other | other_trip | 対象外 |

「その他」の内容は推し活本体か特定できないため、既存のその他遠征へ保守的に分類。新カテゴリは作らない。MONEY_CATEGORIESのeligibleを登録値に使い、既存の換算式・集計関数はそのまま。換算の基礎は本人負担額になる。

## 9–11. 明示反映・二重登録・反映済み判定

GETで本人の確認内容を取得し、ダイアログでタイトル・本人負担額・カテゴリ・日付・特効対象を表示する。POSTで再認可・再検査してからINSERTする。入力のuser_id/amount/category等は受け付けず、セッションとDBの正しい値から決める。

expensesをuser_id＋source_type＋source_idで逆引きし、本人の反映済みだけを表示する。ownerに他人の個人会計へ登録する機能はなく、他人の個人支出や反映状況もAPIへ返さない。

確認値に共同支出version・提案内容・既存本人支出・精算状態を含める。確認後に内容が変わった場合は409。rooms行からロックし、本人expenses行もロックする。全処理を同じトランザクションで保存し、UNIQUEも二重登録の最終防止に使う。

## 12–13. 差分検知・本人による更新

タイトル・本人負担額・カテゴリ・日付・特効対象を既存個人支出と比較する。負担から外れた場合や取消も警告に含む。元支出変更でunsettledになった場合は、反映項目に差がなくても確認を促す。個人支出を自動変更しない。

有効な本人負担が正で再精算後なら「お金管理を更新」が利用できる。確認後に同じexpenses.idを更新し、二重作成しない。本人が個人側で編集したメモと既存のイベント/推しの関連は保持する。

複数イベント時は新規反映のevent_id/oshi_idをNULLにする。1件の場合も公開状態と本人の登録推しを確認できなければNULL。反映後にroom_eventsを変更しても既存支出の関連を推測で変えない。

## 14–15. 取消・削除・再反映

共同支出取消でexpensesは自動削除しない。取消済み一覧を開く案内をお金セクションに出し、対象には取消・反映済み警告を表示する。本人が「お金管理から削除」を確認した時だけ本人支出を削除する。削除はunsettledでも可能。本人負担が0円になったり対象から外れた場合も、既存個人支出の明示削除が可能。

個人会計側の従来の削除APIも利用できる。逆引き対象のexpenses行がなくなるので、有効・正の本人負担・settledなら再反映可能になる。取消済みの共同支出は再反映不可。

## 16–17. UI・API・変更ファイル

ルーム詳細の各共同支出内に本人負担・反映状態・確認操作を配置。0円や負担なしには反映ボタンを出さない。保存後は既存のお金部分更新で再表示し、Chatは再初期化しない。

GET/POST `/api/rooms/expenses/personal.php` を追加。POSTはaction=create/update/deleteと確認トークンを受ける。本人のexpense_idをクライアントから受け取らず、サーバーが逆引きして所有者を確定。ログイン・CSRF・参加状態・本人負担・精算状態を検証。

| ファイル | 内容 |
|---|---|
| app/services/room_personal_money_service.php | 本人負担・対応カテゴリ・確認値・反映・更新・削除 |
| api/rooms/expenses/personal.php / public/api/rooms/expenses/personal.php | 認証付き確認・反映APIと公開入口 |
| includes/room_personal_money.php | 本人用の状態・金額・操作UI |
| includes/room_money.php | 既存一覧への組込みと取消警告 |
| public/assets/js/room_workspace.js | 最新内容の確認と明示操作、部分更新 |
| app/services/money_service.php | room_expense由来の通常編集対応。直接の新規登録は拒否 |
| public/expense_form.php / public/assets/js/money.js | 個人会計一覧・編集で由来表示 |
| tests/room_personal_smoke.py | HTTPの金額・権限・差分等 |
| tests/room_personal_ui.py / room_personal_browser.mjs | PC/スマホの実操作 |
| README.md / 本書 | 日本語の役割・処理フロー・仕様・検証 |

## 18–21. テスト・既存機能の回帰

- 新規HTTP129項目が合格。本人が支払う/他人が支払う、0円/負担なし、他人反映拒否、settled/unsettled、二重登録、別ユーザーの独立反映、元変更と個人保持、カテゴリ/日付差分、明示更新、取消・明示削除、削除後再反映、7カテゴリ/特効、単一/複数イベント、退出後・closedの扱いを確認。
- 検証DBだけにINSERT失敗triggerを作り、反映が失敗した場合に全テーブル行ハッシュが直前と一致することを確認。triggerは試験後に削除。
- 新規PC1440px/スマホ390pxの10画面。確認キャンセル、本人反映、member自身の別反映、元変更で警告、再精算後更新、取消後本人削除、個人側の由来・金額表示を確認。横はみ出し・JS例外・失敗リソースなし。
- 既存回帰976 HTTP項目：精算済み94、精算サマリー65、共同支出154、招待126、同行者58、イベント131、チケット/交通/ホテル支払い151、お金管理167、コピー/FC30。全合格。
- 個人会計の集計式・special_effect_eligibleによる特効計算は変更なし。既存テストでsavings、年間/月別/推し別集計・旧APIを確認。
- Chatコードは変更していない。既存Chat88 HTTP項目・並列連投テスト・PC/スマホ7画面を再利用して合格。会話操作で共同支出・精算状態・個人会計が変わらないことも確認。
- 既存ルーム内操作12画面も合格。今回確認したブラウザー画面は計51（新規10＋共同支出10＋招待12＋Chat7＋ルーム内操作12）。PHP370ファイル構文・JS構文・git diff --check合格。精算計算の単体2,493チェックも合格。
- 各テストの片付け後に既存行ハッシュが開始前と一致。実DBへテスト用の支出・参加者は作っていない。

## 22. 残課題・対象外

重複防止の単位は「共同支出ID＋本人」。同じ実際の支払いを手入力や従来チケット/交通/宿泊連携から別に登録していたかまでは、自動判定しない。反映額は本人が内容を確認して登録する。

100%自動同期・他人の個人会計操作・送金・部分精算・共同TODO/遠征・Chat変更・通知・共同積立は対象外。表示する差分は個人会計へ反映する項目であり、元支出全体の変更履歴を保存するものではない。複数イベントからの任意選択や関連の自動付替えも行わない。

今回の変更は未コミット。DB変更・migrationはなく、戻す場合は今回のコード差分を戻す。利用開始後のroom_expense由来支出は個人会計データとして残す。実際に登録が始まった後のコード巻き戻しでは、旧コードが新source_typeを編集できない点に注意し、該当支出を削除するような復旧はしない。
