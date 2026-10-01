# Phase 2 実装・学習レポート

## 今回追加したコードを理解するための解説

今回の中心は「共有の推し」と「自分が登録した推し」を区別することです。推し本体をユーザーごとにコピーせず、同じ推しを多くの人が参照する構造にしました。予定・イベント・資金の本機能にはまだ着手していません。

## 1. 変更したファイル

主要な変更は次のとおりです。全ファイルの正確な区分は [FILES.md](FILES.md) を参照してください。

| ファイル | 変更した理由 |
| --- | --- |
| config/app.php / .env / .env.example | APP_URLから公開パスを決め、Apacheのサブディレクトリ配置に対応 |
| includes/header.php / bottom_nav.php / footer.php | ナビを1つのHTMLに集約し、スマホ下部・PC上部を切り替える |
| public/assets/css/common.css | 767 / 1199pxを境に幅・列数・ナビを切り替える |
| public/home.php / assets/css/home.css | 推しをDBから読み、PC用グリッドを追加 |
| public/profile.php / assets/css/profile.css | 推し管理への入口を追加 |
| public/discover.php / events.php | 既存サンプルカードをPCで横並び可能にする |
| app/helpers/response.php | Apache版PHPでもCSRFの419を正しく返す |
| database/schema.sql | 新規環境用として追加3テーブルも収録 |
| database/seeds/oshis.csv / members.csv | 実際に検証・取込できるヘッダーに統一 |
| tests/smoke.py | localhostのサブパスに対応 |
| README.md / docs/LEARNING.md / IMPLEMENTATION.md | 現行手順を更新し、Phase 1の記録と区別 |
| .gitignore | Pythonの検証キャッシュも除外 |

## 2. 新規作成した主要ファイル

| ファイル | 役割と読む順序 |
| --- | --- |
| public/oshis.php | 検索・自分の一覧・新規フォームを表示 |
| public/oshi_detail.php | 詳細とメンバー一覧、作成者用フォーム |
| public/assets/js/oshis.js | 入力をJSONとして送信し、結果を安全にDOMへ反映 |
| public/assets/js/home.js | 選択した推しを案内する |
| public/assets/css/oshis.css | 推しのカード、詳細2列、ハート選択の見た目 |
| public/api/oshis/ | 公開URL。実処理はpublic外へ渡す |
| api/oshis/ | 10個のJSON API本体 |
| app/helpers/oshi_api.php | 全API共通の認証・CSRF・ID・エラー変換 |
| app/validators/oshi_validator.php | API/CSVの共通入力ルール |
| app/services/oshi_service.php | 同時保存・権限確認・解除の手順 |
| app/repositories/oshi_repository.php | SQLを一か所に集める |
| database/migrations/002_oshi_management.sql | 作成済みDBへ追加3表だけを適用 |
| scripts/import_masters.php | CLIだけで動くCSV検証/投入 |
| tests/phase2_smoke.py / csv_smoke.php | HTTPとCSVの実データを使う検証 |

PHP/JavaScriptの冒頭と主要関数に日本語コメントを残しました。CSSの新規レスポンシブ部分にも切替の目的を記載しています。

## 3. DBへ追加したテーブルと関係

```text
users（利用者）
  │ 1人に複数の登録
  ▼
user_oshis（利用者ID ＋ 推しID）
  │ 多くの利用者が同じ推しを参照
  ▼
oshis（共有の名前・種別・絵文字・作成者・有効状態）
  │ 1つの推しに複数のメンバー
  ▼
members（名前・カラー名・ハート・HEX・有効状態）
```

**共有マスター**は、皆が使う元になる情報です。**中間テーブル**は、その情報と利用者の関係だけを記録する表です。今回のuser_oshisには「Aさんがこの推しを登録した」という関係を保存します。

**JOIN**は表同士をIDでつなぐSQLです。例えばfindMyOshis()ではuser_oshisにある推しIDからoshisの名前・絵文字を取得します。自分の登録だけはWHERE user_idで制限します。

**UNIQUE**はDBが二重登録を拒否する制約です。user_oshisのuser_id + oshi_id、membersのoshi_id + name、oshisのname + oshi_typeに設定しています。画面ボタンを無効にするだけでは同時送信に弱いため、最後はDBでも保証します。

ユーザーが登録解除しても、user_oshisの本人分だけが消えます。共有マスターや他ユーザーの登録は消えません。users / user_settingsのテーブル定義と既存ユーザーは維持しています。is_active=0のマスターは通常のAPIに出しません。

## 4. API一覧

共通の基点は `http://localhost/oshilife-v2/public` です。すべてログイン必須。POSTはJSON本文とCSRFが必要です。

