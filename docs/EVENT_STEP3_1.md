# イベント管理 Step 3-1 — 自分だけの同行者管理

## 完了範囲

イベント詳細 →「同行者を管理する」から、自分と同行者の名前・メモを管理できる。
追加・編集・利用終了（通常一覧から非表示）・利用再開を実装した。同名の同行者を登録でき、識別にはIDを使う。
自分は利用終了できない。最初は画面上に「自分」を表示し、初回の追加または自分の編集の保存時にDBへ作る。
GET（画面表示・一覧取得）でDBを書き換えない。

この一覧は所有アカウントだけのもの。同じ共有イベントを別の人が管理していても、その人の同行者は取得できない。
他ユーザー検索・招待・共有・通知・共同編集、お金管理・共同支出・精算は実装していない。
Step 3-2には進んでいない。

## 開始前確認・バックアップ

2026年10月2日、開始時のGit作業ツリーはクリーンだった。`config/events.php` の「舞台」（互換内部値sports）はコミット済みであり、今回は変更していない。

使用コマンド（終了コード0）：

```sh
/Applications/XAMPP/xamppfiles/bin/mysqldump -h 127.0.0.1 -u root --single-transaction --skip-events --skip-routines --triggers --hex-blob oshilife_v2 --result-file=/private/tmp/oshilife-step31/before-step31-20261002.sql
```

- バックアップ：上記絶対パス、38,916バイト。
- CREATE TABLE：全21テーブル。INSERT INTO：19個。users、schedules、events、user_event_status、trips、todos、expensesを含む。
- Event Scheduler定義とstored routineは除外。triggerは取得オプションを保持し、実DBのtriggerは0件だった。
- 専用復元先：`oshilife_v2_step31_verify_20261002`。新規作成して復元した。
- 全21テーブルの件数・全行ハッシュ・SHOW CREATE TABLEが元DBと一致。
- 検証DBで011を適用し、新規1テーブル以外に変更がないことを確認してから実DBへ適用した。
- 検証DBとバックアップは削除していない。バックアップには個人データが含まれるため公開・Git登録しない。
- XAMPP設定、my.cnf、Event Scheduler、MariaDBシステムテーブル、旧Oshilifeには変更を加えていない。

## migration・DB構造

`database/migrations/011_event_participants.sql` を一度だけ適用済み。再実行しない。
新規環境用の `database/schema.sql` にも同じ定義を追加した。既存環境へschema.sql全体を実行しない。

追加したのは `event_participants` と、そのテーブル上のINDEX・UNIQUE・CHECK・外部キーだけ。
既存21テーブルへのALTER・UPDATE・DELETEはない。既存データの自動変換や自分の行の一括生成もない。

| 列 | 役割 |
|---|---|
| id | 同名でも区別できる参加者のID |
| user_id / event_id | 誰の、どのイベントの同行者か |
| name / note | 名前150文字・メモ3000文字以内（APIで検証） |
| self_marker | 自分は1、同行者はNULL |
| archived_at | 利用終了した日時。NULLなら利用中 |
| created_at / updated_at | 作成・更新日時 |

`UNIQUE(user_id,event_id,self_marker)` は、自分の1を一件に制限し、NULLの同行者は複数許可する。
CHECKはself_markerをNULLまたは1に限定し、自分の利用終了も拒否する。
`FOREIGN KEY(user_id,event_id)` は既存user_event_statusのUNIQUEを参照する。
単に公開イベントが見えるだけでは登録できず、本人の「自分の管理」が必要。
`ON DELETE RESTRICT` で、同行者がある本人管理の物理削除も防止する。
将来の金銭履歴のため、アプリには参加者の物理削除APIを設けていない。
`UNIQUE(id,user_id,event_id)` は将来の負担内訳等から同一所有者・イベントを参照するための土台。

### 復旧方法

新機能に問題がある場合、まず今回の同行者導線・API・画面のコード変更を戻す。既存イベント・TODO・お金管理の処理は変更していないため、新テーブルを保持したまま既存機能へ戻せる。
運用後に同行者の行ができた場合、テーブルをDROPしてはいけない。新テーブルもバックアップし、記録を残す。
元DBへバックアップを直接上書きすると、その後の正常な登録も失われる。DB復元が必要な場合は必ず別DBへ復元して比較し、復旧対象を判断する。
MariaDBのDDLは通常のデータ更新と同じようにロールバックできないため、今回のように別DBで試行してから一回だけ適用する。

## 主要ファイルの役割

