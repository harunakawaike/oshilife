# 今回追加したコードを理解するための解説

認証を学ぶPhase 1の解説です。推し管理・JOIN・共有マスター・レスポンシブ・URL設定の学習は [Phase 2の解説](PHASE2.md) を続けて読んでください。

## 最初に読む順番

`public/login.php` → `public/assets/js/auth.js` → `public/assets/js/common.js` → `api/auth/login.php` → `app/services/auth_service.php` → `app/repositories/user_repository.php` → `config/database.php` の順で読むと、一回のログインを追えます。

PHP / JavaScript各ファイルの先頭には役割を書き、主要関数にはコメントを付けています。全体の対応表はREADMEの「主要ファイルの役割」、全ファイル名はFILES.mdにあります。

## 各層は何を担当するか

画面（public）はHTMLを作ります。JavaScriptは入力を送信して結果を反映します。APIはHTTPで呼べる処理の入口です。validatorは入力の確認、serviceは仕事の手順、repositoryはDBとの会話、middlewareはその処理をしてよいか確認する門番です。helperには複数箇所から使う小さな道具を置きます。

別々のファイルにすることで、画面の見た目を変えてもSQLを書き直す必要がなくなります。同時に、画面だけ直して認証チェックを忘れることを防ぎやすくします。

## ページを開いてからDBへアクセスするまで

ブラウザーがlogin.phpを開くと、PHPはconfig/app.phpを読み、.envの設定とセッションを準備してHTMLを返します。ログイン画面を表示するだけならDB接続は不要です。

ログインボタンを押すと、auth.jsが入力を取り出し、common.jsのfetch（サーバーへ通信するブラウザーの機能）を使います。公開入口public/api/auth/login.phpを経てapi/auth/login.phpが呼ばれます。ここで送信方法・CSRF・回数・JSONを確認します。

serviceがrepositoryのfindUserByEmailを呼び、repositoryがdatabase()でPDO接続を取得します。PDOはPHPからDBへ話しかけるための標準機能です。prepareでSQLの形を用意し、executeで入力値を渡します。結果が配列としてPHPへ戻り、パスワード照合に進みます。

ログイン後にhome.phpを開くと、middlewareが毎回DBのユーザーを確認します。存在しない・削除済みなら表示できません。ホームの予定や金額は現在は固定の表示例で、予定テーブルはまだありません。

## ログインが成立する仕組み

登録時にpassword_hash()でパスワードを「ハッシュ」という復元しにくい値に変えて保存します。同じパスワードでも毎回異なるハッシュになります。salt（同じ入力が同じ保存値にならないよう加えるランダム値）もPHPが管理します。

ログインではemailで1人を探し、password_verify()で入力したパスワードが保存済みハッシュに対応するか確認します。パスワードをDBから取り出して復号するわけではありません。

成功したらsession_regenerate_id(true)でIDを新しくし、`$_SESSION['user_id']`へユーザーのIDを保存します。APIが成功を返すとJavaScriptがhome.phpへ移動し、そのページの認証チェックが通ります。メールが存在しない場合とパスワード違いの場合は同じエラー文にします。

## セッションとは何か

HTTPの通信は基本的に1回ごとに独立しています。そのままだと、別ページに移ったときに「誰がログインしているか」を忘れてしまいます。

セッションは、サーバー側に情報を保管して通信をまたいで覚えておく仕組みです。ブラウザーにはCookieという小さな保存領域へランダムなセッションIDだけを渡します。次回の通信でブラウザーがIDを送ると、PHPが対応するサーバー上の情報を読みます。パスワード自体をCookieへ入れません。

このアプリのCookie名はOSHILIFE_V2_SESSION、保存先はstorage/sessionsです。2時間操作しないとログイン情報を破棄します。ログアウトではサーバーの情報とCookieの両方を消します。

## APIとは今回の構成では何を指すか

`/api/auth/register.php`などの「HTTPで呼び出せるPHP処理」を指します。ページがHTMLを返すのに対して、APIはJSONというキーと値の組み合わせを返します。

例：`{"success":true,"data":{"user_id":1}}` は登録成功とIDを表します。JavaScriptはsuccessを見て、画面を移動したり日本語のエラーを表示したりします。APIと画面が分かれているので、将来別の画面からも同じ登録処理を呼べます。ただしスマホアプリ向け認証は別途設計します。

## 安全のための処理を読む

- CSRFトークン：この画面を開いたセッションが持つ、推測困難な合言葉。Cookieだけで更新を許可しないために送ります。
- htmlspecialchars：表示名に `<script>` と書かれていても、命令として実行せず文字として表示します。SQL対策とは別の役割です。
- .env：手元ごとのDB設定。秘密をソースコードと分離します。Gitから除外しても、誤って公開フォルダに置けば漏れるため公開範囲も分けます。
- トランザクション：ユーザーだけ保存され、設定だけ失敗する中途半端な状態を避けるため、両方まとめて成功・取消にします。
- UNIQUE制約：同じメールを2つ登録できないDBのルール。同時登録でもDBが守ります。
- middleware：URL直接アクセスでも、必ず認証確認を通す共通関数です。

## 今後どのファイルへ機能を追加するか

| 作りたい機能 | 追加・変更する場所 |
| --- | --- |
| 見た目の調整 | public/*.php、public/assets/css/、includes/ |
| カレンダーの操作 | public/calendar.phpと専用JS、api/schedules/を新設 |
| 予定の保存と取得 | app/validators/、app/services/、app/repositories/に予定用を追加 |
| 新しいDBテーブル | database/へ変更用SQLを追加。schema.sqlの再実行はしない |
| ホームに未追加予定を表示 | user_schedulesとの関係をrepositoryで調べ、home用APIから取得 |
| テーマ変更 | user_settings用repository・service・APIを追加し、CSS変数へ適用 |
| 推し・会場マスター投入 | database/seeds/のCSVと、公開しない取込スクリプトを追加 |
| 認証ルール変更 | auth_service、auth_validator、middleware |

APIには認証・CSRFだけでなく「この予定はログイン本人が編集できるか」という権限確認も必要です。大きな処理をPHP画面へ書き足さず、同じ仕事を担当する層へ追加していくと読みやすさを保てます。
