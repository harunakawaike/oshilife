# Step 3-3：ルーム内共同支出 実装報告（2026-10-03）

## 1. Git開始時状態

HEAD `b632e60`（ルーム発番）。Step 3-2招待リンク方式がコミット済みで、`git status --short`は空。cleanを確認してから開始した。旧Oshilifeには触れていない。
接続設定・実接続とも`oshilife_v2`。rooms / room_members / room_events / room_invite_links / event_participantsを確認。招待リンク追加後のため既存は26でなく**27テーブル**。

## 2. バックアップ結果

- 保存先：`/private/tmp/oshilife-room-expenses-20261003/before.sql`
- ファイル50,398バイト、終了コード0。ファイル600、保存先ディレクトリ700。
- CREATE TABLE 27件、データSQLを確認。
- 新規検証DB `oshilife_v2_rooms_verify_expenses_20261003`へ復元し、元27テーブルの件数・全行ハッシュ・SHOW CREATE TABLE（AUTO_INCREMENT含む）が一致。

```sh
/Applications/XAMPP/xamppfiles/bin/mysqldump -h 127.0.0.1 -u root --single-transaction --skip-events --skip-routines --triggers --hex-blob oshilife_v2 --result-file=/private/tmp/oshilife-room-expenses-20261003/before.sql
```

システムDB、Event Scheduler、my.cnf、XAMPP設定は変更しない。検証DBは残している。

## 3. migrationと復旧方法

`database/migrations/014_room_expenses.sql`を新規追加し、検証DBでリハーサル後、実DBへ一度だけ適用した。001〜013相当の既存schema/migration定義は変更せず、新規セットアップ用schema.sqlの末尾へ同じ2テーブルを追加。
実DBは27→29テーブル。追加直後の新2テーブルは0行。元27テーブルの件数・全行ハッシュ・DDL・採番が完全一致。
既存テーブルにALTER・データ変更なし。必要INDEXと制約は新2テーブル内だけに追加し、room_membersの既存UNIQUE(room_id,user_id)を参照する。

復旧する場合はまず共同支出の更新を停止し、その時点の新2テーブルとDB全体を別名へバックアップする。データを保持してアプリの修正を優先する。撤去が必要なら履歴を退避・確認したうえで子room_expense_members→親room_expensesの順だけを対象にする。元DBをbefore.sqlへ一括で戻して、その後のユーザー操作を消してはいけない。今回は実DBでDROPや復元を実行していない。

## 4. room_expenses構造

| 列 | 役割 |
|---|---|
| id / room_id | 支出と対象ルーム |
| created_by_user_id | 登録本人。セッションから確定し、後から変更しない |
| created_by_name_snapshot | 登録時の名前。改名・退出でも保持 |
| title / category | 支出名150文字・共通定義のカテゴリ |
| total_amount | 実際の支払総額。1〜999,999,999の整数円 |
| paid_by_user_id / paid_by_name_snapshot | 支払者1人と保存時の名前 |
| expense_date / note | 支払日、共有メモ（最大3000文字） |
| status | active / cancelled |
| version | 古いフォームの上書きを防ぐ更新番号 |
| created_at / updated_at | 作成・更新時刻 |

## 5. room_expense_members構造

| 列 | 役割 |
|---|---|
| id / room_expense_id | 負担行と所属する支出 |
| room_id | 本体と同じルームであることをDBでも保証するために持つ |
| user_id | 負担するOshilifeユーザー。個人同行者IDではない |
| display_name_snapshot | 負担に追加した時点の名前 |
| share_amount | 0〜999,999,999の整数円 |
| created_at / updated_at | 作成・更新時刻 |

1つの本体に複数の負担内訳がある「1対多」。ユーザー名をキーにしない。支払者・登録者にもsnapshotを追加し、users.display_nameの変更や論理退会から過去の表示を独立させる。

## 6. INDEX / UNIQUE / FK

- 本体：PRIMARY(id)、UNIQUE(id,room_id)、一覧用(room_id,status,expense_date,id)、登録者・支払者の複合INDEX。
- 本体の登録者・支払者：(room_id,user_id相当)→room_members(room_id,user_id)。存在しない/別ルームのユーザーをDBでも拒否。
- 内訳：UNIQUE(room_expense_id,user_id)で同じ人を重複登録しない。
- 内訳→本体：(room_expense_id,room_id)→room_expenses(id,room_id)。内訳→room_members：(room_id,user_id)。
- 外部キーはRESTRICT。金額範囲、カテゴリ、状態、versionのCHECKを追加。
- active判定と内訳SUMは複数行にまたがるためサービスで検証する。CHECKだけで合計を保証したと誤解しない。実DBへのトリガー追加はない。

