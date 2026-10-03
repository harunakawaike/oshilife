> この文書は表示名検索招待を実装した時点の履歴です。現在の招待方式・API・再参加仕様は[招待リンク変更報告](EVENT_STEP3_2_INVITE_LINKS.md)を参照してください。旧検索招待APIは410で停止しています。

# 新Step 3-2：連番ルーム・招待・参加

2026年10月3日。旧Step 3-2「共同支出」とは別の実装。Step 3-3には進んでいない。

## 1. Git開始時状態

HEADは `b1d793f 同行者管理`、working treeはクリーンだった。
旧shared_expenses / shared_expense_sharesと012_shared_expenses.sqlは存在せず、既存DBは22テーブル、migrationは011までだった。
今回の変更は未コミット。旧Oshilife、XAMPP全体設定、my.cnf、Event Scheduler、システムテーブルは変更していない。

## 2. バックアップ結果

```sh
/Applications/XAMPP/xamppfiles/bin/mysqldump -h 127.0.0.1 -u root --single-transaction --skip-events --skip-routines --triggers --hex-blob oshilife_v2 --result-file=/private/tmp/oshilife-rooms-step32-20261003/before.sql
```

終了コード0、41,744バイト。全22テーブルのCREATE TABLEとデータINSERTを確認した。
trigger保持オプションを指定し、実際のtriggerは0件。Event Scheduler定義とstored routineは除外。
新規DB `oshilife_v2_rooms_verify_20261003` へ復元し、件数・全行ハッシュ・SHOW CREATE TABLE（AUTO_INCREMENTを含む）が元DBと一致した。
検証DBで新012を適用し、既存22テーブル不変を確認してから実DBへ適用した。
バックアップ・検証DBは保持。一時ディレクトリはOSに清掃されることがあるので長期保管先として扱わない。SQLには個人情報が含まれるためGit・公開ディレクトリに置かず、ファイル権限600とした。

## 3. migration内容と復旧

`database/migrations/012_rooms.sql` を新規追加・適用済み。旧012は取り消し済みなので、ファイル名も内容も異なる新012を使う。
手動SQL適用方式で、migration履歴テーブルはない。過去のmigrationは変更していない。
新規4テーブルと、それらのINDEX・UNIQUE・CHECK・外部キーだけを追加。既存22テーブルへのALTERや行更新はない。
新規環境向けdatabase/schema.sqlにも定義を追加。既存DBへschema.sql全体や012を再実行しない。

問題発生時はルーム導線・API・画面の変更を戻し、新規テーブルを保持したまま既存機能へ戻せる。
運用後にルーム・招待が登録された場合は、テーブルを安易にDROPせず別途バックアップする。
DB復旧が必要ならまず別DBへ復元して比較する。実DBへの一括restoreは、バックアップ後の正常な変更も消すため今回は行っていない。

## 4. rooms構造

| 列 | 役割 |
|---|---|
| id | ルームID |
| owner_user_id | 作成者。users.idへの外部キー |
| name | ルーム名（150文字以内） |
| status | active / closed |
| created_at / updated_at | 作成・更新日時 |

roomsにはevent_idを置かない。同じメンバーで複数公演を扱うため、イベントとの関係はroom_eventsへ分離する。
UNIQUE(id,owner_user_id)は、メンバーのowner情報や招待者が実際のownerと一致することを複合外部キーで確認するためのもの。

## 5. room_members構造

room_id、user_id、room_owner_user_id、role、status、joined_at、created_at、updated_atを持つ。
roleはowner/member、statusはactive/left。

- UNIQUE(room_id,user_id)で同じユーザーを二重登録しない。
- room_owner_user_idはroomsのownerと複合外部キーで一致させる。
- owner_markerは生成列（DBがroleから自動計算する列）。ownerなら1、memberならNULL。
- UNIQUE(room_id,owner_marker)でownerを一人に限定。
- CHECKでownerは実際の作成者かつactive、memberは作成者以外とする。
- 退出はstatus=left。再招待を承認すると同じメンバーIDでactiveへ戻し、joined_atを再参加時刻にする。

「1ルームに複数メンバー」は1対多。一人のユーザーも複数ルームに入れるため、ユーザーとルームの関係全体は多対多となる。

