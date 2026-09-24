# A10-01b: リモートリポジトリ URL と認証情報

状態: **実装済み(2026-09-24、案B で承認)**。実装メモは末尾。

## 背景

Redmine のリポジトリは URL(`svn://`、`http(s)://`、`file://`)とユーザー/パスワードで登録できます。
本アプリの `Repository` は **`config('scm.repositories_root')` 配下のローカルパスだけ**を受け付けます(`WithinRepositoriesRoot`)。
`manage_repository` は管理者専用ではなくメンバー権限なので、この制限がなければ、プロジェクトメンバーがアプリのプロセスから読める任意のディレクトリに git/svn を向けられます。
リモート URL を許すことは、この安全境界を意図的に外すことになります。

## 論点

1. **SSRF**: メンバーが任意ホスト/ポートへ SVN/HTTP 接続を張らせられる。社内ネットワークの探索に使える。
2. **資格情報の保管**: パスワードを保存する必要がある。`encrypted` cast は APP_KEY 漏洩で全て復号される。
3. **資格情報の渡し方**: `svn --password` はプロセス一覧に見える。`--password-from-stdin`(svn 1.10 以降)なら見えない。
4. **git のリモート**: `git ls-remote`/`clone --mirror` 相当が必要になり、現在の「ローカルの bare/working repo を読むだけ」の前提が崩れる。

## 選択肢

| 案 | 内容 | 長所 | 短所 |
|---|---|---|---|
| A | 現状維持(ローカルパスのみ)。リモートは管理者がサーバー上でミラーしてからパスで登録 | 安全境界が変わらない | Redmine のリモート SVN 運用は再現できない |
| B | SVN のみ URL+資格情報を許可。ただし管理者専用の新権限(例: `manage_remote_repositories`)と、許可ホストの許可リスト設定(`scm.allowed_hosts`)を必須にする | SSRF を許可リストで封じられる。資格情報は `encrypted` cast + `--password-from-stdin` | 実装が大きい(権限・設定・UI・アダプタ・テスト)。git は対象外 |
| C | B に加えて git のリモート(ミラークローンを `repositories_root` 配下に作成して同期) | 機能が揃う | ミラーの保管容量・同期ジョブ・認証(SSH/HTTPS)の設計が別途必要 |

## 推奨

**案 A を既定とし、必要になった時点で案 B**。
`repositories.login`/`password`(`encrypted`)/`url` 列と許可ホストの許可リスト、新権限をセットで承認・実装する。案 C は運用実績が出てから。

## 影響範囲

- 新規: `repositories.url`/`login`/`password` 列、許可ホスト設定、権限、フォーム項目、`SvnAdapter` の認証引数。
- 変更: `WithinRepositoriesRoot` の適用条件(URL の場合は許可ホスト検証に切替)、`Repository::adapter()`。
- テスト: 許可リスト外ホストの拒否、資格情報が `ps` に出ないこと(stdin 渡し)、API/画面でパスワードが返らないこと。

## 承認が必要な理由

セキュリティ境界(ローカルパス限定)を変える判断であり、実装前に運用方針(リモート SVN を本アプリで扱うか)の確認が要る。

## 実装メモ(2026-09-24)

- 列: `repositories.url`(255)/`login`(60)/`password`(text、`encrypted` cast、`#[Hidden]`)。リモートの場合 `path` は NULL。
- 許可リストは DB 設定ではなく `config('scm.allowed_hosts')`(環境変数 `SCM_ALLOWED_HOSTS`、カンマ区切り)。`repositories_root` と同じくサーバー管理者の領分とした。
- 「許可リストにあるホストのみ」に加え、解決したアドレスが非公開/ループバック/リンクローカル等なら、そのアドレス(IP か CIDR)も許可リストにある場合だけ通す。ホスト名だけの許可ではプライベートアドレスに向けられない(DNS リバインディング対策を意味のあるものにするための判断。社内サーバーは `svn.intra.example.com,10.0.5.20` のように両方書く)。検証時と svn 実行ごとに再解決する。svn 自身の名前解決との間の短い窓は残る。
- スキームは `svn`/`http`/`https` のみ(`file`・`svn+ssh` は不可)。URL 内のユーザー情報は拒否。
- 権限 `manage_remote_repositories`(リポジトリモジュール、既定ロールには付与しない)。持たないメンバーは既存リモートリポジトリの識別子/エンコーディングだけ編集でき、URL・資格情報は変わらない。URL を変更するとパスワードは引き継がない。
- svn には `--non-interactive --no-auth-cache`、ログイン時は `--username`、パスワードは `--password-from-stdin`(svn 1.14)。従来の `--trust-server-cert` は外した(資格情報を送る接続で未知 CA を受け入れないため)。
- URL がリポジトリルート配下(`.../trunk`)を指す場合、ログの変更パスから相対 URL を取り除く(Redmine の `relative_path` 相当)。
