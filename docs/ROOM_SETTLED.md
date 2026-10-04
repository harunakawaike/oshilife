# 連番ルーム：精算済み管理（2026-10-04）

## 1. Git開始時状態

`b5292d9 共同支出`。作業ツリーはクリーン。既存の精算サマリーと「相手に支払う」表記を保持。今回はコミットしていない。

## 2. バックアップ

使用コマンド：

```sh
/Applications/XAMPP/xamppfiles/bin/mysqldump -h 127.0.0.1 -u root --single-transaction --skip-events --skip-routines --triggers --hex-blob oshilife_v2 --result-file=/private/tmp/oshilife-settled-20261004/before.sql
```

サンドボックスの接続制限で最初の実行は2002になったため、接続許可を取得して再実行。終了コード0、54,841バイト、29テーブルのCREATE TABLE・INSERTを確認。Event Scheduler定義は除外、triggerは保持する指定。ファイル権限は600。

復元先は新規検証DB `oshilife_v2_rooms_verify_expenses_settled_20261004`。全29テーブルの行・ID・件数・DDL（INDEX/UNIQUE/外部キー/採番を含む）が元DBと一致。復元可能と判断。検証DBは削除せず残している。

## 3. migration・復旧

新規 `database/migrations/015_room_settlements.sql` を使用。過去migrationは変更していない。`database/schema.sql` は新規環境用に追加テーブルを反映。既存環境へschema全体を再実行しない。

migration管理テーブルは既存構成にない。新規テーブルの不存在を検査し、検証DBで予行した後に実DBへ一度だけCREATE TABLEする。既存29テーブルへのALTER/UPDATE/DELETEや全体restoreはしない。

実DBへ適用済み。29→30テーブルとなり、room_settlementsは0行。適用直後に既存29テーブルの全行・ID・DDL・AUTO_INCREMENTのハッシュ不変を再確認した。記録は `/private/tmp/oshilife-settled-20261004/migration-result.json`。バックアップSHA-256は `cf0276f9e85aa149c9bd30968289e6f41a00ef94be5881389a79f5c651573e99`。

復旧は、今回のコード差分だけを開始時点へ戻す方法を優先する。新テーブルは旧コードが参照しないので残してよい。利用開始後の状態記録を捨てる必要はない。テーブルを削除する場合はその時点のバックアップ・状態記録の保存・明示的確認を先に行う。既存データへbefore.sqlを全体restoreすると、その後の登録が失われるので通常の復旧手順にはしない。

## 4. DB設計と比較

roomsへの列追加も可能だが、既存roomsの定義・行に一切変更を加えず、旧コードへの復旧を容易にするため専用 `room_settlements` を採用。

| 列 | 意味 |
|---|---|
| room_id | 主キー。1ルームに現在状態は1つ |
| settlement_status | unsettled / settled |
| settled_at | ownerが確定した日時 |
| settled_by_user_id | セッションから決めた操作ユーザー |

room_idはroomsを参照。room_id＋settled_by_user_idはroom_membersを参照し、別ルームの人物をDBでも防ぐ。CHECK制約により、未精算は日時・操作者NULL、精算済みは両方必須。状態行がない既存ルームは未精算として読み取るので、初期データ移行は不要。送金案・精算額・部分精算履歴は保存しない。

## 5–7. owner限定確定・日時・操作者

ログイン中のactive memberかつrooms.owner_user_id本人かつrole=ownerだけが確定可能。URLやbodyのuser_idは認可に使わない。非メンバー404、member403、CSRF不一致419、不正ID422。

rooms行をFOR UPDATEでロックし、現在の支出の整合性と確認トークンを検査して保存。共同支出0件は409、支出あり差額0円は確定可能。すでに精算済みで同じ支出なら無操作となり、日時・操作者を上書きしない。

## 8–10. UI・警告・自動解除

ownerに「精算済みにする」。最新サマリーを取得し、誰が誰へいくら支払うかと実際の支払い完了確認を標準確認ダイアログに表示する。キャンセル時はPOSTしない。

確定後は全activeメンバーへ「✅ 精算済み」、日付、操作した人の表示名を表示。現在の計算結果は折りたたみへ移動する。memberには確定ボタンを出さない。未精算で支出ありなら控えめな未精算ラベル。支出なしはボタンも未精算ラベルも出さない。

追加・編集・取消の保存直前にも最新状態を取得し、精算済みなら解除警告を表示する。承認後、支出変更が成功した場合にだけunsettledにし、日時・操作者をNULLにする。フォームを開く、キャンセル、入力不備、更新番号不一致、DB失敗では維持する。通常の共同支出画面とルーム内ダイアログの両方に適用。

## 11. トランザクション・同時操作

トランザクションは一組の処理をまとめて成功・失敗させる仕組み。支出本体・内訳の変更と精算状態解除を同じ既存eventTransaction内で処理し、一方だけ確定させない。