| ファイル | 役割 |
|---|---|
| public/event_detail.php | 本人用の同行者画面への入口 |
| public/event_companions.php | 同行者一覧、追加、編集、終了・再開フォーム |
| public/assets/css/events.css | 長い同行者名がスマホからはみ出さない表示 |
| public/assets/js/events.js（既存・変更なし） | フォームをJSONでAPIへ送り、保存後に再表示 |
| public/api/events/companions/*.php | public配下の公開API入口 |
| api/events/companions/*.php | 操作名を固定して共通処理を呼ぶ入口 |
| app/helpers/participant_api.php | GET/POST、ログイン、CSRF、入力ID、JSON応答 |
| app/services/participant_service.php | 入力検証、自分の作成、登録・編集・終了・再開をトランザクションで処理 |
| app/repositories/participant_repository.php | 本人管理・本人の同行者をDBから取得 |
| database/migrations/011_event_participants.sql | 既存環境へ新テーブルだけを追加 |
| database/schema.sql | 新規環境用の最新DB定義 |
| tests/participants_smoke.py | HTTPで本人・イベント境界、保存、非表示、入力、既存データ保持を確認 |
| tests/participants_constraints.php | 専用復元DBでUNIQUE・CHECK・外部キーを検証し、試験行をロールバック |
| tests/participants_browser.mjs | ChromeでPC／スマホの追加・編集・非表示と表示幅を確認 |
| tests/event_test_support.py | テスト専用ユーザーの同行者も清掃対象に追加 |

## 処理の流れ・初心者向け解説

```text
イベント詳細 → 同行者画面
  ↓ ログイン中の本人を確認
user_event_statusに本人の管理があるか確認
  ↓
本人＋イベントで絞った同行者一覧を表示（閲覧だけでは保存しない）

フォームを保存
  ↓ 既存events.jsがJSONで送信
public/api/events/companions/{操作}.php
  ↓ api → participant_api.php
ログイン・POST・CSRF・入力の検証
  ↓ participant_service.php
本人管理の行をロック（同時操作を順番にする）
  ↓ participant_repository.php / eventQuery
本人＋イベント＋参加者IDを確認してevent_participantsだけを保存
  ↓
JSON応答 → 画面を再読み込み
```

- **API**：画面のJavaScriptから保存・取得を依頼するPHPの入口。画面から処理を分け、どの操作でも共通の権限確認を使う。
- **セッション**：サーバー側のログイン記録。user_idはここから取得し、画面が指定した他人のIDを採用しない。
- **プリペアドステートメント**：SQLの命令と入力値を別々に渡す方法。名前やメモがSQLとして実行されるのを防ぐ。既存eventQueryを利用。
- **CSRFトークン**：他サイトから勝手に保存操作を送られないよう照合する値。既存middlewareを利用。
- **e()/htmlspecialchars**：名前・メモをHTMLではなく文字として表示する。scriptタグ等を書かれても実行しない。
- **トランザクション**：自分の作成と同行者保存を一つの単位として確定する。途中失敗ならまとめて取り消す。
- ログイン認証・password_hash・password_verify・session_regenerate_id・.env接続設定は既存実装を利用し、今回変更していない。

## API一覧

共通パラメータはevent_id。更新ではセッションに加えてCSRFが必要。user_id/self_marker/is_self/archived_atの直接指定は拒否する。

| 操作 | メソッド | 内容 |
|---|---|---|
| list.php | GET | 本人の一覧。終了済みも区別して返す |
| create.php | POST | name/noteで同行者追加。必要なら自分も作る |
| self.php | POST | 自分の初回保存。同じイベントに何度送っても同じ自分のID |
| update.php | POST | id/name/noteで編集 |
| archive.php | POST | idの同行者を利用終了にする |
| restore.php | POST | idの同行者を利用再開する |

公開イベントAPIの応答には同行者を追加していない。旧lives APIも変更していない。

## 検証結果

- 同行者HTTP：58項目合格。追加・編集・同名・利用終了・再開・自分の一意性・自分の終了拒否・GET非更新・未ログイン・CSRF・所有者・別イベントID・入力文字数/型・HTMLエスケープを確認。
- 同行者操作の前後で既存21テーブルの全行ハッシュ一致。
- DB制約：自分の重複拒否、同名許可、自分フラグCHECK、自分の終了拒否、本人管理との外部キー、親削除RESTRICTを専用検証DBで確認。試験行はロールバック。
- Chrome：PC1440px／スマホ390px、イベント詳細と同行者の計4画面。フォーム追加・編集・非表示の実操作、横はみ出し・JS例外・リソースエラーなし。画像も目視確認。
- Step 2：131項目合格。6種類、参加確定TODO、無料0円、近場／遠征、旧API互換等。
- 支払い連携：151項目合格。チケット・交通・ホテル、二重登録防止等。
- お金管理：167項目合格。集計・特効換算等。
- 全PHP構文検査、ブラウザテストJavaScript構文検査、git diff --checkを実施。
- 各テストの専用データは清掃済み。最終比較でも、バックアップ時点の全21テーブルの全行・既存ID・金額・構造を保持。
- テストで一時レコードを追加・清掃するため、次回採番用AUTO_INCREMENTの値は進む。既存IDの付け替えや採番値のリセットは行っていない。

## 残課題・後続の追加先

Step 3-1の依頼範囲は完了。共同支出・立替・精算・expenses連携変更・特効換算変更は未実装。
同行者の機能追加はparticipant_service/repositoryとevent_companionsへ追加する。
将来の共同支出は別テーブル・別serviceで管理し、今回の参加者IDと所有者・イベントの組を参照する。
他ユーザー連携や実際の共有権限を実装する際は、個人用名簿をそのまま公開しないこと。
