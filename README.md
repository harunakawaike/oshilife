# Oshilife v2 — Phase 6 / Step 3-3 ルーム内共同支出


## 連番ルームのトーク（Chat）

ルーム詳細の「イベント → メンバー → トーク → お金」の順序を維持し、同じ画面で会話できます。日本語・改行・絵文字・URL文字列に対応し、1投稿1000文字までです。画像やファイルは送れません。

- activeメンバーだけが閲覧・投稿できます。退出者の投稿は残りますが、退出した本人は読めません。
- 投稿した時の表示名を保存します。自分の投稿は右側の「あなた」、他のメンバーは左側です。
- 本人の投稿だけを論理削除（行を残して削除済みにする方法）できます。ownerも他人の投稿を削除できません。
- 終了ルームは閲覧専用で、投稿・削除はできません。
- 初回は直近100件。画面に保持する履歴も100件までで、古いログの追加読込は今回実装していません。
- 最新付近を見ている時は新着に追随します。過去ログを読んでいる時は末尾へ飛ばさず、「新しいメッセージを見る」ボタンを表示します。

### 主要ファイルの役割

| ファイル | 役割 |
|---|---|
| database/migrations/016_room_messages.sql | トーク専用の新テーブル・INDEX・FK。既存環境へ一度だけ適用 |
| app/services/room_message_service.php | 投稿・差分取得・本人削除・連投制限 |
| app/helpers/room_message_api.php | セッション認証・CSRF・入力ID検証 |
| api/rooms/messages/list.php / send.php / delete.php | 取得・投稿・削除のAPI。public/apiにも入口を配置 |
| includes/room_chat.php | ルーム内の履歴領域・入力欄・注意表示 |
| public/assets/js/room_chat.js | 新着取得、文字としての描画、送信・削除・スクロール |
| public/assets/css/room_chat.css | PC・スマホの吹き出し、入力欄、内部スクロール |
| tests/room_chat_smoke.py / room_chat_browser.mjs | HTTP・並列投稿・ブラウザー検証 |

### 処理の流れ

```text
ルームを開く → list.php → activeメンバー確認 → 直近100件を取得
→ textContentで描画 → 最新位置へスクロール

送信 → send.php → ログイン・CSRF・ルーム参加状態・本文を検証
→ 連投制限を確認 → room_messagesへ本文と投稿時の表示名を保存
→ list.phpで新着取得 → 同じ画面に表示

削除 → delete.php → activeメンバー・進行中ルーム・投稿者本人を確認
→ statusをdeletedへ → 本文を「メッセージを削除しました」に置換
```

`room_messages`は会話だけの保存場所で、支出・精算状態とは独立しています。精算済みのルームで話しても精算状態を解除しません。

**short polling**はブラウザーが定期的にサーバーへ新着を問い合わせる方法です。通信完了から4秒後に次回取得し、同時に複数の取得を走らせません。タブ非表示時は止め、戻ったら追いつきます。**WebSocket**は接続を継続してサーバーからも通知する方式ですが、今回は使用しません。

`after_id`は最後に読んだ投稿のIDです。その後の投稿だけ取得するので、毎回全履歴を読み直す通信・DB負荷を避けられます。ID順を飛ばさないため送信APIのIDでカーソルを進めず、取得APIが返した最後のIDを使います。表示中のIDを最大100件だけ`known_ids`として送り、過去の削除も本文なしのID一覧で反映します。

**XSS対策**：HTMLとして入力を実行させず、JavaScriptの`textContent`で文字として表示します。PHPでユーザー入力をHTMLへ出す箇所は既存の`e()`（htmlspecialchars）を使用します。URLも文字列表示のままです。
**CSRF対策**：他サイトから勝手に投稿・削除されないよう、セッションに対応したトークンを確認します。**認可**はその人にルームを使う権利があるかの確認です。user_idや名前はクライアント入力を信用せず、セッションとDBから決めます。

**rate limit**は短時間の大量操作を制限する仕組みです。同一ユーザーの全ルーム合計で30秒間に20投稿まで（削除済みも含む）。同時投稿時もルーム行とユーザー行をロックして検査・保存を順番に行います。別のルーム・タブからの送信でも制限を共有します。

[Chat実装・再開報告](docs/ROOM_CHAT.md)に現状復元、バックアップ、migration、テストと復旧方法をまとめています。画像・既読・通知・共同TODO・個人会計への反映には進んでいません。

## 連番ルームの精算済み管理

ownerは「お金 → 精算サマリー → 精算済みにする」から、現在の送金案を確認してルーム全体を精算済みにできます。実際の送金が終わってから操作してください。メンバーにも完了日と操作した人を表示します。

精算済みの共同支出を追加・編集・取消する際は確認を表示し、保存成功時だけ未精算に戻します。フォームを開く・キャンセルする・保存に失敗するだけでは解除されません。終了ルームの支出変更禁止は維持し、精算確定と閲覧は可能です。

### 主要ファイルの役割

| ファイル | 役割 |
|---|---|
| database/migrations/015_room_settlements.sql | ルームごとの精算状態を持つテーブルを追加。既存DBには一度だけ適用 |
| app/services/room_settlement_state_service.php | owner確認・精算確定・支出変更の確認・未精算への解除 |
| app/services/room_settlement_service.php | 金額を毎回計算し、精算状態と確認用トークンを返す |
| app/services/room_expense_service.php | 支出保存と精算解除を同じトランザクションで実行 |
| api/rooms/expenses/mark-settled.php | セッション認証とCSRF確認付きのPOST API。public側に入口も配置 |
| includes/room_settlement.php | 完了表示・折りたたみ内訳・ownerのボタン |
| public/assets/js/room_workspace.js / room_expenses.js | 最新内容の確認ダイアログとAPI送信、保存後の部分更新 |
| tests/room_settled_smoke.py | 精算完了・権限・失敗時の保持・自動解除・ROLLBACKの検証 |

### 処理の流れ

```text
ownerが精算ボタン → 最新サマリーAPI → 送金案と確認ダイアログ
→ POST mark-settled.php → ログイン・CSRF・owner確認
→ rooms行をロック → 確認後に支出が変わっていないか照合
→ room_settlementsへ状態・日時・操作ユーザーを保存 → お金部分を更新

精算済みの支出を保存 → 最新状態取得 → 解除の警告 → 利用者が確認
→ 支出変更 ＋ 状態を未精算・日時と操作者をNULL → 一括確定
失敗時 → ROLLBACK（この一組の変更をすべて元に戻す）
```

トランザクションは複数のDB変更を一組として成功・失敗させる仕組みです。ロックは同じルームの操作を順番に処理させます。確認用トークンは支出ID・更新番号・内訳から作った照合値で、確認した後の変更を検出します。ログインのセッションから操作者を決め、クライアントのuser_idを権限として信用しません。

詳細とバックアップ・復旧方法は[精算済み管理の実装報告](docs/ROOM_SETTLED.md)を参照してください。部分精算・送金履歴・個人会計連携・Chatは対象外です。

## 共同支出の精算サマリー

ルーム詳細の「お金」で、共同支出一覧の上に **誰 → 誰／いくら** を自動表示します。
たとえばAがホテル20,000円を支払い各人10,000円、Bが交通12,000円を支払い各人6,000円なら、差し引き **B → A 4,000円** です。本人は「あなた」と表示し、内訳は折りたたみで確認できます。

### 処理の流れ・計算ルール

```text
ルーム詳細表示／共同支出の追加・編集・取消後
↓ お金セクションを描画（既存の部分更新）
roomSettlementSummary：閲覧者がactiveメンバーか確認
↓ 1回のSELECTでactive支出と内訳を取得（cancelledを除外）
calculateRoomSettlement：支出ごとの負担合計＝総額を再確認
↓ ユーザーごとに 支払総額 − 負担総額 = balance
プラス：受取側／マイナス：支払側／0：精算不要
↓ 受取側・支払側をそれぞれ金額降順、同額はuser_id昇順で並べる
順番に小さい方の金額を相殺 → 送金案を表示
```

同じデータなら毎回同じ結果です。送金件数を減らす単純な方式で、数学的に最少の件数を必ず求めるものではありません。複数人の場合、元の個別支出とは異なる組合せにまとめることがありますが、各人の最終差額は変えません。
新しい割り勘や丸めはせず、保存済みの整数円をそのまま加減算します。全員のbalance合計が0、送金案の合計が支払側のマイナス差額の合計と一致し、各人の出入りがbalanceを再現することを検証します。
不整合な支出があれば誤った案を表示せず、サマリーをエラーにします。「精算不要」とも表示しません。

退出者も過去のactive支出に支払・負担があれば含めます。表示名は最新の有効支出IDのsnapshotを使い、同じ支出で支払と負担の両方にいる場合は支払者の保存名を優先します。閲覧権限は別で、退出した本人はサマリーも閲覧できません。
精算額は派生データ（元データから算出する結果）のままです。ルーム単位の精算完了状態だけを保存します。送金・個人expenses/savings・特効換算への反映はありません。

| ファイル | 役割 |
|---|---|
| app/services/room_settlement_service.php | 共通の整合性検証・整数計算・決定的な相殺と、認可付き取得 |
| includes/room_settlement.php | 送金案・本人視点・支払/負担内訳・エラー表示 |
| includes/room_money.php | 一覧の上へサマリーを配置。既存の部分更新で同時更新 |
| api/rooms/expenses/settlement-summary.php | GET room_idで同じ計算結果をJSON返却。非メンバーは拒否 |
| public/api/rooms/expenses/settlement-summary.php | 公開ディレクトリからのAPI入口 |
| tests/room_settlement_unit.php / room_settlement_smoke.py | 相殺の例・保存則・認可・異常値・読み取り専用の検証 |

[精算サマリー実装報告](docs/ROOM_SETTLEMENT_SUMMARY.md)にテスト結果と対象外の機能を記載しています。

## ルーム詳細のUI / UX（Step 3-3後）

ルーム名 → イベント → メンバー/招待 → トーク → お金 → ルーム管理の順に表示します。
複数イベントはPCでは横並び、幅を超える場合やスマホでは横スクロールできます。イベント追加・除外はowner向けの補助導線として維持しています。
メンバーは名前と人数をコンパクトに表示し、「＋ 招待」と「ルーム管理」は必要な時だけ開きます。
ルーム詳細UI再設計時点ではトークは案内のみでした。現在は上記「連番ルームのトーク」のChatを実装しています。

お金セクションは共同支出の一覧・開閉できる詳細を持ち、追加・編集は同ページ内のdialogを使います。dialogは背景への操作を止める標準HTML部品で、閉じるボタンとEscapeで戻れます。
`room_workspace.js`が既存の認可済み`room_expense_form.php`からフォーム部分だけを取得し、既存`room_expenses.js`を初期化します。均等割りの式・送信項目・保存先APIは同じです。
保存・取消後はサーバーが描画した`includes/room_money.php`部分だけを取得し直します。ページを遷移せず、トークや招待の開閉状態もそのままです。表示更新に失敗した場合は「表示を更新」を案内し、保存済みフォームを再送させません。
既存の`room_expenses.php`、`room_expense_detail.php`、`room_expense_form.php`は互換URLとして維持します。

