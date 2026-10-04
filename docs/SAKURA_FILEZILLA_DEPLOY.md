# FileZilla 本番配置設計

確認日：2026-10-05。ローカル：`/Applications/XAMPP/xamppfiles/htdocs/oshilife/oshilife`。HEAD：`58d71f5`。

MySQL 8.0.45、31表import済み、初期ドメイン・共有SSLあり、Web公開先~/www/はユーザー報告を前提とする。本番接続での再確認はしていない。2026-10-05に分離配置用のコード修正とローカル・一時分離配置での検証を実施した。本番DB・設定・秘密情報は変更していない。

## 1. 推奨配置方式

**希望の `/oshilife/` とWeb公開領域外への分離を満たすC方式を推奨。** 公開PHPの起動パスとassets参照は修正・ローカル検証済み。本番側のURL・HTTPS・権限は転送後に確認する。

| 方式 | URL・配置 | コード変更 | 判定 |
|---|---|---|---|
| A：旧コードでpublicの中身だけ移動 | ~/www/oshilife/、非公開側は別階層 | 旧コードでは親参照が壊れた | 採用しない |
| B：実行用プロジェクトの階層を維持 | ~/www/oshilife/public/、URLも/oshilife/public/ | 原則不要 | 変更量は最小。ただし希望URLとは異なり、秘密ファイル保護を.htaccessに依存 |
| **C：公開・非公開の基準パスを分離** | ~/www/oshilife/＋~/oshilife_private/ | 公開PHP入口とassetのファイル参照を調整済み | **推奨。分離配置の一時検証済み** |

変更量だけならBが最小。今回は安全性と希望URLを優先してCを選ぶ。前回ENVガイドの「コード変更不要」は相対配置を維持する前提であり、今回指定された物理分離には当てはまらない。APP_URL変更ではPHPのrequire先は変わらない。

リダイレクトだけで/oshilife/から/public/へ送る案は希望URLの維持にはならない。シンボリックリンク・Alias・rewriteはサーバーの許可や追加設定の確認が必要なため、FileZillaでコピーするだけの手順には含めない。

## 2. 現行構造・publicの扱い

public/は公開ルートとして設計され、画面PHP、assets/css/、assets/js/、公開API入口、rooms/join.phpを含む。直下にcss/やjs/という独立ディレクトリはない。migrationはdatabase/migrations/にある。

非公開側はapp/、api/、config/、includes/、middleware/、storage/、.env。api/本体とpublic/api/入口の両方が必要。片方だけでは動かない。

### 分離で壊れた参照と現在の修正

| ファイル | 旧参照 | 現在の修正 |
|---|---|---|
| public/index.php、public/login.php | ../config/app.php | public/bootstrap.phpからAPP_PRIVATE_ROOT/config/app.phpを読み込む |
| public/events.php、public/event_detail.php等 | ../app/helpers/event_view.php | APP_PRIVATE_ROOT/app/helpers/event_view.phpを読み込む |
| public/rooms.php、public/rooms/join.php | ../app、../../middleware | bootstrap経由でAPP_PRIVATE_ROOT/app・middlewareを読み込む |
| public/api/auth/login.php等の公開API入口 | ../../../api等 | bootstrap経由でAPP_PRIVATE_ROOT/apiを読み込む |
| app/helpers/view.php | PROJECT_ROOT/public/assets | APP_PUBLIC_ROOT/assetsを使用。公開入口のbootstrapが公開先を定義 |
| config/app.php | PROJECT_ROOT/.env | private内のconfigを基準に~/oshilife_private/.envを読む |

内部のapp・api・config・middleware・includes間はprivate内で相対位置を維持する。公開PHP入口はpublic/bootstrap.phpで非公開側の位置を解決し、ローカルでは従来の親ディレクトリを使う。新しい.envキー、認証・DB・業務処理の変更はない。

## 3. ローカル → さくら配置対応表（C方式）

