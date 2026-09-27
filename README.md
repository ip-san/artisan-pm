# Artisan PM

日本語 | [English](README.en.md)

Artisan PM は、オープンソースのプロジェクト管理ツール [Redmine](https://www.redmine.org/) を、Laravel 13・Livewire 4（[Volt](https://livewire.laravel.com/docs/volt) による単一ファイルコンポーネント）・PostgreSQL で作り直したものです。目指しているのは Redmine に着想を得た別のアプリではなく、Redmine の実際の挙動を、画面上の機能一覧だけでなく個々の業務ルールまで再現することです。

Redmine との機能の差は [`docs/parity-checklist.md`](docs/parity-checklist.md) で管理しています。このチェックリストでは、Redmine 本体のソースと本リポジトリのコードをモジュールごとに突き合わせ、意図的に変えた点や対象外とした機能についても理由を記録しています。

## 実装済みの機能

課題管理（トラッカー、ステータス、ワークフロー、カスタムフィールド、関連する課題、ウォッチャー）、ガントチャートとカレンダー、Wiki（版の履歴、リダイレクト、プロジェクトごとに設定できる開始ページ、マクロ）、フォーラム、ニュース、工数管理、複数の SCM リポジトリ（Git/SVN）の閲覧・差分・アノテート、保存済みクエリ、プロジェクトの階層化、ロールによる権限管理、LDAP 認証、2要素認証、メール通知（`@mention` を含む）、リアクション（課題・コメント・ニュース・フォーラム投稿への 👍 の付け外し）、REST API、PDF エクスポート（課題・Wiki・ガントチャート。日本語などの CJK フォントに対応）、公開プロジェクトへのゲストアクセス（`login_required` で切り替え）、アクセス権を失った対象のウォッチを定期的に外すバッチ処理。機能ごとの正確な状況はチェックリストを参照してください。

## 技術スタック

| レイヤー | 採用技術 |
|---|---|
| バックエンド | PHP 8.3 以上（開発は 8.5。`composer.lock` は 8.3 向けに解決済み）、Laravel 13 |
| UI | Livewire 4 + Volt（単一ファイルコンポーネント）、Tailwind CSS |
| 認証 | Laravel Fortify（パスワード、2要素認証/TOTP）、`directorytree/ldaprecord-laravel` による LDAP |
| データベース | PostgreSQL（開発時）。MySQL 8.0 以上 / MariaDB 10.3 以上、SQLite にも対応 |
| 検索 | Laravel Scout（database ドライバー） |
| 添付ファイル | `spatie/laravel-medialibrary` |
| 入れ子集合（プロジェクトの階層のみ。課題は単純な `parent_id` による隣接リストを使用。`docs/design/domain-model.md` 参照） | `kalnoy/nestedset` |
| PDF エクスポート | `barryvdh/laravel-dompdf`（CJK 用に IPAゴシックフォントを同梱。`resources/fonts/` 参照） |
| テスト | Pest 4 |
| 静的解析 | Larastan（PHPStan） |
| ローカル環境 | Laravel Sail（Docker） |

## セットアップ

本プロジェクトは、すべて [Laravel Sail](https://laravel.com/docs/sail) の Docker コンテナ内で動かします。以下のコマンドはすべて `vendor/bin/sail` 経由で実行します。

```bash
# 1. PHP の依存パッケージをインストールする。`vendor/` ができるまでは
#    `vendor/bin/sail` が存在しないため、クローン直後の初回だけは
#    使い捨ての Composer コンテナで実行する。下のイメージタグが
#    お使いの PHP バージョンに合わない場合は、Laravel 公式ドキュメントの
#    "Installing Sail Into Existing Applications" を参照:
#    https://laravel.com/docs/sail#installing-sail-into-existing-applications
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs

# 2. 環境ファイルをコピーする。.env.example は最初から Sail の PostgreSQL
#    サービス（DB_HOST=pgsql、DB_PASSWORD=password）を指しており、
#    compose.yaml の POSTGRES_PASSWORD の既定値とも一致しているので、
#    標準のローカル環境なら手で編集する必要はない。
cp .env.example .env

# 3. コンテナを起動する（アプリ、PostgreSQL、Redis、Mailpit）
vendor/bin/sail up -d

# 4. アプリケーションキーを生成し、マイグレーションと初期データの投入を行う。
#    DatabaseSeeder は、Redmine 本体にも組み込まれている Anonymous/Non-member
#    ロール、デモ用のプロジェクト、すぐにログインできる管理者ユーザー
#    （admin@example.com / password）を作成する。
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate --seed

# 5. JS の依存パッケージをインストールし、フロントエンドをビルドする
vendor/bin/sail npm install
vendor/bin/sail npm run build
```

> **既知の問題**: 執筆時点では、この環境で `npm run build` が `Cannot find module './rolldown-binding.linux-arm64-gnu.node'` というエラーで失敗します。Vite が依存する `rolldown-vite` のビルド済みネイティブバイナリが、このアーキテクチャの Sail コンテナ内で正しく解決されないためです。本アプリのコードが原因ではない、以前からある環境の問題で（`docs/parity-checklist.md` のガントチャートの Tailwind カラーが欠けている件にも記載）、根本原因はまだ特定できていません。バックエンドと Livewire で動くページは問題なく動作し、影響を受けるのは Vite でビルドするフロントエンドのアセット（コンパイル済みの Tailwind CSS など）だけです。

`vendor/bin/sail open` でアプリを開くか、`http://localhost` にアクセスしてください。ローカル開発中に送信したメールは [Mailpit](https://github.com/axllent/mailpit) が受け取り、`http://localhost:8025` で確認できます。

### テストの実行

```bash
vendor/bin/sail artisan test --compact
vendor/bin/sail bin phpstan analyse --no-progress
vendor/bin/sail bin pint --format agent
```

### リポジトリの保存場所

アプリが閲覧・同期する SCM リポジトリは、`SCM_REPOSITORIES_ROOT`（`config/scm.php` 参照）で設定したディレクトリの下に置く必要があります。アプリはそのディレクトリ配下のパスに対して `git`/`svn` コマンドを実行するだけで、リポジトリの作成は行いません。

## レンタルサーバーへのデプロイ

Xserver、さくらのレンタルサーバ、ConoHa WING などのレンタルサーバー（PHP-FPM、MySQL 8.0 以上 / MariaDB 10.3 以上、cron が使えて常駐プロセスは不可）で動作します。各手順に出てくる設定項目の説明は [`.env.example`](.env.example) にあります。

1. **ローカルでビルドする。** レンタルサーバーには Node.js がないことが多く、`public/build` はリポジトリに含めていません。`npm ci && npm run build` を実行し、PHP 8.3 以上で `composer install --no-dev --optimize-autoloader` を実行します（lock ファイルは 8.3 向けに解決済みなので、8.3 以上のサーバーならインストールできます）。サーバーに ldap 拡張がなく、LDAP も使わない場合は `--ignore-platform-req=ext-ldap` を付けてください。
2. **アップロードする。** `vendor/` と `public/build/` を含むアプリ全体を、Web ルートの**外側**のディレクトリ（例: `~/artisan-pm`）に置きます。`public_html` の中には置きません。
3. **Web ルートを `public/` に向ける。** Web ルートは必ずアプリの `public/` ディレクトリにしてください。アプリのディレクトリそのものを Web ルートにすると `.env` が公開されてしまいます。レンタルサーバーではドキュメントルートが `public_html`（または `~/<ドメイン>/public_html`）に固定されているので、次のどちらかで対応します。
   - シンボリックリンクに置き換える（推奨）: `mv public_html public_html.orig && ln -s ~/artisan-pm/public public_html`
   - シンボリックリンクが使えない場合は、アプリを `public_html` の中に置き、すべてのリクエストを `public/` に渡す `public_html/.htaccess` を追加します。このリライトにより、`.env` などアプリ本体のファイルが直接公開されることもありません。
     ```apache
     <IfModule mod_rewrite.c>
         RewriteEngine On
         RewriteRule ^(.*)$ public/$1 [L]
     </IfModule>
     ```
4. **`.env` を設定する**（`.env.example` をコピー）: `APP_ENV=production`、`APP_DEBUG=false`、`APP_URL=https://…`、データベース（`DB_CONNECTION=mysql` または `mariadb`。`DB_COLLATION` はマイグレーションの前に決めておく）、`QUEUE_CONNECTION=database`、サーバーの手前で HTTPS を終端する場合は `TRUSTED_PROXIES=*`、メールの設定、日次ジョブを日本時間の深夜に実行したい場合は `SCHEDULE_TIMEZONE=Asia/Tokyo`。
5. **初期化する。** SSH で実行します。PHP はサーバー上の PHP 8.3 以上をフルパスで指定してください（例: `/usr/bin/php8.3`）。
   ```bash
   php artisan key:generate
   php artisan migrate --force
   php artisan passport:keys          # REST API の OAuth トークン用の鍵
   php artisan db:seed --force        # 既定のロール・トラッカー・ステータス、デモ用プロジェクト、admin@example.com / password
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
   すぐに `admin@example.com` でログインしてメールアドレスとパスワードを変更し、デモ用プロジェクトが不要なら削除してください。`.env` を変更したら、そのたびに `php artisan config:cache` を実行し直します。
6. **パーミッションを確認する。** `storage/` と `bootstrap/cache/` は PHP から書き込める必要があります（`chmod -R u+rwX storage bootstrap/cache`。レンタルサーバーでは PHP が自分のユーザー権限で動くので、755/644 で十分です）。添付ファイル、ログ、dompdf のフォントキャッシュ、Git/SVN リポジトリ（`SCM_REPOSITORIES_ROOT`）はすべて `storage/` の下に置かれます。
7. **cron を登録する。** コントロールパネルで設定できるなら、毎分実行のエントリを1つ登録します。毎分が選べない場合は `*/5` にし、00分から始まるようにします（理由は `.env.example` 参照）。
   ```
   * * * * * cd /home/you/artisan-pm && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
   ```
   これで定期ジョブ（受信メールの取り込み、リポジトリの自動取得、後片付け）が実行されます。`QUEUE_CONNECTION=database` にしていれば、キューに入った通知メール、Webhook、CSV インポートもこのエントリで処理されるので、2つ目のエントリや常駐ワーカーは要りません。
8. **動作を確認する。** 管理 → 情報 の画面で、アプリが検出した PHP 拡張、書き込み可能なディレクトリ、git/svn コマンドを確認できます。

## アーキテクチャ

レイヤー構成の概要、ドメインモデルの全体像、認可モデル、リクエストのライフサイクル、課題のワークフロー、通知とジョブの処理の流れは、[`docs/design/`](docs/design/README.md) に Mermaid の図付きで詳しくまとめています。システム全体を把握したいときは、まずそちらを読んでください。この README では、アプリを動かすまでの手順と、以下の主な設計判断に絞って説明します。

## 主な設計判断

コードベースのあちこちで繰り返し出てくるパターンです。変更を加える前に知っておくと役に立ちます。

- **モデルの不変条件は1か所で守る。** Redmine がモデル層で保証している挙動（例:「プロジェクトの最初のリポジトリは自動的に既定のリポジトリになる」「リポジトリの識別子は一度設定したら変更できない」）は、その属性を書き換えるすべての経路に散らばらせず、Eloquent のモデルフック（`saving`/`created`/`updated`）で実装しています。
- **認可は一元化する。** `App\Support\Authorization\AuthorizationService` が、Redmine のプロジェクト単位・ロール単位の権限解決（組み込みの `Anonymous`/`Non-member` ロールを含む）を再現しています。ポリシーはロールの判定を自前で実装せず、このサービスを呼び出します。
- **通知の宛先は、先にまとめてから1回だけ絞り込む。** 課題のメール、Wiki のメール、`@mention` など、独立した複数の宛先グループ（ロールごとのプロジェクトメンバー、ウォッチャー、明示的にメンションされたユーザー）に通知する機能では、まず各グループを合わせて重複を除き、そのあとで閲覧権限による絞り込みを1回だけかけます。二重の絞り込みや二重送信を防ぐためです。
- **複数リポジトリのルーティングは、省略可能なセグメントではなく名前付きルートの組で実現する。** Laravel のルートパラメーターが本当に省略可能になるのは、URL の最後のセグメントだけです。リポジトリの識別子はパスの途中に必要なので、リポジトリの各アクションで識別子なしのルートと `.repo` 付きのルートを両方登録し、同じコンポーネントに向けています。
- **Redmine を字義どおりに写すより、挙動の同等性を優先する。** Redmine の実装が Ruby/ActiveRecord 固有の事情によるもの（一部のバリデーションの癖など）で、そのまま再現しても得るものが少ない場合は、黙って挙動を変えるのではなく、その違いを `docs/parity-checklist.md` に記録します。
- **ルート名の URL は、元の意味より長く残ることがある。** Wiki の `wiki.index` ルートは、挙動を「ページ一覧を表示する」から「Wiki の開始ページへリダイレクトする」に変えたあとも（Redmine 自体の URL 体系に合わせるため）、名前と URL（`/projects/{project}/wiki`）をそのまま残しました。ページ一覧は新しい `wiki.pages` ルートに移しています。Volt コンポーネントは、アクションからと同じように、初回表示時の `mount()` からもリダイレクトできます。通常のコントローラーを使わずにこうした挙動の入れ替えができるのは、そのためです。
- **機能を追加する前に、チェックリストの説明だけでなく Redmine のソースで確かめる。** チェックリストで一度「未実装」とされていた項目のいくつかは、Redmine の実際のコードを読み直すと、Redmine 自体にない機能でした（子課題の並べ替え UI、ガントチャートのドラッグ&ドロップによる日程変更、ニュースやフォーラム投稿での `@mention` など）。それらを作っていたら、パリティではなく*スコープクリープ*になっていたところです。`docs/parity-checklist.md` には、こうした取り下げを元の（誤った）記述と並べて記録しており、次にその項目を読む人にも理由が伝わるようにしています。

## ライセンス

本プロジェクトは [MIT ライセンス](https://opensource.org/licenses/MIT)のもとで公開しているオープンソースソフトウェアです。
