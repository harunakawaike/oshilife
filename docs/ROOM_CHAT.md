# 連番ルーム Chat：実装・再開報告（2026-10-04）

## 1. 実際に確認したGit状態

開始時のHEADは `d3ddea3 清算済み管理`、作業ツリーはクリーンだった。
再開指示受領時にもHEADと全差分を読み直した。Chat差分は未コミットで、既存の共同支出・精算サービス自体の変更はなかった。コードの作り直し、git reset、過去migrationの再実行はしていない。

## 2. 発見した途中成果・残作業

Chatのサービス、共通API、list/send/deleteのAPIとpublic入口、テンプレート、JS/CSS、016のSQL、HTTPテストとChromeテストが存在していた。
`public/room_detail.php`は既にトークテンプレートを読み込んでいた。既存テストの清掃順序とプレースホルダー確認もChat用に更新されていた。

ログを実際に確認したところ、報告済み86項目に加え、会計保持検証を加えた89項目、PC/スマホ7画面、既存回帰976 HTTP項目まで通過していた。未完了は追加した並列送信試験、README/docs、実DBへの初回適用だった。

## 3. DB・migration調査とバックアップ

接続DBは `oshilife_v2`。再開調査時の実DBは30テーブルで、room_messagesは存在しなかった。rooms、room_members、room_events、招待リンク、room_expenses、room_expense_members、room_settlementsは存在した。migration一覧は002〜016で、016だけがChat用。

`016_room_messages.sql`は専用検証DBにだけ適用済みで、実DBでは未適用だった。migration管理テーブルは既存構成にないため、実テーブルの存在・定義と検証記録で適用状況を確認した。

バックアップ：`/private/tmp/oshilife-chat-20261004/before.sql`、56,180バイト、権限600。

```sh
/Applications/XAMPP/xamppfiles/bin/mysqldump -h 127.0.0.1 -u root --single-transaction --skip-events --skip-routines --triggers --hex-blob oshilife_v2 --result-file=/private/tmp/oshilife-chat-20261004/before.sql
```

最初のサンドボックス内実行は接続拒否2002。接続許可後のdump終了コードは0。全30テーブルのCREATE TABLE・データSQLを確認し、新規検証DB `oshilife_v2_rooms_verify_expenses_chat_20261004`へ復元した。行・ID・件数・構造（INDEX/FK/AUTO_INCREMENT含む）が元DBと一致。そこで016を予行し、既存30テーブル不変・31テーブルになることを確認。

実DB適用前にバックアップ時と全30テーブルの行・DDLが一致することを再検査してから、016を一度だけ適用した。適用後は31テーブルでroom_messagesは0行。既存30テーブルのデータ・ID・金額・採番・精算状態を含む行とDDLのハッシュ不変を再確認済み。既存DB全体のrestoreは行っていない。

記録：`/private/tmp/oshilife-chat-20261004/migration-result.json`。バックアップSHA-256：`b1923bed10b18b4fb25874ddaaaee6d3b15ab22fea6bca087e92d0376d886620`。

## 4. room_messages構造・INDEX・FK

| 列 | 内容 |
|---|---|
| id | 投稿ID、BIGINT UNSIGNED、AUTO_INCREMENT、主キー |
| room_id / user_id | 対象ルームとセッションから決めた投稿者 |
| display_name_snapshot | 投稿時の表示名。後の改名で書き換えない |
| message | テキスト本文、最大1000文字 |
| status | active / deleted |
| created_at / updated_at | 投稿・更新日時 |

`(room_id,id)`は時系列・差分取得、`(room_id,user_id)`はメンバーの複合外部キー、`(user_id,created_at)`は全ルームを通じた連投制限に使用。
FKは `(room_id,user_id) → room_members(room_id,user_id)`、ON DELETE RESTRICT。退出はメンバー行の状態変更なので過去投稿を保てる。statusと文字数はCHECK制約でも検証。削除は対象IDを主キー検索するので、選択性の低いstatus単独INDEXは追加していない。

## 5–8. 投稿・複数メンバー・polling・after_id

GET `/api/rooms/messages/list.php`、POST `send.php` / `delete.php`。
全APIでログインとactive memberを検査。送信時は1000文字・空白のみ・形式不正をサーバーでも検証。日本語・絵文字はPHPのmb_strlenとJSのコードポイント単位で数える。改行を保持し、URLはリンク化せず文字列表示。

初回は最新100件を時系列順に返す。以後はafter_idより大きい投稿を最大100件だけ取得。取得完了から4秒後に次回通信し、未取得が100件以上溜まった時だけ短い間隔で追いつく。DOMも100件まで保持。今回は過去100件より前を読む機能は設けない。

send応答のIDで取得カーソルを進めると直前の他人の投稿が抜けるため、list応答だけで進める。ルーム行のロックにより同じルームの投稿保存は順番に確定する。

非表示中は新たなpollingを停止し、復帰後に差分を取得。過去の削除は表示中の最大100 IDのうちdeletedになったIDだけを返す。毎回本文全件を取得しない。

## 9. 削除

進行中ルームのactive memberかつ投稿者本人だけ。ownerにも他人の削除権は付けない。別roomのmessage IDは404、他人の投稿は403。statusをdeletedにする論理削除で、行を物理削除しない。

