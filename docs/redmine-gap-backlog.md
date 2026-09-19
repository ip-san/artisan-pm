# Redmine 未実装機能バックログ(実装者向け作業リスト)

**作成日**: 2026-09-19
**比較基準**: Redmine **7.0.0**(参照チェックアウト `/Users/sesoko/Desktop/workspace/redmine`、`lib/redmine/version.rb`で確認)
**本アプリの基準コミット**: `a6630e7`(ブランチ `ip-san/snook`、2026-08-19)
**親ドキュメント**: [`docs/parity-checklist.md`](parity-checklist.md)(2026-07-31 時点のスナップショット)

## このドキュメントの使い方

- 本書は `parity-checklist.md` の `missing` / `partial` 行と、`done` 行の備考に埋まっていた「対象外」「未対応」「簡略化」の断片、そして **Redmine 7.0.0 の実ソースとの機械照合**(設定キー・権限・APIルート・クエリフィルタ/列・スキーマ列・マクロ・マイページブロック・Webhookイベント)から抽出した未実装項目を、作業単位に再構成したもの。
- 各行の「本アプリの現状」には**確認したファイルパス**または**grep で 0 件だった語**を書いている。実装前にそのファイルを開いて現状を再確認すること(チェックリストには過去に「実装済みなのに missing」「未実装なのに done」の誤記が複数回あった)。
- **項目を完了したら、`parity-checklist.md` の該当行を更新すること**(本書だけを更新しない)。本書の「checklist 行」列が対応する行の見出し。
- 分類:
  - **A. 実装対象** — 未実装または部分実装で、着手可能なもの。
  - **B. 対象外確定** — 設計判断・セキュリティ判断・正式決定によりやらないと決めたもの。**再開にはユーザーの判断が必要**。勝手に実装しない。
  - **B'. 承認待ち / ブロック中** — 依存追加や設計判断が先に必要なもの。ブロッカー解消後は A に移す。
  - **C. `parity-checklist.md` の訂正** — 記載が実装と食い違っている行。
- 2026-07-30 にユーザーから「100%を目指して続けて」という包括承認が出ているため、チェックリストで「意図的な簡略化」と記録されていた小項目も、設計レベルの理由が無いものは A に載せている(行に「旧: 意図的簡略化」と付記)。
- 規模の目安: **S** = 半日以内・1〜3ファイル / **M** = 1〜3日・新規テーブルや複数画面 / **L** = 3日超・基盤変更や複数スライス。
- Redmine 側の参照は `app/models/*.rb`・`config/settings.yml`・`lib/redmine/preparation.rb`・`config/routes.rb` の相対パス。

---

## 0. 自律ループ実行プロトコル(実装エージェント向け)

本書は、実装エージェント(Sonnet 5 等)が**ユーザーの介入なしに最後まで回し切る**ことを前提に設計している。状態の唯一の正は §0.3 の実行キュー表の「状態」列。各イテレーションは前のイテレーションの記憶に依存せず、本書と `parity-checklist.md` とコードだけから再開できる。運用上は **1 回の起動で 1 行だけ処理して終了**し、外側のシェルループが再起動する(§0.4、`redmine-gap-backlog-runner.md`)。以下 §0.1〜0.2 はその 1 回分の手順と、行を跨いで守る規則。

### 0.1 1イテレーションの手順(この順番を崩さない)

1. **キューを読む**: §0.3 の実行キューを上から見て、状態が `todo` で、かつ「依存」列の ID がすべて `done` になっている最初の行を選ぶ。`wip` の行があれば(前回の中断)それを優先して再開する。**再開時は先に `git status` と `git diff` を見て**、ディスク上にどこまで未コミットの作業が残っているかを把握してから続ける。
2. **状態を `wip` にする**: 選んだ行の状態を `wip(YYYY-MM-DD)` に書き換えてから作業を始める(中断時に再開点が分かるように)。
3. **現状を再確認する**: 該当 A 行の「本アプリの現状」に書かれたファイルを開き、本当に未実装かを確認する。既に実装済みなら状態を `done(既存, YYYY-MM-DD)` にし、§C に訂正行を追加して次へ進む(実装しない)。
4. **Redmine 側を読む**: 「Redmine 側の機能」列のファイルを `/Users/sesoko/Desktop/workspace/redmine` で開き、挙動(バリデーション・権限・エッジケース)を確認する。読めない場合は本書の記載を仕様として扱い、その旨をコミットメッセージに残す。
5. **設計判断が要るか判定する**: 「前提・設計上の注意」に「設計メモ必須」「設計判断」「スキーマ判断」とある行、または規模 **L** の行は、`docs/design/` に設計メモ(1ページ、選択肢と推奨案)を書き、状態を `blocked(要承認: docs/design/xxx.md)` にして**次の行へ進む**(停止しない)。
6. **実装する**: CLAUDE.md の規約(`vendor/bin/sail` 経由、`make:` コマンド、Pint、既存パターン踏襲、依存追加禁止)に従う。関連スキル(`livewire-development`、`volt-development`、`pest-testing`、`laravel-best-practices`)を必ず有効化する。
7. **テストを書いて通す**: 新規機能は Feature テスト必須。`vendor/bin/sail artisan test --compact --filter=<対象>` で対象テストを通し、最後に影響範囲のディレクトリ単位で再実行する。**10 項目完了するごとに全スイート**(`vendor/bin/sail artisan test --compact`)を実行し、回帰があればその修正を優先する。ドキュメントのみの行(段 0、`done(既存)` の判定)では手順 7〜8 を省略する。
8. **Pint**: `vendor/bin/sail bin pint --dirty --format agent`。
9. **ドキュメントを更新する**(同じコミットで): (a) `parity-checklist.md` の該当行を `done(YYYY-MM-DD)` に更新し備考に要点を追記、(b) 本書 §0.3 の状態を `done(YYYY-MM-DD)` に、(c) 実装中に見つけた新たな未実装・誤記は本書の該当セクションに行を追加(ID は末尾に採番)。
10. **コミットする**: 1 項目 = 1 コミット。メッセージ先頭に ID を入れる(例: `A1-23: Add project default version and default assignee`)。`git push` はしない。
11. **規模超過の扱い**: 想定規模の 2 倍を超えそうなら、行を `A1-17a`/`A1-17b` のように分割して表に追加し、完了した部分だけを `done` にして次へ進む。

### 0.2 停止条件・禁止事項・エスカレーション

- **ループ終了**: §0.3 の全行が `done` または `blocked` になったとき。最後に「`blocked` 一覧とその判断点」を 1 つのまとめとして報告する。
- **ユーザーに質問して止まらない**: 判断が必要な項目は `blocked(要承認: 理由)` にして先へ進む。ループの途中で止めるのは、テストが壊れて復旧できない場合か、`git` の状態が不整合な場合のみ。
- **B 表の項目は着手しない**。B' 表の項目はブロッカー解消(ユーザーの承認・依存追加)が本書に追記されるまで着手しない。
- **`vendor/bin/sail` が使えない環境では停止する**(テストを実行できないため)。`vendor/` が無い場合は `vendor/bin/sail composer install` ではなく、ユーザーに環境の復旧を依頼して終了する。
- **既存テストを削除しない**。落ちるテストが出たら、仕様変更として妥当なら更新し、その理由をコミットメッセージに書く。
- **セキュリティ**: 可視性(`Issue::scopeVisibleTo()` 等)や認可(Policy)に触れる行では、否定ケース(権限なし・他プロジェクト・非公開)のテストを必ず追加する。
- **スコープ外の改修に手を出さない**: 目的の行に無関係なリファクタは別行として追加してから扱う。

### 0.3 実行キュー(依存順・単一の状態管理表)

状態の値: `todo` / `wip(日付)` / `done(日付)` / `done(既存, 日付)` / `blocked(理由)`。順番は「依存を満たしつつ、小さく独立したものを先に」で並べている。同じ段の中は上から順に。