精算確定・支出変更とも同じrooms行を最初にロックして順番に実行。サマリー取得も同じロック下で状態と金額を読み、違う時点の組合せを返さない。

確認用トークンはルームID・active支出のID/更新番号/金額/内訳のSHA-256。確認後の支出変更は409とし、再読込を促す。トークンは権限の代わりではなく、認証・owner確認・CSRFとは別に照合する。同じ確認への二重確定を無操作にするため、状態自体はトークンに含めない。

## 12. API

- POST `/api/rooms/expenses/mark-settled.php`：room_id、confirmation_token。セッション・CSRF・active ownerを必須にする。
- GET `/api/rooms/expenses/settlement-summary.php`：既存balances/transfersを保持。settlement_status、settled_at、settled_by_user_id、settled_by_display_name、confirmation_tokenを追加。
- create/update/cancel：精算済みルームでは警告確認済みのsettlement_tokenを要求。古いクライアントが確認なしで解除する操作は409。未精算の従来API呼出は引き続き利用可能。

## 13. 精算サマリーとの統合・主要ファイル

| ファイル | 今回の役割 |
|---|---|
| app/services/room_settlement_state_service.php | 確定・確認・状態解除の共通処理 |
| app/services/room_settlement_service.php | 既存計算を維持、状態読取・整合した取得・トークン追加 |
| app/services/room_expense_service.php | 保存・取消のトランザクションへ確認と解除を組込 |
| app/helpers/room_expense_api.php | 取消APIから確認トークンを引き渡す |
| api/rooms/expenses/mark-settled.php / public/api/rooms/expenses/mark-settled.php | 認証APIとpublic側入口 |
| includes/room_settlement.php / room_money.php | 確定ボタン・完了表示・説明 |
| public/assets/js/room_workspace.js / room_expenses.js | 確認、送信、部分更新 |
| public/assets/css/room_workspace.css | 既存テーマ色で完了表示 |
| database/migrations/015_room_settlements.sql / schema.sql | 新テーブル |
| tests/room_settled_smoke.py | 新仕様・ロールバック検証 |
| tests/room_workspace_browser.mjs | PC/スマホの確認・確定・解除操作 |
| tests/event_test_support.py / room_expenses_smoke.py / rooms_smoke.py / room_settlement_smoke.py | 新テーブル主キー・検証後片付け・API追加項目に対応 |
| README.md / docs/ROOM_SETTLED.md | 操作・処理フロー・変更内容・復旧方法 |

PHPはAPIで認可と保存を担当、JavaScriptは利用者への確認と画面更新を担当。APIはブラウザーから呼ぶPHPの処理窓口。金額の計算式はPHPの既存共通関数だけに置く。初心者向けの処理図はREADMEの「連番ルームの精算済み管理」に記載。

## 14–16. テスト・回帰

検証は実DBを直接操作せず、復元した検証DBとアプリコピーで実施。

- 精算済み管理94 HTTP項目：owner/member/非member/別room/退出、CSRF、二重操作、日時・操作者、完了表示、開くだけ/キャンセル/失敗時の保持、追加/編集/取消の解除、差額0円、closedルーム。
- 状態更新に失敗するtriggerを検証DBだけに一時作成。追加・編集・取消すべてで500となり、全テーブルの行ハッシュが直前と一致。支出と状態の同時ROLLBACKを確認後、triggerを削除。
- 精算サマリー65 HTTP項目、計算単体2,493チェック。cancelled除外・退出者・不整合検出・読取によるデータ不変を維持。
- 共同支出154 HTTP項目、招待リンク126項目、同行者58項目、イベント131項目、支払い連携151項目、お金管理167項目、イベントコピー/FC30項目。合計976 HTTP項目。
- ブラウザー34画面：新UI12＋既存共同支出10＋招待12。PC1440px/スマホ390pxで横はみ出し・JS例外・失敗リソースなし。完了表示、確認キャンセル、フォーム表示だけでは保持、保存後解除、member表示、均等割り、部分更新を確認。PC/スマホ完了画面も目視確認。
- PHP357ファイル構文検証、変更JS構文検証、git diff --check合格。
- 回帰実行中にテスト用ログインが429（回数制限）になったため、検証用アプリの保存領域を分けて残りを再実行し合格。製品側の認証制限は変更していない。

## 17. 残課題・対象外

個人expenses/savings、特効換算、実際の送金、部分精算、送金履歴、Chat、通知は今回追加しない。トークは既存プレースホルダーのまま。終了ルームの支出変更禁止を維持し、精算の確定と閲覧のみ許可する。

精算済みはownerによる完了宣言であり、金融機関の送金を検証するものではない。3人以上の送金案は従来の相殺方式を維持。検証DB・バックアップは保持。今回の変更は未コミット。
