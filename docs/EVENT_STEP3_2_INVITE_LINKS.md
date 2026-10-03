# Step 3-2 招待リンクへの変更報告（2026-10-03）

## 1. Git開始時

HEADは`b1d793f`（同行者管理）。前回Step 3-2の未コミット差分が存在する状態から継続した。既存差分を取り消さず、012_rooms.sqlも変更せず保持した。旧Oshilifeには変更なし。

## 2. バックアップ

`/private/tmp/oshilife-room-links-20261003/before.sql`：48,510バイト、終了コード0。ファイル600、ディレクトリ700。

```sh
/Applications/XAMPP/xamppfiles/bin/mysqldump -h 127.0.0.1 -u root --single-transaction --skip-events --skip-routines --triggers --hex-blob oshilife_v2 --result-file=/private/tmp/oshilife-room-links-20261003/before.sql
```

CREATE TABLE 26件・データSQLを確認。新規検証DB`oshilife_v2_rooms_verify_links_20261003`へ復元し、元DBの件数・全行ハッシュ・DDL（AUTO_INCREMENT含む）と一致した。検証DBは残している。
既存ルーム1件・メンバー2件・イベント紐付け1件・旧招待1件を確認し、削除・変換せず保護した。

## 3. migrationと復旧

`database/migrations/013_room_invite_links.sql`はCREATE TABLE 1件のみ。012の書き換えなし。元26テーブルにALTER/UPDATE/DELETEなし。migration管理表はなく、連番SQLで管理する。
復元済み検証DBでリハーサル後、実DBへ一度だけ適用。実DBは27テーブル、旧26テーブルの件数・全行ハッシュ・DDL・採番は前後完全一致。新テーブル0件。

復旧が必要な場合は新リンクの発行・参加を停止し、現時点の全DBを別名へバックアップする。新テーブルは保持したままアプリ修正を優先し、旧表示名招待を再び有効化しない。新テーブルを撤去する場合は、リンク履歴を退避・確認したうえでroom_invite_linksだけを対象にする。既存ルームや参加履歴をバックアップ時点へ一括復元しない。今回は復旧・DROP・全DB復元を実DBへ実行していない。

## 4. 招待リンクDB構造

B案（room_invite_linksを追加）を採用。旧room_invitationsの既存行・制約・IDを保持できるため。

| 列 | 役割 |
|---|---|
| id | リンクのID。生URLの代わりにはならない |
| room_id / created_by_user_id | ルーム・発行owner。roomsの複合外部キーで整合を保証 |
| token_hash | SHA-256の64桁。一意INDEX、ASCIIの厳密比較 |
| expires_at | 発行から7日後 |
| max_uses | 初期NULL（人数制限なし） |
| used_count | 成立した新規参加・再参加の回数 |
| status | active / revoked / expired |
| created_at / updated_at | 作成・更新時刻 |

max_usesを指定した場合は正数・used_count以下にならない制約。現在のAPIではユーザーに上限を設定させない。

## 5–6. tokenとhash

`random_bytes(32)`を`bin2hex`で64桁にする（256bit）。SHA-256ハッシュのみ保存し、生tokenは発行POSTの応答に一度だけ返す。一覧API・詳細・再読み込み後の画面は生tokenもハッシュも返さない。
`APP_URL`からリンクを生成する。Hostヘッダーや任意リダイレクトURLを信用しない。DB照合はプリペアドステートメントを使用する。

## 7–8. 有効期限とrevoke

7日間、複数人に利用可能。時刻を毎回確認するためSchedulerや定期更新不要。expiredは表示上の算出でも扱い、GETでDB更新しない。
ownerのみ無効化可能。終了ルームでも無効化可。複数リンクはそれぞれ独立し、再発行は別乱数。旧リンクも期限か無効化までは有効。ルーム終了時には有効リンクを無効化する。

## 9. ログイン復帰

`public/rooms/join.php`が未ログインの場合、形式検証済みtokenだけをセッションへ保存しlogin.phpへ移動。ログインAPIはログイン前にこのtokenを読み、認証成功・セッションID再生成後に固定招待パスを一度返す。失敗時には保持する。auth.jsが復帰し、通常ログインはhome.phpへ進む。任意のreturn_to入力を使わない。

## 10–11. 参加・再参加

確認ページはルーム名・owner表示名のみ開示。GETは参加しない。参加POSTはログイン本人IDとCSRFを確認し、owner/member指定や他人のuser_idは拒否する。
ルーム行を先にロックし、リンクを最新状態で再確認してから参加保存。無効化・終了と同じロック順を使う。参加済みなら成功として扱い、行も回数も増やさない。ownerのroleを変えない。
退出済みなら同じroom_members.idのstatusをactiveへ戻しjoined_atを更新。新たな重複行は作らない。使用回数は再参加成功時に1増える。
room_idだけでは参加不可。改ざん・期限切れ・無効化済み・終了ルーム・上限超過は拒否する。古い確認画面から送信しても再検査する。