| # | ID | 依存 | 規模 | 状態 |
|---|---|---|---|---|
| **段 0: チェックリスト訂正**(コード変更なし) | | | | |
| 0 | C 表の全行を `parity-checklist.md` に反映(コード変更なし、テスト/Pint 不要) | — | S | done(2026-09-20) |
| **段 1: 独立した S 項目** | | | | |
| 1 | A11-12 | — | S | done(2026-09-20) |
| 2 | A1-23 | — | S | done(2026-09-20) |
| 2a | A1-32 | A1-23 | S | todo |
| 2b | A1-33 | A1-23 | S | todo |
| A5-16b | `default_issue_start_date_to_creation_date` を REST API 課題作成(`IssuesController#build_new_issue_from_params`)と受信メール課題作成(`mail_handler.rb:216`)にも適用 | A5-16 で Web フォームのみ設定化。API/メールは開始日を補完しない | 設定オン時に両経路で `start_date ??= today` | 設定の既定がオンのため、適用すると既存 API クライアント/メールの挙動が変わる。**適用前にユーザーへ確認**(または既定オフに変更) | S | Issues本体「担当者『自分』ショートカット・既定開始/期日」 |
| A1-34 | 親課題を削除すると子孫も削除される(Redmine: `acts_as_nested_set :dependent => :destroy`、`issues_controller.rb:434` の `self_and_descendants`。工数の確認対象も子孫を含む) | 本アプリは子課題を `parent_id` NULL 化して最上位に残す(`IssueDeletionTest` 'orphans its children'、チェックリスト「課題削除」に意図的とある) | 削除時に子孫を再帰削除し、工数の合計/付替対象を子孫分まで含める。削除確認に「N 件のサブタスクも削除されます」を表示 | **データ削除の意味が変わる**ため要承認。既存テストの期待値を反転する | S〜M | Issues本体「課題削除」 |
| A1-35 | 一括削除の確認画面での工数の扱い(`todo`、複数プロジェクト選択時は付替なし) | `issues/index.blade.php` の一括削除は `IssueService::delete()` を既定(nullify)で呼ぶのみ | 一括削除にも A1-09 と同じ選択肢を追加(選択課題の工数合計を表示、単一プロジェクトのときだけ付替を許可、付替先は選択課題以外) | A1-09 完了が前提 | S | Issues本体「課題削除」 |
| A1-33 | REST API `PUT /projects/{id}` での `default_version_id` / `default_assigned_to_id` の更新(Redmine の `safe_attributes`、`project.rb:839-841`) | A1-23 で読み取り(`default_version`/`default_assignee`)のみ実装。`UpdateProjectRequest` に規則なし | 両フィールドを追加し、Web フォームと同じ選択肢(オープンな共有バージョン/割当可能メンバー)で検証 | A1-23 完了が前提 | S | REST API「Projects」 |
| 3 | A1-24 | (取り下げ)トラッカーの `is_in_chlog` | — | Redmine 7.0.0 で廃止済み(`db/migrate/20210728131544_drop_is_in_chlog_column.rb`、`app/` に使用箇所なし)。作業不要 | 機械照合が古いマイグレーションの `add_column` だけを見て、後続の `drop` を見落としていた | — | Trackers 節(C-18) |
| 4 | A3-04 | — | S | done(2026-09-20) |
| 5 | A3-10 | — | S | done(2026-09-20) |
| 6 | A4-06 | — | S | done(2026-09-20) |
| 7 | A4-15 | — | S | done(2026-09-20) |
| 8 | A5-16 | — | S | done(2026-09-20) |
| 8a | A5-16b | A5-16 | S | blocked(要承認: 既存 API/メールの挙動が変わる。設定の既定をオフにするか、適用してよいか) |
| 9 | A5-02 / A9-05 | — | S | done(2026-09-20) |
| 10 | A5-03 | — | S | done(2026-09-20) |
| 11 | A1-09 | — | S | done(2026-09-20) |
| 11a | A1-35 | A1-09 | S | todo |
| 11b | A1-34 | A1-09 | S〜M | blocked(要承認: 子課題の再帰削除に挙動変更、既存の意図的仕様を覆す) |
| 12 | A1-30 | — | S | done(2026-09-20) |
| 13 | A9-02 | — | S | done(2026-09-20) |
| 14 | A10-04 | — | S | done(2026-09-20) |
| 15 | A7-13 | — | S | done(2026-09-20) |
| 16 | A7-02 | — | S | done(2026-09-20) |
| 17 | A7-03 | — | S | done(2026-09-20) |
| 18 | A7-04 | (実装済み→是正)Wiki 個別バージョンの削除(`wiki#destroy_version`) | 履歴画面の `deleteVersion()` は既に存在した(バックログ作成時に「未実装」と誤認)。ただし権限が `edit_wiki_pages`、最新版と最後の1版は削除不可だった | `delete_wiki_pages`+`editable?` に是正、最新版の削除で前の版へ戻す、最後の1版の削除でページ削除(2026-09-20 実装済み) | — | S | Wiki「バージョン単体の削除」(C-19) |
| 19 | A1-26 | — | S | done(2026-09-20) |
| 20 | A1-21 | — | S | done(2026-09-20) |
| 21 | A3-08 | — | S | done(2026-09-20) |
| 22 | A3-07 | — | S | todo |
| 23 | A3-05 | — | S | todo |
| 24 | A1-13 | — | S | todo |
| 25 | A1-14 | — | S | todo |
| 26 | A11-13 | — | S | todo |
| 27 | A11-11 | A11-13 | S | todo |
| 28 | A11-01 | — | S | todo |
| 29 | A11-02 | — | S | todo |
| 30 | A11-03 | — | S | todo |
| 31 | A11-04 | — | S | todo |
| 32 | A11-08 | — | S | todo |
| 33 | A11-09 | — | S | todo |
| 34 | A11-14 | — | S | todo |
| 35 | A6-02 | — | S | todo |
| 36 | A6-03 | — | S | todo |
| 37 | A6-04 | — | S | todo |
| 38 | A5-11 / A6-07 | — | S | todo |
| 39 | A12-01 | — | S | todo |
| 40 | A12-04 | — | S | todo |
| 41 | A4-08 | — | S | todo |
| 42 | A4-04 | — | S | todo |
| 43 | A4-05 | — | S | todo |
| 45 | A7-09 | — | S〜M | todo |
| 46 | A11-06 | A7-09 | S | todo |
| 47 | A11-05 | — | S | todo |
| 48 | A2-01 | — | S | todo |
| 49 | A2-07 | — | S〜M | todo |
| 50 | A7-12 | — | S | todo |
| 51 | A9-06 | A2-07, A7-12 | S | todo |
| 52 | A5-09 | — | S〜M | todo |
| 53 | A8-01 | A5-09 | S | todo |
| 54 | A8-03 / A13-03 | — | S | todo |
| 55 | A5-12 / A10-02 | — | S〜M | todo |
| 56 | A10-01 | — | S〜M | todo |
| 57 | A10-05 / A5-07 | — | S | todo |
| 58 | A10-03 / A13-06 | — | S | todo |
| 59 | A7-10 | — | S | todo |
| 60 | A7-11 | — | S | todo |
| 61 | A7-05 / A7-06 / A13-04 | — | S | todo |
| 62 | A7-08 / A13-02 | — | S | todo |
| 63 | A13-01 | — | S〜M | todo |
| 64 | A5-04 / A14-06 | — | S | todo |
| 66 | A9-08 | — | S | todo |
| 67 | A5-15 | — | S | todo |
| 68 | A1-15 | — | S | todo |
| 69 | A5-01 | — | S | todo |
| 70 | A6-06 | A5-01 | S | todo |
| 71 | A4-09 | — | S〜M | todo |
| 72 | A1-31 | A4-09 | S | todo |
| A1-32 | バージョンフォームの「既定バージョンにする」チェックボックス(`versions/_form.html.erb:14`、`Version#default_project_version`)と、バージョン一覧・設定画面での既定バージョン表示 | A1-23 で `projects.default_version_id` は実装済み。`versions/form.blade.php` にチェックボックスなし | チェックで `projects.default_version_id` を更新、外すと(自分が既定なら)NULL。一覧に既定マークを表示 | A1-23 完了が前提 | S | Versions「Wikiページ紐付け・既定バージョン設定」 |
| 73 | A4-11 | — | S | todo |
| 74 | A14-04 | — | S | todo |
| 75 | A14-05 | — | S | todo |
| 76 | A2-09 | — | S | todo |
| 77 | A8-07 | — | S | todo |
| 78 | A7-07 | — | S | todo |
| 79 | A7-14 | — | S〜M | todo |
| 80 | A3-12 | — | S〜M | todo |
| 81 | A11-15 | — | S | todo |
| 81a | A1-01 | — | S | todo |
| 81b | A1-02 | — | S | todo |
| 81c | A1-03 | — | S〜M | todo |
| 81d | A4-07 | — | S | todo |
| 81e | A5-08 | — | S | todo |
| 81f | A4-10a | — | S | todo |
| **段 2: M 項目(基盤になるものを先に)** | | | | |
| 82 | A4-13 | — | M | todo |
| 83 | A4-14 | A4-13 | S | todo |
| 84 | A6-05 | A4-13 | S | todo |
| 85 | A9-07 / A14-07 | A4-13 | S | todo |
| 86 | A1-04 | A4-13 | M | todo |
| 87 | A2-02 | A4-13 | S〜M | todo |
| 88 | A1-16 | A1-15 | M | todo |
| 89 | A3-06 | — | M | todo |
| 90 | A1-17 | A1-16, A3-06 | L | todo |
| 91 | A2-08 | A1-17 | M | todo |
| 92 | A1-25 | — | M | todo |
| 93 | A1-06 | A1-25 | M | todo |
| 94 | A1-05 | A1-06 | M | todo |
| 95 | A4-16 | A1-05 | S | todo |
| 96 | A8-06 | A1-05 | S | todo |
| 97 | A1-07 | — | M | todo |
| 98 | A1-08 | A1-07 | M | todo |
| 99 | A1-10 | — | M | todo |
| 100 | A1-11 | — | M | todo |
| 101 | A1-12 | — | S〜M | todo |
| 102 | A1-18 | — | M | todo |
| 103 | A1-19 | A1-18 | M | todo |
| 104 | A1-22 | — | S | todo |
| 105 | A1-27 | — | M | todo |
| 106 | A1-28 | — | M〜L | todo |
| 107 | A1-29 | — | S〜M | todo |
| 108 | A2-03 | — | M | todo |
| 109 | A2-05 | A2-03 | S〜M | todo |
| 110 | A2-04 | — | M | todo |
| 111 | A2-06 | — | S〜M | todo |
| 112 | A3-01 | — | M | todo |
| 113 | A3-02 / A3-11 / A13-05 | A3-01 | M | todo |
| 114 | A3-03 | — | M | todo |
| 115 | A11-16 | A3-03 | S | todo |
| 116 | A3-09 | — | M | todo |
| 117 | A4-01 | — | M | todo |
| 118 | A4-02 | — | M | todo |
| 119 | A4-03 | — | M | todo |
| 120 | A4-12 / A14-02 | — | M | todo |
| 121 | A6-01 | — | M | todo |
| 122 | A7-01 | — | M | todo |
| 123 | A8-02 | — | M | todo |
| 124 | A8-04 | A8-02 | M | todo |
| 125 | A8-05 | — | S〜M | todo |
| 126 | A9-01 | — | M | todo |
| 127 | A9-03 | — | M | todo |
| 128 | A9-04 | — | M | todo |
| 129 | A11-07 | — | M | todo |
| 130 | A11-10 | — | M | todo |
| 131 | A12-02 / A13-07 | — | M | todo |
| 132 | A12-03 | — | M | todo |
| 133 | A12-05 | — | M | todo |
| 134 | A12-06 / A5-10 | — | M | todo |
| 135 | A5-06 / A14-03 | — | M | todo |
| **段 3: L 項目(設計メモ→`blocked(要承認)`→承認後に実装)** | | | | |
| 136 | A4-10b | A4-10a | L | todo |
| 137 | A1-20 | — | L | todo |
| 137a | A5-05 | A1-20 | S | todo |
| 138 | A14-01 | — | L | todo |
| 139 | B'-02 | 承認 | M | blocked(要承認: MediaLibrary 設計) |
| 140 | B'-03 | 承認 | S〜M | blocked(要承認: ScmAdapter 設計) |
| 141 | B'-01 | 承認 | M×3 | blocked(要承認: hg/cvs/bzr バイナリ追加) |

### 0.4 起動方法

実装エージェントへの指示書は [`docs/redmine-gap-backlog-runner.md`](redmine-gap-backlog-runner.md)。**1 回の起動で 1 行だけ処理して終了する**設計で、外側のシェルループ(同ファイル §9)が `GAP-LOOP: COMPLETE` か `GAP-LOOP: ABORT` を出力するまで繰り返し起動する。状態は §0.3 と git だけに置くため、途中で落ちても次の起動で再開できる。

---

## A. 実装対象