## 7–8. 支払者と負担者

新規保存では同じルームのactiveかつ退会していないユーザーのみ選択可能。支払者は1人、負担者は選択した1人以上。支払者が負担者に含まれなくても保存できる。
負担者に選んだ人の0円を許可し、未選択者の行は作らない。候補取得APIはactiveメンバーしか利用できない。
支払者と負担者を別にすることで、ホテル2万円をAが支払いA/Bが1万円ずつ負担する記録を共有できる。今回は個人のお金管理には影響しない。

## 9–10. 均等割りと端数

日本円の整数演算のみ。総額÷選択人数の商を各人に割り当て、余りの1円ずつを以下の固定順で割り当てる。

1. 負担者に含まれる支払者。
2. 残る負担者はroom_members.id昇順。

10,000÷3なら3,334/3,333/3,333。支払者を負担から外した時は全員member ID順。均等割り後も各人の金額を編集できる。
過去の退出者がいる編集では、その人の金額を固定し、残額を参加中の選択者だけで配る。

## 11. 合計整合性

PHPで型・整数範囲・重複user_id・各額を検証し、負担額合計が総額と一致しないと422：`負担額の合計が支出総額と一致していません`。
保存後にもDBのSUM(share_amount)を取得して確認する。ブラウザーの表示やJSONを改ざんされても、差額がある支出を確定しない。

## 12. DBトランザクション

eventTransactionでbeginTransaction→ルーム行FOR UPDATE→認可/入力/所属検証→本体保存→内訳保存→SUM検証→commit。
例外はrollBackで全変更を戻す。退出・終了・他の保存も同じルーム行を先にロックするため、途中で前提が変わらない。
編集時は本体versionを照合して増やす。古い更新・取消は409。内訳は残る人のID・保存名を保持し、追加/金額更新/選択解除を同じトランザクションで行う。

## 13. 編集権限

activeメンバーの登録者本人またはownerのみ。登録者が退出していたら本人でも不可。その他のactiveメンバーは閲覧だけ。
room_idと支出idを組で照合し、URL/APIのID書換えで別ルームの支出を読み書きできない。登録者・保存名・状態・roleの入力上書きを拒否する。
画面を隠すだけでは直接APIが呼べるため、サーバー側でも毎回認可する。全更新はログインとCSRF必須。

## 14. 取消

status=cancelled、versionを増やすだけ。本体/内訳/IDは削除しない。通常一覧では除外し「取消済みも表示」で確認可能。詳細も取消済み表示。
取消済みの編集・再取消・復活は不可。closedルームは閲覧のみ、登録/編集/取消不可。

## 15. 退出・改名の履歴

退出者を新しい支払者・負担者として追加できない。過去の支払者はそのまま保持可能で、別のactiveな支払者へ明示的に訂正することもできる。
過去の退出者の負担行・金額は編集時も消去/増減不可。名前・金額を保持する。再参加すればactiveとして選べる。
登録者snapshotは変更しない。残っている負担者/同じ支払者のsnapshotも、保存のたびに現在名へ上書きしない。

## 16. UI変更

ルーム詳細に「お金 → 共同支出を見る」を追加。新規3画面：一覧、詳細、登録/編集。
カードに支出名・総額・支払者・登録者・支払日・各負担額。メモは詳細に表示。候補はチェック選択、金額入力は整数円、均等割りと現在の合計/差額を表示。
スマホ縦配置、PCカード配置。既存テーマ・音符アイコン・下部ナビ5項目を維持。個人のお金管理画面とは独立。

## 17. API一覧

`/api/rooms/expenses/`（public側の入口も対応）：

| API | 方法 | 用途 |
|---|---|---|
| list.php | GET room_id, include_cancelled=0/1 | 一覧・内訳 |
| detail.php | GET room_id,id | 詳細・内訳・can_edit |
| members.php | GET room_id | activeな支払/負担候補 |
| create.php | POST | 本体・内訳新規作成 |
| update.php | POST room_id,id,versionと全項目 | 本体・内訳一括編集 |
| cancel.php | POST room_id,id,version | 履歴を保持した取消 |