| ファイル | 役割 |
|---|---|
| public/room_detail.php | 画面の優先順位と各セクション、dialogの入れ物 |
| includes/room_money.php | 同ページ内の共同支出一覧・内訳・取消履歴 |
| public/assets/css/room_workspace.css | イベント横並び/横スクロール、トーク主領域、コンパクトな補助機能 |
| public/assets/js/room_workspace.js | dialogの読み込み・閉じる・保存後の部分更新・取消 |
| public/assets/js/room_expenses.js | 既存フォームの初期化を通常画面とdialogで共用 |

DB構造・migration・API・認可・共同支出の金額計算は変更していません。精算・個人会計反映など次Stepへは進んでいません。
テストと変更範囲は[ルームUI変更報告](docs/ROOM_UI_REDESIGN.md)に記載しています。

## Step 3-3：ルーム内共同支出

**連番ルーム → ルーム詳細「お金」→ ＋共同支出を追加**から使います。追加・編集はダイアログで開き、保存後もルーム詳細に留まります。各支出を押すと内訳・メモ・編集・取消を展開できます。
共同支出は、同じルームのユーザーが見る1つの共有記録です。個人のメモや同行者（event_participants）を元にせず、参加中のroom_membersから支払者・負担者を選びます。

たとえばホテル20,000円をAさんが支払い、Aさん・Bさんが10,000円ずつ負担する場合、**支払者A：20,000円／負担A：10,000円、B：10,000円**を別々に記録します。
支払額は店等に実際に支払った額、負担額は最終的に各人が負担する額です。両者を混ぜると、立替分まで自分の消費として集計してしまいます。
このStepでは個人expenses、savings、月別・年間集計、特効換算へ書き込みません。Step 3-3本体では精算計算・精算状態を実装していません。現在の計算表示は上記「精算サマリー」を参照してください。

### 主要ファイルの役割（共同支出）

| ファイル / ディレクトリ | 役割 |
|---|---|
| public/room_expenses.php | 共有一覧。取消履歴の表示切替 |
| public/room_expense_detail.php | 登録者・支払者・各負担額・メモの詳細 |
| public/room_expense_form.php | 新規登録・編集。参加者と個別負担を選択 |
| public/assets/js/room_expenses.js | 整数円の均等割り、合計表示、保存・取消API呼出 |
| public/assets/css/room_expenses.css | PCのカード配置・スマホの縦配置 |
| config/room_expenses.php | 7カテゴリと金額上限の共通定義 |
| app/helpers/room_expense_view.php | 共通画面設定・共有内訳の表示 |
| app/helpers/room_expense_api.php | 認証・CSRF・入力ID・固定操作への分岐 |
| app/validators/room_expense_validator.php | 整数金額、日付、重複負担者、合計チェック |
| app/repositories/room_expense_repository.php | ルーム内での閲覧権限・DB取得 |
| app/services/room_expense_service.php | 編集権限・参加状態・一括保存・取消 |
| api/rooms/expenses/・public/api/rooms/expenses/ | list / detail / members / create / update / cancel |
| database/migrations/014_room_expenses.sql | 新規2テーブルだけを追加 |

### データと処理の流れ

- **room_expenses**は支出本体。総額・支払者・登録者・支払日・カテゴリ・メモ・状態を保存します。
- **room_expense_members**は各人の負担内訳。1つの本体に複数の内訳が属する形を「1対多」と呼びます。
- 名前は登録時の写し（snapshot）も保存します。ユーザーの改名や退出で、過去の支払者・登録者・負担者が分からなくなるのを防ぎます。名前そのものではなくuser_idで区別します。

```text
room_expense_form.phpを開く
↓ ログイン・同じルームのactiveメンバーか確認
参加中のroom_membersを候補として表示
↓ 金額と負担者を入力（均等割り後の個別変更も可）
room_expenses.js → api/rooms/expenses/create.php または update.php
↓ PHPが権限・所属・整数円・負担者重複・負担合計を再確認
DBトランザクション開始／ルーム行をロック
↓ 本体と内訳を保存し、保存後のSUM（合計）も確認
成功ならCOMMIT（確定）／失敗ならROLLBACK（一括取消）
↓ JSON応答 → 詳細画面へ
他のactiveメンバーも同じ支出ID・同じ負担内訳を閲覧
```

実装ではトランザクションとルームロックの中で所属・編集権限・入力を検証し、そのまま保存します。途中で退出や終了が割り込んで検証と保存が食い違うのを防ぎます。
トランザクションは複数のDB変更を1組にする仕組みです。本体だけ残り内訳が欠ける状態を防ぎます。
SUM（内訳合計）と総額が一致しなければ保存しません。画面の合計表示やボタンは改ざんできるため、PHP側の再検証が必要です。

### 金額・均等割り・履歴のルール

- 日本円の整数のみ。総額は1〜999,999,999円、各負担は0〜999,999,999円。選んだ人の0円は保存できますが、未選択の人の0円行は作りません。
- 均等割りの端数は、**選択した負担者のうち支払者を優先し、その後はroom_members.idの昇順**で1円ずつ配分します。支払者を負担者に含めない場合も使えます。
- 退出者は新規支出の候補から除外。過去の支出には保存名と金額を残し、編集時もその人の既存負担は固定します。固定額を引いた残額だけを参加中の負担者で均等割りできます。過去の支払者が退出済みでも、そのまま保持可能です。
- 閲覧・登録はactiveメンバー、編集・取消はactiveな登録者本人またはowner。退出した登録者は操作不可。終了ルームは閲覧のみ。
- 取消はstatus=cancelledにして本体・内訳を残します。通常一覧では隠し、「取消済みも表示」で確認できます。取消済みの再編集・復活は対象外です。
- 更新番号versionにより、他の人が保存した後に古いフォームを送ると409で停止します。再読み込みして内容を確認してください。
- 編集では選択を外した参加中メンバーの内訳を取り除きます。残るメンバーの内訳ID・保存名は保持します。変更前の全版を保存する監査履歴は今回の対象外です。

### migrationと今後

今回の開始時点は招待リンクを含む**27テーブル**です。014はroom_expensesとroom_expense_membersだけを追加し、既存27テーブルのデータ・ID・金額・AUTO_INCREMENT・制約を保持します。既存migrationは変更しません。
新規セットアップはschema.sql、既存環境は未適用のmigrationだけを使います。適用済みの014を再実行しないでください。

将来は、取消されていない支出の支払総額と本人負担額をuser_idごとに比較して精算を設計できます。Step 3-3本体では差引額を計算・表示せず、確認/承認ワークフロー、共同TODO・遠征・Chat・通知も追加しません。
実装・バックアップ・復旧・検証結果は[Step 3-3実装報告](docs/EVENT_STEP3_3_ROOM_EXPENSES.md)にまとめています。

## 新Step 3-2：連番ルーム・招待リンク・参加

イベント一覧・イベント詳細の「連番ルーム」から使います。
**ルームを作成 → 招待リンクを作成 → コピーしてLINE等で共有 → 相手がログイン → 確認画面で「参加する」**の順です。
リンクを開くだけでは参加しません。キャンセルもデータを変更しません。
リンクは7日間有効・参加人数制限なし。ownerがいつでも無効化でき、再発行もできます。
URLは発行直後だけ表示します。再読み込み後は再表示できないため、必要なら新しいリンクを作成してください。

表示名検索・特定ユーザー宛て招待は廃止しました。同じ表示名でも各アカウントで参加できます。
退出後は有効なリンクから再参加でき、以前のメンバーIDを引き継ぎます。
owner本人・参加中メンバーの重複登録や使用回数加算は行いません。
招待リンク変更時点では共同支出・精算・Chat・通知を追加していません。現在の共同支出は上記Step 3-3を参照してください。

### 主要ファイルの役割（連番ルーム）

| ファイル / ディレクトリ | 役割 |
|---|---|
| public/rooms.php | 自分のルーム一覧・作成 |
| public/room_detail.php | 参加中メンバーの詳細、ownerのリンク発行・無効化 |
| public/rooms/join.php | ログインへの案内と参加確認。GETでは参加しない |
| public/assets/js/rooms.js / public/assets/css/rooms.css | API送信・リンク表示とコピー・PC／スマホ表示 |
| app/helpers/room_view.php | 共通画面設定・操作フォーム |
| app/helpers/room_api.php | 認証・CSRF・操作分岐。旧招待APIは410で停止 |
| app/repositories/room_repository.php | 参加権限と共有可能な情報の取得 |
| app/services/room_service.php | 作成・退出・終了・イベント紐付け |
| app/services/room_link_service.php | トークン発行・ハッシュ照合・期限・参加・再参加 |
| api/auth/login.php / public/assets/js/auth.js | ログイン成功後、保存された招待ページへ戻る |
| api/rooms/links/・public/api/rooms/links/ | create・list・revoke・preview・joinのAPI入口 |
| database/migrations/012_rooms.sql | 元の4テーブル。適用済み内容は書き換えない |
| database/migrations/013_room_invite_links.sql | 招待リンク1テーブル追加。既存26テーブルは変更しない |

### DBの役割と処理の流れ（連番ルーム）

- **rooms**：ルーム名・作成者・状態。
- **room_members**：Oshilifeユーザーの参加状態とowner/memberの役割。
- **room_events**：ルームと複数イベントを結ぶ中間テーブル。
- **room_invite_links**：招待トークンのハッシュ・期限・利用回数・無効化状態。
- **room_invitations**：廃止した個別招待の履歴として保持。現在の画面・APIから利用しない。

```text
ownerが「招待リンクを作成」
↓ ログイン・CSRF・owner・ルーム状態を確認
暗号学的乱数32バイトを発行 → SHA-256ハッシュだけDBへ保存
↓ 生トークン付きURLは発行応答で一度だけ渡す
リンクを受け取った人がアクセス
↓ 未ログインならトークンをセッションに一時保存 → ログイン → 固定の招待ページへ復帰
ルーム名・owner表示名を確認
↓ 本人が「参加する」を押した時だけPOST
CSRF・トークン・有効期限・無効化・ルーム状態を再確認
↓ ルーム行をロックして同時更新を順番に処理
room_membersを追加、または退出行をactiveへ戻す → ルーム詳細
```