### A-1. 課題管理(Issues / Workflow / Custom Fields)

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A1-01 | 新規課題フォームでのウォッチャー指定(`watcher_user_ids`、`app/views/issues/_form.html.erb`) | `resources/views/livewire/issues/form.blade.php` に watcher の記述なし(grep 0件) | 新規作成フォームにメンバーのチェックボックス群を追加し、`IssueService::create()` 後に `Watcher` を一括作成。`add_issue_watchers` 権限でゲート | 課題詳細のウォッチャー追加ロジック(`issues/show.blade.php`)を再利用 | S | Watchers「他ユーザーをWatcherとして追加/削除」 |
| A1-02 | ウォッチャー追加のオートコンプリート(`watchers#autocomplete_for_user`) | 単純な `<select>`、プロジェクトメンバー限定 | 名前/メール部分一致の検索入力に置換。`projects/members.blade.php` の `userCandidates()` パターンを流用 | `Role.users_visibility` の制約(`AuthorizationService::hasSiteWideUserVisibility()`)を通す | S | 同上 |
| A1-03 | 親課題・関連課題のオートコンプリート(`/issues/auto_complete`) | ID の数値入力のみ(`issues/form.blade.php`、`issues/show.blade.php` に debounce/autocomplete なし) | `#ID` または件名部分一致で候補を返す Livewire メソッド+候補リスト UI。`cross_project_issue_relations` 設定に従いスコープを切替 | 閲覧不可課題を候補に出さない(`Issue::scopeVisibleTo()`) | S〜M | Issue Relations「データモデル」 |
| A1-04 | 履歴タブ(履歴 / コメント / プロパティ変更 / 作業時間 / チェンジセット、`history_default_tab` ユーザー設定) | `issues/show.blade.php` は全 Journal を1本のリストで表示。タブ切替なし | タブ UI と Journal 種別フィルタ(notes あり/属性のみ/TimeEntry/Changeset)。既定タブはユーザー設定(A4-13) | Changeset/TimeEntry を Journal と時系列でマージする必要あり | M | Journal 節 |
| A1-05 | 課題一覧の右クリックコンテキストメニュー(`context_menus/issues`) | なし(grep 0件) | 複数選択→右クリックで ステータス/優先度/担当者/対象バージョン/一括編集/コピー/移動/削除 のメニュー。既存の一括編集アクションを呼ぶだけで良い | Alpine.js で実装可(Livewire 4) | M | 一括編集 節 |
| A1-06 | 一括編集の対象属性(トラッカー/カテゴリ/開始日・期日/カスタムフィールド/親課題/非公開/一括コメント) | 選択課題が単一ステータスのときのみステータス変更可。他属性はさらに少ない(`issues/index.blade.php` の bulk 系メソッド) | Redmine の `IssuesController#bulk_update` 相当に拡張。複数ステータス混在時は「共通の遷移先」のみ提示 | ワークフロー遷移の交差判定は `WorkflowService` を再利用 | M | 「ステータス一括編集の選択制約」partial(旧: 意図的) |
| A1-07 | 一括コピー時のサブタスク複製(`Issue#copy` の `subtasks` オプション) | 一括コピーは親のみ、子孫は複製しない | 子孫を深さ優先で再帰コピーし親 ID をリマップ。対象バージョン/担当者の妥当性を移動先で再検証 | 隣接リスト(`parent_id`)で走査 | M | 「一括コピー・一括プロジェクト間移動・一括削除」 |
| A1-08 | 課題コピー時の関連/添付/サブタスク/ウォッチャー引き継ぎと `link_copied_issue`・`copy_attachments_on_issue_copy` 設定 | `IssueService::copy()` は `$copyAttachments` 引数あり。`?copy_from=` プリフィルは何も複製しない。設定キー2つは未定義(grep 0件) | コピーフォームに「添付をコピー」「サブタスクをコピー」「ウォッチャーをコピー」チェックボックス、`link_copied_issue`(yes/no/ask)で `copied_to` 関連作成を制御 | A1-07 と共通ロジック | M | Issues本体「課題のコピー」 |
| A1-09 | 課題削除時の工数の扱い選択(`params[:todo]`: 破棄/再割当/そのまま) | 常に `nullOnDelete` で保持のみ | 削除確認ダイアログに3択を追加し `IssueService::delete()` に渡す | 旧: 意図的簡略化 | S | Issues本体「課題削除」 |
| A1-10 | カスタムフィールド形式 `user` / `version`(`lib/redmine/field_format.rb` の `RecordList`) | `app/CustomFields/Formats/` に 10 形式(string/text/int/float/date/bool/list/enumeration/link/progressbar)。`app/Enums/CustomFieldFormat.php` も同じ | `FormatContract::options(CustomField $field)` に対象オブジェクト(Issue→Project)を渡せるようシグネチャ拡張し、メンバー/バージョン一覧を選択肢に。`user` は `user_role` 絞り込み、`version` は `version_status` 絞り込みオプション | **設計変更**: 全 Format 実装と呼び出し元(課題フォーム・一覧・フィルタ・CSV・API)への影響を先に洗う | M | カスタムフィールド(課題)「フィールド形式のカバレッジ」 |
| A1-11 | カスタムフィールドでの一覧並べ替え | `CustomFieldFilter::isSortable()` が `false`、列見出しクリックは no-op | `custom_field_values` への LEFT JOIN で ORDER BY。形式別の値列(`value_string`/`value_int`/…)を選ぶ | 複数値 CF は対象外のまま(Redmine も同様) | M | 「表示列・CSV列としてのカスタムフィールド」 |
| A1-12 | 複数値カスタムフィールド(`multiple: true`)でのグルーピング | グルーピング対象外(選択欄にも出ない) | Redmine 同様、値ごとに行を重複表示するか、集計は `COUNT(DISTINCT issues.id)` にする | パフォーマンス上の理由で見送られていた | S〜M | クエリ「グルーピング」 |
| A1-13 | Progressbar 形式の `ratio_interval`(`issue_done_ratio_interval` 設定) | `ProgressbarFormat` はフリー整数入力。設定キーなし | 設定「課題トラッキング」に刻み幅(1/5/10)、進捗率入力を `<select>` 化。CF 側の `ratio_interval` 属性も追加 | 旧: 意図的対象外 | S | 同上 |
| A1-14 | 楽観的ロックを一括編集・REST API・リポジトリ連動に適用 | `IssueService::update()` は `$expectedLockVersion` 省略時に常に許可 | API `PUT /issues/{id}` で `lock_version` を受け取り 409 を返す。一括編集はフォーム読込時の値を保持 | 旧: 意図的 | S | Issues本体「楽観的ロック」 |
| A1-15 | `estimated_remaining_hours`(Redmine 7.0 新規、`app/models/issue.rb:1210`)と `total_spent_hours`/`spent_hours` 列 | 列・計算なし(grep 0件)。`Issue::totalEstimatedHours()` はあり | 計算プロパティ(`estimated_hours * (100 - done_ratio) / 100`、親は子の合計)を追加し、詳細・一覧列・CSV・API に露出 | 列としての露出は A2-01 と同時に | S | Issues本体 |
| A1-16 | 課題一覧の選択可能列: `id`/`project`/`parent`/`updated_on`/`estimated_hours`/`estimated_remaining_hours`/`closed_on`/`last_updated_by`/`description`/`last_notes`/`spent_hours`/`total_spent_hours`/`is_private`(`issue_query.rb` の `available_columns`) | `issues/index.blade.php` の `DISPLAY_COLUMNS` は tracker/status/priority/subject/category/assigned_to/author/fixed_version/start_date/due_date/created_at/done_ratio/relations/attachments/watchers | 欠落列を `DISPLAY_COLUMNS` と `QueryFilterEngine` の並べ替え対象に追加。`description`/`last_notes` はブロック列(Redmine の `inline: false`) | `last_updated_by` は Journal の最新 `user_id` | M | クエリ「列選択」 |
| A1-17 | 課題フィルタ: `description`/`notes`/`updated_on`/`closed_on`/`estimated_hours`/`spent_time`/`parent_id`/`child_id`/`issue_id`/`is_private`/`attachment`/`attachment_description`/`watcher_id`/`updated_by`/`last_updated_by`/`member_of_group`/`assigned_to_role`/`author.group`/`author.role`/`fixed_version.due_date`/`fixed_version.status`/`subproject_id`/`project.status`/関連タイプ別(`relates` 等)/`any_searchable`(`issue_query.rb:153-262`) | `app/Support/Query/IssueFilterFieldRegistry.php` は assigned_to_id/author_id/category_id/created_at/done_ratio/due_date/fixed_version_id/priority_id/project_id/start_date/status_id/subject/tracker_id の 13 種 | `NativeColumnFilter` に加え、サブクエリ型フィルタ(関連・ウォッチャー・グループ/ロール・添付)を `FilterableField` 実装として追加。保存済みクエリ・Atom・CSV・マイページブロックは `QueryFilterEngine` 経由なので自動追従 | `subproject_id` は A3-06(`display_subprojects_issues`)が前提 | L | クエリ/フィルタ 節 |
| A1-18 | 稼働日ベースの日付計算(`non_working_week_days` 設定、`Redmine::Utils::DateCalculation`) | 暦日計算のみ(`IssueService::rescheduleSuccessors()`、grep「稼働日」0件) | 設定「課題トラッキング」に非稼働曜日チェックボックス、`working_days`/`add_working_days` ヘルパーを導入しリスケジュール・遅延計算(`IssueRelation.delay`)・ガントに適用 | 既存のリスケジュールテストを暦日→稼働日で更新 | M | Issue Relations「関連日付からの自動リスケジュール」 |
| A1-19 | リスケジュールの親子階層への伝播(`Issue#reschedule_on!` の leaves/ancestors) | `precedes`/`follows` チェーンのみ。子・親には伝播しない | 後続課題の子孫にも同じシフトを適用し、親の日付は `parent_issue_dates` 設定に従って再集計 | 循環ガード(最大50ホップ)を維持 | M | 同上 |
| A1-20 | グループへの課題割当(`issue_group_assignment` 設定、`Principal` 担当) | `issues.assigned_to_id` は users FK のみ、設定なし(grep 0件) | `assigned_to` を polymorphic 化するか `assigned_to_group_id` 列を追加。担当者候補にグループを含め、通知はグループ展開 | **スキーマ判断**: Redmine は `principals` 単一テーブル継承。本アプリは users/groups 分離のため設計メモが必要 | L | Issues本体 |
| A1-21 | 関連課題テーブルの列選択(`related_issues_default_columns`、`display_related_issues_table_headers`) | 課題詳細の関連課題は固定表示 | 設定に列選択を追加し、`issues/show.blade.php` の関連課題ブロックを列設定に従って描画 | — | S | 設定「課題トラッキング」 |
| A1-22 | Journal 編集者・編集日時の記録(`journals.updated_by_id`/`updated_on`)、非公開フラグの編集 | `journals` テーブルに `updated_by` なし(migration grep 0件)。編集フォーム・API は本文のみ | 列追加+編集時に記録し「(編集済み by X)」表示。編集フォームと `PUT /journals/{id}` で `private_notes` 切替を許可(`set_notes_private` 権限) | — | S | Journal「個別 Journal の編集」、REST API「Journals」 |
| A1-23 | プロジェクトの既定バージョン・既定担当者(`projects.default_version_id`/`default_assigned_to_id`、`project.rb:43-44`) | `projects` テーブルに列なし | 列追加+プロジェクト設定フォームに選択欄、新規課題フォームで対象バージョン/担当者の初期値に使用(カテゴリの既定担当者より優先度は低い) | チェックリストで「既定バージョン設定に該当する Redmine 機能未特定」とされていた項目の正体 | S | Versions「Wikiページ紐付け・既定バージョン設定」 |
| A1-24 | トラッカーの `is_in_chlog`(変更履歴に表示) | `trackers` に列なし | 列+フォームのチェックボックス。バージョン詳細の課題一覧で絞り込みに使用 | 優先度低 | S | Trackers 節 |
| A1-25 | 新規課題時のワークフロー遷移(`old_status_id IS NULL` の行) | `WorkflowService.php:51` は `old_status_id = 現在ステータス` のみ参照。`IssueService::create()` はワークフローを見ずトラッカー既定ステータスを採用 | 新規課題フォームのステータス選択肢を「`old_status_id IS NULL` かつ該当ロール」の遷移先に制限。管理画面(`workflows/edit.blade.php`)に「新規課題」行を追加 | チェックリスト §0 項目 10 で既知 | M | Issue Statuses / Workflow 節 |
| A1-26 | ワークフローコピーの省略記法(トラッカーまたはロールを「全て」指定) | コピー元・コピー先とも明示選択必須 | コピーフォームに「全トラッカー」「全ロール」選択肢を追加し二重ループでコピー | — | S | 「ワークフローのコピー」 |
| A1-27 | ロール×トラッカー単位の課題権限(`roles.settings` の `permissions_all_trackers`/`permissions_tracker_ids`) | `roles` テーブルに `settings` なし。`roles/form.blade.php` に tracker の記述なし | `settings` JSON 列を追加し、`add_issues`/`view_issues`/`edit_issues`/`delete_issues` ごとに「全トラッカー or 選択トラッカー」を設定。`IssuePolicy` と `Issue::scopeVisibleTo()` にトラッカー条件を追加 | 可視性スコープの変更は Atom/API/マイページに波及 | M | ロール・権限 節 |
| A1-28 | CSV インポート: カスタムフィールド列、遅延付き関連、`unique_id` による親子/関連の遅延解決 | `ImportIssuesJob::mapRowToAttributes()` は CF・関連未対応 | CF は `Issue::relevantCustomFields()` の認可コンテキスト(`auth()->user()`)をジョブ内で実行ユーザーに差し替える設計が必要。`unique_id` は2パス(先に全行作成→後で親/関連を解決) | **設計メモ必須**(認可コンテキストの扱い) | M〜L | 「マッピング可能な列」「カテゴリ/バージョンの自動作成…」 |
| A1-29 | 課題一覧の PDF エクスポート(`issues/index` の `format=pdf`)と `issues_export_limit` 設定 | PDF は課題単体(`routes/web.php:96` `issues.pdf`)・Wiki・ガントのみ | 現在のフィルタ/列を反映した一覧 PDF を dompdf で生成。CSV/PDF とも `issues_export_limit` で件数を打ち切り | `resources/views/pdf/issue.blade.php` のスタイルを流用 | S〜M | 「PDFエクスポート・Atomフィード」 |
| A1-30 | Atom フィードへの現在のフィルタ/ソート反映 | `IssueAtomController` は「未クローズ・最近更新」固定 | 一覧画面の Atom リンクに現在のクエリ文字列を付与し、`QueryFilterEngine` で同条件を適用 | 旧: 意図的簡略化 | S | Issues本体「Atom フィード」 |
| A1-31 | 課題の Journal 全体 Atom(`/issues/changes`) | なし | 全プロジェクト/プロジェクト単位の「最近の変更」フィード | A9-06(Atom key)が前提 | S | — (checklist 未掲載) |