## 6. room_invitations構造

id、room_id、inviter_user_id、invited_user_id、status、created_at、responded_at。
statusはpending / accepted / declined / cancelled。

- (room_id,inviter_user_id)をrooms(id,owner_user_id)へ紐づけ、招待者をownerに限定。
- invited_user_idはusers.idへの外部キー。自分への招待はCHECKとAPIの両方で拒否。
- pending_markerはpendingだけ1、それ以外NULLの生成列。
- UNIQUE(room_id,invited_user_id,pending_marker)で未回答招待の重複を防ぐ。
- 回答済みの履歴を残したまま、新しい招待を作れる。
- pendingではresponded_at=NULL、回答・取消後は日時必須のCHECKを付ける。

## 7. room_events構造

id、room_id、event_id、created_at。
UNIQUE(room_id,event_id)で同じイベントの二重追加を防ぐ。rooms / eventsへ外部キーを持つ。
「1ルームに複数イベント」「同じイベントに複数ルーム」を表す中間テーブルであり、多対多の関係。
追加・削除するのはこの紐付けだけ。イベント本体や個人カレンダー、当落、TODO、遠征、会計には書き込まない。
全4テーブルの外部キーはON DELETE RESTRICTで、参照中のデータを不用意に物理削除できない構造とした。

## 8. owner作成方法

ルーム作成の一操作で、roomsとroom_membersのowner行をトランザクション内に作る。
トランザクションとは、複数の保存をまとめて成功・失敗させる仕組み。参加行の作成に失敗したらルーム作成も取り消す。
owner_user_idはログインセッションから決める。role・所有者・status等をクライアントが直接指定した場合は拒否する。
ownerは今回は退出できない。owner移譲も実装していない。

## 9. 招待フローと個人情報

```text
ownerがルームを作成
↓
表示名を完全一致で検索
↓
相手を確認して「招待する」
↓
room_invitationsへpendingを保存（メンバーにはまだしない）
↓
相手の「連番ルーム一覧 → 届いた招待」に表示
↓
相手が参加／辞退を選択
```

検索はactiveルームのownerのみ。返すユーザー情報はdisplay_nameと操作に必要なIDだけ。
IDはAPI・フォーム内部で使用し、画面には表示しない。メールアドレスやパスワード等はSELECT・応答・表示しない。
検索は表示名の完全一致に限定し、同名候補が複数なら特定できない旨を表示して招待を止める。直接APIでIDを指定されてもこの曖昧性を再確認する。
招待前に相手と表示名を確認する運用。招待リンク・公開ユーザーコード・同名ユーザーの識別改善は今回未実装。

FC会員番号、本名、住所、電話番号、カード番号などを入力する欄はない。共有するユーザー情報はOshilife上の表示名だけ。
ルーム名とdisplay_nameはユーザーが入力するため、機密情報を含めない運用とする。
通知・メール・プッシュ送信は追加していない。招待は本人がルーム一覧で確認する。

## 10. accept / decline・退出・終了

- 承認前：招待された本人へ招待ID・ルーム名・招待者表示名・作成日時だけを渡す。ルーム詳細は閲覧不可。
- accept：招待された本人だけがpendingをacceptedへ変更し、memberを追加または再参加させる。両方を一括確定。
- decline：declinedへ変更するだけでメンバーを作らない。
- 同じacceptを再送しても、すでにactiveなら成功扱いで行を増やさない。
- 退出後の古いacceptedを再送しても復帰できない。ownerからの新しい招待が必要。
- 辞退・取消済みの招待をacceptする操作も拒否する。
- member退出はleftとして履歴を保持。退出後は詳細・メンバー・イベント・招待中を閲覧できない。
- ownerによるcloseでroomsをclosedへ変更し、pending招待もcancelledへ変更する。
- closedは新規招待・承認・イベント追加/削除・名前編集を禁止。参加中メンバーは履歴を閲覧可能。
- closedでも一般memberは退出可能。ownerは退出不可。

全更新は最初にroomsの行をロック（同じルームの更新を順番に処理）するので、承認・退出・終了・再招待が同時に起きても状態が食い違わない。

## 11. room_events追加方法