ハッシュは元の文字列を戻せない照合用の値です。推測困難な乱数を使うため、パスワード用の遅いハッシュではなくSHA-256で安全に検索できます。
セッションはサーバーがブラウザーごとの情報を一時保存する仕組みです。任意のreturn_to URLは受け付けず、保存した64桁のトークンから招待ページの固定パスを作るため、外部サイトへ誘導するオープンリダイレクトを防ぎます。
APIはJavaScriptが呼び出すPHPの処理窓口です。画面のボタンを隠すだけでなく、API自身が権限とCSRF（他サイトからの勝手な操作を防ぐ確認値）を検証します。

ownerは名前変更・リンク発行/無効化・イベント追加/除外・ルーム終了ができます。memberは閲覧・本人の退出ができます。ownerは退出できません。
終了したルームは参加中メンバーが履歴を閲覧できますが、新たな参加はできません。終了時に有効リンクも無効化します。
確認画面にはルーム名・owner表示名だけを表示し、メール・内部user ID・他メンバー・個人の同行者・当落・TODO・支払いは公開しません。
個人のevent_participantsは独立したままで、ルームへ自動コピーしません。

リンクURLはAPP_URLから生成します。localhostのURLは同じ端末向けです。他の端末から共有利用する環境では、アクセス可能なHTTPSのAPP_URLを設定してください。
トークンはリンクの所持者が参加するための秘密です。画面はno-store/no-referrerを使いますが、ブラウザー履歴・共有先・WebサーバーのアクセスログにはURLが残り得るため、本番では招待URLのクエリをログに残さない運用も検討してください。今回XAMPPの設定は変更していません。

013はバックアップと別DBでの復元・テスト後に適用済みです。新規セットアップはschema.sqlを使い、既存環境は未適用のmigrationだけを順に適用してください。
詳細は[招待リンク変更報告](docs/EVENT_STEP3_2_INVITE_LINKS.md)、変更前の記録は[旧Step 3-2実装報告](docs/EVENT_STEP3_2_ROOMS.md)を参照してください。

## イベントのコピー・ファンクラブ先行受付

自分が登録したイベントの詳細にある「コピーして別日程を登録」から、日付・時間・会場を変更して新規登録できます。
共有のイベント名・種別・推し・会場・日時・共有メモをフォームへ引き継ぎ、開催状況は「開催予定」に戻します。
保存するまでDBへの追加はありません。保存時は既存のevents/create APIを使い、新しいイベントIDと予定IDを作ります。
元イベントは更新せず、同行者・個人メモ・申込・当落・TODO・遠征・支払い情報はコピーしません。同一日程の重複確認も維持します。

受付方式に「ファンクラブ先行受付」を追加しました。抽選として扱い、DBには既存値のentry_method=lottery、sales_type=fanclubを保存します。
保存後の画面ではこの組み合わせを「ファンクラブ先行受付」と表示します。FCの先着販売は「先着」＋販売区分「FC」で管理できます。
DBの制約や既存データは変更していません。migrationは不要です。

処理の役割：event_form.phpがコピー元の所有者と初期値を確認し、events.jsが既存の新規APIへ送信します。
受付方式はevent_validator.phpで既存値へ変換し、event_repository.phpとevent_detail.phpで表示を揃えます。
テストはtests/event_copy_smoke.py（30 HTTP検証・元イベント不変・個人情報非コピー）とPC/スマホ6画面で確認済みです。
Step 2の131項目、支払い連携151項目も合格しています。

## イベント管理 Step 3-1：同行者（自分だけ）

イベント詳細の「同行者を管理する」から、同行者の名前・メモを追加・編集できます。
利用終了にすると通常一覧から隠れ、記録を残したまま再開できます。同名登録可能で、自分はイベントごとに1人です。
本人の「自分の管理」を保存したイベントで利用でき、他ユーザーには共有されません。

011_event_participants.sqlは適用済みです。既存21テーブルを変更せず、event_participantsだけを追加しました。
共同支出・立替・精算・お金管理との連携は今回の対象外です。
バックアップ・復元・migration・復旧方法・テスト結果は[Step 3-1実装報告](docs/EVENT_STEP3_1.md)を参照してください。

### 主要ファイルの役割（Step 3-1）

| ファイル / ディレクトリ | 役割 |
|---|---|
| public/event_companions.php | 同行者一覧と追加・編集・利用終了・再開の画面 |
| public/event_detail.php | 同行者画面への本人専用の入口 |
| public/api/events/companions/・api/events/companions/ | 同行者APIの公開入口と操作ごとの入口 |
| app/helpers/participant_api.php | ログイン・CSRF・入力ID・JSON応答 |
| app/services/participant_service.php | 自分の一意性、入力検証、保存・利用終了 |
| app/repositories/participant_repository.php | 所有ユーザーとイベントを限定したDB取得 |
| database/migrations/011_event_participants.sql | 新テーブル・INDEX・制約の追加 |

### 処理の流れ（Step 3-1）

```text
同行者画面で保存
↓ 既存events.jsが入力をJSONにして送信
同行者API → ログイン・CSRF確認
↓
本人のイベント管理を確認 → 自分の重複・参加者の所有者を確認
↓
event_participantsだけに保存 → JSON応答 → 画面を再表示
```

画面表示だけではDBに書き込みません。自分の行は初回の保存時に作成します。
APIは画面から処理を依頼する入口です。所有者はセッション（サーバーのログイン記録）から判断し、SQLへの値はプリペアドステートメントで安全に渡します。
以下のStep 2以前の章は既存機能と導入履歴です。現在の参加判定はStep 2の「参加確定」が基準です。



## イベント管理 Step 2：参加確定から準備を始める

抽選LIVE・先着イベント・予約制舞台・無料展覧会を同じイベント管理で扱えるようになりました。登録／編集で6種類を選択し、「自分の管理」で受付方式と参加状況を保存してください。

| 項目 | 意味 | 例 |
|---|---|---|
| 受付方式 | 参加手続きの種類 | 抽選・先着・予約・申込不要・未確認 |
| 申込状況 | 手続きをどこまで進めたか | 未申込・手続き中・申込済み・申込不要 |
| 抽選結果 | 抽選で選ばれたか | 結果待ち・当選・落選。非抽選は対象外 |
| 参加状況 | 実際に参加するか | 検討中・参加確定・不参加・取消 |
| 販売区分 | どの受付枠か | FC・一般販売・制作開放・公式先行 |
| 支払い状況 | お金を支払ったか | 未完了・支払い済み。0円は支払い不要表示 |

例えば「予約済みの舞台」は当選という結果を持ちません。「一般販売」は購入済みを意味しません。そのため各状態を分け、TODO生成を**当選から参加確定**へ変更しました。新画面では本人が参加状況を選びます。

- 抽選LIVE：抽選 → 申込済み → 当選 → 参加確定 → 準備TODO。
- 先着イベント：先着 → 申込／購入済み → 参加確定 → 準備TODO。
- 予約制舞台：予約 → 予約済み → 参加確定 → 準備TODO。
- 無料展覧会：申込不要 → 金額0円 → 参加確定 → 支払い以外のTODO・遠征。

申込の保存値applyingは維持し、表示を「手続き中」にしています。非抽選はnot_applicable、申込不要はnot_requiredへサーバー側で正規化します。受付方式に応じて不要な欄は隠れます。

チケット・入場料は「参加確定・正の金額・支払い済み・未反映」でお金管理へ反映できます。入金TODOと支払い選択は同期しますが、支出への登録は確認画面での本人操作が必要です。0円は反映しません。live_ticket保存値・source_idが本人管理IDであること・特効換算は維持しています。

既存DB用の010_event_participation.sqlは適用済みです。バックアップ取得・検証DBでの復元と移行確認後に実行しました。再実行不要です。以下のStep 1説明は前段階の履歴です。現在の仕様と移行方法、主要ファイル、処理フロー、テスト結果は[Step 2実装報告](docs/EVENT_STEP2.md)を参照してください。

## イベント名称への統一（Step 1の履歴）

Oshilifeは音楽アーティストだけでなく、俳優・舞台・スポーツ・作品など、さまざまな推し活に利用できることを目指します。そのため画面・PHP・API・DBの管理名称をevent系へ揃えました。**今回は名称と参照構造のみの移行です。申込・当落・当選TODO・支払い反映条件は従来どおりです。**

一覧は `public/events.php`、詳細は `event_detail.php`、登録・編集は `event_form.php`、APIは `public/api/events/` が公開入口です。旧画面はIDを保持して新画面へ移動し、旧 `/api/lives/` は新処理を呼ぶ互換入口です。互換APIだけ旧JSONキーを返します。

```mermaid
flowchart TD
    S["schedules：タイトル・推し・日時の共有元"] --> E["events：schedule_id・会場・開催状態・種類"]
    E --> U["user_event_status：本人の申込・当落・支払い"]
    U --> T["trips：本人の遠征"]
    U --> D["todos：本人の準備"]
    T --> R["transportations / accommodations：交通・ホテル"]
    U --> X["expenses：確認して反映した支出"]
    R --> X
```

矢印は情報のつながりです。外部キー（誤った関連付けを防ぐDB制約）は `events.schedule_id` が `schedules.id` を参照する方向です。支出のチケット反映元 `source_id` は **user_event_status.id** であり、event_idではありません。

`schedules.category` はカレンダー予定のカテゴリ、`events.event_type` は管理対象の種類です。別の項目であり、Step 1では既存・新規ともevent_typeは `live`。登録画面に種類選択はまだありません。将来の6種類の表示名は `config/events.php` の `EVENT_TYPES` にまとめています。

既存DBにはバックアップ・復元確認後に **009_event_management.sqlだけ** を一度適用します。この環境は適用済みです。適用済み006〜008は変更していません。新規の空DBには現在のschema.sqlを使用し、その後009を重ねて実行しないでください。DDL（テーブル定義の変更）は全体を一括で取り消せないため、書き込み停止・バックアップ・検証DBでの試行が必要です。

| 主要ファイル | 役割 |
|---|---|
| public/events.php / event_detail.php / event_form.php | 一覧・詳細・登録編集 |
| public/assets/js/events.js / css/events.css | API呼び出し・PC／スマホ表示 |
| config/events.php | 種類名・既存の状態名・TODOテンプレート |
| app/helpers/event_api.php / event_view.php | API認証・経路振り分けと共通画面部品 |
| app/helpers/legacy_event_api.php | 旧APIの入出力名だけを変換 |
| app/validators/event_validator.php | 保存前の入力検証 |
| app/services/event_service.php | イベント・本人管理・TODO・遠征の保存 |
| app/repositories/event_repository.php | DB取得。個人情報は本人IDで絞り込む |
| database/migrations/009_event_management.sql | 既存ID・値を保持する名称移行 |
| docs/EVENT_STEP1.md | バックアップ・移行・テスト結果と残した名称 |

### 処理の流れ

`event_form.php → events.js → public/api/events/create.php → api/events/create.php → event_api.php → event_service.php → 入力検証 → schedules / eventsへ保存 → JSON → event_detail.php`