### A-2. クエリ / 一覧 / レポート

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A2-01 | 列の並び順変更(保存済みクエリの `column_names` 順) | チェックボックスで選択のみ、順序は固定 | 選択列の上下移動 UI(または `wire:sort`)を課題一覧・工数一覧に追加し、`column_names` の順序で描画 | — | S | クエリ「列選択」 |
| A2-02 | 既定クエリ(グローバル `default_issue_query`、プロジェクト `projects.default_issue_query_id`、ユーザー設定 `default_issue_query`) | 3つとも未実装(settings/projects/users にキー・列なし) | 課題一覧を初期表示するときに ユーザー設定 → プロジェクト設定 → グローバル設定 の順で保存済みクエリを適用 | ユーザー設定は A4-13 の基盤上に | S〜M | 設定「課題トラッキング」 |
| A2-03 | プロジェクト一覧クエリ(`ProjectQuery`: フィルタ・列・保存、`project_list_defaults`、`project_list_display_type` = board/list、`default_project_query`) | `projects/index.blade.php` は名前検索+ステータスフィルタ+ブックマークのみ | `QueryType` に `Project` を追加し `ProjectFilterFieldRegistry` を新設。ボード(カード)表示切替と設定キー3つ | `Query` モデルは `type` 列で既に多型 | M | Projects「プロジェクト一覧」 |
| A2-04 | 管理者向けユーザー一覧クエリ(`UserQuery`: ステータス/グループ/ロール/認証方式フィルタ、列選択、CSV) | `users/index.blade.php` に検索・フィルタなし(grep 0件) | フィルタ+列選択+CSV。`QueryFilterEngine` を再利用 | 管理者専用 | M | ユーザー管理・認証 節 |
| A2-05 | 管理画面のプロジェクト一覧クエリ(`ProjectAdminQuery`、Redmine 6.0〜) | 管理者専用のプロジェクト一覧画面なし(一般の `projects.index` を兼用) | `/admin/projects` 相当: 全ステータス横断・フィルタ・一括アーカイブ/削除 | A2-03 の基盤上に | S〜M | — (checklist 未掲載) |
| A2-06 | 課題レポートのドリルダウン(`reports#issue_report_details`)・サブプロジェクト集計・CSV | 1画面のグリッドのみ | 各軸(トラッカー/優先度/担当者/作成者/バージョン/カテゴリ/サブプロジェクト)の詳細ページと CSV | 旧: 意図的簡略化 | S〜M | 「課題レポート」 |
| A2-07 | ページサイズ選択(`per_page_options`)と検索結果ページネーション(`search_results_per_page`) | どの一覧にもページサイズ `<select>` なし。検索結果はページネーション自体なし | 共通コンポーネント `<x-per-page-select>` を作り課題/工数/プロジェクト/News/文書一覧に配置。検索結果に `LengthAwarePaginator` | — | S〜M | 設定「全般」 |
| A2-08 | 工数フィルタ: `subproject_id`/`issue.parent_id`/`issue.status_id`/`issue.fixed_version_id`/`issue.category_id`/`issue.subject`/`user.group`/`user.role`/`author_id`/`project.status`、列: `project`/`created_on`/`tweek`/`author`/CF | `TimeEntryFilterFieldRegistry.php` は user_id/activity_id/spent_on/hours/project_id。列は spent_on/user/activity/issue/comments/hours | 課題側の JOIN フィルタと列を工数側に移植 | A1-17 と同じ `FilterableField` 実装を共有 | M | クエリ「列選択」 |
| A2-09 | 課題一覧の合計行の設定化(`issue_list_default_totals`)、工数一覧既定(`time_entry_list_defaults`) | 合計は予定/実績を固定表示、設定キーなし | 合計対象列(予定/実績/残工数/数値 CF)を設定で選択 | — | S | クエリ「合計/集計」 |

### A-3. プロジェクト / メンバー / ロール

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A3-01 | 一般ユーザーによるトップレベルプロジェクト作成(`add_project` グローバル権限、`Role#permissions_all_trackers` と同様の非プロジェクト権限) | `ProjectPolicy::create` は管理者のみ `true` | グローバル権限(プロジェクト非依存)を `PermissionServiceProvider` に導入し、非メンバーロールにも付与可能に。作成者を自動で `new_project_user_role_id` のメンバーにする | 現状 `new_project_user_role_id` 設定は管理者作成時のみ意味を持つ | M | Projects「プロジェクト作成」 |
| A3-02 | 権限 `select_project_publicity`(公開/非公開の切替を `edit_project` から分離、Redmine 5.1〜) | `edit_project` に包含 | 権限追加+プロジェクト編集フォームの `is_public` を条件表示 | A13 も参照 | S | ロール・権限 節 |
| A3-03 | 子プロジェクトのメンバー継承(`projects.inherit_members`、`Member.inherited_from`) | 列・概念なし(grep 0件) | 列追加、親メンバー変更時に子へ伝播するオブザーバ、継承メンバーは子側で削除不可(`Member#deletable?`) | REST Memberships の `inherited_from` 露出も同時に | M | Projects「サブプロジェクト」、REST API「Memberships」 |
| A3-04 | プロジェクトの `homepage` 列 | `projects` に列なし | 列+フォーム+概要画面リンク+API | — | S | — (checklist 未掲載) |
| A3-05 | クローズ中プロジェクトの編集ブロック | クローズ中でも設定変更・再オープン可能 | Redmine の `Project#allows_to?`(クローズ時は読み取り権限と `close_project` のみ)を `ProjectPolicy` に反映 | 旧: 実装上の判断 | S | Projects「クローズ/再オープン」 |
| A3-06 | サブプロジェクトの課題を親の一覧に含める(`display_subprojects_issues` 設定、`subproject_id` フィルタ) | 設定なし(grep 0件)。一覧・工数合計はプロジェクト自身のみ | 設定追加、課題一覧/ガント/カレンダー/工数合計で子孫プロジェクトを既定で含める。フィルタ `subproject_id` | A1-17 と連動 | M | 工数管理「プロジェクトの実績工数合計」 |
| A3-07 | プロジェクト一覧: フィルタ使用時のツリー維持、可視祖先基準のインデント | フィルタ時はフラット表示、深さは絶対深度 | 可視プロジェクトのみでツリーを再構築(`kalnoy/nestedset` の `toTree()`) | 旧: 意図的簡略化 | S | Projects「プロジェクト一覧」 |
| A3-08 | グループメンバーのロール編集 | 削除→再追加のみ | メンバー編集フォームでグループ行も編集可能に | — | S | Projects「メンバー管理」 |
| A3-09 | メンバー単位のメール通知選択(`members.mail_notification`、ユーザーの `mail_notification = selected`) | `members` に列なし。`selected` は `only_my_events` に縮退 | 列追加、プロフィールの通知設定に「選択したプロジェクトのみ」+プロジェクト一覧チェックボックス、`NotificationRecipients` で判定 | — | M | Journal「メール通知(課題)」 |
| A3-10 | ロールの既定作業分類(`roles.default_time_entry_activity_id`) | 列なし(grep 0件) | 列+ロールフォーム+工数入力フォームの初期値 | — | S | 工数管理 節 |
| A3-11 | プロジェクト単位の作業分類オーバーライドの権限 `manage_project_activities` | 作業分類のプロジェクトスコープ自体は実装済み(`enumerations` migration)。専用権限なし | 権限を追加しプロジェクト設定「作業分類」タブをゲート | A13 参照 | S | Enumerations 節 |
| A3-12 | 管理画面の「ユーザー → プロジェクトメンバーシップ」タブ(`principal_memberships`) | `users/form.blade.php` にメンバーシップ管理なし(grep 0件)。グループ側も同様 | ユーザー/グループ編集画面に所属プロジェクトとロールの追加/変更/削除タブ | `MemberPolicy` を再利用 | S〜M | ユーザー管理・認証 節 |

### A-4. ユーザー / 認証 / 個人設定

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A4-01 | ユーザー CSV インポート(`UserImport`、`/users/imports`) | Issue/TimeEntry インポートのみ(`IssueImport`/`TimeEntryImport` モデル) | `UserImport` モデル+ジョブ+画面。login/name/email/password/auth_source/status/is_admin/language/custom fields のマッピング | 既存 `ImportIssuesJob` のパターンを踏襲 | M | 一括編集・インポート 節(未掲載) |
| A4-02 | 追加メールアドレス(`email_addresses`、`max_additional_emails` 設定) | `users.email` 単一 | `email_addresses` テーブル、プロフィールで追加/削除、通知宛先に含める、受信メールの送信者照合に含める | — | M | 設定「ユーザー」 |
| A4-03 | パスワード有効期限・次回ログイン時の強制変更(`password_max_age`、`users.passwd_changed_on`/`must_change_passwd`) | 列・設定なし | 列追加、ログイン後ミドルウェアでパスワード変更画面へ強制リダイレクト、管理者のユーザー編集に「次回ログイン時に変更を要求」 | 旧: 意図的対象外(単なる設定値追加では済まないため) | M | 設定「認証」 |
| A4-04 | パスワード文字種要件(`password_required_char_classes`: 大文字/小文字/数字/記号を個別選択) | `Password::min()` のみ(`AppServiceProvider.php:47`) | 4分類のチェックボックス設定+カスタムバリデーションルール | 旧: 意図的対象外(Laravel 標準ルールと粒度不一致) | S | 同上 |
| A4-05 | セッション絶対有効期限(`session_lifetime`) | アイドルタイムアウト(`EnforceSessionTimeout`)のみ | ログイン時刻をセッションに保存し、経過時間で強制ログアウト | 旧: 意図的対象外 | S | 同上 |
| A4-06 | `lost_password` 設定(パスワード再設定機能の有効/無効) | Fortify `resetPasswords()` は常時有効(`config/fortify.php:167`)。設定キーなし | 設定チェックボックス+ログイン画面リンクとルートを条件化 | — | S | 同上 |
| A4-07 | 登録時のカスタムフィールド入力(`show_custom_fields_on_registration`) | 自己登録フォームに CF なし | User CF(`CustomizableType::User`)を登録フォームに表示 | — | S | 設定「ユーザー」 |
| A4-08 | ログインの大文字小文字を区別しない一意性、メールドメイン許可/拒否を管理者フォームにも適用 | どちらも自己登録・DB 制約のまま | `login`/`email` のユニーク判定を `LOWER()` に、`users/form.blade.php` にもドメイン検証を適用 | 旧: 意図的対象外 | S | 「ログイン欄の必須化」「自己登録メールドメイン許可/拒否リスト」 |
| A4-09 | Atom キーによる非公開フィード(`users.atom_key`(`rss_key`)、`my/atom_key` リセット) | フィードは `auth` 越しのみ、`atom_key` なし(grep 0件) | 列追加、`?key=` クエリで認証するミドルウェア、プロフィールでキー表示/リセット、各 Atom リンクにキー付与 | フィードリーダーから読めるようにする唯一の手段 | S〜M | Issues本体「Atom フィード」 |
| A4-10a | 表示名形式(`user_format`)の選択肢のうち `name` 単一列で表現できるもの | `users.name` 単一列、設定なし(grep 0件) | 「名前」「名前 (login)」「login」の3形式を設定で選び、`User::displayName()` ヘルパーに集約して全画面で使用 | — | S | 設定「表示」 |
| A4-10b | 姓名分離(`firstname`/`lastname`)と `user_format` の全形式 | `users.name` 単一列 | 列分割マイグレーション(既存 `name` を空白で分割)、フォーム/API/インポート/LDAP 属性マッピングの全経路を更新 | **設計判断**(スキーマ変更・全画面に波及)。設計メモ→承認後に着手 | L | 同上 |
| A4-11 | アバター(`gravatar_enabled`/`gravatar_default`)、Redmine 6.0 の添付アバター | なし(grep 0件) | Gravatar URL 生成ヘルパー+設定、課題詳細/Journal/メンバー一覧に表示 | — | S | 設定「表示」 |
| A4-12 | ユーザーのタイムゾーン(`default_users_time_zone`、`users.time_zone`)と日付/時刻形式(`date_format`/`time_format`/`timespan_format`) | `config/app.php` の単一タイムゾーン。ユーザー列なし | ユーザー列+プロフィール選択、表示時に `Carbon::setTimezone()`。日付形式は設定で選択し Blade ヘルパーで統一 | A14-01(i18n)と同時に扱うのが効率的 | M | 設定「表示」 |
| A4-13 | ユーザー個人設定(`UserPreference`): `comments_sorting`、`warn_on_leaving_unsaved`、`notify_about_high_priority_issues`、`textarea_font`、`recently_used_projects`、`history_default_tab`、`default_issue_query`/`default_project_query`、`auto_watch_on`(+設定 `default_users_auto_watch_on`)、`hide_mail`(+`default_users_hide_mail`) | `users` に `mail_notification`/`no_self_notified`/`language` のみ。設定基盤なし | `user_preferences` テーブル(または JSON 列)とプロフィール画面のセクション。各設定を消費する箇所(Journal 並び順、履歴既定タブ、自動ウォッチ、公開プロフィールのメール非表示)を配線 | A1-04、A2-02、A9-08 が依存 | M | 設定「ユーザー」 |
| A4-14 | 自動ウォッチ(`auto_watch_on`: 作成した課題/コメントした課題を自動でウォッチ) | 作成者/担当者は通知対象だがウォッチャーにはならない | A4-13 の設定を見て `IssueService::create()`/コメント追加時に `Watcher` を作成 | — | S | Watchers「作成者/担当者の自動Watch」 |
| A4-15 | グループ単位の 2FA 必須(`groups.twofa_required`) | `Group` に列なし。`User::mustActivateTwoFactor()` は値 1 を 0 と同等に扱う | 列+グループフォーム、`mustActivateTwoFactor()` に所属グループ判定を追加 | — | S | 「2FA必須設定」 |
| A4-16 | ユーザー一覧のコンテキストメニュー(`context_menus/users`) | なし | 一括ロック/解除/削除/グループ追加 | A1-05 の共通部品を流用 | S | — (checklist 未掲載) |

### A-5. アプリケーション設定(Redmine `config/settings.yml` 120 キー中、本アプリに無い 79 キー)