## 12. 廃止した検索招待

表示名検索関数、宛先ユーザー指定の招待サービス、検索UI、届いた招待UIを除去。
旧`/api/rooms/invitations/{search,list,create,accept,decline,cancel}.php`は認証後410を返す互換入口として残し、検索・招待・参加・DB変更を行わない。既存の旧招待1行は履歴保存のみ。

## 13. UI

ownerのルーム詳細に「招待リンクを作成」、URL、コピー、有効期限、無効化を追加。URLは発行直後だけ表示。
Clipboard APIが利用できない場合は文字列を選択し、手動コピーの案内へ切り替える。スマホは縦配置。既存テーマ・音符アイコン・ナビ5項目を維持。
参加確認は「参加する／キャンセル」。すでに参加している場合はルーム詳細へ案内する。

## 14. APIと主な変更ファイル

| API | メソッド・権限 | 役割 |
|---|---|---|
| rooms/links/create | POST・active owner | 発行（生URLはこの応答だけ） |
| rooms/links/list | GET・owner | 状態・期限・回数のみ取得 |
| rooms/links/revoke | POST・owner | 同じルーム内の対象リンクを無効化 |
| rooms/links/preview | GET・ログイン | 有効tokenの最小確認情報 |
| rooms/links/join | POST・ログイン本人 | 確認後の参加 |

入口はapi/とpublic/api/の両方に配置。既存のルーム一覧・詳細・メンバー・イベント・退出APIは維持。detailから旧invitationsの項目を除去した。

- `app/services/room_link_service.php`：新規の発行・照合・参加処理。
- `app/services/room_service.php`：旧個別招待処理を除去。終了時のリンク失効。
- `app/repositories/room_repository.php`：旧検索・招待取得を除去。
- `app/helpers/room_api.php`：新API分岐・旧API停止。`room_view.php`：リンクサービス読込。
- `public/rooms.php`・`public/room_detail.php`・`public/rooms/join.php`・`public/assets/js/rooms.js`・`public/assets/css/rooms.css`：一覧・詳細・確認・発行・コピー。
- `api/auth/login.php`・`public/assets/js/auth.js`：安全なログイン復帰。
- `database/migrations/013_room_invite_links.sql`・`database/schema.sql`：追加テーブル。
- `tests/rooms_smoke.py`・`tests/rooms_browser.mjs`・`tests/rooms_constraints.php`・`tests/event_test_support.py`：新招待方式の試験、テストデータ清掃。
- `README.md`・本書・旧報告の履歴注記：学習用の役割とフロー。

## 15. テスト結果

専用DB＋コピーしたソース＋分離セッションの一時PHPサーバーで実施。実アプリのユーザー登録・ログイン回数・採番を変えない。

- 招待リンクHTTP **126項目**：権限、乱数・ハッシュ、7日期限、無制限、未ログイン復帰、外部return_to無視、確認・キャンセルで不参加、CSRF、二重送信、owner・既参加・同名ユーザー、再参加ID保持、期限切れ・revoke・closed・改ざん拒否、他人ID拒否、再発行、旧6ルート停止、複数イベント、メンバー権限、退出・終了、個人情報非公開。
- DB制約：owner整合、一意メンバー、旧招待履歴制約、イベントFK、新リンクのハッシュ一意・owner FK・状態・利用上限制約。試験行はロールバック。
- ブラウザー **12画面**：1440px PC・390pxスマホ。作成→イベント追加→発行→コピー→未ログイン→ログイン→招待確認→参加→退出→終了。横はみ出し・JS例外・リソース失敗なし。
- 既存回帰 **537項目**：同行者58、Step 2イベント131、支払い連携151、お金管理167、イベントコピー/FC30。TODO・遠征・特効換算を含む。
- 実DB：元26テーブルの全行・件数・構造・AUTO_INCREMENT一致。以前からのルーム/参加/旧招待も保持。

## 16–17. 既存機能への影響・Git

既存の個人データ、お金管理・支払い・特効換算の実装は変更していない。ルームのowner/member権限・複数イベント・退出・終了・個人同行者との独立も維持。
既存未コミット差分に今回の変更を追加した状態で、Gitコミットは実施していない。

## 18. Step 3-3前の残課題

- 共同支出・精算・Chat・通知・owner移譲は未実装。Step 3-3へ進んでいない。
- 招待リンクは所持者が参加できる方式。発行者が共有相手を選び、必要時に無効化する。
- localhostリンクは端末外からそのまま使えない。共有運用の開始時はアクセス可能なHTTPSのAPP_URLが必要。
- 生URLは再表示できない。期限切れ・紛失時は再発行する。
- no-store/no-referrerを設定するが、ブラウザー履歴やWebサーバーログまで消去するものではない。本番環境のログ設計は別途確認する。XAMPP設定は今回変更なし。