`event_detail.php → events/status/update.php → user_event_status → 当選かつ移動区分選択済みなら不足TODOを生成`

`入金TODOの完了 → 本人の支払い状況 → money/source.phpで反映可否を確認 → 支出登録画面 → expenses/create.phpで再確認・重複防止 → expensesへ保存`

`live_ticket` の保存値・特効換算・当落の値は変更していません。新着の `kind=live` は互換値として受け付けますが、新JavaScriptは `kind=event` を使用します。分類条件自体は従来どおりです。

詳細は [Step 1実装報告](docs/EVENT_STEP1.md) を参照してください。以下のPhase別説明には各時点の検証結果・未実装項目の履歴も含まれます。


推し活の予定・イベント・遠征・お金を、自分の推しを中心にまとめるWebアプリです。
Phase 1の認証基盤に、Phase 2の推し検索・新規作成・編集・自分への登録/解除・メンバーとハートカラー・ホーム推し切替・レスポンシブ対応を追加しました。

Phase 3では予定の公開・非公開登録、月間カレンダー、他ユーザーの予定取り込み、自動同期と自分用編集を追加しました。詳細なファイル一覧・API・DB制約・学習解説・A/B確認手順は [Phase 3実装ガイド](docs/PHASE3.md) を参照してください。

Phase 4では修正提案・投稿者の承認／却下・感謝のリアクション・共有状況・当月集計を追加しました。[Phase 4実装ガイド](docs/PHASE4.md) に全ファイル、API、DB制約、確認手順をまとめています。

Phase 5ではイベント公演の共有、本人の申込・当落、当選後TODO、交通・ホテルをまとめた遠征管理、ホームの次のイベントを追加しました。[Phase 5実装ガイド](docs/PHASE5.md) にファイル一覧、API、DB、学習解説、検証結果、ブラウザでの確認手順をまとめています。

Phase 6では年間お金管理、共通／推し別の積立、支出、遠征費の確認後反映、特効換算を追加しました。マイページの「お金管理」から利用できます。[Phase 6実装・学習ガイド](docs/PHASE6.md) に指定21項目の報告と確認手順をまとめています。

## 今すぐ開く

**XAMPPでApacheとMySQLを起動して、<http://localhost/oshilife-v2/public/> を開きます。**

- 未ログインのindex.php → `/oshilife-v2/public/login.php`
- ログイン済みのindex.php → `/oshilife-v2/public/home.php`
- ホームやマイページ → **推し管理** → 検索 → 追加、または新しい推しを登録
- 推し詳細 → メンバー追加（共有マスター作成者のみ）

今の環境ではDB・テーブル・Apacheからのアクセスは設定済みです。schema.sqlの再実行は不要です。

## 保存先・ワークスペース・公開範囲

実際に編集するソースは、ユーザーが指定した現在のVS Codeワークスペースです。

```text
/Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife
```

ApacheがURLから参照する場所は、同じソースへのシンボリックリンク（別名の入口）です。

```text
/Applications/XAMPP/xamppfiles/htdocs/oshilife-v2
    → /Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife
```

ファイルは二重管理していません。現在のVS Codeで編集すると指定のlocalhost URLへ反映されます。Gitは現在のワークスペースのものを維持し、設定・履歴・インデックスを変更していません。他の旧Oshilifeプロジェクトや既存DBには変更していません。

以前の別コピーは削除せず `/private/tmp/oshilife-v2-phase1-apache-backup-20260928` に退避しました。このコピーは開発に使いません。一時領域なので恒久バックアップではありません。

Apacheの全体設定は変更せず、既存のFollowSymLinksとAllowOverride設定を利用します。プロジェクト直下の `.htaccess` でアクセスを拒否し、`public/.htaccess`だけで公開を許可します。`.env`、`.git`、config、database、storage、scriptsは直接取得できないことをHTTPで確認済みです。

本番では可能ならDocumentRootをpublicに設定し、非公開コードを公開領域の外に配置します。

## 使用技術・必要環境

- HTML / CSS Grid・Flexbox / JavaScript。外部CDN・フレームワークなし
- PHP 8.2以上、PDO / pdo_mysql / mbstring / session
- MySQL 8系またはMariaDB 10.4以上、InnoDB / utf8mb4
- ローカル：Mac、XAMPP Apache 2.4、MySQL
- 検証用：Python 3。CSV取込はPHP CLI

## .envとURL設定

`.env`はGit対象外の環境設定です。新規環境でのみ `.env.example` をコピーして作成します。現在の `.env` を上書きしないでください。

```dotenv
APP_URL=http://localhost/oshilife-v2/public
APP_ENV=local
APP_BASE_PATH=
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=oshilife_v2
DB_USER=root
DB_PASSWORD=
```

DBのroot・パスワードなしは、ユーザーが選んだXAMPPローカル専用設定です。本番では専用DBユーザーとパスワードを設定します。

`APP_URL`から公開パスを取り出し、PHPのappUrl()とJSのappUrl()、Cookieのpathへ同じ値を使います。CSS・JavaScript・API・フォーム成功後の遷移をバラバラに直す必要はありません。Hostヘッダーを信用してURLを作りません。

本番例：`APP_URL=https://example.com/v2`。本番がルート公開なら `https://example.com`。末尾の `/` はあっても取り除きます。本番はHTTPSと `APP_ENV=production` が必要です。DB接続情報も本番専用DBへ変更します。`APP_BASE_PATH`はPhase 1との互換用で、APP_URLがある場合には使用しません。

引用符で囲んだ設定値に対応しています。行末コメント・変数展開には対応しません。コメントは独立した行に `#` で書きます。サーバーで同名の環境変数が定義されていれば、その値を優先します。

## Apacheのファイル権限

このMacではApacheがdaemonユーザーで動くため、ACL（ユーザー単位のアクセス許可）で `.env` の読み取りと `storage/logs`・`storage/sessions`・`storage/rate_limits` への保存をdaemonにだけ許可しました。ソース全体を777にはしていません。

別環境へ配置するときは、そのサーバーのPHP実行ユーザーに同等の読み取り・保存権限を付けます。権限エラー時はApacheのログも確認してください。

従来のPHP内蔵8082番サーバーは停止しました。通常の確認にはXAMPP Apacheを使ってください。内蔵サーバーを使う場合はAPP_URLを `http://127.0.0.1:8082` へ変更して `php -S 127.0.0.1:8082 -t public` とし、Apacheに戻す際はAPP_URLも戻します。

## DBセットアップと更新

現在の `oshilife_v2` は、users / user_settingsにPhase 2の3テーブルを追加済みです。既存ユーザー1件を維持しました。

新規環境では未使用の専用DB名で作成します。既存OshilifeのDBに実行しないでください。

```sql
CREATE DATABASE oshilife_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

新規DBだけに、全体スキーマを実行します。

```sh
/Applications/XAMPP/xamppfiles/bin/mysql --protocol=TCP -h 127.0.0.1 -P 3306 -u root oshilife_v2 < database/schema.sql
```

別環境でPhase 1まで作成済みなら、全体ではなく追加分だけを一度実行します。

```sh
/Applications/XAMPP/xamppfiles/bin/mysql --protocol=TCP -h 127.0.0.1 -P 3306 -u root oshilife_v2 < database/migrations/002_oshi_management.sql
```

既存テーブルがあれば停止する設計です。DROPや既存テーブル再作成は行いません。MySQLのDDL（テーブル作成）は通常のデータ更新と異なり全体をrollbackできないため、実行前にバックアップとテーブル有無を確認します。さくらの接頭辞付きDB名へ移す場合は `.env` と新規用schema.sqlの `USE` を変更します。

## 実装範囲

Phase 1：新規登録、ログイン、ログアウト、ログイン状態取得、認証・CSRF、セッション、共通UI、各画面の仮UI。パスワードは10文字以上・72バイト以内。登録後はログイン画面へ案内します。

Phase 2：

- 共有推し検索（名前の部分一致、1ページ50件）と登録済み表示
- 推し新規作成と自分への自動登録、複数絵文字、推し種別
- 自分の推し一覧、確認ダイアログ付き解除、共有情報の維持
- メンバー追加・一覧、11種類のハート選択、カラー名・HEX保存
- ホームの選択肢をuser_oshisと連動。選択状態の表示まで実装
- スマホ・タブレット・PCを同一HTMLとCSS media queryで切替
- CSV取込の確認・明示実行の土台、日本語解説、HTTP統合テスト

作成済みの共有マスターのメンバー追加は**そのマスター作成者だけ**に限定しました。他ユーザーは検索・閲覧・自分への登録/解除ができます。推しの名前・種別・絵文字も作成者だけが詳細画面から編集できます。共同編集や無効化の管理画面は次Phaseで権限設計とともに追加します。

## レスポンシブ表示

| 幅 | レイアウト | ナビ |
| --- | --- | --- |
| 767px以下 | 画面幅を使った1列、カード縦並び | 固定下部ナビ |
| 768〜1199px | 余白を増やし、ホーム・一覧・詳細を2列 | 固定下部ナビ |
| 1200px以上 | 本文最大1360px、ホーム2列・推し一覧3列 | ヘッダー内の横並びナビ |

スマホとPCでHTMLを複製せず、ナビも1つだけです。ログイン/登録フォームは読みやすい幅を保ち、横いっぱいには伸ばしません。柔らかい色・角丸・薄い影は維持しています。

## 主要ファイルの役割

| ファイル / ディレクトリ | 役割 |
| --- | --- |
| public/ | 表示画面とCSS・JS、公開APIの入口 |
| api/ | JSON APIの本体。HTMLではなくデータを返す |
| app/services/ | 処理手順・トランザクション・権限判断 |
| app/repositories/ | PDOとSQLによるDB読み書き |
| app/validators/ | APIとCSVの入力検証 |
| app/helpers/ | 設定読込、URL、HTMLエスケープ、JSON応答など |
| middleware/ | 認証とCSRFの共通チェック |
| config/app.php | URL・セッション・共通起動設定 |
| config/database.php | .envの専用MySQLへ接続 |
| database/schema.sql | 新規環境用の全21テーブル（イベント名称対応） |
| database/migrations/002_oshi_management.sql | Phase 1からの追加3テーブル |
| database/seeds/ | 初期マスター用CSVテンプレート |
| includes/ | 共通ヘッダー・ナビ・フッター |
| scripts/import_masters.php | 公開しないCSV取込CLI |
| storage/ | 非公開の実行時データ |

個別の主要ファイル：

| ファイル | 役割 |
| --- | --- |
| public/index.php | ログイン状態で最初の移動先を振り分ける |
| public/login.php / register.php | 認証フォームの表示 |
| public/home.php | 本人の推しを取得し、切替とホームの表示例を出す |
| public/oshis.php | 自分の推し一覧、検索、新規作成の画面 |
| public/oshi_detail.php | 推し詳細、ハート付きメンバー、作成者向け追加フォーム |
| public/profile.php | 本人情報、推し管理リンク、ログアウト |
| public/calendar.php / discover.php / events.php | 次Phaseの機能の表示例 |
| public/assets/js/common.js | 同一公開パスでのAPI通信、CSRF、ログアウト |
| public/assets/js/auth.js | 認証フォーム送信と項目エラー表示 |
| public/assets/js/oshis.js | 検索・追加・確認付き解除・作成・ハート選択の送信 |
| public/assets/js/home.js | 選択した推しを案内。予定絞り込みは未対応 |
| public/assets/css/common.css | 全体レイアウトと3段階のレスポンシブ切替 |
| public/assets/css/oshis.css | 推しカード、詳細、ハートの選択状態 |
| public/assets/css/各画面.css | 画面固有の装飾 |
| app/repositories/oshi_repository.php | 共有推し、メンバー、本人の登録をJOINで取得・保存 |
| app/services/oshi_service.php | 作成と登録の同時確定、権限確認、解除 |
| app/validators/oshi_validator.php | 種別、名前、絵文字、ハート、HEX検証 |
| app/helpers/oshi_api.php | 推しAPIの認証・ID検証・日本語エラーの共通化 |
| api/oshis/*.php / members/create.php | 推し管理の10個の処理入口 |
| public/api/oshis/ | 上記の処理を呼ぶ公開用の小さなPHP |
| app/services/auth_service.php | パスワード生成・照合、ログイン状態の管理 |
| app/repositories/user_repository.php | ユーザー検索と設定の同時保存 |
| app/validators/auth_validator.php | 登録入力のサーバー側検証 |
| app/helpers/env.php / view.php | .env読込 / 安全なHTML表示とURL生成 |
| app/helpers/response.php / api_bootstrap.php | JSON統一、API例外処理、Apacheの419対応 |
| app/helpers/rate_limit.php | 認証への短時間リクエスト制限 |
| middleware/auth.php / csrf.php | ログイン確認 / CSRF照合 |
| includes/header.php / bottom_nav.php / footer.php | 共通UI。ナビはheader内に一度だけ出力 |
| tests/smoke.py | Phase 1の認証回帰テスト |
| tests/phase2_smoke.py | 2ユーザー・権限・共有・URLのHTTP検証 |
| tests/csv_smoke.php | CSVのdry-run・投入・再実行・拒否の検証 |

全ファイルの一覧とPhase 2変更区分は [docs/FILES.md](docs/FILES.md) を参照してください。

## 処理の流れ

矢印は「次に処理が進む先」を表します。APIはHTMLを返さず、JSONというデータを返します。

### 新規登録

```text
public/register.php（登録画面）
    ↓ フォームに入力して送信