機械照合の結果(付録 1)。他セクションで扱うものは ID を記載、それ以外はここで扱う。

| ID | 設定キー群 | 本アプリの現状 | 残作業 | 規模 | 関連 ID |
|---|---|---|---|---|---|
| A5-01 | `host_name` / `protocol` | `config/app.url` に依存 | メール内リンク・Atom の絶対 URL 生成を設定値から組み立てる。設定画面「全般」に追加 | S | A6 |
| A5-02 | `feeds_limit` | `ActivityFeedController` 等で定数 | 設定化(既定 15) | S | A9-05 |
| A5-03 | `cache_formatted_text` | Markdown を毎回レンダリング | `WikiMarkdownRenderer::render()` の出力を `Cache::remember`(キー: 本文ハッシュ+プロジェクト) | S | — |
| A5-04 | `new_item_menu_tab`(ヘッダーの「+」メニュー: 非表示/新規課題のみ/全て) | 「+」メニュー自体なし | ヘッダーに新規作成ドロップダウン(課題/News/文書/Wiki/ファイル/バージョン/工数)と設定 | S | — |
| A5-05 | `assignee_dropdown_display_format`(担当者ドロップダウンにグループ名等を表示) | 該当なし | 担当者選択の表示形式設定 | S | A1-20 |
| A5-06 | `ui_theme` | テーマ切替基盤なし | Tailwind のダーク/ライトまたは複数カラーテーマを CSS 変数で切替、設定+ユーザー設定 | M | A14-03 |
| A5-07 | `attachment` 系: `bulk_download_max_size`、`file_max_size_displayed`、`diff_max_lines_displayed`、`thumbnails_size`(`thumbnails_enabled` は設定キーとして未定義) | `attachment_max_size`/`attachment_extensions_*` は `AttachmentValidationRules.php` で実装済み。残りなし | 一括 ZIP ダウンロード上限、リポジトリ/Wiki のファイル表示上限、Diff 行数上限、サムネイル寸法 | S | A7-10, A10-06 |
| A5-08 | `wiki_compression`、`wiki_tablesort_enabled` | Wiki 本文は非圧縮保存、テーブルソートなし | 圧縮は優先度低(スキップ可)。テーブルソートはクライアント JS で `<table>` にソート可能属性を付与 | S | A7 |
| A5-09 | `timelog_*`: `timelog_required_fields`、`timelog_max_hours_per_day`、`timelog_accept_0_hours`、`timelog_accept_closed_issues`、`timelog_accept_future_dates` | いずれもなし(grep 0件) | `TimeEntry` のバリデーションに5設定を反映(課題/コメント必須、1日上限、0時間拒否、クローズ課題拒否、未来日拒否)。Web UI/API/インポート/コミットキーワードの全経路 | S〜M | A8-01 |
| A5-10 | `mail_handler_*`: `mail_handler_api_enabled`/`mail_handler_api_key`(rdm-mailhandler.rb からの HTTP 受信)、`mail_handler_enable_regex_delimiters`、`mail_handler_enable_regex_excluded_filenames` | 受信は IMAP/POP ポーリングのみ、区切り/除外は完全一致・ワイルドカードのみ | API キー認証の `POST /mail_handler` エンドポイント、正規表現モードのトグル | S〜M | A12-06 |
| A5-11 | `emails_header`、`show_status_changes_in_mail_subject`、`default_users_hide_mail` | `emails_footer` のみ | ヘッダー文追加、件名の `(ステータス)` を設定で省略可能に、メール非表示の既定値 | S | A6 |
| A5-12 | `commit_ref_keywords`、`commit_update_keywords`(`done_ratio`/`if_tracker_id` 付き)、`commit_cross_project_ref`、`commit_logs_encoding`、`commit_logs_formatting`、`repositories_encodings`、`repository_log_display_limit`、`enabled_scm`(名称違い) | `commit_fixing_keyword_rules` は `{keywords, status_id}` のみ(`RepositorySyncService.php:23`)。refs キーワードはハードコード。他はなし | ルールに `done_ratio`/`tracker_id` を追加、参照キーワードを設定化、他プロジェクト参照の許可設定、ログのエンコーディング指定、履歴表示件数 | S〜M | A10 |
| A5-13 | `sys_api_enabled` / `sys_api_key` | `/sys` WS なし(grep 0件) | A10-03 参照 | — | A10-03 |
| A5-14 | `default_language`、`force_default_language_for_anonymous`/`_for_loggedin`、`text_formatting`、`user_format`、`date_format`、`time_format`、`timespan_format`、`default_users_time_zone` | 基盤なし | A14-01、A4-10a/b、A4-12 参照。`text_formatting` は B-06 | — | A14, A4 |
| A5-15 | `gantt_items_limit`、`gantt_months_limit` | ガントは全件描画 | 描画件数/月数の上限設定 | S | A9-01 |
| A5-16 | `default_issue_start_date_to_creation_date` | 開始日=作成日をハードコードで既定にしている | 設定化(既定 true) | S | Issues本体「担当者『自分』ショートカット・既定開始/期日」 |
| A5-17 | `cross_project_subtasks`、`copy_attachments_on_issue_copy`、`link_copied_issue`、`issue_group_assignment`、`issue_done_ratio_interval`、`issue_list_default_totals`、`related_issues_default_columns`、`display_related_issues_table_headers`、`display_subprojects_issues`、`issues_export_limit`、`default_issue_query`、`default_project_query`、`project_list_defaults`、`project_list_display_type`、`per_page_options`、`search_results_per_page`、`password_max_age`、`password_required_char_classes`、`session_lifetime`、`lost_password`、`max_additional_emails`、`show_custom_fields_on_registration`、`default_users_auto_watch_on`、`gravatar_*`、`non_working_week_days`、`time_entry_list_defaults`、`webhooks_enabled`、`jsonp_enabled` | なし | 対応する機能 ID を参照(設定タブの行は対応機能と同じコミットで追加する、というチェックリストの運用原則 1 を守る) | — | 各 ID |

> `default_projects_modules` と `issue_list_default_columns` は `Setting::get()` 直呼びではなく `settings/index.blade.php` のプロパティ経由で実装済みのため、機械照合上「無い」と出るが実装済み。

### A-6. 通知・メール

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A6-01 | 通知イベント `issue_note_added`(コメントのみ別トグル)、`message_posted`(フォーラム)、`document_added`、`file_added` | `settings/index.blade.php:63-68` は issue_added/issue_updated/wiki_content_added/wiki_content_updated/news_added/news_comment_added の 6 種 | フォーラム投稿・文書追加・ファイル追加のメール(`App\Mail\*`+リスナー)を追加し `notified_events` に配線。`issue_note_added` はコメント有無で分岐 | `NotificationRecipients` に `forMessage`/`forDocument`/`forAttachment` を追加 | M | Journal「メール通知(課題)」 |
| A6-02 | @mention の News コメント・フォーラム投稿への拡張 | `NotificationRecipients::forIssue`/`forWikiPage` のみ(`app/Support/Mail/NotificationRecipients.php`) | `NewsCommentCreated`/`MessageCreated` イベントに `mentionedLogins` を追加し同じ抽出ロジックを適用 | Issue/Wiki 側の実装をそのまま横展開 | S | Watchers「作成者/担当者の自動Watch・@mention」 |
| A6-03 | 通知宛先のグループ展開(グループメンバーとして参加しているユーザーへの配信) | `Project::assignableUsers()` と同じ「直接メンバーのみ」 | メンバー解決時に `groups_users` を展開 | A1-20 とも関連 | S | Journal「メール通知(課題)」 |
| A6-04 | メール本文への添付/関連の差分表示 | Journal の `property=attr`/`cf` のみ描画 | `attachment`/`relation` の JournalDetail もメールテンプレートに描画 | — | S | 同上 |
| A6-05 | 高優先度課題の通知(`notify_about_high_priority_issues`) | なし | A4-13 の設定を追加し、`priority.position >= 既定より上` の課題は `only_my_events` でも通知 | — | S | — (checklist 未掲載) |
| A6-06 | In-Reply-To / References ヘッダーによる返信の課題特定 | 件名の `[... #123]` 一致のみ | 送信メールに `Message-ID`(`redmine.issue-123.20260919@host`)を付与し、受信側でヘッダーを解析 | A5-01 の `host_name` が前提 | S | 拡張性「メール返信による課題更新」 |
| A6-07 | `emails_header`、`show_status_changes_in_mail_subject` | なし | A5-11 参照 | — | S | — |

### A-7. Wiki / フォーラム / News / 文書 / 添付ファイル

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A7-01 | Wiki マクロ: `{{macro_list}}`、`{{hello_world}}`、`{{recent_pages}}`、`{{thumbnail(file)}}`、`{{issue(#id)}}`、`{{child_pages(Page, depth=N, parent=1)}}`、`{{include(project:Page)}}` | `WikiMarkdownRenderer.php` は toc/child_pages/collapse/include の 4 種(オプションなし) | 各マクロを追加。`child_pages` の再帰は Wiki 階層 UI が1階層のため、先に子ページの再帰表示を実装 | プラグイン登録面(`PluginManager`)からもマクロを登録できるようレジストリ化すると Redmine の `Redmine::WikiFormatting::Macros.register` 相当になる | M | Wiki「マクロエンジン全体」 |
| A7-02 | `{{collapse}}` のネスト | 非貪欲マッチで最初の `}}` で閉じる | 再帰下降で対応する閉じタグを探す | 既知の制限 | S | 同上 |
| A7-03 | Wiki ページ移動時に子ページも一緒に移動(`handle_children_move`) | 子は最上位へ切り離す | 移動先プロジェクトに子孫も再帰移動(タイトル衝突時はエラー) | 旧: 意図的簡略化 | S | Wiki「親の付け替え」 |
| A7-04 | Wiki 個別バージョンの削除(`wiki#destroy_version`) | 未実装(データ整合性の理由で見送り) | 中間バージョン削除時は後続バージョンの `version` 番号を維持したまま行削除(Redmine と同じ) | 旧: 意図的 | S | Wiki「バージョン単体の削除」 |
| A7-05 | Wiki 全体の削除(`wikis#destroy`、プロジェクト設定から) | なし(grep 0件) | プロジェクト設定「Wiki」タブに全ページ削除ボタン(`manage_wiki` 権限、A13) | — | S | Wiki 節 |
| A7-06 | 権限 `view_wiki_edits`(履歴閲覧を別権限に) | 閲覧権限 `view_wiki_pages` で履歴・差分・注釈を表示 | 権限追加+`WikiPagePolicy` の history/diff/annotate を分離、API `?version=` も同権限 | A13 | S | REST API「Wiki pages」 |
| A7-07 | Wiki 一括 PDF エクスポート(`wiki#export` の PDF)は実装済みだが、`export_wiki_pages` の HTML/TXT の ZIP に添付ファイルを含める | ZIP は本文のみ | 各ページの添付を `attachments/` ディレクトリとして同梱 | 優先度低 | S | Wiki「PDF/HTML/TXT/ZIPエクスポート」 |
| A7-08 | フォーラム: 返信のウォッチ、権限 `add_message_watchers`/`delete_message_watchers`/`view_message_watchers`、投稿の引用返信 | トピックのみ Watch。専用権限なし | 権限追加と `MessagePolicy` の分離、返信本文の引用ボタン | A13 | S | フォーラム「トピックのWatch」 |
| A7-09 | 文書一覧の「作成者」グルーピング、添付ファイルのアップロード者記録(`attachments.author_id`) | Spatie Media に `uploaded_by` なし(grep 0件) | `media.custom_properties.uploaded_by` または専用列に保存し、`addMedia()` の全呼び出し箇所で設定。文書一覧のグルーピングと A11-06 の API 露出で使用 | 全アップロード経路(課題/Wiki/フォーラム/News/文書/ファイル/API)を網羅する | S〜M | Documents「カテゴリ/日付/タイトル/作成者でのグルーピング」 |
| A7-10 | 添付ファイルの一括 ZIP ダウンロード(`bulk_download_max_size`)、一括編集(`attachments#edit_all`) | なし | 課題/Wiki/文書の添付一覧に「すべてダウンロード」と「説明を一括編集」 | — | S | 添付ファイル 節 |
| A7-11 | `files` アクティビティプロバイダと `wiki_edits` の既定オフ(`activity.register :files`/`:wiki_edits, default: false`) | `app/Support/Activity/Providers/` は Changeset/Document/Issue/IssueJournal/Message/News/TimeEntry/Wiki の 8 種。Files モジュールの添付追加は活動に出ない | `FileActivityProvider`(バージョンに紐づく Media)を追加。種別チェックボックスの既定オン/オフをプロバイダ定義に持たせる | — | S | ダッシュボード「グローバルアクティビティフィード」 |
| A7-12 | 検索対象の添付ファイル(ファイル名/説明)(`SearchController` の `attachments` トグル、`attachment` フィルタ) | `SearchService` に添付検索なし | `searchAttachments()` を追加し、所有オブジェクトの可視性で絞り込み | API `GET /search` の `attachments` パラメータも | S | 検索(モジュール横断)、REST API「Search」 |
| A7-13 | 一覧画面でのリンク形式 CF のリンク化 | 課題一覧列ではプレーンテキスト | `<x-custom-field-value>` を一覧列にも適用 | 旧: 意図的簡略化 | S | カスタムフィールド「フィールド形式」 |
| A7-14 | 課題一覧・詳細でのサムネイル表示(`thumbnails_size`)、テキスト/PDF 添付のインラインプレビュー(`attachments#show`) | サムネイル生成は実装済み。プレビュー画面なし | 添付の `show` 画面(画像/テキスト/PDF/Diff の表示) | — | S〜M | 添付ファイル「サムネイル/画像変換」 |