owner本人のuser_event_statusに存在し、schedulesが公開かつ削除済みでないイベントだけを候補・追加対象とする。
招待されたmemberは推しのフォローや個人管理を自動作成されずに、共有の基本情報を閲覧できる。
イベントのタイトル・日付・時間・会場・種別を表示し、個人の当落・参加状況・支払額・TODO・同行者はJOINしない。
追加後に予定が非公開・削除へ変わった場合は紐付けを残し、「現在公開されていないイベント」と表示する。非公開になった内容はルーム応答に含めない。

## 12. 認可方法

認証は「誰としてログインしているか」、認可は「その人がこのルームで何をしてよいか」の確認。
画面のボタンを隠すだけではAPIを直接呼ばれるため、サーバー側で毎回チェックする。

| 操作 | 条件 |
|---|---|
| ルーム一覧 | 本人のroom_members.status=active |
| 詳細・メンバー・紐付けイベント・招待中 | 対象ルームのactiveメンバー |
| 名前編集・終了・招待・招待取消・イベント追加/削除 | activeメンバーかつowner。終了以外の更新はルームもactive |
| 招待への返答 | 招待のinvited_user_idがログイン本人、ルームIDと招待IDが一致 |
| 退出 | activeメンバーかつmember |

存在しない／参加していないルームは同じ404。memberによるowner操作は403、終了済み・回答済み等は409。
POSTはCSRF必須。CSRFは別サイトから本人のCookieだけを使って勝手に操作されることを防ぐ照合値。
SQLはeventQueryのPDO prepare/executeで命令と値を分離してSQLインジェクションを防ぐ。
名前の表示はPHPのe()/htmlspecialchars、JavaScriptのtextContentで、入力中のHTMLを実行させない。

## 13. UI変更・主要ファイル

イベント一覧・イベント詳細に「連番ルーム」リンクを追加。bottom navは5項目・音符アイコンのまま。
PCはメンバーと招待中を二列、イベントと管理を幅いっぱいに配置。スマホは縦並び。
個人用同行者画面は変更せず、ルームとの自動紐付けはない。未実装の遠征・お金・TODO・Chatタブも追加しない。

| ファイル / ディレクトリ | 役割 |
|---|---|
| public/rooms.php | 自分のルーム一覧、届いた招待、明示的な作成 |
| public/room_detail.php | 参加済みユーザーだけの詳細・管理操作 |
| public/events.php / event_detail.php | ルームへの入口 |
| public/assets/js/rooms.js | 保存・表示名検索・招待送信・画面更新 |
| public/assets/css/rooms.css | テーマに合わせたPC／スマホ表示 |
| app/helpers/room_view.php | 画面共通設定・操作フォーム |
| app/helpers/room_api.php | 認証・CSRF・入力ID・操作の分岐とJSON応答 |
| app/repositories/room_repository.php | activeメンバー認可と必要な共有情報の取得 |
| app/services/room_service.php | ルーム作成、招待返答、退出、終了、イベント紐付けの一括処理 |
| api/rooms/・public/api/rooms/ | 固定経路のAPI入口 |
| database/migrations/012_rooms.sql | 新規4テーブル・制約の追加 |
| database/schema.sql | 新規環境用の構造 |
| tests/rooms_smoke.py | 3ユーザーでのHTTP・不正ID・プライバシー・既存22テーブル不変 |
| tests/rooms_browser.mjs | 2ユーザーでPC／スマホの招待・承認・退出・終了を実操作 |
| tests/rooms_constraints.php | 専用DBで外部キー・owner・重複制約を直接検証 |
| tests/event_test_support.py | テストが作成したownerのルームだけを子→親の順に清掃 |

## 14. API一覧

APIは画面のJavaScriptから処理を依頼するPHPの入口。公開入口はpublic/api配下で、認可・保存は共通コードに集約している。