public/assets/js/auth.js（入力をJSONへ変換）
    ↓ common.jsがCSRF付きPOSTを送信
public/api/auth/register.php（公開入口）
    ↓
api/auth/register.php
    ↓ メソッド・CSRF・送信頻度・JSON形式を確認
app/validators/auth_validator.php（入力チェック）
    ↓
app/services/auth_service.php（password_hashで変換）
    ↓
app/repositories/user_repository.php
    ↓ PDOのprepare + execute
usersへ保存 ＋ user_settingsへ初期設定保存
    ↓ 両方成功すれば確定、失敗なら両方取消
JSONレスポンス {success: true, data: {user_id: ...}}
    ↓ JavaScriptが読み取る
login.phpへ移動して「登録が完了しました」と表示
```

入力エラーなら422、重複emailなら409をJSONで返し、画面の該当欄に理由を表示します。

### ログイン

```text
public/login.php
    ↓ auth.js → common.js（CSRF付きPOST）
public/api/auth/login.php → api/auth/login.php
    ↓ auth_service → user_repository
emailからユーザー検索（deleted_at IS NULLのみ）
    ↓
password_verify（入力したパスワードと保存ハッシュを照合）
    ├ 不一致 → 401のJSON → 画面に日本語エラー
    ↓ 一致
session_regenerate_id(true)（セッションIDを新しくする）
    ↓
$_SESSION['user_id']へID保存、CSRFトークンも更新
    ↓ JSON成功応答
JavaScriptがpublic/home.phpへ移動
```

### ログイン必須ページ

```text
home.phpへアクセス（他4画面も同様）
    ↓
middleware/auth.php の requireAuth()
    ↓
セッションにuser_idがあるか確認
    ↓ あればDBでユーザーが有効か確認
    ├ ログイン済み → 共通ヘッダー・ナビ → ページ本文を表示
    └ 未ログイン   → login.phpへ移動
```

ブラウザー側でリンクを隠すだけでは保護できません。URLを直接入力した場合もPHPの門番が実行されます。

## API仕様

| メソッド / URL | 応答 |
| --- | --- |
| POST `/api/auth/register.php` | 201、登録したuser_id |
| POST `/api/auth/login.php` | 200、userと新しいcsrf_token |
| POST `/api/auth/logout.php` | 200、空オブジェクト |
| GET `/api/auth/me.php` | 200、user（未ログインはnull）とcsrf_token |

POSTは `Content-Type: application/json` と `X-CSRF-Token` が必要です。Cookieを同時に送ります。CSRFは画面のmeta要素またはme APIから取得します。401は認証失敗、405はメソッド違い、409は重複、419はCSRF不一致、422は入力エラー、429は送信回数超過、500は内部障害です。JSON形式違反は400/415、大きすぎる本文は413です。

```json
{"success":true,"data":{}}
```

```json
{"success":false,"message":"入力内容を確認してください。","errors":{"email":"正しいメールアドレスを入力してください。"}}
```

## DBの関係：共有マスターと中間テーブル

```text
users（ユーザー）
  1人 ── 複数の user_oshis（誰がどの推しを登録したか）
                    複数 ── 1つの oshis（共有の推し）
                                         1つ ── 複数の members（メンバー）
```

例えばAさんとBさんが同じ推しを登録しても、oshisは1行、user_oshisは2行です。この関連だけを持つ表を「中間テーブル」と呼びます。Aさんが解除しても、Aさんの関連1行が消えるだけで、Bさんや推し本体は残ります。

- **JOIN**：別々の表をIDでつなぎ、一緒に読み取るSQL。名前・絵文字をユーザーごとにコピーする必要がなくなります。
- **共有マスター**：皆が同じ情報を参照する元データ。今回のoshisとmembersが該当します。
- **UNIQUE**：DBが重複を拒否するルール。同時に2回送信しても `user_id + oshi_id` が同じ登録はできません。
- oshis：name + oshi_typeがUNIQUE。作成者、is_active、複数絵文字を保持。作成者ユーザーが削除されてもマスターは残します。
- members：oshi_id + nameがUNIQUE。ハートは❤️ 🧡 💛 💚 🩵 💙 💜 🩷 🤍 🖤 🤎から選択。
- user_oshis：本人と共有推しのIDのみを関連付け、registered_atを保持。
- 全テーブルutf8mb4、InnoDB。既存users / user_settingsの定義は変更しません。

## 推し登録・作成の処理フロー

```text
マイページ → oshis.php（推し管理）
   ↓ oshis.jsから検索GET
api/oshis/search.php
   ↓ 認証 → 入力確認 → repositoryのJOIN検索
JSONで共有推し＋自分の登録状態
   ├ 見つかった → 追加 → follow.php（POST＋CSRF）
   │                 ↓ セッションから本人IDを取得
   │               user_oshisだけをINSERT
   │
   └ 見つからない → 新規フォーム → create.php（POST＋CSRF）
                         ↓ 入力検証・password等の情報は受け取らない
                       serviceのトランザクション
                         ↓ oshis作成＋user_oshis追加を一緒に確定
                       詳細へ移動
                         ↓ 作成者だけメンバー追加が可能
                       members/create.php → 検証・所有者確認 → members保存
```

解除は確認ダイアログ → unfollow.php → セッション本人のuser_oshisだけDELETEです。フォームのuser_idを送っても使用しません。

## Phase 2 API一覧

下記のURLはすべて公開URLの配下です。例：`http://localhost/oshilife-v2/public/api/oshis/search.php?q=SixTONES`。
全APIでログインが必要です。POSTではJSONとX-CSRF-Tokenを送ります。

| メソッド / URL | 入力 | 成功データ |
| --- | --- | --- |
| GET /api/oshis/list.php | page（任意） | oshis、page、has_more |
| GET /api/oshis/search.php | q、page（任意） | oshis、page、has_more |
| POST /api/oshis/create.php | name、oshi_type、emoji | oshi（201） |
| POST /api/oshis/update.php | oshi_id、name、oshi_type、emoji | 更新後のoshi（作成者のみ） |
| POST /api/oshis/follow.php | oshi_id | 空オブジェクト |
| POST /api/oshis/unfollow.php | oshi_id | 空オブジェクト |
| GET /api/oshis/my.php | なし | 本人のoshis |
| GET /api/oshis/detail.php | id | oshi、is_followed/can_manageを含む |
| GET /api/oshis/members.php | id | members |
| POST /api/oshis/members/create.php | oshi_id、name、color_name、heart_emoji、hex_color | member_id（201） |

JSONの封筒はPhase 1と同じsuccess/dataまたはsuccess/message/errorsです。無権限403、対象なし404、重複409、入力不備422。無効化済み推しは検索・一覧・詳細・メンバー取得・新規追加の対象外です。

## CSVの扱い

`database/seeds/oshis.csv` と `members.csv` は正しいヘッダーだけを持つテンプレートです。実マスターの大量投入はしていません。

```csv
name,oshi_type,emoji
```

```csv
oshi_name,name,color_name,heart_emoji,hex_color
```

UTF-8で作成します。CSVは初期共有マスターだけを対象にし、user_oshisや利用中に増える個人データは変更しません。

確認のみ（creator-idは実在する自分のユーザーIDに置き換える）：

```sh
/Applications/XAMPP/xamppfiles/bin/php scripts/import_masters.php --creator-id=1
```

確認結果を見て、投入する場合のみ `--apply` を追加します。

```sh
/Applications/XAMPP/xamppfiles/bin/php scripts/import_masters.php --creator-id=1 --apply
```

別ファイルは `--oshis=/path/oshis.csv --members=/path/members.csv` で指定できます。既定はdry-runなのでDBに書き込みません。入力ルールはAPIと共通です。全体検証後にトランザクションで投入し、途中失敗時は取消します。同じ内容はスキップし、既存値と異なる場合は上書きせずエラーにします。

メンバーCSVは推し名で対応づけます。同名・別種別の推しが複数ある場合は、誤って紐付けないよう停止します。別作成者のマスターにはメンバーを追加できません。1ファイル5000行までです。

## セキュリティ対策と理由