### A-8. 工数管理

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A8-01 | 工数の単体編集でのプロジェクト移動、および一括編集への時間/課題/プロジェクト/ユーザー追加(`TimelogController#update`/`#bulk_update`) | 単体編集フォーム(`time-entries/form.blade.php`)は issue_id/user_id/activity_id/hours/spent_on/comments を編集可でプロジェクト移動のみ不可。**一括編集**(`index.blade.php`)は作業分類/日付/コメントの3項目のみ(チェックリスト記載は正しい、C-17 取り下げ) | プロジェクト選択(移動先で `log_time` 権限があるもの)を追加し、課題との整合(課題が別プロジェクトなら解除)を検証。A5-09 のバリデーションを適用 | `edit_own_time_entries`(A13)で自分の分のみ許可 | S | 工数管理「TimeEntry CRUD」 |
| A8-02 | 工数のカスタムフィールド(`TimeEntryCustomField`、`CustomizableType::TimeEntry`) | `app/Enums/CustomizableType.php` に TimeEntry なし。`IssuePriority` もなし | `CustomizableType::TimeEntry`/`IssuePriority` を追加し、フォーム/一覧列/CSV/レポート軸/API に露出 | `Enumeration` は `match($this->type)` で既に多型化済み | M | 「TimeEntry CRUD」、カスタムフィールド 節 |
| A8-03 | 工数の記録者と対象者の分離(`time_entries.author_id` と `user_id`、権限 `log_time_for_other_users`) | `user_id` のみ。他者分の記録は `edit_time_entries` を流用 | `author_id` 列追加、権限追加、API の `user_id` 指定を新権限でゲート | A13 | S | REST API「Time entries」 |
| A8-04 | 多次元レポート: カスタムフィールド軸、プロジェクト横断(`/time_entries/report`)、CSV(`report_to_csv`) | `TimeReportBuilder` は単一プロジェクト・固定軸・CSV なし | list/bool 型 CF を軸に追加、グローバル `/time_entries/report`、CSV 出力 | A8-02 が CF 軸の前提 | M | 工数管理「多次元工数レポート」 |
| A8-05 | プロジェクト横断の工数一覧での編集/削除/CSV/保存済みクエリ | `time-entries.global-index` は閲覧のみ | プロジェクト単位画面の機能を横断画面へ移植(可視性は `Role.time_entries_visibility` で判定済み) | — | S〜M | 「プロジェクト横断の工数一覧」 |
| A8-06 | 工数一覧のコンテキストメニュー(`context_menus/time_entries`) | なし | A1-05 の部品で作業分類/課題/一括編集/削除 | — | S | — |
| A8-07 | `timespan_format`(小数/時分表示) | 小数固定 | 設定+表示ヘルパー | — | S | 設定「表示」 |

### A-9. 横断ビュー(マイページ / 活動 / ガント / カレンダー / 検索)

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A9-01 | プロジェクト横断ガント(`/issues/gantt`)、PNG エクスポート、共有バージョンのマイルストーン、PDF 内の関連線、`gantt_items_limit`/`gantt_months_limit` | ガントは `/projects/{project}/gantt` のみ(`gantt.global` grep 0件)。PNG なし。共有バージョン非表示。PDF に関連線なし | `gantt.global-index` を他の global-index 群と同じ構成で追加。PNG は `Imagick`/`gd` で SVG→PNG。共有バージョン(`Version::sharing`)をマイルストーン候補に含める | 関連線の PDF は dompdf の SVG 対応が不安定なため要検証 | M | ダッシュボード「ガント」 |
| A9-02 | カレンダーへのバージョン期日表示 | `calendar/index.blade.php` に version の記述なし | プロジェクト(および共有)バージョンの `due_date` を◆で表示 | ガントのマイルストーン実装(`versions.roadmap`)を流用 | S | ダッシュボード「カレンダー」 |
| A9-03 | マイページのブロック: `issuesupdatedbyme`(自分が更新した課題)、`calendar`、同一クエリの最大3回配置(`max_occurs`)、ブロックごとの設定(`my_page_settings`: 列/ソート) | `app/Support/Dashboard/Blocks/` は Activity/AssignedIssues/Documents/LatestNews/ReportedIssues/TimeEntries/WatchedIssues + SavedIssueQuery。同一クエリは1つまで | `UpdatedByMeBlock`(Journal の user_id 基準)、`CalendarBlock`(週表示)、ブロック設定 UI | calendar ブロックは「一覧形式に馴染まない」として見送られていた | M | ダッシュボード「マイページ」 |
| A9-04 | グローバル活動: ページネーション/件数上限、`activity_scope` 個人設定、全プロジェクト Atom(`/activity.atom`) | `activity.global-index` は「可視プロジェクト数×8プロバイダ」を全件走査、Atom なし | 各プロバイダに複数プロジェクト対応の `entries()` を追加し1クエリ化、日付単位ページング、`ActivityFeedController` のグローバル版 | 8 プロバイダ全部の改修 | M | 「グローバルアクティビティフィード」 |
| A9-05 | `feeds_limit` 設定 | 定数 | A5-02 参照 | — | S | 設定「全般」 |
| A9-06 | 検索: `bookmarks` スコープ、結果ページネーション、添付検索 | `ProjectBookmark` は実装済み(`projects/index.blade.php` の `bookmarkedOnly`)だが検索スコープに未配線(`search/*.blade.php` grep 0件)。ページネーションなし | スコープ選択肢に「ブックマーク」を追加、`search_results_per_page` でページング(A2-07)、添付(A7-12) | チェックリストは「ブックマーク機能自体が無い」と記載(C-03) | S | 「検索(モジュール横断)」 |
| A9-07 | プロジェクトジャンプボックス(ヘッダーの検索付きプロジェクト切替)と `recently_used_projects` | なし(grep 0件) | ヘッダーにインクリメンタル検索付きドロップダウン、最近使ったプロジェクトを A4-13 に保存 | — | S | — (checklist 未掲載) |
| A9-08 | 課題一覧から `#123` 直接ジャンプは実装済み。Redmine の検索ボックスの `#123`/`r123`(リビジョン)/`p:`(プロジェクト)ショートカット | `#123` のみ | `r123` でチェンジセット、`project-identifier:` 形式を解釈 | 優先度低 | S | 同上 |

### A-10. リポジトリ(SCM)

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A10-01 | リポジトリ接続情報(`repositories.url`/`root_url`/`login`/`password`/`log_encoding`/`path_encoding`/`extra_info`) | `Repository` の fillable は project_id/type/path/last_synced_revision/is_default/identifier。認証情報・エンコーディングなし | SVN の URL+ユーザー/パスワード(暗号化 cast)、ログ/パスのエンコーディング指定を追加し `SvnAdapter`/`GitAdapter` に渡す | 認証情報は `encrypted` cast | S〜M | リポジトリ連携「対応SCM種別」 |
| A10-02 | コミットキーワード設定の拡張(A5-12)、`commit_cross_project_ref` | `{keywords, status_id}` のみ、他プロジェクト参照は常に許可 | 参照キーワード設定化、更新ルールに `done_ratio`/`if_tracker_id`、他プロジェクト参照の許可トグル | — | S | 「コミットメッセージのキーワード連動」 |
| A10-03 | `/sys` WS(`sys/projects`、`sys/fetch_changesets`、`sys_api_key`)と `reposman.rb` 連携 | なし(grep 0件) | API キー認証の `GET /sys/projects.json`、`GET /sys/fetch_changesets?id=` を追加(post-receive フックからの同期トリガー用) | 既存 `RepositorySyncService` を呼ぶだけ | S | 設定「リポジトリ」 |
| A10-04 | Annotate の同一リビジョン連続行の色分けブロック | 全行に個別表示 | 連続する同一 revision をグループ化し交互に背景色 | 旧: 意図的対象外 | S | 「Annotate/Blame」 |
| A10-05 | `repository_log_display_limit`、`diff_max_lines_displayed`、`file_max_size_displayed` | 定数または無制限 | 設定化し `repository/*.blade.php` で参照 | — | S | 設定「リポジトリ」 |
| A10-06 | Filesystem アダプタ | 保留 | B'-03 参照 | — | — | — |

### A-11. REST API(Redmine `config/routes.rb` との差分)

| ID | Redmine 側のエンドポイント | 本アプリの現状(`routes/api.php`) | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A11-01 | `GET /issues.json`(全プロジェクト横断、`project_id`/`status_id=*`/`sort` 等) | `GET /projects/{project}/issues` のみ | `Issue::scopeVisibleToAcrossProjects()`(Web の `issues.global-index` が使用)を API に配線 | Redmine クライアント(redmine-cli 等)は横断エンドポイントを前提にすることが多い | S | REST API「Issues」 |
| A11-02 | `GET /time_entries.json`(横断)、`GET /issues/{id}/time_entries.json`、`POST /issues/{id}/time_entries.json` | プロジェクトネストのみ | 横断一覧と課題ネストルートを追加 | — | S | REST API「Time entries」 |
| A11-03 | `GET /news.json`(横断) | プロジェクトネストのみ | `news.global-index` と同じ可視性で横断一覧 | — | S | REST API「News」 |
| A11-04 | `POST/DELETE /news/{id}/comments` | なし(`comments_count` のみ) | News コメントの作成/削除 API | — | S | 同上 |
| A11-05 | `GET /projects/{id}/files.json`、`POST /projects/{id}/files.json`(Files モジュール) | なし | Files モジュールの添付一覧/追加 API(`upload` トークンを使用) | — | S | REST API 節(未掲載) |
| A11-06 | `GET /attachments/{id}.json`、`PATCH /attachments/{id}.json`(説明/ファイル名)、`DELETE /attachments/{id}.json`、`GET /attachments/download/{id}` | `POST /uploads` のみ | Media を対象に取得/更新/削除。`author` は A7-09 が前提 | Spatie Media の ID を露出 | S | REST API「添付ファイル(アップロードAPI)」 |
| A11-07 | `POST/PUT/DELETE /users`、`show` の可視性ティア(本人/管理者/公開)と応答フィールド出し分け(`admin`/`mail`/`api_key`/`status`) | `GET` のみ、管理者限定、`UserResource` は常に同一形状 | 書き込み系を追加(`is_admin` は fillable 外のため明示的に扱う)、`UserPolicy::view` を Web の公開プロフィール(`User::isVisibleTo()`)に合わせる | パスワード/`must_change_passwd`/`generate_password`/`send_information` | M | REST API「Users」 |
| A11-08 | `POST /groups/{id}/users.json`、`DELETE /groups/{id}/users/{user_id}.json` | `user_ids` の完全置換のみ | 追加/削除専用エンドポイント | — | S | REST API「Groups」 |
| A11-09 | `GET /issues/{id}?include=allowed_statuses,changesets`、`include=children` の再帰、`journals` の `details` 完全形 | `include` は journals/relations/attachments/children(1階層)/watchers | `allowed_statuses` は `WorkflowService` で算出、`changesets` は `Changeset` 関連、`children` を再帰化 | — | S | REST API「Issues」 |
| A11-10 | 各リソースのカスタムフィールド値の読み書き(`custom_fields` 配列)、Wiki/News/Version/Project の `?include=attachments` | `app/Http/Resources/Api`・`app/Http/Requests/Api`・`app/Http/Controllers/Api` に `custom_field` の記述なし(grep 0件)。**Issue API を含む全リソースで CF の読み書き不可** | `custom_fields: [{id, name, value}]` を Issue/Project/Version/Group/User/TimeEntry の Resource と Store/Update Request に追加(ロール可視性は `Issue::relevantCustomFields()` を再利用)。`AttachmentResource` を共通化して `include=attachments` 対応 | Redmine API クライアントの多くは Issue の `custom_fields` を前提にする。優先度高 | M | REST API 各行 |
| A11-11 | `GET /custom_fields.json` の `description`/`is_for_all`/`is_filter`/`visible`/`default_value_mode`/`trackers`/`roles` | 該当カラムが無いものは省略 | `is_filter`(A11-13)追加後に露出。`trackers`/`roles` は既存関連から出せる | — | S | REST API「Custom fields」 |
| A11-12 | `GET /roles/{id}.json` の `users_visibility` | `RoleResource` に `users_visibility` なし(grep 0件)。機能自体は 2026-07-30 実装済み | Resource に追加 | チェックリスト記載は古い(C-04) | S | REST API「Roles」 |
| A11-13 | カスタムフィールドの `is_filter` フラグ(フィルタとして使えるかを CF ごとに制御) | `custom_fields` に列なし(migration grep 0件)。全 CF がフィルタ対象 | 列+フォーム+`IssueFilterFieldRegistry` で判定 | Web 側の機能でもある | S | カスタムフィールド 節 |
| A11-14 | `PUT /my/account.json` の password/2FA/通知設定/CF、`GET /my/api_key`、`POST /my/api_key`(リセット) | name/email のみ | フィールド拡張。API キーはプロフィール画面で再生成できるため Resource に露出のみ | sudo mode は B-08 | S | REST API「My account」 |
| A11-15 | `jsonp_enabled`(JSONP コールバック) | なし | 設定+`callback` パラメータ対応ミドルウェア | 優先度低・セキュリティ注意 | S | — |
| A11-16 | Memberships の `inherited_from` | 概念なし | A3-03 実装後に露出 | — | S | REST API「Memberships」 |