| メソッド | URL | 役割 |
| --- | --- | --- |
| GET | /api/oshis/list.php | 有効な共有推しの一覧 |
| GET | /api/oshis/search.php?q=名前 | 名前で検索。登録済み状態付き |
| POST | /api/oshis/create.php | 共有推しの作成＋本人の登録 |
| POST | /api/oshis/update.php | 作成者本人が名前・種別・絵文字を編集 |
| POST | /api/oshis/follow.php | 本人の登録を追加 |
| POST | /api/oshis/unfollow.php | 本人の登録だけを解除 |
| GET | /api/oshis/my.php | 本人の登録済み推し一覧 |
| GET | /api/oshis/detail.php?id=ID | 詳細、登録状態、管理権限 |
| GET | /api/oshis/members.php?id=ID | 有効メンバー一覧 |
| POST | /api/oshis/members/create.php | 作成者がメンバーを追加 |

APIとは、ブラウザー等から通信で呼べる処理の入口です。HTML画面の代わりにJSONを返します。PHPページとAPIはどちらも同じrepositoryを利用できるため、ホームで推し一覧を取得する際に自分のAPIへHTTP通信をする必要はありません。

## 5・6. レスポンシブとPC/スマホの違い

- 767px以下：1列、幅をほぼ100%使い、ナビは下部固定。
- 768〜1199px：ホームと一覧・詳細を2列にし、余白を増やす。
- 1200px以上：本文は最大1360px。推し一覧3列、ホーム2列、ナビはヘッダー内。
- DOM（HTMLの要素構造）をPC用/スマホ用に複製せず、CSS Gridとmedia queryで配置だけを変える。
- ログイン等の入力フォームは無理に広げず、カード内の文字サイズも極端に拡大しない。
- 既存のアイボリー・くすみピンク・ラベンダー、角丸・薄い影を維持。

## 7. 推し登録の処理フロー

```text
oshis.phpを開く
  ↓ 共通起動と認証middleware
oshis.js → GET search.php
  ↓ oshi_api.phpがセッション確認
oshi_repository.phpが共有マスターと本人の登録をJOIN
  ↓ JSON
検索結果をtextContentで表示
  ↓ 「追加」
POST follow.php（CSRFトークンを付ける）
  ↓ セッションからuser_idを決定
oshi_service.php → 有効なマスターを確認・ロック
  ↓ PDO prepare / execute
user_oshisへ保存 → JSON → 自分の一覧と登録済み表示を更新
```

新規作成ではvalidatorで入力確認後、oshisとuser_oshisをトランザクションで保存します。**トランザクション**は複数の保存をまとめて成功/取消にする仕組みです。推しだけ作られて自分の一覧に入らない中途半端な状態を避けます。

PDOはPHPからDBに接続する標準機能です。prepareでSQLの形を固定し、executeで値を分けて渡すため、入力にSQLの命令を書かれても検索や保存の命令に混ざりません。

ログインとセッションの仕組みはPhase 1を維持しています。セッションは「ログイン中の本人ID」をサーバーで保持する仕組みです。フォームのuser_idやcreated_by_user_idは信用せず、サーバーで取得した本人IDを使います。

## 8. CSVの扱い

2つのCSVはヘッダーのみで、マスターの大量投入はしていません。CLIの既定動作はdry-run（内容確認だけ）です。実行時に `--apply` を指定した場合だけ、作成者IDを確認して投入します。

- APIと同じvalidatorを使い、絵文字・ハート・HEX・長さを検証。
- 同一内容はスキップ、既存と違う内容は上書きせず拒否。
- 同名の推しが複数種別ある場合、members.csvの参照先が曖昧なので停止。
- 他の作成者の推しへメンバーを追加できない。
- user_oshisは変更しない。初期マスターとユーザーの日々の操作を区別する。

## 9. セキュリティと仕様判断

認証・CSRF・PDO・utf8mb4・HTMLエスケープを維持しました。PHPはe()/htmlspecialchars、JSはtextContentで名前や絵文字を表示します。HEX色は `#` と6桁の16進数に限定。ハートは11候補から選択します。

共有情報を誰でも変更できる状態を避けるため、メンバー追加はマスターの作成者だけにしました。UIで隠すだけでなくAPIのservice内でも権限を確認します。本人登録の解除は共有情報への変更ではないため、各ユーザーが自分の分を解除できます。

新規マスターはname + oshi_typeの重複を拒否します。同名の俳優とグループなど別種別は登録できます。推し名1〜100文字、メンバー名1〜100文字、カラー名1〜40文字、絵文字/記号1〜32文字。複数絵文字を切り落とさず保存します。

## 10. localhost確認と検証結果