| 処理 | 必要な理由 |
| --- | --- |
| PDO prepare + execute | 入力をSQLの命令と混ぜず、SQLインジェクションを防ぐ |
| password_hash / password_verify | 平文を保存せず照合する。復号して比較する仕組みではない |
| session_regenerate_id(true) | ログイン前の既知のIDを攻撃者に使い回されないようにする |
| CSRFトークン + SameSite | 外部サイトから意図しない登録・ログイン・ログアウトを実行させない |
| htmlspecialchars / textContent | ユーザーの文字をHTMLやJavaScriptとして実行しない |
| 認証middleware | 各保護ページの入口で確認を統一し、確認漏れを減らす |
| .env + public分離 + .gitignore | 認証情報を公開ファイルやGit履歴へ入れない |
| HttpOnly / Secure / strict mode | JSからCookieを読み取れなくし、本番HTTPSで送信し、不正なIDを受け付けない |
| セッション2時間の無操作期限 | 放置されたログイン状態を無期限に残さない |
| IP単位の30回/15分制限 | パスワード総当たりや大量登録を抑える（Cookie削除で回避不可） |
| CSP / no-store | 外部スクリプト等の実行を制限し、認証ページのキャッシュを抑える |
| 汎用エラー応答 | SQLや接続情報を利用者へ漏らさない |

回数制限は単一サーバー向けの簡易版です。共有IPでは利用者が同じ枠を使い、複数IPの攻撃は防げません。storage/rate_limitsの期限切れファイルは定期保守で整理してください。本番ではアクセス監視・共有ストア型制限などを検討します。

## 検証方法と結果

XAMPP Apache / MySQLを起動し、プロジェクトルートから実行します。

```sh
python3 tests/smoke.py --base http://localhost/oshilife-v2/public
python3 tests/phase2_smoke.py
/Applications/XAMPP/xamppfiles/bin/php tests/csv_smoke.php
```

テストは固有名の一時ユーザーと推しだけを作り、最後にそのデータだけを削除します。実ユーザーは削除しません。認証APIの回数制限に達した場合は15分待ちます。PHP/JS構文、Apache上の認証30件・Phase 2 HTTP109件を確認済みです。

実ブラウザーの操作権限が許可されなかったため、PC/スマホの見た目・ハート選択・確認ダイアログ・JavaScriptによる実際の画面遷移は未確認です。HTTPでのCSS/JS配信・API・遷移先と、JavaScript構文検証とは区別しています。

手動で確認する操作：

1. 指定URLを開き、ログイン画面とCSSを確認する。
2. 新規登録 → ログイン → ホームへ移動する。
3. マイページ → 推し管理 → 名前で検索する。
4. 見つからなければ新規作成（例：絵文字👑🐃）。
5. 詳細でメンバーを追加し、🩷や🖤の選択状態と一覧を確認する。
6. ホームの推し切替に作成した推しが出ることを確認する。
7. 登録解除の確認でキャンセル/実行を試し、検索結果には共有推しが残ることを確認する。
8. 別ユーザーでも検索・追加でき、作成者以外にはメンバー追加フォームが出ないことを確認する。
9. 幅390px、900px、1280px以上でナビ・カード配置・横スクロールを確認する。
10. ログアウトし、home.phpへ直接アクセスするとlogin.phpへ戻ることを確認する。

詳しい変更ファイルと結果は [Phase 2レポート](docs/PHASE2.md)、認証の基本は [学習ガイド](docs/LEARNING.md) にまとめました。

## Phase 4当時の未実装とPhase 5へ進む前の確認（履歴）

予定共有・同期・修正提案・感謝のリアクション・配色設定は実装済みです。イベント本機能、遠征、会場周辺スポット、お金管理、特効換算、連番ルーム、Chat、月次カードの固定保存、年末振り返りは未実装です。推しの無効化画面、メンバー編集・削除、メール確認・パスワード再設定も今後の範囲です。

- 実ブラウザのスマホ／PC表示と操作を確認する。
- 月次集計は現在残る追加・リアクションを数える仕様を確認する。
- 共有マスターの共同管理・作成者退会後の権限、論理削除からの復帰ルールを決める。
- 次のDB変更も新しいmigration SQLで追加する。既存DBへschema.sqlを再実行しない。
- スマホアプリ化では現在のCookieセッションからの認証方式・CORS・CSRFを別途設計する。