### A-12. 拡張性(プラグイン / Webhook / 受信メール)

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A12-01 | Webhook イベント `news.*`(Redmine 7.0 は Issue/News/TimeEntry/Version/WikiPage の 5 モデルが `acts_as_webhookable`) | `app/Enums/WebhookEvent.php` は issue/wiki_page/time_entry/version の 12 種。`news.*` なし | `NewsCreated`/`NewsCommentCreated` イベントは既存なので `DispatchWebhooksForNewsEvent` リスナーと Enum 値を追加 | — | S | 拡張性 節(Webhook は「本アプリ独自機能」と記載されていたが Redmine 7.0 でコア化、C-09) |
| A12-02 | Webhook の所有ユーザーと可視性判定(`Webhook#user`、`object.visible?(hook.user) && allowed_to?(:use_webhooks)`)、`webhooks_enabled` 設定、権限 `use_webhooks` | Webhook は管理者が登録するグローバル設定、ユーザー紐付けなし | `webhooks.user_id` 列を追加し配信時に可視性を判定、`use_webhooks` 権限(A13)、有効/無効設定 | 現行 Webhook の配信対象(全課題)からの後方互換に注意 | M | 同上 |
| A12-03 | プラグインのランタイム検出(`plugins/*/init.rb` 自動読込) | `bootstrap/providers.php` への手動登録(`PluginManager` docblock) | `plugins/` ディレクトリ走査+Composer オートロード登録、有効/無効フラグ | 旧: 意図的(第一段階) | M | 拡張性「ランタイムでのプラグイン検出」 |
| A12-04 | プラグイン独自の設定パーシャル(`settings :partial => '...'`) | 型推定の汎用エディタのみ | プラグイン定義に Blade ビュー名を渡せるようにし、あれば汎用エディタの代わりに描画 | — | S | 「プラグイン設定UI・永続化」 |
| A12-05 | モデル/コントローラのライフサイクルフック(`controller_issues_edit_before_save` 等) | ビュー描画フック(`<x-hook>`)のみ | 主要サービス(`IssueService::create/update/delete`、`TimeEntry`、`WikiPage`)の前後に Laravel イベント(既に `IssueCreated` 等はある)を整理し、プラグインが購読できるフック名一覧をドキュメント化 | 既存イベントの流用で大半は賄える | M | 「コントローラ/モデルのライフサイクルフック」 |
| A12-06 | 受信メール: API キー経由の受信(`mail_handler_api_*`)、`allow_override` によるキーワード上書き許可リスト、カスタムフィールドキーワード、`project_from_subaddress`、`default_group`、`no_account_notice`/`no_notification` | IMAP/POP ポーリング、11 キーワード固定、CF 非対応 | `POST /mail_handler`(A5-10)、キーワード許可リスト設定、CF 名一致でのキーワード、`+project` サブアドレス解釈 | `unknown_user`/`no_permission_check` は B-05 | M | 「メール本文のキーワードコマンド」 |

### A-13. 権限の粒度(Redmine 80 権限中、本アプリに無い 27 件)

`lib/redmine/preparation.rb` と `app/Providers/PermissionServiceProvider.php`(54 権限、うち Redmine と同名 53)の機械照合。本アプリ独自の `move_issues` は Redmine にない(Redmine は `edit_issues`+移動先の `add_issues` で判定)。

| ID | 権限 | 現状の代替 | 残作業 | 規模 | 関連 ID |
|---|---|---|---|---|---|
| A13-01 | `add_issue_notes`、`edit_own_issues`、`set_own_issues_private`、`manage_subtasks` | `edit_issues`/`set_issues_private` に包含 | 権限追加+`IssuePolicy` で「自分の課題のみ」「コメントのみ」「サブタスク操作」を分離 | S〜M | A-1 |
| A13-02 | `view_issue_watchers`、`delete_issue_watchers`、`view_wiki_page_watchers`/`add_wiki_page_watchers`/`delete_wiki_page_watchers`、`view_message_watchers`/`add_message_watchers`/`delete_message_watchers` | 課題は閲覧権限でウォッチャー表示、Wiki は `edit_wiki_pages`、フォーラムは未対応 | 権限追加+各 Policy の `manageWatchers` を分離 | S | A1-01, A7-08 |
| A13-03 | `edit_own_time_entries`、`log_time_for_other_users`、`import_time_entries`、`import_issues` | `edit_time_entries` を流用、インポートは `add_issues`/`log_time` で判定 | 権限追加+Policy | S | A8-01, A8-03 |
| A13-04 | `manage_wiki`、`view_wiki_edits`、`delete_wiki_pages_attachments` | `delete_wiki_pages`/`edit_wiki_pages` に包含 | 権限追加+`WikiPagePolicy` | S | A7-05, A7-06 |
| A13-05 | `add_project`、`select_project_publicity`、`view_members`、`manage_project_activities`、`save_queries`、`search_project` | 管理者専用/`edit_project`/`view_project`/`manage_public_queries` に包含 | グローバル権限の仕組み(A3-01)を導入したうえで追加。`view_members` はメンバー一覧タブの表示ゲート、`save_queries` は非公開クエリ保存のゲート、`search_project` は検索対象プロジェクトの制御 | M | A3-01, A3-02, A3-11 |
| A13-06 | `commit_access`(リポジトリへの書き込み権限、`/sys` WS でのアクセス制御に使用) | なし | A10-03 と同時 | S | A10-03 |
| A13-07 | `use_webhooks` | なし | A12-02 と同時 | S | A12-02 |

### A-14. 横断基盤(checklist 未掲載)

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 |
|---|---|---|---|---|---|
| A14-01 | 多言語対応(`config/locales/*.yml` 49 言語、`default_language`、`force_default_language_*`、ユーザーの `language`) | `lang/` ディレクトリなし。Blade/PHP に日本語をハードコード。`users.language` 列は存在するが UI で未使用(`profile/index.blade.php` grep 0件) | 全 UI 文字列を `__()` に置換し `lang/ja.json`/`lang/en.json` を作成、ユーザー言語選択、`SetLocale` ミドルウェア、日付/数値の `Carbon::locale()`。メールテンプレートも対象 | **L**。最初に英語と日本語の 2 言語で。Redmine の `config/locales/ja.yml` のキーを流用すると翻訳作業が減る | L |
| A14-02 | ユーザータイムゾーン・日付/時刻形式 | A4-12 | — | — | M |
| A14-03 | テーマ切替(`ui_theme`) | Tailwind 単一テーマ | A5-06 | — | M |
| A14-04 | 管理 → 情報(`admin/info`: バージョン・環境・チェックリスト(ファイル書込可否・ImageMagick・SCM バイナリ有無)) | なし(`admin.info` grep 0件) | `/admin/info` に PHP/Laravel/DB バージョン、`storage/` 書込可否、`git`/`svn` バイナリ有無、キュー/スケジューラ稼働状況を表示 | 管理者専用 | S |
| A14-05 | 「デフォルト設定のロード」(`admin/default_configuration`: ロール/トラッカー/ステータス/ワークフロー/優先度の初期データを管理画面から投入) | DB シーダーのみ | 管理画面から初期データ投入ボタン(既存シーダーを呼ぶ)、言語選択付き | A14-01 と連動 | S |
| A14-06 | 新規作成メニュー「+」(`new_item_menu_tab`) | A5-04 | — | — | S |
| A14-07 | プロジェクトジャンプボックス | A9-07 | — | — | S |

---

## B. 対象外確定(再開はユーザー判断)

| ID | 項目 | 理由(決定日) | 再開する場合の論点 |
|---|---|---|---|
| B-01 | 課題の Nested set 化(`issues.root_id`/`lft`/`rgt`) | 隣接リスト(`parent_id`)設計を採用(計画書 §7、ただし出典未検証) | 深い階層の集計性能で問題が出た場合のみ。`kalnoy/nestedset` は Project で採用済みなので技術的には可能 |
| B-02 | プロジェクト間サブタスク(`cross_project_subtasks` 設定) | 親子をプロジェクトスコープに限定(カテゴリ/バージョンと同じ設計判断) | B-01 とセットで検討 |
| B-03 | プロジェクトコピー(`Project#copy`、8 メソッド約 265 行+`Issue#copy_from`) | 2026-07-30 に「大型据え置き」として正式決定。部分実装は「主要機能が無いのに動いているように見える」ため不採用 | 実装する場合は A1-07(サブタスクコピー)を先に完成させ、その上に載せる |
| B-04 | REST API の XML 形式 | JSON のみ(計画書合意) | — |
| B-05 | 受信メールの `unknown_user`(アカウント自動作成)/`no_permission_check` | セキュリティ判断としてコードに明記 | — |
| B-06 | Textile 記法(`text_formatting` 設定) | Markdown(CommonMark)のみ。Redmine 7.0 でも Textile は残っているが非推奨 | Textile→Markdown 変換ツールを提供する方が現実的 |
| B-07 | プロジェクトモジュール・クエリフィルタ演算子のプラグイン拡張 | `ProjectModuleKey`/`FilterOperator` はコンパイル時 Enum(`PluginManager` docblock) | Enum を文字列レジストリに置換する設計変更が必要 |
| B-08 | sudo mode(パスワード再確認を要求する管理操作) | Web 側にも無いため API でも対象外 | Fortify の `password.confirm` ミドルウェアは存在するので、必要なら小規模 |
| B-09 | Journal の削除 API/UI、親子の手動並べ替え UI、ガントのドラッグ&ドロップ | Redmine 本家に存在しないことをソースで確認済み(パリティ対象外) | — |

## B'. 承認待ち / ブロック中

| ID | 項目 | ブロッカー | 解消後の作業 | 規模 |
|---|---|---|---|---|
| B'-01 | Mercurial / CVS / Bazaar アダプタ | Sail コンテナに `hg`/`cvs`/`bzr` バイナリなし。導入は CLAUDE.md の依存追加承認ゲート対象 | `docker/` の Dockerfile にバイナリ追加→`ScmAdapter` 実装(Git/SVN と同じ実バイナリ E2E テスト) | M ×3 |
| B'-02 | カスタムフィールド形式 `attachment` | Spatie MediaLibrary の `model_type/model_id` 非 null 制約により「CF 値としての添付」の所有者モデル設計が必要 | `CustomFieldValue` を HasMedia にするか、専用の中間モデルを作る設計メモを先に書く | M |
| B'-03 | Filesystem アダプタ | Redmine 側は `entries`/`cat` のみでリビジョン概念なし。`ScmAdapter` の `log`/`diff`/`blame` をどう表現するかの設計判断待ち | `ScmAdapter` に `supports(Capability)` を追加し、非対応タブを UI で非表示にする方式が候補 | S〜M |
| B'-04 | A1-20(グループ割当)・A4-10b(姓名分離)・A14-01(i18n) | いずれもスキーマ/全画面に波及する。着手前に設計メモをユーザーに提示して承認を得る | — | L |