登録/更新項目はtitle,category,total_amount,paid_by_user_id,expense_date,note,shares（user_id/share_amountの配列）。created_by_user_idは受け取らずセッションから確定。

## 18. 招待リンク回帰

既存rooms_smoke.pyの126 HTTP項目とPC/スマホ12画面が合格。7日期限、無効化/再発行、ログイン後復帰、明示参加、退出/再参加、closed、旧検索API停止を維持。
招待・参加サービスや既存招待APIには変更を加えていない。

## 19. テスト結果

専用検証DB＋ソースの一時コピー＋分離したセッション/認証回数領域で実施。実DBへのテストユーザー・支出作成なし。

- **共同支出154 HTTP検証**：owner/member登録、他member共有、支払者/登録者/負担表示、整数/小数/負数/0/上限/重複、合計一致/不一致、個別負担、7カテゴリ、IDOR、非参加者/他ルーム拒否、登録者/owner編集・取消、他member拒否、version競合、退出・改名・再参加、closed。
- **ロールバック**：検証DBだけに一時的な障害用トリガーを作り、内訳INSERT/UPDATEで例外を発生させた。HTTP500後に本体・全内訳が保存前と完全一致。検証トリガーは撤去済み。
- **DB制約**：登録者/支払者/負担者の同一ルームFK、負担者UNIQUE、金額・カテゴリ・状態・versionのCHECK、履歴のRESTRICT。試験行をロールバック。
- **共同支出ブラウザー10画面**：1440px PC・390pxスマホ、一覧/フォーム/詳細/別member閲覧/取消。均等割り（端数あり・支払者を負担から外すケース）、個別変更、共有、編集、取消を操作。横はみ出し・JS例外・失敗リソースなし。
- **既存回帰537項目**：同行者58、イベントStep 2 131、支払い連携151、お金管理167、イベントコピー/FC30。イベント6種類・確定TODO・無料・近場/遠征・交通/ホテル/チケット支払い・特効換算・カレンダー同期を含む。
- 連続実行時、最後のイベントコピーは検証用認証回数制限429に達したため、専用サーバーの試験状態を分離し直してその30項目だけ再実行し合格。アプリのレート制限は緩めていない。
- 実DBの既存27テーブルは全行・ID/金額・件数・DDL/制約・AUTO_INCREMENTがバックアップ時点と一致。

## 20. 既存機能への影響

既存expenses/savings/集計/特効換算、event_participants、イベント・TODO・遠征・支払い連携の実装とデータを変更していない。新規記録を個人会計へ二重登録しない。
変更した既存製品ファイルはroom_detail.php（入口）とschema.sql（末尾新規定義）、README。テスト共通清掃は試験用ルームに属する新2テーブルを子から清掃するよう拡張した。

主な追加ファイル：config/room_expenses.php、app/{helpers,validators,repositories,services}/room_expense_*.php、public/room_expense*.php、public/assets/{js,css}/room_expenses.*、api/rooms/expensesとpublic/api/rooms/expenses、014 SQL、専用テスト3本、本書。

## 21. README追加内容

支払額と負担額の違い、個人expensesとの分離、1対多、保存名、SUM、トランザクションとロールバック、サーバー認可、端数配分、退出・取消・競合編集、ファイル役割と処理フロー、次の精算設計への繋がりを日本語で説明した。
新PHP/JSの冒頭に役割、主要関数に日本語コメントを付けた。

## 22. 残課題

精算額・精算状態、個人会計反映、承認/確認、共同TODO/遠征、Chat・通知・ファイル共有・リアルタイムは未実装。
編集前の全版を保存する監査ログ、取消の復活、複数支払者は今回対象外。退出者の固定負担を後から訂正する場合は将来の訂正ルールが必要。
変更は未コミット。Step 3-4へは進めていない。

## 23. Step 3-4「精算」前に確認すべき点

取消済み支出を除外して、各user_idの支払総額と負担総額を集計する設計が可能。ただし今回は計算しない。
精算後の元支出編集/取消をどう扱うか、退出者との精算・複数人間の送金経路、精算の記録/取消権限と履歴を先に決める。
将来の個人expenses連携も「自分の実質負担額だけ」を独立した段階で設計し、立替返金をsavingsやマイナス支出にしない方針を維持する。