今回の未実装範囲と詳しい確認事項は [Phase 4ガイド](docs/PHASE4.md#未実装phase-5前の確認事項) を参照してください。

## 登録した推し情報の編集

マイページ → 推し管理 → 詳細を見る → **推し情報を編集** → **変更を保存**で、名前・種別・絵文字を変更できます。現在の内容が入力済みで表示され、キャンセルすると元の値へ戻ります。

共有マスターなので、変更はその推しを登録している他のユーザーの一覧・検索・ホームにも次回取得時に反映されます。推しのIDを維持するため、登録関係やメンバー情報は残ります。編集は作成者本人だけが行えます。

処理は `oshi_detail.php → oshis.js → api/oshis/update.php → 入力検証 → oshi_service.phpで作成者確認 → oshi_repository.phpでUPDATE → 詳細を再表示` の順です。POST/CSRF、ログイン確認、PDOプリペアドステートメントを使い、重複名・種別は409として変更を取り消します。新しいDBテーブルやmigrationは不要です。

編集APIと権限・重複・無効化・既存関連の保持を含め、HTTP109件を確認しました。実ブラウザーの操作確認は未実施です。

## 推しテーマカラーの廃止

推し本体は名前・種別・絵文字だけを登録・編集します。新規登録、編集、詳細表示、API、推しCSVからテーマカラーを外しました。メンバーのカラー名・ハート・HEXは継続して使用します。既存データを削除しないため、DBのoshis.theme_colorは旧仕様との互換用に残していますが、アプリは読み書きしません。DB変更の実行は不要です。

## メンバーカラーの選び方

メンバー追加では、色名付きのハートを選ぶだけで登録できます。色名と保存用の色コードは自動で設定されるため、HEXを入力する必要はありません。「色を細かく調整する（任意）」を開けば、色見本から好きな色を選び、色名も変更できます。メンバー一覧はハートと色名を表示します。

実装では `oshi_validator.php` の `MEMBER_COLOR_PRESETS` をHTMLのdata属性へ渡し、`oshis.js` の `selectMemberColor()` が選択されたハートに合わせて入力値を更新します。DB/APIでは引き続きhex_colorを内部の保存形式として利用し、既存データの変換やDB変更は不要です。


## 主要ファイルの役割（Phase 3追加）

| ファイル / ディレクトリ | 役割 |
| --- | --- |
| public/schedule_form.php・schedule_detail.php | 予定の入力と詳細画面 |
| public/calendar.php・discover.php・home.php | 月間／日別予定、公開検索、今日と未追加の新着 |
| api/schedules/・public/api/schedules/ | 予定の12 API本体と公開入口 |
| app/services/schedule_service.php | 所有者チェック、保存、取り込み、同期解除 |
| app/repositories/schedule_repository.php | 元予定と取り込みのJOIN・DBアクセス |
| app/validators/schedule_validator.php | 日時・URL・IDなどの入力検査 |
| config/schedules.php | カテゴリと情報元の日本語表示を共通化 |
| database/migrations/003_schedules.sql | 既存DBを壊さず4テーブル追加（現在適用済み） |
| public/assets/js/schedules.js | 安全な予定カード表示と追加操作 |
| scripts/create_demo_users.php | ローカル確認専用A/Bユーザーを生成 |

個別JS・共通UI・テスト・変更ファイルも含めた一覧は [Phase 3ガイド](docs/PHASE3.md) にあります。

## 処理の流れ（Phase 3追加）

```text
公開予定登録
schedule_form.php → JavaScript → create API
  → ログイン・CSRF・入力・重複候補の検査
  → schedules（予定本体）
  → schedule_members（複数メンバーとの関係）
  → schedule_sources（情報元）
  → 全部成功なら確定 → JSON → 詳細画面

他ユーザーが追加
公開予定詳細／ホーム／見つける → add-to-calendar API
  → public・activeを確認 → user_schedulesへ関係だけ登録
  → 自分のカレンダーに表示

同期
作成者がschedulesを更新
  → sync_enabled=trueのユーザーが画面を再取得
  → 元のschedulesの最新値を読む → 表示も変わる

自分用編集
自分用に編集 → 同期解除の確認ダイアログ → customize API
  → sync_enabled=false → custom_*へ保存
  → 以後、個人のタイトル・日時・終日・メモを表示
  （元schedule_idは残す）

ホーム新着
publicかつactive → 自分の推しか → 他人が作った予定か
  → user_schedulesに本人の取り込みがないか
  → YES → ホームへ表示
  → 追加成功 → カードを消す → 件数・今日の予定を再取得
```

自分が作成した予定はuser_schedulesへ複製しません。非公開予定は常に作成者だけが閲覧できます。同期は画面の取得時に反映し、開いた画面への即時配信ではありません。同期解除後も中止・削除状態は共有元を参照します。

確認ユーザーA/Bの認証情報は `storage/demo-accounts.txt` に保存済み（Git除外・非公開）。[A/B切替手順と検証結果](docs/PHASE3.md#ブラウザでabを切り替える確認手順) に従ってlocalhostで確認してください。API統合検証は合格済みですが、Chrome操作が許可されなかったためブラウザでの描画・操作確認は未実施です。

## 自分の好きな配色にする

マイページ → **画面の色**でメインカラーと背景を選択し、**この色で保存**を押します。ブルー・グリーン・オレンジ・レッド・パープル・ピンク・イエロー・モノトーンの色名から選べるほか、色の見本をクリックして自由に選べます。色コードの入力は不要です。背景はライト・ダーク・自由な色を選択できます。

プレビューは保存前にこの画面へ反映します。「保存した色に戻す」で取り消せます。保存後はホーム、カレンダー、見つける等へ共通で反映し、別端末でも同じアカウントなら設定を読み込みます。既存アカウントの保存色は維持し、文字色・カード色を配色に合わせて生成します。未ログイン時は標準のブルーとライト背景です。

### 主要ファイルの役割（配色追加）

| ファイル | 役割 |
| --- | --- |
| `public/profile.php` | 色名と色選択、ライト／ダーク、保存・取消の画面 |
| `public/assets/js/theme.js` | プレビューとJSON APIへの保存、成功・失敗メッセージ |
| `app/helpers/theme.php` | 本人の配色の読書き、入力検証、文字色の明暗差を計算 |
| `api/settings/theme.php` | GETで本人の配色を取得、POSTで保存。認証とCSRFを検査 |
| `public/api/settings/theme.php` | 上記APIの公開入口 |
| `public/theme.css.php` | 本人の設定をCSSとして返す。プレビュー時もDBは更新しない |
| `public/assets/css/theme.css` | ホームの固定グラデーション等を共通変数へ置換する表示ルール |
| `includes/header.php` | 全ページで共通の配色CSSを読み込む |
| `tests/theme_smoke.py` / `tests/theme_colors.php` | APIの保存・認可と文字の明暗差を検証 |

### 処理の流れ・コードの解説

```text
マイページで色を選ぶ → JavaScript → theme.css.php（試着用CSS）→ この画面へ反映
「この色で保存」 → POST api/settings/theme.php
  → ログイン本人をセッションで確認 → CSRF・色の形式を検証
  → user_settingsのtheme_color（背景）とaccent_color（メイン色）を保存
別のページを開く → 共通ヘッダー → theme.css.php
  → 本人のuser_settingsを読む → 共通CSS変数 → 保存した配色を表示
```

セッションはログインした本人をサーバー側で覚える仕組みです。user_idを入力から受け取らずセッションから使うので、他人の配色を上書きできません。既存の `user_settings` 2カラムを使い、DB構造の変更はありません。配色設定のSQLもPDOのprepare/executeで値を別送します。

CSS変数は「背景色」「文字色」などに付ける共通の名前です。各画面へ色を直接埋め込まず、共通の変数を変えて一括反映します。任意の色でも文字が消えないよう、明るさを計算して本文とボタンの文字色を白・黒から選び、リンクも背景との明暗差を確認します。カードは読みやすい明るさに調整し、選んだメインカラーはボタン・ナビ・カードの縁へ反映します。

外部CSSとして返す構成なので、既存のCSP（実行できるスクリプトやCSSの制限）を緩める必要はありません。CSSの入力は6桁の色だけを許可し、CSSへ別の命令が混入するのを防ぎます。本人の色を他のアカウントで使い回さないようCSSはキャッシュしません。

色の候補を追加するなら `THEME_PRESETS` とCSSの色見本、配色の計算は `themeVariables()`、画面の配置は `theme.css` と `profile.php` を変更します。

2026-09-30：32件のHTTP検証で保存・再ログイン後の復元・本人限定・CSRF・不正な色の拒否・プレビューでDBを変更しないことを確認。一時ユーザーは削除済みです。明暗差の自動検証とPHP/JavaScriptの構文検査も実施。実ブラウザでの色選択操作・描画確認は未実施です。

## カレンダーの日付から登録・予定を削除する

- カレンダーの日付の数字を押すと、選択日が入力された予定登録画面へ進みます。
- 日付の下の件数を押すと、その日の予定一覧を表示します。右上の「予定を登録」も選択日を引き継ぎます。
- ホームの今日の予定・カレンダーの日別一覧で「予定を削除」を押し、確認すると自分で登録した予定を削除できます。削除後は一覧と件数を再取得します。
- 他ユーザーから取り込んだ予定は「カレンダーから外す」で自分の取り込みだけを解除します。元の共有予定は残ります。

`calendar.js` が日付付きの登録URLと日別一覧ボタンを作り、`schedules.js` が共通の削除操作を担当します。JavaScriptから既存の削除／取り込み解除APIへ送信し、PHPがログイン本人と所有者を確認します。自分の予定はstatusをdeletedにする論理削除なので、共有元への参照を壊しません。`home.js` と `calendar.js` は操作成功後に最新の一覧と件数を取得します。

2026-09-30：日付引き継ぎ・非公開予定の削除後の表示を含む156件のHTTP検証に合格。`node tests/calendar_ui.mjs` でも日付リンク、日別一覧、削除確認のキャンセル、削除と取り込み解除の使い分け、件数更新を検証しました。このJS検証はDOMのモデルによる動作確認であり、実ブラウザの描画確認ではありません。

### 更新した画面の動きが反映されない場合への対応

共通ヘッダーからCSS/JavaScriptを読み込むとき、`app/helpers/view.php` の `assetUrl()` がファイル内容のハッシュ（内容から作る識別子）を `?v=...` としてURLへ付けます。ファイル更新時にURLが変わるため、ブラウザーが前のJavaScriptを使い続けることを防ぎます。本人別の動的な配色CSSは従来どおりno-storeで取得します。

カレンダーの日付は数字だけを表示し、押すとその日付の登録画面へ進みます。下の件数ボタンは一覧表示です。2026-09-30、localhostが返すHTMLのバージョン付きURL、実際に配信されるJSとローカルファイルの一致、日付の引き継ぎを含む158件のHTTP検証に合格しました。ブラウザーに古いコードが残っていたか自体は直接確認していません。


## 主要ファイルの役割（Phase 4追加）

| ファイル / ディレクトリ | 役割 |
| --- | --- |
| config/feedback.php | 修正項目・状態・感謝の種類を共通管理 |
| app/services/feedback_service.php | 修正提案・承認・却下・感謝の切替と権限検査 |
| app/repositories/feedback_repository.php | 提案履歴・共有状況・月次集計のSQL |
| app/helpers/feedback_api.php | API共通の認証・CSRF・サービス読み込み |
| public/corrections.php | 投稿者本人に届いた提案を確認する画面 |
| includes/schedule_feedback.php | 公開予定詳細の感謝・共有状況・提案フォーム |
| public/assets/js/feedback.js・corrections.js・monthly_summary.js | 詳細・提案一覧・ホーム集計の動き |
| public/assets/css/feedback.css | 本人の配色とスマホ／PCに合わせた表示 |
| api/schedules/corrections/・reactions/・stats.php | 提案4 API、感謝2 API、共有状況API |
| api/reflections/monthly-summary.php | 将来の振り返りにも使える当月／指定月の集計 |
| database/migrations/004_feedback.sql | correction_requestsとreactionsを追加（現在のDBは適用済み） |

## 処理の流れ（Phase 4追加）

```text
修正提案
Bさん → 公開予定 → 修正を提案 → correction_requestsへpendingで保存
  → Aさん（投稿者）がマイページで確認
  → 承認 → schedulesの1項目だけ更新＋提案をapprovedに変更
  → 同期中ユーザーが次に予定を取得 → 最新内容を表示
  → 自分用編集のcustom_*は保持

リアクション
公開予定 → 助かった！／ありがとう！ → toggle API
  → reactionsへ追加（再度押すと本人の同種行を解除）
  → 種類別に集計 → 件数・本人の選択状態を表示

共有状況
user_schedules → カレンダー追加の件数・人数
reactions → 助かった！・ありがとう！の件数
  → 本人の公開予定に限定 → 月初以上・翌月初未満で絞る
  → ホーム「最近のありがとう」へ表示
```

他人が直接UPDATE（DBの上書き）できると、未確認の内容が同期先へ広がるため、提案用テーブルで確認を待ちます。承認APIは投稿者本人か検査し、提案後に元の値が変わっていた場合も上書きを拒否します。トランザクションで元予定と履歴を一緒に確定し、却下しても履歴を残します。

reactionsのUNIQUE制約は同じ予定・本人・種類の二重登録を防ぎます。toggleは未登録なら追加、登録済みなら解除という切替です。COUNTは件数、COUNT DISTINCTは重複しない人数。1人が2予定を取り込むと2件・1人です。月次は開催日ではなく追加・感謝が行われた日時で数え、解除した行は集計から外れます。順位は作らず、本人へ共有が役立ったことを伝えます。

情報元は共通の種類名と「元情報を見る」リンクで表示し、登録件数・更新日時・確認待ち提案の有無を添えます。未承認の内容や根拠のない信頼度スコアは正式情報として扱いません。

[Phase 4ガイドの確認手順](docs/PHASE4.md#ブラウザで確認する手順) でA/B/Cを切り替えて確認できます。APIとDOMモデルの検証は合格済みですが、実ブラウザでの描画・操作確認は残っています。

## 受信通知とホームのミニダッシュボード

「助かった！」「ありがとう！」「修正提案」を受け取ると、ログイン中の画面右上に通知ポップアップが出ます。アプリを表示している間は約20秒ごとに確認し、背景タブでは停止、戻ったときに再確認します。アプリを閉じている間のOSプッシュ通知ではありません。ログインして開き直したときにも未表示分を確認できます。

ポップアップは最大3件、12秒後に消えますが、ヘッダーの「通知」に未読として残ります。通知を押して詳細／修正提案一覧を開くか、「表示中の通知を既読にする」で既読になります。本人の表示済み・既読はDBへ保存され、ページ移動後にも引き継ぎます。最新30件を一覧表示します。同じ人・予定・種類のリアクションを押し直しても通知行を増やさず、解除された感謝は一覧・未読件数から除外します。

ホームの「最近のありがとう」は、月表示と、カレンダー追加／助かった！／ありがとう！の3つの数値を並べたダッシュボードです。0件も同じ位置に数字で表示し、長い説明は短い一言へまとめました。推し切替と本人の配色に引き続き連動します。

| ファイル | 役割 |
| --- | --- |
| database/migrations/005_notifications.sql | 通知テーブル・未読INDEX・イベントのUNIQUE。既存の感謝と確認待ち提案も移行済み |
| app/repositories/notification_repository.php | 通知生成、本人限定の一覧、表示済み・既読、解除済み感謝の除外 |
| app/services/feedback_service.php | 提案・感謝の保存と同じトランザクションで通知を保存 |
| api/notifications/list.php・read.php | GETで一覧・未読・未表示、CSRF付きPOSTで本人の表示済み・既読を更新 |
| public/api/notifications/ | 上記APIの公開入口 |
| includes/notifications.php・header.php・footer.php | 共通の通知ボタン・一覧・ポップアップ領域をログイン画面へ配置 |
| public/assets/js/notifications.js | 定期確認・ポップアップ・一覧・既読操作 |
| public/assets/css/notifications.css | スマホ／PCと本人の配色に合わせた通知表示 |
| public/assets/js/monthly_summary.js・css/feedback.css | ホームの数値ダッシュボード |

```text
他ユーザーの感謝・修正提案
  → 既存データとnotificationsをまとめて保存
  → 受信者の画面が通知APIを定期確認
  → ポップアップ表示 → shown_atを保存（未読は残す）
  → 通知リンクを開く／既読ボタン → read_atを保存
```

通知先や本人IDはサーバー側で決めます。タイトルはHTMLとして実行させず文字として表示します。既読POSTも必ず通知先本人を条件にするため、他の人の通知IDを送っても更新できません。既存の感謝・提案・ユーザー設定は消していません。

2026-09-30：通知を含む190件のHTTP検証に合格し、既存12テーブルの内容がテスト前後で一致。一時データは清掃済み。DOMモデルで3数値の表示、ポップアップ、表示済みと既読の分離、同画面での再表示防止、文字列表示を確認しました。実ブラウザでの見た目の検証は未実施です。

## Phase 5：主要ファイルの役割

| ファイル / ディレクトリ | 役割 |
| --- | --- |
| `public/events.php` / `event_form.php` / `event_detail.php` | 公演の検索・共有登録・本人の当落と準備 |
| `public/trip_detail.php` / `travel_form.php` | 本人の遠征まとめ、複数の交通・宿泊の入力 |
| `public/home.php` | 直近の管理中イベント・残り日数・未完了TODO |
| `public/assets/js/events.js` | API送信、重複確認、TODO操作、検索、ホームの推し切替 |
| `public/assets/css/events.css` | 本人のテーマ色を使ったPC・スマホ向け配置 |
| `config/events.php` | 状態の日本語表示と近場6項目／遠征8項目のTODOテンプレート |
| `app/helpers/event_view.php` | 認証済み画面の共通入力欄・TODO表示 |
| `app/helpers/event_api.php` | 共通の認証・CSRF・API振り分け |
| `app/validators/event_validator.php` | 必須入力・日付順序・金額・URLの検証 |
| `app/services/event_service.php` | 共有公演、本人状況、TODO、遠征の保存手順 |
| `app/repositories/event_repository.php` | 共有公演と本人限定データのSQL取得 |
| `api/events/` / `api/trips/` / `api/venues/` | イベント・個人管理・TODO・遠征・交通・宿泊・会場のAPI |
| `public/api/` 内の同名入口 | ブラウザからAPIへアクセスする公開URL |
| `database/migrations/006_live_management.sql` | 既存DBを維持して7テーブルを追加（この環境は適用済み） |
| `tests/phase5_smoke.py` / `tests/events_ui.mjs` | 3ユーザーのHTTP・DB検証とJavaScript操作検証 |

## Phase 5：処理の流れ

```text
共有公演の登録
event_form.php → events.js → api/events/create.php
  → 認証・CSRF → 入力検証・重複候補確認
  → schedulesへ公演の日時・タイトルを保存
  → eventsがschedule_idを参照 → JSON応答 → 詳細へ

自分の当落
event_detail.php → events.js → api/events/status/update.php
  → セッションから本人ID取得 → user_event_statusへ保存
  → 当選＋近場なら6項目、当選＋遠征なら8項目のTODO
  → 固定のtemplate_keyで不足分だけ追加 → 画面へ

遠征
本人の当落で遠征を選択 → tripsを1人1公演1件作成
  → 交通transportations／ホテルaccommodationsを複数登録
  → trip_detail.phpで公演・交通・ホテル・TODO・会場を確認

ホーム
home.php → events/next.php → 本人が管理する今日以降の公演
  → 中止・終了・本人が落選した公演を除いた直近日 → 残り日数・未完了TODOを表示
```

`events` は共有公演、`user_event_status` 以下は本人の情報です。同じ公演を複数人が管理しても当落や予約は混ざりません。セッション（サーバー側のログイン記録）の本人IDで必ず絞り、他人のtrip_idを送られても取得・編集を拒否します。

イベント1公演は既存の公開予定1件に対応します。タイトル・日時は `schedules` に一元化し、同期中のカレンダーは変更を反映、自分用に編集済みの内容は維持します。本人の管理を保存するとカレンダーにも重複なく追加されます。延期・中止は削除せず状態として残します。

TODOは表示名を変えても固定識別子で判定するため、再当選で増えません。削除済みの識別子も残し、勝手に復活させません。近場→遠征は交通・ホテルなど不足分だけ追加、逆の変更では既存TODOや予約を削除しません。

APIはJSONでデータを返すPHPの入口、serviceは保存手順、repositoryはDBへの問い合わせ、validatorは入力チェックです。コードを読む順序と認証の仕組みは [Phase 5ガイドの学習解説](docs/PHASE5.md#15-個人情報へのアクセス制御と初心者向け解説) に記載しています。

2026-09-30：Phase 5は147件、既存Phase 3は176件、Phase 4は192件のHTTP確認に合格。一時テストデータ清掃後、全19テーブルの元データが維持されていることを確認しました。JavaScript操作のDOMモデル検証も合格。**実ブラウザでのPC／スマホの見た目・操作確認は未実施**です。[ブラウザ確認手順](docs/PHASE5.md#18-ブラウザで確認する手順) を参照してください。

### ホームの新着を公開予定とイベント情報に分ける

「新着の公開予定」はイベント以外、「新着のイベント情報」はイベント管理の連携公演とイベントカテゴリの予定です。両方とも本人が登録した推しの未追加公開情報だけを表示します。今日の予定と「次のイベント」は従来どおりです。

`home.php` が2つの表示欄を用意し、`home.js` が `api/schedules/unadded.php?kind=schedule` と `kind=live` を別々に取得します。`schedule_repository.php` は分類後に件数集計と20件ずつのページ分割をするため、表示後に間引いて件数がずれることを防ぎます。kind省略時は従来の全種類取得を維持します。

各欄の件数・追加読込は独立し、0件の件数表示は隠します。カレンダーへ追加した後は両欄を取り直します。`tests/home_feeds_ui.mjs` で独立したページ送り、追加後更新、推しを連続変更した場合の古い応答の破棄を検証しています。

## Phase 6：主要ファイルの役割

| ファイル / ディレクトリ | 役割 |
| --- | --- |
| `public/money.php` | 年間残高・積立・支出、月／カテゴリ／推し別内訳、特効換算 |
| `public/saving_form.php`・`expense_form.php` | 本人の登録・編集・削除と遠征費の反映確認 |
| `public/assets/js/money.js`・`css/money.css` | 年・推し切替、棒グラフ、特効タブ、フォーム、ホームの資金表示 |
| `config/money.php` | カテゴリ・特効初期値、炎100円／CO₂300円／銀テ4,000円の基準・注意書き |
| `app/validators/money_validator.php` | 入力形式、金額・年・推しの検証 |
| `app/services/money_service.php` | 本人と関連先の確認、保存・削除、二重反映防止 |
| `app/repositories/money_repository.php` | 本人限定取得、SUM・GROUP BYによる集計、元予約の確認 |
| `app/helpers/money_api.php`・`money_view.php` | API認証・CSRF・共通画面部品 |
| `api/money/`・`public/api/money/` | 積立・支出・集計・特効・反映元のAPIと公開入口 |
| `database/migrations/007_money_management.sql` | savings・expensesの追加（この環境は適用済み） |
| `tests/phase6_smoke.py`・`tests/money_ui.mjs` | HTTP・DB・権限と画面操作の検証 |

## Phase 6：処理の流れ

```text
積立：ユーザー → 積立フォーム → savings → 年間集計
支出：ユーザー → 支出フォーム → expenses → 年間集計
残高：前年繰越 ＋ 選択年の積立 − 選択年の支出 → 現在残高

遠征費：交通・宿泊 →「お金管理に反映」→ 内容を確認して保存
  → 元予約の本人確認 → expenses → UNIQUEで二重登録を防止

特効：本人のexpenses → special_effect_eligible=true → SUM
  → 炎／CO₂／銀テの基準額で割る → 特効タブへ表示
```

積立と支出は役割が異なるためsavingsとexpensesに分けます。oshi_idをNULL可能にすることで共通積立と推し別積立を1件ごとに混在できます。全推しでは全記録、推し別ではその推しだけを集計し、共通積立は配分せず別の参考額として表示します。

前年繰越は対象年の1月1日より前の積立合計−支出合計です。**SUM** は金額の合計、**GROUP BY** は月・カテゴリ・推しでまとめるSQLです。年間は年全体を合計し、月別は同じ年の記録を12ヶ月に分けます。カテゴリ別は同じカテゴリ同士を集計。推し別の内訳は金額ランキングにしません。

特効は直接の推し活支出だけが対象です。手数料・交通・宿泊・食事・観光・その他遠征は対象外。登録時のspecial_effect_eligibleを保存するので、将来カテゴリ設定が変わっても過去の換算額を勝手に変えません。詳しい定義と指定の注意書きは画面の「換算について」に表示します。

交通・宿泊の金額は確認してから反映し、予約の作成だけではexpensesへ登録しません。user_id＋source_type＋source_idのUNIQUEで同じ予約の二重反映を防ぎます。反映後に元予約を変更・削除しても支出は自動変更せず、お金管理側で編集・削除します。支出を削除した後は、元予約が残っていれば再確認して反映できます。

金額は既存予約と同じDECIMAL(12,2)で保持し、小数を失いません。ホームの「推し活資金」も同じ年間APIの実データを使い、上部の推し切替に追随します。

Phase 6は167項目のHTTP検証と、全21テーブルの元データ維持を確認。Phase 5の147項目、新着分類・イベント・カレンダー・通知などのJavaScript検証も合格しています。**実ブラウザでのPC／スマホの描画・操作は未確認**です。各ファイルの詳しい役割、認証・API・DB処理の読み方、指定21項目の報告、ブラウザ確認手順は [Phase 6ガイド](docs/PHASE6.md) を参照してください。

## 支払い完了からお金管理への反映連携

イベント詳細の「自分の管理」にチケット代、交通・ホテルの編集に支払い状況を追加しました。支払い操作だけではexpensesへ登録しません。

| 種類 | 反映ボタンが出る条件 | 特効換算 |
| --- | --- | --- |
| チケット | 当選・金額>0・固定キーpaymentの入金TODO完了・未反映 | 対象 |
| 交通 | 予約済み・支払い済み・金額>0・未反映 | 対象外 |
| ホテル | 予約済み・支払い済み・金額>0・未反映 | 対象外 |

```text
支払い完了 →「お金管理に反映できます」（支出はまだ増えない）
  → 利用者が反映ボタンを押す → 金額・支払日などを確認して保存
  → expensesを作成 ＋ 元のexpense_idを保存（同じトランザクション）
  → ✓ お金管理に反映済み

支出を削除 → DBのON DELETE SET NULLで元のexpense_idを解除
  → 支払い条件を満たしていれば再反映可能
```

確認を挟むのは、意図しない登録を防ぎ、金額や支出日を利用者が選べるようにするためです。交通・ホテルは周辺費用なので年間支出には含め、特効換算には含めません。

既存の反映APIを拡張し、expense_id確認とUNIQUEの両方で二重登録を防ぎます。元の金額変更で会計を自動上書きせず、差がある場合は警告と既存支出の編集リンクを表示します。

`008_payment_import.sql` は適用済み。既存の交通・宿泊支出も元のexpense_idへ引き継ぎました。支払いの過去状態は推測しません。`app/helpers/payment_view.php` が3状態を共通表示し、`money_repository.php` が反映条件と本人、`money_service.php` が保存前の再確認とID連携、`event_service.php` が入金TODO・支払い状態を担当します。

支払い連携151項目と既存お金管理167項目のHTTP検証、全21テーブルの元データ維持を確認しました。実ブラウザ操作は未実施です。変更ファイル・追加列・API・学習解説・ブラウザ確認手順の13項目は [支払い連携ガイド](docs/PAYMENT_IMPORT.md) を参照してください。