---

## C. `parity-checklist.md` の訂正が必要な行

以下は 2026-09-19 時点でコードと記載が食い違っている箇所。**行を消化する際に周辺行の実態も同時に確認する**(チェックリスト運用原則 3)。

| ID | 行 | 現在の記載 | 実態 | 修正内容 |
|---|---|---|---|---|
| C-01 | Versions「Version CRUD・対象バージョン割当」 | `partial(2026-07-22訂正)` | 備考本文の通り `versions/{index,form}.blade.php` で CRUD 実装済み | ステータスを `done(2026-07-22)` に |
| C-02 | Wiki「PDF/HTML/TXT/ZIPエクスポート」 | 「PDF出力(新規依存が必要なため対象外)は引き続き未実装」 | 2026-07-30 に Wiki 単体 PDF・一括 PDF とも実装済み(§0.5「PDF出力」行) | 文言削除、`done` に |
| C-03 | 検索(モジュール横断)「プロジェクト絞り込みトグル」 | 「`bookmarks` は本アプリにプロジェクトブックマーク機能自体が無いため対象外」 | `ProjectBookmark` モデル・`project_bookmarks` テーブル(2026-07-21)・`projects/index.blade.php` の `bookmarkedOnly` が存在 | 「ブックマークスコープは未配線(A9-06)」に書き換え |
| C-04 | REST API「Roles」 | 「`users_visibility` は本アプリ未実装のため対象外」 | `Role.users_visibility` は 2026-07-30 に実装済み。`RoleResource` に未露出 | 「Resource 未露出(A11-12)」に書き換え |
| C-05 | Journal「メール通知(課題)」 | 「@mention通知は引き続き未着手」 | Issue/Wiki の @mention は 2026-07-30 に実装済み。未対応は News/フォーラムのみ | 文言修正(A6-02 参照) |
| C-06 | §0.5「保留の再確認」 | 「単独では割に合わない: … Wikiのannotate(284、ニッチ)」 | Wiki「Annotate/Blame」行は `done(2026-07-22)`、`wiki/annotate.blade.php` が存在 | 該当句を削除 |
| C-07 | Trackers「ロードマップ対象フラグ・デフォルト非公開・説明文テンプレート」 | 「『説明文テンプレート』に該当するRedmine側フィールドは特定できず未着手」 | Redmine の `trackers.description`(`db/migrate/20190315102101_add_trackers_description.rb`)。本アプリも `trackers.description` 列+`trackers/form.blade.php` に入力欄あり | 「Tracker.description として実装済み」に訂正、行を `done` に(適用済み。残る `is_in_chlog` は廃止済みで対象外、C-18) |
| C-08 | Versions「Wikiページ紐付け・既定バージョン設定」 | 「『既定バージョン設定』に該当するRedmine機能は未特定」 | `projects.default_version_id`(+`default_assigned_to_id`、`project.rb:43-44`) | A1-23 を参照する形に書き換え |
| C-09 | Journal「メール通知(Wikiページ)」ほか | 「Redmine本家にWebhook相当の概念が無いため、本アプリ独自機能」 | Redmine 7.0.0 はコアに Webhook(`app/models/webhook.rb`、`acts_as_webhookable`、権限 `use_webhooks`、設定 `webhooks_enabled`)を持つ | 記載を訂正し、拡張性節に Webhook のパリティ行(A12-01/02)を追加 |
| C-10 | 拡張性「受信メールによる課題作成」「メール返信による課題更新」 | 「本アプリはまだ送信メール通知を実装していない」 | 送信メール通知は 2026-07-29〜30 に実装済み | 文言削除、In-Reply-To 経路は A6-06 として残す |
| C-11 | 工数管理「プロジェクト横断の工数一覧」 | 「ピボット表自体はプロジェクト単体でも未実装のまま」 | 「多次元工数レポート(ピボット表)」行は `done(2026-07-30)` | 注記を削除 |
| C-12 | §0 項目 3 / 見出し「アプリケーション設定(Redmine 119キー中 ~9項目)」 | 「119キー中わずか6項目」 | Redmine 7.0.0 の `settings.yml` は 120 キー、本アプリは 48 キー実装(うち Redmine と同名 41、付録 1) | 数値を更新 |
| C-13 | §0 項目 5 | 「REST API が Projects・Issues・Versions・Issue categories のみ」 | `routes/api.php` は 22 リソース群 | 打ち消し線+現状に更新 |
| C-14 | Issues本体「課題のコピー」 | 「ジャーナル/添付/関連/親子は意図的にコピー対象外」 | `IssueService::copy()` に `$copyAttachments` 引数が存在(一括コピー経路)。`?copy_from=` プリフィルは依然何も複製しない | 2 経路の違いを明記(A1-08) |
| C-15 | 一括編集「PDFエクスポート・Atomフィード」 | 課題 PDF を `done` と記載 | 単体課題 PDF のみ。一覧 PDF は未実装 | 「一覧 PDF は A1-29」を追記 |
| C-16 | §0 項目 2 | 「専用の『リセットして通知』フローはまだない」 | `users/form.blade.php:150` の `sendPasswordReset()` がパスワード再設定リンクをメール送信(2026-07-22 の行で `done`) | 文言を打ち消し線に |
| C-17 | (取り下げ) 工数管理「TimeEntry CRUD」 | 「編集対象は作業分類/日付/コメントの3項目のみ」 | **記載は正しい**。この文は`time-entries/index.blade.php`の**一括編集**(`bulkActivityId`/`bulkSpentOn`/`bulkComments`)の説明で、単体編集フォームの話ではない。Redmine の一括編集は時間・課題・プロジェクト・ユーザーも変更できる | 訂正不要。不足は A8-01 に記載 |
| C-18 | (バックログ自身の訂正)A1-24 `is_in_chlog` | 付録の機械照合で「スキーマ列の欠落」として列挙 | Redmine 7.0.0 は 2021 年に列を廃止済み。**教訓**: マイグレーションの `add_column` だけでなく、`app/` での使用箇所と後続の `drop` を確認する。他の列(`inherit_members`/`homepage`/`default_time_entry_activity_id`/`updated_by_id`/`passwd_changed_on`/`must_change_passwd`/`members.mail_notification`/`is_filter`/`twofa_required` 等)は `app/` に使用箇所があり現役と確認済み(2026-09-20) | 反映済み |
| C-19 | (バックログ自身の訂正)A7-04 | 「Wiki 個別バージョンの削除は未実装」 | 実装済みだった。実際の差分は権限(`edit_wiki_pages` ↔ Redmine の `delete_wiki_pages`)と最新版/最後の1版の扱い | 反映済み(A7-04) |

---

## 付録 1. 機械照合の生データ(2026-09-19)

### 設定キー(`config/settings.yml` 120 キー − 本アプリ 48 キー)

Redmine にあり本アプリの `Setting::get/set` に無いキー(79 = 120 − Redmine と同名の 41、うち `default_projects_modules`/`issue_list_default_columns` はプロパティ経由で実装済み):

```
assignee_dropdown_display_format bulk_download_max_size cache_formatted_text
commit_cross_project_ref commit_logs_encoding commit_logs_formatting
commit_ref_keywords commit_update_keywords copy_attachments_on_issue_copy
cross_project_subtasks date_format default_issue_query
default_issue_start_date_to_creation_date default_language default_project_query
default_projects_modules* default_users_auto_watch_on default_users_hide_mail
default_users_time_zone diff_max_lines_displayed display_related_issues_table_headers
display_subprojects_issues emails_header enabled_scm feeds_limit
file_max_size_displayed force_default_language_for_anonymous
force_default_language_for_loggedin gantt_items_limit gantt_months_limit
gravatar_default gravatar_enabled host_name issue_done_ratio_interval
issue_group_assignment issue_list_default_columns* issue_list_default_totals
issues_export_limit jsonp_enabled link_copied_issue lost_password
mail_handler_api_enabled mail_handler_api_key mail_handler_enable_regex_delimiters
mail_handler_enable_regex_excluded_filenames max_additional_emails new_item_menu_tab
non_working_week_days password_max_age password_required_char_classes
per_page_options project_list_defaults project_list_display_type protocol
related_issues_default_columns repositories_encodings repository_log_display_limit
search_results_per_page session_lifetime show_custom_fields_on_registration
show_status_changes_in_mail_subject sys_api_enabled sys_api_key text_formatting
thumbnails_enabled thumbnails_size time_entry_list_defaults time_format
timelog_accept_0_hours timelog_accept_closed_issues timelog_accept_future_dates
timelog_max_hours_per_day timelog_required_fields timespan_format ui_theme
user_format webhooks_enabled wiki_compression wiki_tablesort_enabled
```

本アプリ独自キー(Redmine に同名なし): `commit_fixing_keyword_rules`(≒`commit_update_keywords`)、`default_issues_per_page`(≒`per_page_options`)、`enabled_scm_types`(≒`enabled_scm`)、`incoming_mail_*` 4 種。

### 権限(`preparation.rb` 80 − Redmine と同名の本アプリ権限 53 = 27)

```
add_issue_notes add_message_watchers add_project add_wiki_page_watchers commit_access
delete_issue_watchers delete_message_watchers delete_wiki_page_watchers
delete_wiki_pages_attachments edit_own_issues edit_own_time_entries import_issues
import_time_entries log_time_for_other_users manage_project_activities manage_subtasks
manage_wiki save_queries search_project select_project_publicity set_own_issues_private
use_webhooks view_issue_watchers view_members view_message_watchers view_wiki_edits
view_wiki_page_watchers
```

### 課題フィルタ(`issue_query.rb` − `IssueFilterFieldRegistry`)

Redmine 37 種(CF 除く)− 本アプリ 13 種。欠落は A1-17 に列挙。

### 課題一覧列(`issue_query.rb` の `available_columns` − `DISPLAY_COLUMNS`)

欠落: `id`(常時表示なら不要)`project` `parent` `updated_on` `estimated_hours` `estimated_remaining_hours` `closed_on` `last_updated_by` `description` `last_notes` `spent_hours` `total_spent_hours` `is_private`。

### カスタムフィールド形式(`field_format.rb` 13 − 本アプリ 10)

欠落: `user` `version`(A1-10)`attachment`(B'-02)。

### カスタマイズ可能オブジェクト(Redmine 10 − 本アプリ 8)

欠落: `TimeEntryCustomField` `IssuePriorityCustomField`(A8-02)。

### Wiki マクロ(`macros.rb` 8 − 本アプリ 4)

欠落: `macro_list` `hello_world` `recent_pages` `thumbnail` `issue` +`child_pages`/`include` のオプション(A7-01)。

### マイページブロック(`my_page.rb` 10 − 本アプリ 8)

欠落: `issuesupdatedbyme` `calendar`(A9-03)。

### アクティビティプロバイダ(Redmine 8 − 本アプリ 8)

欠落: `files`(Attachment)。本アプリ独自: `IssueJournal` を独立プロバイダにしている(Redmine は `issues` に統合)。

### Webhook イベント(Redmine 7.0 5 モデル − 本アプリ 4 モデル)

欠落: `news.*`(A12-01)。

### REST API ルート

Redmine にあり本アプリに無い: `GET /issues`、`GET /time_entries`、`GET|POST /issues/:id/time_entries`、`GET /news`、`POST|DELETE /news/:id/comments`、`GET|POST /projects/:id/files`、`GET|PATCH|DELETE /attachments/:id`、`GET /attachments/download/:id`、`POST|PUT|DELETE /users`、`POST|DELETE /groups/:id/users`、`GET|POST /my/api_key`、`POST /my/atom_key`、`/sys/*`、`/mail_handler`。

## 付録 2. 未検証・本書の限界

- 参照 Redmine は 7.0.0 のチェックアウト。バージョン固有の新機能(`estimated_remaining_hours`、Webhook、ユーザーインポート、Reaction)は 7.0.0 の実ソースで存在を確認したが、リリースノートとの照合は行っていない。
- `parity-checklist.md` の `done` 行の**実装品質**(Redmine と同じ挙動か)は本書の対象外。本書は「存在するか」のみを見ている。
- `vendor/` が本ワークツリーに無いためテストは実行していない(本書はドキュメントのみの変更)。