`~`はサーバーのホーム、通常 `/home/＜アカウント＞` の意味。FileZillaで文字「~」のフォルダーを作らず、実際のリモートホームを確認する。以下は未実施の配置計画。

| ローカル（プロジェクトからの相対パス） | さくら | Web公開可否 | アップロード |
|---|---|---|---|
| public/*.php（bootstrap.phpを含む） | ~/www/oshilife/*.php | 公開入口 | 必要 |
| public/rooms/ | ~/www/oshilife/rooms/ | 公開入口 | 必要 |
| public/api/ | ~/www/oshilife/api/ | 公開入口（API内で認証） | 同上。ルートapiと混ぜない |
| public/assets/（css・js等すべて） | ~/www/oshilife/assets/ | 公開 | 必要。css/jsを別階層へ動かさない |
| public/.htaccess | ~/www/oshilife/.htaccess | 公開領域の制御 | 必要 |
| config/ | ~/oshilife_private/config/ | 非公開 | 必要 |
| app/ | ~/oshilife_private/app/ | 非公開 | 必要 |
| api/ | ~/oshilife_private/api/ | 非公開 | 必要 |
| includes/ | ~/oshilife_private/includes/ | 非公開 | 必要 |
| middleware/ | ~/oshilife_private/middleware/ | 非公開 | 必要 |
| storage/sessions/ | ~/oshilife_private/storage/sessions/ | 非公開 | 空ディレクトリを本番作成 |
| storage/logs/ | ~/oshilife_private/storage/logs/ | 非公開 | 空ディレクトリを本番作成 |
| storage/rate_limits/ | ~/oshilife_private/storage/rate_limits/ | 非公開 | 空ディレクトリを本番作成 |
| ルート.htaccess | ~/oshilife_private/.htaccess | 非公開領域の補助防御 | 配置可。物理分離が主防御 |
| ローカル.env | コピー先なし | 秘密 | **送らない。本番で新規作成** |
| .env.example / .env.production.example | 配置しない | 設定見本 | ローカルで参照するだけ |
| database/（schema・migrations） | 配置しない | 非公開 | 不要。DBは作成済み |
| deploy/ | 配置しない | 非公開 | SQL等は管理用。Web実行に不要 |
| docs/ / README.md | 配置しない | 非公開 | 不要 |
| scripts/ / tests/ | 配置しない | 非公開 | 不要 |
| .git/ / .gitignore / .DS_Store | 配置しない | 非公開 | 不要 |

表は現在存在する主要ディレクトリを網羅。後続パス調整でファイルが追加された場合は、承認済みの配置表にも追加してから転送する。

### B方式を選び直す場合

public/は中身を取り出さず、`~/www/oshilife/public/`へ配置。app/、api/、config/、includes/、middleware/、空storage/、ルート.htaccessは`~/www/oshilife/`直下に相対構造を維持。本番.envは`~/www/oshilife/.env`。APP_URLは`https://＜初期ドメイン＞/oshilife/public`になる。ルート.htaccessの拒否を先に配置・確認し、拒否が効かなければ秘密情報を置かない。~/www/.htaccessを上書きしない。Bへの変更を黙って行わない。

## 4. アップロード対象と除外

C方式では表の実行用ディレクトリと必要な.htaccessのみを選んで転送する。プロジェクト全体をドラッグしない。publicの旧API互換入口やlegacy画面も実行資材なので勝手に除外しない。

送らないもの：ローカル.env、DB dump、backup、スクリーンショット、開発メモ、docs、README、Git情報、.DS_Store、__pycache__、*.pyc、開発scripts、tests、SQL、ローカルログ、既存sessionファイル、rate_limitsのJSON、storage/demo-accounts.txt、一時ファイル。これらの一部が今後生成されても除外する。vendor/・node_modules/は現在直下になく、今回新たに生成・追加する必要はない。

## 5. 本番.envの場所

**C方式：`~/oshilife_private/.env`（絶対パス表記：`/home/＜アカウント＞/oshilife_private/.env`）。** config/app.phpはconfigの親をPROJECT_ROOTとして.envを読む。public側の参照修正は完了している。

後の許可された作業で、サーバー側の安全な編集手段で新規作成する。FileZillaでローカル.envをアップロードしない。APP_URLは`https://＜初期ドメイン＞/oshilife`、APP_ENVはproduction。その他は[ENV設定ガイド](SAKURA_ENV_SETUP.md)を参照。秘密情報は本書・Git・チャット・テンプレートに残さない。

privateにはドメイン公開先、Alias、公開側からのシンボリックリンクを設定しない。.envをwwwの外に置くことで、通常のURLから到達できない配置にする。

## 6. .htaccess

現行ルートは`Require all denied`。public側は`Options -Indexes`、`Require all granted`、`DirectoryIndex index.php`。C方式では公開側へ**public/.htaccess**を置く。ルートの拒否ファイルを公開先に誤配置すると403になる。

RequireはApache 2.4の標準的なアクセス制御。子ディレクトリの認可設定で上書きされ得るが、AllowOverride等の実サーバー設定に依存する。[Apache公式](https://httpd.apache.org/docs/2.4/mod/mod_authz_core.html#authmerging)

さくらで各ディレクティブが許可されることは今回接続検証していない。500が出ても.htaccessを無条件で削除しない。Cでは秘密領域の物理分離を維持し、ログから原因を確認する。Bは.env・config・storage等へのHTTP拒否が必須。publicだけを許可することを検証する。どちらも既存の~/www/.htaccessや他サイトを変更しない。

初期ドメインの公開領域は今回~/www/。Web公開フォルダーとURLの関係は[さくら公式](https://help.sakura.ad.jp/purpose_beginner/2867/)も参照。今回はドメイン設定を変更しない。

## 7. permissions

特別な書込先は**3ディレクトリあり**。config/app.php:39のstorage/logs、同:51のstorage/sessions、app/helpers/rate_limit.php:10–11のstorage/rate_limits。PHP実行ユーザーに書込み・ディレクトリ通過権限が必要。ログ・セッション・制限情報のファイルはアプリが作成する。

アプリ独自のcache/、upload/、tmp/は今回の構造にない。これらを追加する必要はない。

通常ファイル644・通常ディレクトリ755を出発点とし、実行方式・所有者に合わせて確認する。PHPが所有者として動く場合、privateのディレクトリ700、.env600等の限定権限を使える。数値は動作保証ではなく、PHPの実行ユーザーが読める／必要箇所に書けることを確認する。エラー解消のため一律777や再帰的な書込許可を設定しない。

## 8. FileZilla作業順

C方式のパス修正と一時分離配置での検証は完了。以下は実際に転送する際の手順であり、本番操作はまだ行っていない。

1. Git差分と転送対象を確認し、公開URLと配置を確定する。public/bootstrap.phpを必ず含める。
2. FileZillaの接続情報をさくらのサーバー接続情報で用意する。DBホスト・DBパスワードではない。利用可能な暗号化転送方式と証明書／ホスト鍵を確認する。
3. 接続後、リモート側ホームを確認する。~/www/の既存ファイルを削除・上書きしない。oshilife/が既にあれば内容とバックアップを確認して停止判断する。
4. ホーム直下にoshilife_private/を作成。www/の中に作らない。表の非公開実行ファイルを配置する。
5. private/storageの3ディレクトリを空で作り、PHPの必要権限を設定する。
6. 本番.envをサーバー側で新規作成する。秘密値をFileZillaのスクリーンショット等へ写さない。
7. ~/www/oshilife/を作成し、修正・検証済みpublic/の**中身**を配置する。publicという追加階層は作らない。.htaccessも転送されたか確認する。
8. 転送失敗一覧が空であること、大文字小文字・階層・assets・API入口が揃っていることを確認する。非公開apiを公開apiへ混ぜない。
9. SSL・HTTP転送・APP_URL・書込権限を確認し、次章のURLへアクセスする。
10. 初回動作と非公開領域の保護を確認する。schemaやmigrationは再実行しない。

## 9. 初回アクセス

Cの修正完了後は`https://＜初期ドメイン＞/oshilife/`。未ログインなら`/oshilife/login.php`へ進む想定。APP_URLと実際の共有SSLホストが一致し、証明書警告がないことを確認する。

CSS・JSが読み込まれること、APIがHTMLエラーではなくJSONを返すこと、ログイン／ログアウトと招待からの復帰を確認する。新規登録等の書込みを伴う試験は別途許可されたテストアカウントで行う。31表作成済みでもユーザー行が存在するとは限らない。

`/oshilife/.env`、`/oshilife/config/`、`/oshilife/storage/`等で秘密情報や一覧が返らないことを確認する。Cではそこに実ファイルを置かない。Bを選んだ場合はこれらの拒否が秘密保護の必須条件になる。

## 10. トラブル時

| 症状 | 確認箇所 |
|---|---|
| 500 | サーバーのエラーログ、.htaccess許可、PHP版、require先、転送漏れ。起動前エラーはアプリログに残らない場合あり |
| 503／一般エラー画面 | private/storage/logs/php-error.log、.envの場所、DB項目、セッション権限。詳細を公開画面へ出さない |
| 403 | 拒否用.htaccessの誤配置、サーバー側アクセス制限、所有者・権限。拒否を無条件に解除しない |
| 404 | /publicを含めたかどうか、リモート階層、index.php、public/apiの転送漏れ・大文字小文字 |
| DB接続失敗 | DB用ホスト・ユーザー・名前・パスワード、pdo_mysql。FTP資格情報との混同、空欄を確認。DB作り直しはしない |
| CSSが効かない | /oshilife/assets/cssへのURL、assets転送、APP_URL、assetUrlの公開ファイル基準パス修正 |
| redirect不正 | APP_URLの/oshilife、ホスト・HTTPS、古いCookie。PHPファイルの移動はAPP_URLでは修正できない |
| session / CSRFエラー | private/storage/sessionsの書込、Secure CookieとHTTPS、Cookieパス、ホストの不一致 |
| API／Chat通信失敗 | ブラウザーNetwork、public/apiと非公開apiの両方、JSON応答、HTTPS、セッション、rate_limits権限 |

## 11. アップロード前チェックリスト

- [x] 配置変更に必要なコード修正とローカル・一時分離配置の検証済み
- [ ] デプロイ対象のGit commit済み
- [x] git statusを確認（コード差分と準備資料は未コミット）
- [ ] ローカルのコード／DBバックアップを確認（今回は取得・検証していない）
- [x] 本番DB schema作成済み（ユーザー報告）
- [x] MySQL 8.0.45、31表確認済み（ユーザー報告）
- [ ] 公開URLの実ホスト・/oshilife/を確定
- [ ] 共有SSLの実アクセス・HTTP転送を確認（共有SSLありとの報告は受領）
- [x] 本番.envの項目を整理済み（実値未入力）
- [ ] C方式の非公開／公開配置を承認、既存配置との重複なし
- [ ] 不要ファイル・ローカル.env・既存storage内容を除外
- [ ] 最終転送対象にパスワードを直書きしていない
- [ ] PHP版・拡張・非公開保護・3保存先の権限を確認

コード修正・本書を含む準備資料は未コミット。Git commit/push、本番アップロードはまだ行っていない。

## 12. 次の判断

推奨Cの公開入口とasset参照のパス調整は完了。次は本書の配置表に従いFileZillaで転送し、さくら上でHTTPS・PHP・権限と主要機能を検証する。今回アップロード・本番操作は行っていない。