- 公開URL：<http://localhost/oshilife-v2/public/>
- Phase 2初版のPHP 57ファイルとJavaScript 4ファイルを確認済み。推し編集追加後はPHP計59ファイルになり、追加・変更したPHPとJavaScriptの構文も確認。
- Apache上でPhase 1認証30件、Phase 2 HTTP109件が成功。
- indexの未ログイン時のlogin.php・ログイン済み時のhome.phpへの移動、homeの保護、認証API、CSS/JSのサブパスと配信を確認。
- 別ユーザーのマスター検索・追加・解除、二重登録拒否、user_id偽装の無視、作成者以外の追加403、無効化マスター非表示を確認。
- 絵文字・ハート・HEX、HTML出力のエスケープ、CSRF、不正ID・種別等を確認。
- .env / .git / config / database / storage / scriptsへの直接HTTPアクセスが403になることを確認。
- CSVのdry-run、実投入、絵文字、個人登録を触らないこと、再実行、既存衝突・不正入力の拒否を確認。
- テストデータは各テスト自身が作ったものだけ削除。既存ユーザーと設定は各1件のまま。

**ブラウザーの実操作と表示検証は未実施です。** Computer UseによるGoogle Chrome操作が許可されなかったため、画面幅別の見た目、JavaScriptによるボタン操作、ハートの選択表示、解除確認ダイアログは実ブラウザーで確認できていません。HTTP応答・配信・構文チェックとは区別します。

ユーザーが確認する順序は、ログイン → マイページの推し管理 → 検索 → 新規作成 → メンバー追加 → ホームの切替 → 解除のキャンセル/実行 → 別ユーザーで検索 → ログアウトです。画面幅は390px・900px・1280px以上を目安にします。

## 11. 未実装

予定の本機能・共有・同期・修正提案・リアクション・イベント本機能・遠征・お金・連番ルーム・Chatは範囲外です。ホームの予定等はサンプルのままで、推し選択による予定の絞り込みも未実装です。

共有マスターの無効化画面やメンバーの編集/無効化画面、共同管理、ユーザーテーマの適用も未実装です。作成者退会時はマスターが残り、管理者の引継ぎは次Phaseで設計します。

## 12. Phase 3前の確認と追加先

- 実ブラウザーでスマホ/PCの見た目と操作を確認する。
- 共同編集・作成者不在時の引継ぎ・誤登録対応の仕様を決める。
- 予定用のapi/services/repositories/validatorsを追加し、oshisとmembersのIDを参照する。
- 予定データに本人の編集権限チェックを入れ、他ユーザーの予定を書き換えられないようにする。
- DBは次のmigrationを追加し、既存スキーマを削除再作成しない。
- ホームの公開予定は自分のカレンダーに未追加のものだけにする。
- 自分の推し選択の永続化方法は、実際の絞り込みと合わせて決める。

## 追加対応：推し情報の編集

詳細画面に作成者専用の「推し情報を編集」を追加しました。PHPは入力欄の現在値もe()でエスケープします。JavaScriptは共通API通信を使って保存し、成功後に詳細画面を読み直します。エラー時は入力を残し、該当項目へ理由を表示します。

- 新規ファイル：api/oshis/update.php、public/api/oshis/update.php。
- 変更：oshi_detail.php、oshis.js、oshis.css、oshi_service.php、oshi_repository.php、統合テストとドキュメント。
- updateOshi()は対象をロックして作成者を確認し、updateOshiRecord()は表示情報3項目だけをUPDATEします。
- 他ユーザーの操作403、未認証401、CSRF不足419、入力不備422、重複409、無効/存在しない推し404を確認済み。
- 同じ内容を保存しても成功します。メンバー・登録の関係を保持し、他ユーザーにも更新後の共有情報を返します。
- HTTP109件が成功。検証終了後も既存のusers=1、user_settings=1、oshis=1、members=4、user_oshis=1を維持。一時データだけを清掃しました。
- ブラウザーでの編集ボタン・キャンセル・保存操作は未確認です。

## 推しテーマカラーの廃止

推し本体は名前・種別・絵文字だけを登録・編集します。新規登録、編集、詳細表示、API、推しCSVからテーマカラーを外しました。メンバーのカラー名・ハート・HEXは継続して使用します。既存データを削除しないため、DBのoshis.theme_colorは旧仕様との互換用に残していますが、アプリは読み書きしません。DB変更の実行は不要です。

## メンバーカラーの選び方

メンバー追加では、色名付きのハートを選ぶだけで登録できます。色名と保存用の色コードは自動で設定されるため、HEXを入力する必要はありません。「色を細かく調整する（任意）」を開けば、色見本から好きな色を選び、色名も変更できます。メンバー一覧はハートと色名を表示します。

実装では `oshi_validator.php` の `MEMBER_COLOR_PRESETS` をHTMLのdata属性へ渡し、`oshis.js` の `selectMemberColor()` が選択されたハートに合わせて入力値を更新します。DB/APIでは引き続きhex_colorを内部の保存形式として利用し、既存データの変換やDB変更は不要です。