deleted本文はSQLのCASEでNULLにし、APIへ返さない。表示済み本文もpollingのdeleted_idsを受けたら「メッセージを削除しました」に置換する。

## 10. XSS・CSRF・認可・rate limit

本文はtextContentで描画し、innerHTMLを使わない。HTMLやscriptは文字として表示される。CSRFは既存セッションのトークンで投稿・削除を保護。クライアントからのuser_id・表示名・status指定は422で拒否し、本人情報はセッションとDBから採用する。

同一ユーザーの全ルーム合計で30秒間に20件まで。削除済みの投稿もカウントする。rooms行、users行の順にロックし、現在の行を読むFOR UPDATEで回数を検査してINSERTする。他のタブやルームからの同時投稿も同じユーザーの制限を共有。XAMPP全体の設定やシステムテーブルは変更しない。

## 11–12. closed・退出

今回の再開指示「closed roomは閲覧のみ」に合わせ、終了ルームは投稿・削除とも409で拒否し、入力欄・削除ボタンを非表示にする。履歴の閲覧と新着/削除状態の取得は可能。

退出memberは閲覧・投稿・削除不可。残ったactive memberからは退出者の過去投稿をsnapshot名で読める。認可を失ったブラウザーではpollingを止め、トーク本文を画面から除く。

## 13–15. スクロール・PC・スマホ

初回と自分の送信後は末尾へスクロール。新着時は末尾付近なら追随し、過去ログを読んでいる間は強制移動せず「新しいメッセージを見る」を表示する。

ルーム名→イベント→メンバー/招待→トーク→お金→管理を維持。トークはPC400px、スマホ350pxの内部スクロール履歴＋入力領域を確保する。吹き出しはPC最大80%、スマホ90%で長文を折り返す。入力欄は最低96px、スマホ16px文字。テーマ色は既存CSS変数を使う。

## 16–17. 検証

前回の86項目は既存 `room_chat_smoke.py`を再利用した。文字数・日本語/絵文字/改行・空白拒否・認可・CSRF・偽装拒否・after_id・論理削除・名前snapshot・連投・初回100件を保持。会計保持検証とブラウザー連携を追加。最終版はclosedで本人削除も拒否する仕様に合わせた88 HTTP項目が合格（前回の86項目相当を含む）。

24プロセスの並列送信を2ルームへ振り分け、20件成功・4件429を確認。PC1440px/スマホ390pxの7画面でブラウザー検証が合格。横はみ出し・JS例外・失敗リソースなし。PC/スマホ画面も目視確認済み。

別memberの投稿がpollingで出ること、同じ投稿の重複なし、双方からの取得、削除通知、closed、退出、初回/送信後のスクロール、過去閲覧中の非強制スクロール、非表示時の停止、1000文字・大量ログを確認済み。

既存テストを再利用：精算済み94、精算サマリー65、共同支出154、招待126、同行者58、イベント131、個人支払い151、お金管理167、イベントコピー/FC30、合計976 HTTP項目が合格。新UI12・既存共同支出10・招待12の計34ブラウザー画面も合格。精算計算単体2,493チェック合格。Chatとの合計は1,064 HTTP項目、41ブラウザー画面。PHP366ファイルの構文検証・変更JS構文検証・git diff --checkも合格。

テストは実DBではなく、専用検証DBと `/private/tmp/oshilife-chat-20261004/test-app`で実行した。精算済みルームで投稿・削除しても、共同支出・精算状態・個人expenses/savingsの行ハッシュが変わらないことを確認済み。

## 18. DB保護・復旧

既存30テーブルへデータ移行や列追加は行わず、room_messagesの新設だけ。既存の共同支出・精算済み状態・金額・ID・採番を保持する。

復旧時は今回のコード差分だけを戻し、room_messagesは残すのを優先する。旧コードは新テーブルを参照しない。投稿が始まった後のテーブル削除やDB全体restoreは通常の復旧手順にせず、必要ならその時点のバックアップと明示的確認を先に行う。バックアップ・検証DBは削除せず保持。

## 19. ファイル・学習ドキュメント

追加：room_message_service.php、room_message_api.php、API/public入口各3本、includes/room_chat.php、room_chat.js/css、016_room_messages.sql、Chat HTTP/ブラウザテスト、本書。
変更：public/room_detail.php、database/schema.sql、README、テスト清掃のevent_test_support.py、プレースホルダー判定を更新したroom_workspace_browser.mjs。

READMEの「連番ルームのトーク」に主要ファイルの役割・保存フロー・pollingとWebSocketの違い・after_id・全件取得を避ける理由・XSS/CSRF/認可・論理削除・rate limit・退出/closedの説明を追記した。PHP/JSに日本語の役割・処理理由コメントを記載。

## 20–21. Git状態・残課題

変更は未コミット。精算済み管理のコミットは保持している。既読・未読件数・画像/ファイル/音声・push・WebSocket・リアクション・メンション・共同TODO/遠征・個人会計への共同支出反映には進んでいない。

保存済みの100件より古いログの取得UI、通信失敗時の送信の厳密な重複排除は未実装。保存結果が通信切断で不明な場合は、自動再送せず最新表示を確認する。Chatの本文はルーム内共有なので、UIに機密情報を送らない案内を出す。