| URL（.php） | メソッド | 用途 |
|---|---|---|
| /api/rooms/create | POST | nameで作成 |
| /api/rooms/list | GET | 本人が参加中の一覧 |
| /api/rooms/detail | GET | room_idの詳細 |
| /api/rooms/update | POST | room_id, nameで名前変更 |
| /api/rooms/close | POST | room_idの終了 |
| /api/rooms/events/list | GET | room_idのイベント一覧 |
| /api/rooms/events/available | GET | ownerが追加できる候補 |
| /api/rooms/events/add・remove | POST | room_id, event_idで追加／除外 |
| /api/rooms/invitations/search | GET | room_id, q（表示名）の完全一致検索 |
| /api/rooms/invitations/create | POST | room_id, invited_user_idで招待 |
| /api/rooms/invitations/list | GET | room_idあり：参加中ルームの招待中一覧。なし：本人に届いた招待 |
| /api/rooms/invitations/accept・decline | POST | room_id, id（招待ID）で返答 |
| /api/rooms/invitations/cancel | POST | room_id, idでownerが取消 |
| /api/rooms/members/list | GET | room_idの参加中メンバー |
| /api/rooms/members/leave | POST | room_idから本人が退出 |

owner_user_id、room_owner_user_id、inviter_user_id、user_id、role、statusの直接指定は拒否する。
個人のevent_participantsや会計APIへルーム情報を混ぜていない。

## 15. テスト結果

- ルームHTTP：102項目合格。作成・owner・一覧・招待・同名検索拒否・承認・辞退・再送・再招待・退出・閉鎖・複数イベント・他ルーム／他ユーザーID・CSRF・個人情報非混入を確認。
- DB制約：ownerの一致・一意性、同一メンバー・pending招待の重複、招待者の一致、応答日時・状態、イベント重複、外部キー、参照中の物理削除拒否を確認。試験行はロールバック。
- 実ブラウザ：PC1440px／スマホ390px、2アカウントで作成→イベント追加→招待→相手が参加→両者の閲覧→退出→終了を操作。計8画面、横はみ出し・JS例外・リソースエラーなし。スクリーンショットも目視確認。
- 同行者：58項目合格。
- Step 2：131項目合格（6種類、参加確定TODO、無料、遠征、カレンダー同期、旧API等）。
- 支払い連携：151項目合格（チケット・交通・ホテル）。
- お金管理：167項目合格（集計・特効換算等）。
- コピー・FC先行：30項目合格。
- 既存回帰計537項目合格。
- 全PHP構文、JavaScript構文、git diff --checkを確認。

更新系テストは専用DBと `/private/tmp/oshilife-rooms-step32-20261003/test-app` の同一ソースコピー、専用PHPサーバーで実施。
実アプリの.env、セッション、認証回数、DB採番をテストで動かしていない。専用サーバーは終了済みで、テストのユーザー・ルーム等も清掃済み。

## 16. 既存機能への影響

実DBの最終比較で、既存22テーブルの全行ハッシュ・件数・SHOW CREATE TABLE・AUTO_INCREMENTがバックアップ時点と完全一致。
新規rooms、room_members、room_invitations、room_eventsの4テーブルだけを追加し、DBは26テーブルになった。
イベント・当落・TODO・遠征・会計・特効換算・通知・個人用同行者の処理は変更していない。
shared_expenses等は存在せず、共同支出・精算・Chatは未実装。

## 17. README追加内容

rooms / room_members / room_invitations / room_eventsの意味、1対多・多対多、主要ファイル、招待承認の流れ、owner/member、サーバー側認可を日本語で説明した。
PHP/JavaScriptの先頭と主要関数に役割・理由のコメントを付けている。

## 18. 残課題

新Step 3-2の依頼範囲は完了。
同名ユーザーは安全に特定できないため招待を止める。今後、公開ユーザーコードや招待リンクなどの識別手段を検討できる。
owner移譲・退出、終了ルームの再開、メンバー強制退出、通知、共同TODO、共同遠征、支出・精算・Chatは未実装。
event_participantsからルームへの変換や自動招待も行わない。

## 19. Step 3-3前の確認事項

- ルームと個人の申込・参加・遠征・TODOをどこまで分離して共有するか。
- 後続の共同支出で、支払者・負担者をroom_membersに紐づける方針と、退出後の履歴・権限。
- owner移譲・退会・ルーム終了時の管理責任。
- 招待識別の改善、期限・ブロック・回数制限が必要か。
- 個人のお金管理へは本人の実質負担だけを明示的に反映し、立替返金を積立・マイナス支出にしない方針。

Step 3-3は別途承認後に着手する。
