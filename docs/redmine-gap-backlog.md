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
| 2a | A1-32 | A1-23 | S | done(2026-09-20) |
| 2b | A1-33 | A1-23 | S | done(2026-09-20) |
| A5-16b | `default_issue_start_date_to_creation_date` を REST API 課題作成(`IssuesController#build_new_issue_from_params`)と受信メール課題作成(`mail_handler.rb:216`)にも適用 | A5-16 で Web フォームのみ設定化。API/メールは開始日を補完しない | 設定オン時に両経路で `start_date ??= today` | 設定の既定がオンのため、適用すると既存 API クライアント/メールの挙動が変わる。**適用前にユーザーへ確認**(または既定オフに変更) | S | Issues本体「担当者『自分』ショートカット・既定開始/期日」 |
| A4-12b | タイムゾーンを設定した利用者(または設定「新規ユーザーの既定の個人設定」のタイムゾーン)には、画面・CSV・PDF の日時がそのゾーンで表示され、「今日」(新規課題の開始日・期日、工数の日付、活動の期間、カレンダー)もそのゾーンの今日になる。**全員に影響**: 作成日・更新日・終了日・最終ログインの日付フィルタ(REST の `created_on`/`updated_on` も)は、その日の 0 時ちょうどではなくその日全体と比較する(「〜以下」「=」で当日分が含まれるようになる)。バージョンは期日の翌日から遅れ扱い(従来は期日当日の 0 時 UTC から)、残り/超過日数は整数。工数の「未来の日付」は工数の対象ユーザーの今日で判定 | タイムゾーンを設定しなければ日時の表示は従来どおり UTC |
| A4-12c | 設定「日付の形式」「時刻の形式」が増える。選ぶと画面・CSV・PDF・メールの日付/時刻(課題の履歴の開始日・期日の変更も)がその形式になる | 既定(空)は従来どおり `2026-09-24 15:05`。REST API は変わらない |
| A1-34 | 親課題を削除すると子孫も削除される(Redmine: `acts_as_nested_set :dependent => :destroy`、`issues_controller.rb:434` の `self_and_descendants`。工数の確認対象も子孫を含む) | **done(2026-09-24)**: `IssueService::deleteMany()`(`delete()` は 1 件版)が `Issue::selfAndDescendantIds()`(再帰 CTE、深い順)で子孫をまとめて削除(子から順に `delete()` するのでメディア・検索索引・`IssueDeleted` も各課題で処理)。工数の nullify/destroy/reassign は子孫の工数も対象、付替先は削除される課題(子孫を含む)を拒否し、選択が 1 プロジェクトのときだけ付替可。課題詳細・一覧の一括削除/右クリックの確認に「N件のサブタスクも削除されます。」、工数の合計も子孫込み。一括削除で親と子を同時に選んでも 1 回ずつ削除。REST `DELETE /issues/{id}` も同じ。**Redmine と同じく子孫は可視性・削除権限を確認せずに削除する**(Redmine は選択した課題だけ `visible?`/`deletable?` を確認)。削除後に残る親は Redmine の `after_destroy :update_parent_attributes` と同じく派生属性を再計算(子が無くなった親はそのまま)。テスト: `IssueDeletionTest`('orphans its children' を反転)、`IssueBulkDeleteTest` | 削除時に子孫を再帰削除し、工数の合計/付替対象を子孫分まで含める。削除確認に「N 件のサブタスクも削除されます」を表示 | **データ削除の意味が変わる**ため要承認。既存テストの期待値を反転する | S〜M | Issues本体「課題削除」 |
| A1-35 | 一括削除の確認画面での工数の扱い(`todo`、複数プロジェクト選択時は付替なし) | `issues/index.blade.php` の一括削除は `IssueService::delete()` を既定(nullify)で呼ぶのみ | 一括削除にも A1-09 と同じ選択肢を追加(選択課題の工数合計を表示、単一プロジェクトのときだけ付替を許可、付替先は選択課題以外) | A1-09 完了が前提 | S | Issues本体「課題削除」 |
| A11-17 | REST の課題一覧の高度なフィルタ(`f[]`/`op[]`/`v[][]`、カスタムフィールド、`updated_on` 範囲など)と `limit`/`offset`、`include` の追加 | **done(2026-09-24)**。`GET /issues.json`・`/projects/{id}/issues.json` が Redmine の `f[]`/`op[]`/`v[field][]` と短縮形 `field=[演算子]値[|値]`(`updated_on=><2026-01-01|2026-01-31` など、Web 一覧の全フィルタ)を受け付け、`App\Support\Api\RedmineIssueListParams` で `QueryFilterEngine` 用に変換(`o`/`c`/`*`/`!*`/`t`/`w`/`>t-` なども対応、ドット名は `_` のキーへ、不正な値・未知の項目は無視)。`limit`(既定 25、最大 100)/`offset`/`page`、応答に `total_count`/`offset`/`limit`。`query_id` は閲覧できる課題クエリのみ(不可は 403)。見えない CF のフィルタは A1-37(2026-09-24)で無視するよう修正。従来の簡易パラメータはそのまま。`include` の追加(`attachments` 等)と `sort` の多段は未対応 | `ListQueryString::fromRequestInput()` で `activeFilterKeys` 等を解釈し `QueryFilterEngine` を API 一覧にも適用(または Redmine 形式の `f[]` を変換)、`limit`/`offset` を受け付ける | A1-17(フィルタ拡張)と同じエンジンを使うため後続が有利 | M | REST API「Issues」 |
| A1-33 | REST API `PUT /projects/{id}` での `default_version_id` / `default_assigned_to_id` の更新(Redmine の `safe_attributes`、`project.rb:839-841`) | A1-23 で読み取り(`default_version`/`default_assignee`)のみ実装。`UpdateProjectRequest` に規則なし | 両フィールドを追加し、Web フォームと同じ選択肢(オープンな共有バージョン/割当可能メンバー)で検証 | A1-23 完了が前提 | S | REST API「Projects」 |
| 3 | A1-24 | (取り下げ)トラッカーの `is_in_chlog` | — | Redmine 7.0.0 で廃止済み(`db/migrate/20210728131544_drop_is_in_chlog_column.rb`、`app/` に使用箇所なし)。作業不要 | 機械照合が古いマイグレーションの `add_column` だけを見て、後続の `drop` を見落としていた | — | Trackers 節(C-18) |
| 4 | A3-04 | — | S | done(2026-09-20) |
| 5 | A3-10 | — | S | done(2026-09-20) |
| 6 | A4-06 | — | S | done(2026-09-20) |
| 7 | A4-15 | — | S | done(2026-09-20) |
| 8 | A5-16 | — | S | done(2026-09-20) |
| 8a | A5-16b | A5-16 | S | done(2026-09-20、別のスイッチ・既定オフで承認) |
| 9 | A5-02 / A9-05 | — | S | done(2026-09-20) |
| 10 | A5-03 | — | S | done(2026-09-20) |
| 11 | A1-09 | — | S | done(2026-09-20) |
| 11a | A1-35 | A1-09 | S | done(2026-09-20) |
| 11b | A1-34 | A1-09 | S〜M | done(2026-09-24、子孫の再帰削除・工数の合計/処理/付替先の除外を子孫まで・確認に「N件のサブタスクも削除されます。」・一括削除の親子同時選択・API。Redmine と同じく見えない/削除権限のない子孫も削除。残る親の属性を再計算。工数必須時の nullify 拒否は A1-40) |
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
| 22 | A3-07 | — | S | done(2026-09-20) |
| 23 | A3-05 | — | S | done(2026-09-20) |
| 24 | A1-13 | — | S | done(2026-09-20) |
| 25 | A1-14 | — | S | done(2026-09-20) |
| 26 | A11-13 | — | S | done(2026-09-20) |
| 27 | A11-11 | A11-13 | S | done(2026-09-20) |
| 28 | A11-01 | — | S | done(2026-09-20) |
| 28a | A11-17 | A11-01, A1-17 | M | done(2026-09-24、`include` の追加と `sort` の多段・任意列は対象外。既定の件数は 15→25) |
| 29 | A11-02 | — | S | done(2026-09-20) |
| 30 | A11-03 | — | S | done(2026-09-20) |
| 31 | A11-04 | — | S | done(2026-09-20) |
| 32 | A11-08 | — | S | done(2026-09-20) |
| 33 | A11-09 | — | S | done(2026-09-20) |
| 34 | A11-14 | — | S | done(2026-09-20) |
| 35 | A6-02 | (取り下げ)@mention の News コメント・フォーラム投稿への拡張 | — | Redmine 7.0.0 で `acts_as_mentionable` を持つのは Issue(`description`)・Journal(`notes`)・WikiContent(`text`) のみ(`app/models/{issue,journal,wiki_content}.rb`)。News コメントとフォーラム投稿は対象外 | 作業不要(チェックリストが「次点・未着手」と誤って書いていた) | — | Watchers「作成者/担当者の自動Watch・@mention」(C-22) |
| 36 | A6-03 | — | S | done(2026-09-20) |
| 37 | A6-04 | — | S | done(2026-09-20、**描画のみ・通知は未達**: メール化される Journal に添付/関連が乗らない。実現は A6-04b) |
| 37a | A6-04b | A6-04 | M | done(2026-09-20、編集+添付は Redmine の 1 通に対し 2 通) |
| A6-04b | 添付・関連の変更を実際にメール通知する(編集と同じ Journal に添付を含める、関連の追加/削除の Journal 通知) | A6-04 でメール本文は描画できるようにしたが、`IssueNotificationMail` に渡る Journal は `IssueService::update()` の `$detailsJournal` のみ。添付は `journalizeAttachment()`(`issues/form.blade.php:589`、`Api/V1/IssueController.php:295`)が update 後に別 Journal で記録し、`journalizeRelation()` も通知しない | `update()` が添付(Media 追加を update より前に行うか、添付一覧を引数で受ける)を同じ Journal の `attachment` 詳細に含め、メール送信条件にも加える。関連の追加/削除は独立した通知(Webhook を発火させない専用イベントまたは通知の直接送信)にする | **`IssueUpdated` を関連/添付で発火すると Webhook `issue.updated` も飛ぶため、専用の通知経路が必要**。フォームと API の 3 呼び出し元の順序変更を伴う | M | Journal「メール通知(課題)」 |
| 38 | A5-11 / A6-07 | — | S | done(2026-09-20、default_users_hide_mail は A4-13 待ち) |
| 39 | A12-01 | — | S | done(2026-09-20) |
| 40 | A12-04 | — | S | done(2026-09-20) |
| 41 | A4-08 | — | S | done(2026-09-20) |
| 42 | A4-04 | — | S | done(2026-09-20) |
| 43 | A4-05 | — | S | done(2026-09-20) |
| 45 | A7-09 | — | S〜M | done(2026-09-20、過去のファイルのアップロード者は不明のまま) |
| 46 | A11-06 | A7-09 | S | done(2026-09-20) |
| 47 | A11-05 | — | S | done(2026-09-20) |
| 48 | A2-01 | — | S | done(2026-09-20) |
| 49 | A2-07 | — | S〜M | done(2026-09-20、ページ分割が既にある一覧のみ。残りは A2-07b) |
| 49b | A2-07b | A2-07 | M | done(2026-09-20、文書一覧は対象外: C-30) |
| 50 | A7-12 | — | S | done(2026-09-20) |
| 51 | A9-06 | A2-07, A7-12 | S | done(2026-09-20) |
| 52 | A5-09 | — | S〜M | done(2026-09-20) |
| 53 | A8-01 | A5-09 | S | done(2026-09-20) |
| 54 | A8-03 / A13-03 | — | S | done(2026-09-20) |
| 55 | A5-12 / A10-02 | — | S〜M | done(2026-09-20、エンコーディング/表示件数は A5-12b) |
| 55b | A5-12b | A5-12 | S | done(2026-09-20、エンコーディングは全体設定のみ) |
| 56 | A10-01 | — | S〜M | done(2026-09-20、エンコーディングのみ。URL/資格情報は A10-01b) |
| 56b | A10-01b | A10-01 | M | done(2026-09-24、設計メモ案B: SVNのみ URL+ログイン/パスワード(暗号化)、`scm.allowed_hosts`、権限`manage_remote_repositories`。Git のリモート(案C)・`root_url`/`extra_info` は対象外) |
| 57 | A10-05 / A5-07 | — | S | done(2026-09-20、bulk_download_max_size は A7-10 と同時) |
| 58 | A10-03 / A13-06 | — | S | done(2026-09-20、リポジトリ作成 API は対象外) |
| 59 | A7-10 | — | S | done(2026-09-20、フォーラム投稿の画面リンクなし) |
| 60 | A7-11 | — | S | done(2026-09-20) |
| 61 | A7-05 / A7-06 / A13-04 | — | S | done(2026-09-20) |
| 62 | A7-08 / A13-02 | — | S | done(2026-09-20、A7-08 のうち引用返信/返信ウォッチは対象外=C-24) |
| 63 | A13-01 | — | S〜M | done(2026-09-20) |
| 64 | A5-04 / A14-06 | — | S | done(2026-09-20) |
| 66 | A9-08 | — | S | done(対象外: Redmine 7.0 に無い, 2026-09-20) |
| 67 | A5-15 | — | S | done(2026-09-20) |
| 68 | A1-15 | — | S | done(2026-09-20、グローバル一覧の列は未対応) |
| 69 | A5-01 | — | S | done(2026-09-20) |
| 70 | A6-06 | A5-01 | S | done(2026-09-20、対象は課題通知のみ) |
| 71 | A4-09 | — | S〜M | done(2026-09-20) |
| 72 | A1-31 | A4-09 | S | done(2026-09-20、query_id 指定は未対応) |
| A1-32 | バージョンフォームの「既定バージョンにする」チェックボックス(`versions/_form.html.erb:14`、`Version#default_project_version`)と、バージョン一覧・設定画面での既定バージョン表示 | A1-23 で `projects.default_version_id` は実装済み。`versions/form.blade.php` にチェックボックスなし | チェックで `projects.default_version_id` を更新、外すと(自分が既定なら)NULL。一覧に既定マークを表示 | A1-23 完了が前提 | S | Versions「Wikiページ紐付け・既定バージョン設定」 |
| 73 | A4-11 | — | S | done(2026-09-20、アップロード式アバターは対象外) |
| 74 | A14-04 | — | S | done(2026-09-20、スケジューラ稼働状況は未対応) |
| 75 | A14-05 | — | S | done(2026-09-20) |
| 76 | A2-09 | — | S | done(2026-09-20、数値カスタムフィールドの合計は未対応) |
| 77 | A8-07 | — | S | done(2026-09-20、入力側の 1:30 は未対応) |
| 78 | A7-07 | — | S | done(対象外: Redmine 7.0 に無い, 2026-09-20) |
| 78b | A7-15 | — | S | done(2026-09-20) |
| 79 | A7-14 | — | S〜M | done(2026-09-20、サムネイルのリンク先は変更なし) |
| 80 | A3-12 | — | S〜M | done(2026-09-20) |
| 81 | A11-15 | — | S | done(2026-09-20、GET のみ) |
| 81a | A1-01 | — | S | done(2026-09-20) |
| 81b | A1-02 | — | S | done(2026-09-20) |
| 81c | A1-03 | — | S〜M | done(2026-09-20) |
| 81d | A4-07 | — | S | done(2026-09-20) |
| 81e | A5-08 | — | S | done(2026-09-20、wiki_compression は対象外、JS は未検証) |
| 81f | A4-10a | — | S | done(2026-09-20、セレクト/メール/APIは対象外) |
| **段 2: M 項目(基盤になるものを先に)** | | | | |
| 82 | A4-13 | — | M | done(2026-09-20、残りは A4-13b) |
| 82b | A4-13b | A4-13 | S | done(2026-09-24、A2-03b で `default_project_query` を配線。他の 3 項目は A6-05・A1-04・A9-07 で配線済み) |
| 83 | A4-14 | A4-13 | S | done(2026-09-20、記載の「ウォッチャーにならない」は既存実装済み=個人設定化のみ) |
| 84 | A6-05 | A4-13 | S | done(2026-09-20) |
| 85 | A9-07 / A14-07 | A4-13 | S | done(2026-09-20) |
| 86 | A1-04 | A4-13 | M | done(2026-09-20、作業時間タブは既存の工数欄で代替) |
| 87 | A2-02 | A4-13 | S〜M | done(2026-09-20) |
| 88 | A1-16 | A1-15 | M | done(2026-09-20、id/project 列は対象外) |
| 89 | A3-06 | — | M | done(2026-09-20、課題一覧と工数合計のみ。ガント/カレンダー/レポート/フィルタは未対応) |
| 90 | A1-17 | A1-16, A3-06 | L | done(2026-09-24、推奨案 B で 90a〜90d に分割して実施。`subproject_id` は A1-17e、演算子の残りは A1-36。追補: フィルタ追加をボタン列から Redmine 同様の optgroup 付きセレクトに変更し、ID 系フィルタはカンマ区切り入力に) |
| 90a | A1-17a | A1-16 | S〜M | done(2026-09-24、演算子 `*~`/`^`/`$` と「トラッカーで無効な標準項目のフィルタを隠す」は A1-36) |
| 90b | A1-17b | A1-17a | S〜M | done(2026-09-24、演算子 `=p`/`=!p`/`!p`/`*o`/`!o` も追加。ID の複数入力は A1-17 追補で UI も対応) |
| 90c | A1-17c | A1-17a | M | done(2026-09-24、`author.group`/`author.role` はキーを `author_group`/`author_role` に。匿名ユーザーの選択肢は無し) |
| 90d | A1-17d | A1-17a | S〜M | done(2026-09-24、`subproject_id` は A3-06b 未了のため A1-17e に分離) |
| 90e | A1-36 | A1-17a | S | done(2026-09-24、演算子 `*~`/`^`/`$` と Redmine の語分割を全テキストフィルタに、検索・テキストフィルタとも PostgreSQL で ILIKE。全トラッカーが無効にした標準項目のフィルタを除外(親課題は Redmine 同様に残す)。無効項目の列の除外は A1-45) |
| 90e2 | A1-45 | A1-36 | S | done(2026-09-24、課題一覧(プロジェクト・横断)の列・並べ替え・グループ化の候補と表・CSV/PDF から、全トラッカーが無効にした標準項目の列を除外。予定工数なら合計予定工数・残り工数も。親課題は Redmine 同様に残す) |
| 90h | A1-37 | A1-17 | S | done(2026-09-24、フィルタ・列・並べ替え・グループ・CSV/PDF・Atom・REST・マイページ。否定演算子は Redmine と違い見えない行を含めない。工数一覧の課題/プロジェクト CF 列は元々無い) |
| 90f | A3-06b | A3-06 | **done(2026-09-24)**。`SubprojectScope::projectsForIssues`/`projectsForTimeEntries`(アーカイブ済みの子孫は除外)をガント(行はプロジェクトの木順、マイルストーンも対象プロジェクト分、行のリンクは課題自身のプロジェクト)・カレンダー(課題とバージョン)・工数一覧/レポート(`visibleToAcrossProjects`、フィルタの課題条件もサブプロジェクトを見る。レポートはプロジェクト軸を追加)・課題 Atom・`GET /projects/:id/issues.json`・マイページの保存クエリブロックに適用。活動は `with_subprojects` の指定が無ければ設定に従う。課題レポートは A3-06 で対応済み。工数一覧でサブプロジェクトの工数は表示・編集リンクのみで、選択・一括編集・行削除はこのプロジェクト自身の工数に限る(親の一覧からの一括編集が工数を親へ移さないように)。ロードマップ/バージョンと、ガントのサブプロジェクト見出し行は A3-14。`subproject_id` フィルタは A1-17e | done(2026-09-24、ガント・カレンダー・工数一覧/レポート・活動の既定・課題 Atom・REST の課題一覧・マイページのクエリブロック。課題レポートは既存。ロードマップとガントのサブプロジェクト見出し行は A3-14、フィルタは A1-17e) |
| 90f2 | A3-14 | A3-06b | S〜M | done(2026-09-24、ロードマップの「サブプロジェクト」切替(`with_subprojects`、既定は `display_subprojects_issues`)で閲覧できるサブプロジェクトのバージョンも表示。ガントはサブプロジェクトを含むときプロジェクトごとの見出し行+課題+マイルストーン。共有バージョン・完了済みの表示・トラッカー選択・バージョンごとの課題一覧は A3-14b) |
| 90f3 | A3-14b | A3-14 | S〜M | todo |
| 90g | A1-17e | A3-06b | **done(2026-09-24)**。`SubprojectScope::filter()`(キー `subproject_id`、サブプロジェクトを持つプロジェクトの一覧にだけ出る。選択肢は閲覧できるアーカイブ以外の子孫)。有効な `subproject_id` があれば `projectsForIssues`/`projectsForTimeEntries` が設定に関係なく子孫を取り込み、フィルタの条件は `project_id` を絞るだけ(=/いずれか: 本体+選んだもの、!/いずれにも: 本体+選ばなかったもの、未設定(`!*`): 本体のみ、設定済み(`*`): すべて)。見えない・アーカイブ済み・無関係なプロジェクトを指定しても何も増えない。課題一覧・ガント・カレンダー・課題 Atom・REST(`subproject_id=*`、`f[]=subproject_id`)・マイページのクエリブロック・工数一覧/レポートで有効 | done(2026-09-24、課題一覧・ガント・カレンダー・Atom・REST・マイページのクエリブロック・工数一覧/レポート。工数側は A2-08 の保留分) |
| 91 | A2-08 | A1-17 | M | done(2026-09-24、`subproject_id` は A1-17e と同じく A3-06b 待ち、カスタムフィールドのフィルタと課題/プロジェクト側の関連列は A2-08b) |
| 91a | A2-08b | A2-08 | **done(2026-09-24)**。フィルタ: `TimeEntryExtraFilterFields::customFieldFields()` が「フィルタとして使用」の工数 CF(`cf_N`)・課題 CF(`issue_cf_N`、閲覧できる課題だけを見る)・プロジェクト CF(`project_cf_N`)・ユーザー CF(`user_cf_N`)を出す。各 CF は対象プロジェクトのうち閲覧ロールで見えるところだけを条件にし(`CustomFieldVisibility`、A1-37 と同じく否定演算子も見えない行を含めない)、どこでも見えない CF は出さない。ユーザー CF は本アプリでは管理者しか値を見られないため管理者にだけ出す。列: 新設の `App\Support\Query\TimeEntryColumns` が `issue_tracker`/`issue_parent`/`issue_status`/`issue_category`/`issue_fixed_version`(閲覧できる課題のみ値)、課題 CF(`issue_cf_N`)、プロジェクト CF(`project_cf_N`)を工数一覧(プロジェクト/横断)に追加。工数 CF 列もロール制限のあるものは閲覧できるプロジェクトの行だけ値を出す(従来は横断一覧では出さなかった)。新しい列は並べ替え・グループ化の対象外 | done(2026-09-24、CF フィルタ 4 種と課題属性・課題 CF・プロジェクト CF の列。ユーザー CF フィルタは管理者のみ。新列は並べ替え対象外) |
| 92 | A1-25 | — | M | done(2026-09-20、API の status_id は未対応) |
| 93 | A1-06 | A1-25 | M | done(2026-09-20、カスタムフィールドの一括編集は A1-06b) |
| 93b | A1-06b | A1-06 | M | done(2026-09-20、右クリックメニューのCFサブメニューは対象外) |
| 94 | A1-05 | A1-06 | M | done(2026-09-20、カスタムフィールド/ウォッチャー/単一課題向け項目は A1-05b) |
| 94b | A1-05b | A1-05 | S | done(2026-09-20、CFサブメニュー・ウォッチャー追加・IDフィルタは未対応) |
| 95 | A4-16 | A1-05 | S | done(2026-09-20) |
| 96 | A8-06 | A1-05 | S | done(2026-09-20、カスタムフィールドのサブメニューは未対応) |
| 97 | A1-07 | — | M | done(2026-09-20、一括コピーのみ。単一コピー画面は A1-08) |
| 98 | A1-08 | A1-07 | M | done(2026-09-20) |
| 99 | A1-10 | — | M | done(2026-09-20、A1-10a。承認: 推奨案。フィルタ等は A1-10b、複数値等は A1-10c) |
| 99b | A1-10b | A1-10 | S〜M | done(2026-09-20) |
| 99c | A1-10c | A1-10 | S | done(2026-09-20、複数値の入力欄は選択肢型のみ) |
| 100 | A1-11 | — | M | done(2026-09-20) |
| 101 | A1-12 | — | S〜M | done(対象外: Redmine 7.0 に無い, 2026-09-20) |
| 102 | A1-18 | — | M | done(2026-09-20、ガント/表示は暦日のまま) |
| 103 | A1-19 | A1-18 | M | done(2026-09-20) |
| 104 | A1-22 | — | S | done(2026-09-20、更新日時は既存の updated_at を使用) |
| 105 | A1-27 | — | M | done(2026-09-24、A1-27a〜c。匿名の非公開課題は A1-38、グループ担当の閲覧は A1-39。性能フォロー: 可視性ルールをリクエスト内メモ化) |
| 105a | A1-27a | — | S | done(2026-09-24、`roles.settings`・`AuthorizationService::allowedTrackerIds()`・ロール編集画面の権限×トラッカー表。判定への適用は b/c) |
| 105b | A1-27b | A1-27a | M | done(2026-09-24、view_issues を全読み取り経路に適用。監査表はコミットメッセージ。匿名の非公開課題は A1-38、グループ担当は A1-39) |
| 105c | A1-27c | A1-27a | M | done(2026-09-24、ポリシー・トラッカー選択肢(新規/編集/一括編集/右クリック/移動/コピー/子課題コピー)・REST 作成/更新・インポート・受信メール) |
| 105d | A1-38 | — | S | done(2026-09-24、`issueVisibilityRules()` で匿名の閲覧範囲を「公開課題のみ」に集約。ロール編集画面で Anonymous の閲覧範囲を非表示) |
| 105e | A1-39 | A1-20a | S | done(2026-09-24、default/own の担当者条件に所属グループ。issue_group_assignment の設定に関わらず Redmine と同じ) |
| 105f | A1-40 | A1-34 | S | done(2026-09-24、Web(詳細/一括/右クリック)・REST とも nullify を拒否し選択肢から除外、この設定時の既定は destroy。設定がなければ既定 nullify のまま) |
| 105g | A1-41 | A1-38 | S〜M | done(2026-09-24、プロジェクトのカレンダー/ガント/検索/活動/ロードマップと全 Atom(`atom.key` がログイン不要時はゲストを通す)。全体の画面・プロジェクト概要などは A1-44。検索の CF・課題の更新 Atom の CF 詳細を役割で制限) |
| 105k | A1-44 | A1-41 | S〜M | todo |
| A1-45 | 全トラッカーが無効にした標準項目を課題一覧の列の候補からも外す(`issue_query.rb` の `available_columns` で `disabled_core_fields` の列を除外。予定工数なら合計予定工数・残工数も) | **done(2026-09-24)**。`IssueFilterFieldRegistry::coreColumnsDisabledByEveryTracker()`(フィルタと同じ `Tracker.disabled_core_fields(trackers)` の判定。`estimated_hours` なら `total_estimated_hours`/`estimated_remaining_hours` も。親課題は Redmine の `parent_issue_id` が列名 `parent` と一致しないため残る)と `rolledUpTrackers()`(サブプロジェクト込みのトラッカー)。プロジェクトの課題一覧は `nativeColumns`/`availableColumns`/`sortableColumns` から除き、選んだ列は `shownColumns`(利用できる列だけ、順序維持 = Redmine の `inline_columns`)で表・CSV・PDF に出す。グループ化の「優先度」「担当者」も無効なら出さず、保存済みのグループ化は無視。横断一覧は閲覧できるプロジェクトのトラッカーで同じ判定。保存クエリの `column_names` はそのまま(トラッカー設定を戻せば再表示)。テスト: `TrackerDisabledCoreFieldsTest` | `IssueFilterFieldRegistry` の除外と同じ判定を列の候補に適用 | A1-36 の後 | S | クエリ「列選択」 |
| 105h | A1-42 | A1-34 | S | done(2026-09-24、`Project` の deleting で自プロジェクトとサブプロジェクトの課題を `deleteMany(Destroy)`。別プロジェクトの子孫・工数・添付も削除、`Project::delete()` をトランザクション化) |
| 105i | A1-43 | A1-20 | S | done(2026-09-24、`projects.default_assigned_to_group_id`/`issue_categories.assigned_to_group_id`。プロジェクト設定・カテゴリのフォームと REST。新規課題フォームの既定担当に反映(グループ割当オフ・割り当て不可のグループは適用しない)。REST/CSV/メールでの課題作成に既定担当を適用しないのは既存どおり) |
| 105j | A6-08 | A1-20 | S | done(2026-09-24、更新の Journal にある担当者の旧値(ユーザー/グループのメンバー)を関係者に加える。各自の通知設定・閲覧可否で絞る) |
| 105l | A1-46 | A1-43 | S | todo |
| 106 | A1-28 | — | M〜L | done(2026-09-24、A1-28a〜c。工数/ユーザーのインポートの CSV 読み取りは A1-28d) |
| 106a | A1-28a | — | S | done(2026-09-24、`CsvReader`(fgetcsv、引用符内の改行、BOM)を課題インポートのジョブと列見出しの読み取りに適用。工数/ユーザーのインポートは A1-28d) |
| 106b | A1-28b | A1-28a | M | done(2026-09-24、`relevantCustomFields(?User)`・`IssueService` の CF 保存/比較を作成者/実行者で・マッピング画面の CF 列(名前一致の自動割当)・`CustomField::valueFromKeyword()`(受信メールと共通)・必須/形式の検証で行エラー。ワークフローの必須/読み取り専用は従来どおりインポートに未適用) |
| 106c | A1-28c | A1-28b | M | done(2026-09-24、`unique_id`・親の前方参照(依存順に作成)・重複/参照先なし/親の失敗/循環は行エラー・関連列 7 種(遅延付き)を `IssueService::addRelation()` で。一意なID 列が無いときの数字は従来どおり既存課題の番号(Redmine の行番号参照は採らない)。関連の検証は画面/API と `IssueRelationTarget` に共通化) |
| 106d | A1-28d | A1-28a | **done(2026-09-24)**。`ImportTimeEntriesJob`・`ImportUsersJob` と両画面の見出し読み取りを `CsvReader::read()`/`header()` に置き換え(引用符内の改行と BOM 付き見出しを正しく読む)。`ImportUsersJob::readCsv()` は削除。ユーザーインポートの空行は従来の読み飛ばしをやめ、課題インポートと同じく失敗行として数える | done(2026-09-24、工数/ユーザーのジョブと見出し読み取りを `CsvReader` に。ユーザーの空行は読み飛ばしから課題と同じ失敗行へ) |
| 107 | A1-29 | — | S〜M | done(2026-09-20) |
| 108 | A2-03 | — | M | done(2026-09-24、A2-03a〜c。API は A11-18) |
| 108a | A2-03a | — | M | done(2026-09-24、保存クエリ/既定クエリは A2-03b、ボード表示と設定は A2-03c) |
| 108b | A2-03b | A2-03a | S〜M | done(2026-09-24、個人設定 `default_project_query` も配線=A4-13b 完了) |
| 108c | A2-03c | A2-03a | S | done(2026-09-24、表示形式は保存クエリには保存しない) |
| 108d | A11-18 | A2-03a | S | done(2026-09-24、プロジェクト一覧と同じ可視範囲にフィルタ(f[]/op[]/v[] と短縮形)と limit/offset/page、`total_count`。並びは従来どおり名前順) |
| 109 | A2-05 | A2-03 | S〜M | done(2026-09-24、保存クエリは A2-05b、アーカイブの連鎖は A3-13。クローズ/再オープン・コピーは Redmine の管理メニューにも無い) |
| 109b | A2-05b | A2-05 | S | done(2026-09-24、`QueryType::ProjectAdmin`: 管理のプロジェクト一覧で保存・読込、全管理者が見る/非管理者は見えない。REST `/queries?type=project_admin` も管理者のみ) |
| 109c | A3-13 | — | S〜M | done(2026-09-24、概要画面・管理一覧・REST の 4 操作を `Project::archive()`/`unarchive()`/`close()`/`reopen()` に集約し、Redmine と同じく子孫へ連鎖。アーカイブは外部の課題が子孫のバージョンを対象にしていれば拒否) |
| A3-14 | ロードマップとバージョン一覧のサブプロジェクト(`versions_controller.rb` の `with_subprojects`、既定は `display_subprojects_issues`。`rolled_up_versions` を並べ、課題はサブプロジェクト分も数える)と、ガントでのサブプロジェクト見出し行(Redmine の `Gantt#render` は子プロジェクトごとに行を立てる) | **done(2026-09-24)**。ロードマップ(`versions/roadmap.blade.php`)に「サブプロジェクト」チェックボックス(子孫があるときだけ。URL `with_subprojects=1/0`、未指定は設定 `display_subprojects_issues`)。オンでは `SubprojectScope::projectsForIssuesWhen()`(アーカイブ済みと `view_issues` の無い子孫を除く = Redmine の `rolled_up_versions.visible`)のバージョンも期日→名前→ID 順に並べ、他プロジェクトのバージョンは「プロジェクト名 - バージョン名」、課題数のリンクはそのプロジェクトの課題一覧へ。進捗・件数は従来どおりバージョンの閲覧できる全課題(Redmine の `visible_fixed_issues` と同じ)。ガント(`gantt/index.blade.php`)はサブプロジェクトを含むとき横断ガントと同じ行構造(プロジェクト見出し→そのプロジェクトの課題の木→マイルストーン、別プロジェクトの親を持つ課題はそのプロジェクトの根、件数上限は全行で数える)。PDF/PNG も同じ行。単独プロジェクトは従来どおり見出しなし。テスト: `RoadmapTest`・`SubprojectScreensTest` | ロードマップに「サブプロジェクト」チェックボックスと `rolled_up_versions`。ガントは横断ガントの行構造(プロジェクト行+課題行)を流用 | A3-06b で分離 | S〜M | Roadmap / ガント |
| A3-14b | ロードマップの残り: 共有バージョン(`shared_versions`。他プロジェクトのバージョンは自プロジェクト/サブプロジェクトの課題が対象にしているときだけ)、「完了したバージョン」の表示切替(`completed=1`)、サイドバーのトラッカー選択(`tracker_ids[]`、既定は `is_in_roadmap`)、バージョンごとの課題一覧(`@issues_by_version`) | A3-14(2026-09-24)でサブプロジェクトのみ対応。ロードマップは自プロジェクト(+サブプロジェクト)のバージョンだけ、完了済みは常に非表示、トラッカーは `is_in_roadmap` 固定、課題は件数のみ | `versions_controller.rb#index` に合わせて `versions/roadmap.blade.php` を拡張 | 共有バージョンは `Project::sharedVersions()` を流用 | S〜M | Roadmap |
| 109a | A2-10 | A2-03 | S〜M | done(2026-09-24、フィルタ「プロジェクト」(と親プロジェクト)の `mine`/`bookmarks`、列「最終活動日」(`LastActivityProvider`)、`projects.csv`、ボードの Markdown 説明とカスタムフィールド、保存クエリの表示形式(`queries.options`)) |
| 110 | A2-04 | — | M | done(2026-09-20、保存クエリ・ページング・ユーザーのCFは未対応) |
| 111 | A2-06 | — | S〜M | done(2026-09-20、グラフは対象外) |
| 112 | A3-01 | — | M | done(2026-09-20) |
| 113 | A3-02 / A3-11 / A13-05 | A3-01 | M | done(2026-09-20、save_queries と search_project は A13-05b) |
| 113b | A13-05b | A13-05 | S〜M | done(2026-09-20、ガントの保存クエリは対象外) |
| 114 | A3-03 | — | M | done(2026-09-24、A3-03a/b。REST の `inherited` は A11-16) |
| 114a | A3-03a | — | M | done(2026-09-24、親のメンバー変更の伝播・継承行の保護・移動/新規作成は A3-03b) |
| 114b | A3-03b | A3-03a | M | done(2026-09-24) |
| 115 | A11-16 | A3-03 | S | done(2026-09-24) |
| 115a | A3-03c | A3-03b | S | done(2026-09-24、ロール削除は Redmine どおり使用中なら拒否=後始末は不要。既存のロールなしメンバーは残す) |
| 116 | A3-09 | — | M | done(2026-09-20) |
| 117 | A4-01 | — | M | done(2026-09-20、must_change_passwd と通知は未対応) |
| 118 | A4-02 | — | M | done(2026-09-20) |
| 119 | A4-03 | — | M | done(2026-09-20) |
| 120 | A4-12 / A14-02 | — | M | done(2026-09-24、承認: 設計メモの推奨案。A4-12a〜c に分割。残りは A4-12d) |
| 120a | A4-12a | — | S | done(2026-09-24、既定のゾーンは作成時の複写ではなく未設定のユーザー全員に読み取り時に適用。管理画面のユーザー編集にはタイムゾーン欄なし=言語と同じ) |
| 120b | A4-12b | A4-12a | M | done(2026-09-24、画面群ごとに 4 コミット。Atom の `<updated>`・REST API・メール本文(日時なし)・ガントの月見出し(`Y-m`)は対象外。活動の工数は Redmine の作成日時でなく作業日で並ぶまま) |
| 120c | A4-12c | A4-12a | S | done(2026-09-24、空の設定は Redmine の「言語に合わせる」ではなく従来どおり ISO/24 時間制。日付型カスタムフィールドの値と工数レポートの期間見出しは A4-12d) |
| 120d | A4-12d | A4-12c | S | done(2026-09-24、日付型カスタムフィールドの表示値・履歴、工数レポートの日/月見出し、ガントの月見出し、カレンダーの見出し(日付形式を選んだときだけ)、管理画面のユーザー編集に言語とタイムゾーン) |
| 121 | A6-01 | — | M | done(2026-09-20、issue_status_updated 等の細分は未対応) |
| 122 | A7-01 | — | M | done(2026-09-20、インライン利用と一部オプションは未対応) |
| 123 | A8-02 | — | M | done(2026-09-20、API/一括編集/横断一覧は A8-02b) |
| 123b | A8-02b | A8-02 | S〜M | done(2026-09-20、API は A11-10 で対応済み) |
| 124 | A8-04 | A8-02 | M | done(2026-09-20) |
| 125 | A8-05 | — | S〜M | done(2026-09-20、一括編集は対象外) |
| 126 | A9-01 | — | M | done(2026-09-24、A9-01a〜c) |
| 126a | A9-01a | — | M | done(2026-09-24、行数上限はプロジェクト見出し・マイルストーンも数える。祖先プロジェクトは閲覧できるものだけ見出しに出す) |
| 126b | A9-01b | A9-01a | M | done(2026-09-24、GD+同梱 IPAGothic。週/日の見出し(zoom)と遅延部分の赤は対象外。横断ガントの PDF も追加) |
| 126c | A9-01c | A9-01a | S | done(2026-09-24、L 字の 2 本の div で近似。矢印なし) |
| 127 | A9-03 | — | M | done(2026-09-20、max_occurs とブロック設定は A9-03b) |
| 127b | A9-03b | A9-03 | M | done(2026-09-20、カレンダー等の設定は対象外) |
| 128 | A9-04 | — | M | done(2026-09-20、ページング/件数上限は A9-04b) |
| 128b | A9-04b | A9-04 | M | done(2026-09-20、権限確認のクエリはプロジェクト数に比例したまま) |
| 129 | A11-07 | — | M | done(2026-09-20、send_information/generate_password は未対応) |
| 130 | A11-10 | — | M | done(2026-09-20、include=attachments の共通化は対象外) |
| 131 | A12-02 / A13-07 | — | M | done(2026-09-20、ユーザー自身の管理画面は A12-02b) |
| 131b | A12-02b | A12-02 | S〜M | done(2026-09-20) |
| 132 | A12-03 | — | M | done(2026-09-24、承認: 設計メモの推奨案。A12-03a/b) |
| 132a | A12-03a | — | S | done(2026-09-24、マニフェスト検証・autoload・有効なものだけ読み込み・壊れたものはログして読み飛ばし。ルートキャッシュ・Octane は対象外) |
| 132b | A12-03b | A12-03a | S | done(2026-09-24、一覧・有効/無効・読み込み失敗の理由・読めないフォルダの表示) |
| 133 | A12-05 | — | M | done(2026-09-20、保存前フックは対象外) |
| 134 | A12-06 / A5-10 | — | M | done(2026-09-20、キーワード許可リスト等は A12-06b) |
| 134b | A12-06b | A12-06 | M | done(2026-09-20) |
| 134c | A12-06c | A12-06b | M | done(対象外: 実装しない案で承認, 2026-09-20。docs/design/gap-A12-06c.md) |
| 134d | A12-06d | A12-06c | S | done(2026-09-20) |
| 135 | A5-06 / A14-03 | — | M | done(2026-09-24、A5-06a/b は既存のトークン層で代替。A5-06 と A14-03 の 2 コミット) |
| 135a | A5-06a | — | M | done(既存, 2026-09-24、トークン層 26bcfe0 とレイアウト・共通コンポーネントの移行 79cd84f で代替。`bg-white`→`bg-surface` は A5-06 で) |
| 135b | A5-06b | A5-06a | M〜L | done(既存, 2026-09-24、全画面のクラス移行は 79cd84f で済み) |
| 135c | A5-06 | A5-06a | S | done(2026-09-24、ダークテーマのトークン値・`bg-surface`・ガードテスト。コントラストは要目視) |
| 135d | A14-03 | A5-06 | S | done(2026-09-24、設定 `ui_theme`(light 既定/dark/system)と個人設定の上書き(「サイトの既定」)、`<html data-theme>`。ダークのコントラストは要目視) |
| **段 3: L 項目(設計メモ→`blocked(要承認)`→承認後に実装)** | | | | |
| 136 | A4-10b | A4-10a | L | done(2026-09-24、承認: 設計メモの推奨案。A4-10b-1〜3 に分割) |
| 136a | A4-10b-1 | A4-10a | S | done(2026-09-24、姓・名の列と全 11 形式。`name` は姓・名が両方あれば「名 姓」に自動同期。自己登録フォームにも姓・名) |
| 136b | A4-10b-2 | A4-10b-1 | S〜M | done(2026-09-24、選択肢・絞り込みの選択肢・担当者名・メール・APIの作成者/担当者・ユーザー詳細・アバターの頭文字。並び順は `name` のまま) |
| 136c | A4-10b-3 | A4-10b-1 | S | done(2026-09-24、LDAP の姓・名の属性、CSV インポートの姓・名、REST API の `firstname`/`lastname`(ユーザー・マイアカウント)) |
| 136d | A4-10b-4 | A4-10b-2 | S | done(2026-09-24、`User::scopeSortedByFormat()`/`User::sortByFormat()`。担当者・ウォッチャー・メンバー/グループの追加候補・絞り込み・工数のユーザー・カスタムフィールド(ユーザー)・既定の担当者・コミッター・Webhook の所有者・課題レポートの作成者。管理画面の一覧と REST の既定順は名前のまま) |
| 136e | A4-10b-5 | A4-10b-3 | S | done(2026-09-24、`User::scopeMatchingName()`(Redmine の `Principal.like`)。LDAP の再ログインで姓・名を消すのは、姓・名の属性を設定したソースだけ) |
| 137 | A1-20 | — | L | done(2026-09-24、A1-20a/b。設計メモ案 A、`issue_group_assignment` 既定オフ) |
| 137x | A1-20a | — | M | done(2026-09-24、列・設定・担当者候補・フォーム/一括編集/右クリック/REST 書き込み・表示。通知・フィルタ・並べ替え等は A1-20b) |
| 137y | A1-20b | A1-20a | M | done(2026-09-24、グループへの自動ウォッチは Redmine どおり行わない。以前の担当者への通知は A6-08、既定担当者のグループ化は A1-43) |
| 137a | A5-05 | A1-20 | S | done(2026-09-24、3 形式。編集時の「関係者」optgroup は未対応) |
| 138 | A14-01 | — | L | done(2026-09-20、A14-01a。承認: 推奨案 A。画面の置換は A14-01b 以降)|
| 138a | A14-01a | A14-01 | S | done(2026-09-20) |
| 138b | A14-01b | A14-01a | M×n | done(2026-09-23、A14-01b1: 共通レイアウトと課題画面。既定の言語を ja に変更。残りは A14-01b2〜b8) |
| 138c | A14-01b2 | A14-01b | M | done(2026-09-23、共通コンポーネント `components/*.blade.php` も含めた) |
| 138d | A14-01b3 | A14-01b | M | done(2026-09-23) |
| 138e | A14-01b4 | A14-01b | M | done(2026-09-23) |
| 138f | A14-01b5 | A14-01b | M | done(2026-09-23) |
| 138g | A14-01b6 | A14-01b | M | done(2026-09-23) |
| 138h | A14-01b7 | A14-01b | S〜M | done(2026-09-23) |
| 138i | A14-01b8 | A14-01b | M | done(2026-09-23、メール関連の `app/Mail`・`app/Notifications`・通知リスナーは A14-01b6 へ) |
| 139 | B'-02 | 承認 | M | done(2026-09-24、課題のカスタムフィールドのみ。設計メモ docs/design/gap-B-02.md。他の種類は B'-02b) |
| 139b | B'-02b | B'-02 | M | todo(課題以外のカスタマイズ可能な種類(プロジェクト・バージョン・文書など)の添付ファイル形式。フォームと詳細画面が種類ごとに別実装) |
| 140 | B'-03 | 承認 | S〜M | done(2026-09-24、`ScmCapability`+`ScmAdapter::supports()`、`FilesystemAdapter`(entries/cat のみ)。ファイル名の `path_encoding` 変換は対象外) |
| 141 | B'-01 | 承認 | M×3 | done(2026-09-24、Mercurial・Bazaar・CVS。ブランチ/タグの表示、CVS のブランチリビジョンは対象外) |

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
| A1-05b | 右クリックメニューの残り項目: カスタムフィールドのサブメニュー、ウォッチャー(追加/ウォッチ切替)、単一課題での「作業時間を記録」「子課題を追加」、URL コピー、「フィルタ」(選択課題 ID で一覧を絞る) | A1-05 では ステータス/トラッカー/優先度/対象バージョン/担当者/カテゴリ/進捗率/編集/コピー/削除のみ | 各項目を `contextUpdate` または該当画面へのリンクとして追加 | A1-05 で分離 | S | 「一括編集」 |
| A1-06 | 一括編集の対象属性(トラッカー/カテゴリ/開始日・期日/カスタムフィールド/親課題/非公開/一括コメント) | 選択課題が単一ステータスのときのみステータス変更可。他属性はさらに少ない(`issues/index.blade.php` の bulk 系メソッド) | Redmine の `IssuesController#bulk_update` 相当に拡張。複数ステータス混在時は「共通の遷移先」のみ提示 | ワークフロー遷移の交差判定は `WorkflowService` を再利用 | M | 「ステータス一括編集の選択制約」partial(旧: 意図的) |
| A1-06b | 一括編集でカスタムフィールドを変更(Redmine の `bulk_edit` は共通のカスタムフィールドを表示) | A1-06 では未対応 | 選択課題に共通のカスタムフィールド(可視・編集可)を一括編集フォームに出し、各課題の `IssueService::update` 経由で保存 | A1-06 で分離 | M | 「一括編集」 |
| A1-07 | 一括コピー時のサブタスク複製(`Issue#copy` の `subtasks` オプション) | 一括コピーは親のみ、子孫は複製しない | 子孫を深さ優先で再帰コピーし親 ID をリマップ。対象バージョン/担当者の妥当性を移動先で再検証 | 隣接リスト(`parent_id`)で走査 | M | 「一括コピー・一括プロジェクト間移動・一括削除」 |
| A1-08 | 課題コピー時の関連/添付/サブタスク/ウォッチャー引き継ぎと `link_copied_issue`・`copy_attachments_on_issue_copy` 設定 | `IssueService::copy()` は `$copyAttachments` 引数あり。`?copy_from=` プリフィルは何も複製しない。設定キー2つは未定義(grep 0件) | コピーフォームに「添付をコピー」「サブタスクをコピー」「ウォッチャーをコピー」チェックボックス、`link_copied_issue`(yes/no/ask)で `copied_to` 関連作成を制御 | A1-07 と共通ロジック | M | Issues本体「課題のコピー」 |
| A1-09 | 課題削除時の工数の扱い選択(`params[:todo]`: 破棄/再割当/そのまま) | 常に `nullOnDelete` で保持のみ | 削除確認ダイアログに3択を追加し `IssueService::delete()` に渡す | 旧: 意図的簡略化 | S | Issues本体「課題削除」 |
| A1-10 | カスタムフィールド形式 `user` / `version`(`lib/redmine/field_format.rb` の `RecordList`) | `app/CustomFields/Formats/` に 10 形式(string/text/int/float/date/bool/list/enumeration/link/progressbar)。`app/Enums/CustomFieldFormat.php` も同じ | `FormatContract::options(CustomField $field)` に対象オブジェクト(Issue→Project)を渡せるようシグネチャ拡張し、メンバー/バージョン一覧を選択肢に。`user` は `user_role` 絞り込み、`version` は `version_status` 絞り込みオプション | **設計変更**: 全 Format 実装と呼び出し元(課題フォーム・一覧・フィルタ・CSV・API)への影響を先に洗う | M | カスタムフィールド(課題)「フィールド形式のカバレッジ」 |
| A1-10b | `user`/`version` のカスタムフィールド: 一覧フィルタ(`user` は「自分」を含む)・CSV 出力・REST API の値と `possible_values`・ジャーナル差分の表示 | A1-10a はフォーム/検証/表示まで | 各経路で id → 名前を解決し、フィルタは `value_int` で照合 | A1-10 で分離 | S〜M | 「カスタムフィールド」 |
| A1-10c | `user`/`version` のカスタムフィールドの複数値と入力欄、`format_options` の細部 | 入力欄は単一選択のみ(複数値の入力欄は他の形式にも無い) | 複数選択の入力欄、管理画面で複数値可の形式を制限 | A1-10 で分離 | S | 「カスタムフィールド」 |
| A1-11 | カスタムフィールドでの一覧並べ替え | `CustomFieldFilter::isSortable()` が `false`、列見出しクリックは no-op | `custom_field_values` への LEFT JOIN で ORDER BY。形式別の値列(`value_string`/`value_int`/…)を選ぶ | 複数値 CF は対象外のまま(Redmine も同様) | M | 「表示列・CSV列としてのカスタムフィールド」 |
| A1-12 | 複数値カスタムフィールド(`multiple: true`)でのグルーピング | グルーピング対象外(選択欄にも出ない) | Redmine 同様、値ごとに行を重複表示するか、集計は `COUNT(DISTINCT issues.id)` にする | パフォーマンス上の理由で見送られていた | S〜M | クエリ「グルーピング」 |
| A1-13 | Progressbar 形式の `ratio_interval`(`issue_done_ratio_interval` 設定) | `ProgressbarFormat` はフリー整数入力。設定キーなし | 設定「課題トラッキング」に刻み幅(1/5/10)、進捗率入力を `<select>` 化。CF 側の `ratio_interval` 属性も追加 | 旧: 意図的対象外 | S | 同上 |
| A1-14 | 楽観的ロックを一括編集・REST API・リポジトリ連動に適用 | `IssueService::update()` は `$expectedLockVersion` 省略時に常に許可 | API `PUT /issues/{id}` で `lock_version` を受け取り 409 を返す。一括編集はフォーム読込時の値を保持 | 旧: 意図的 | S | Issues本体「楽観的ロック」 |
| A1-15 | `estimated_remaining_hours`(Redmine 7.0 新規、`app/models/issue.rb:1210`)と `total_spent_hours`/`spent_hours` 列 | 列・計算なし(grep 0件)。`Issue::totalEstimatedHours()` はあり | 計算プロパティ(`estimated_hours * (100 - done_ratio) / 100`、親は子の合計)を追加し、詳細・一覧列・CSV・API に露出 | 列としての露出は A2-01 と同時に | S | Issues本体 |
| A1-16 | 課題一覧の選択可能列: `id`/`project`/`parent`/`updated_on`/`estimated_hours`/`estimated_remaining_hours`/`closed_on`/`last_updated_by`/`description`/`last_notes`/`spent_hours`/`total_spent_hours`/`is_private`(`issue_query.rb` の `available_columns`) | `issues/index.blade.php` の `DISPLAY_COLUMNS` は tracker/status/priority/subject/category/assigned_to/author/fixed_version/start_date/due_date/created_at/done_ratio/relations/attachments/watchers | 欠落列を `DISPLAY_COLUMNS` と `QueryFilterEngine` の並べ替え対象に追加。`description`/`last_notes` はブロック列(Redmine の `inline: false`) | `last_updated_by` は Journal の最新 `user_id` | M | クエリ「列選択」 |
| A1-17 | 課題フィルタ: `description`/`notes`/`updated_on`/`closed_on`/`estimated_hours`/`spent_time`/`parent_id`/`child_id`/`issue_id`/`is_private`/`attachment`/`attachment_description`/`watcher_id`/`updated_by`/`last_updated_by`/`member_of_group`/`assigned_to_role`/`author.group`/`author.role`/`fixed_version.due_date`/`fixed_version.status`/`subproject_id`/`project.status`/関連タイプ別(`relates` 等)/`any_searchable`(`issue_query.rb:153-262`) | `app/Support/Query/IssueFilterFieldRegistry.php` は assigned_to_id/author_id/category_id/created_at/done_ratio/due_date/fixed_version_id/priority_id/project_id/start_date/status_id/subject/tracker_id の 13 種。**A1-17a(2026-09-24)**: `IssueExtraFilterFields`(`CallbackFilter`)で description/notes/estimated_hours/is_private/issue_id/parent_id を追加。**A1-17b(2026-09-24)**: child_id と関連タイプ別 9 種。**A1-17c(2026-09-24)**: ウォッチャー/更新者/最終更新者/担当者のグループ・ロール/作成者のグループ・ロール/添付。**A1-17d(2026-09-24)**: 対象バージョンの期日/ステータス、プロジェクトのステータス、作業時間、検索可能な項目。`subproject_id` は A1-17e | `NativeColumnFilter` に加え、サブクエリ型フィルタ(関連・ウォッチャー・グループ/ロール・添付)を `FilterableField` 実装として追加。保存済みクエリ・Atom・CSV・マイページブロックは `QueryFilterEngine` 経由なので自動追従 | `subproject_id` は A3-06(`display_subprojects_issues`)が前提 | L | クエリ/フィルタ 節 |
| A1-17a | 課題自身の列のフィルタ: `description`/`notes`/`estimated_hours`/`is_private`/`issue_id`/`parent_id`(`issue_query.rb` の `sql_for_notes_field`/`sql_for_is_private_field`/`sql_for_issue_id_field`/`sql_for_parent_id_field`) | done(2026-09-24)。`app/Support/Query/IssueExtraFilterFields.php` | notes は閲覧できるジャーナルへの EXISTS(非公開注記は本人か `view_private_notes` のあるプロジェクトのみ)、is_private は `set_issues_private`/`set_own_issues_private` を持つ人にだけ表示、parent_id の「含む」は再帰 CTE で子孫 | `journals.issue_id` に索引を追加 | S〜M | クエリ「課題フィルタの種類」 |
| A1-17b | 関係のフィルタ: `child_id` と関連タイプ別(`relates`/`blocks`/`blocked`/`duplicates`/`duplicated`/`precedes`/`follows`/`copied_to`/`copied_from`) | done(2026-09-24)。`IssueExtraFilterFields::relationFilters()`/`childId()`。`FilterOperator` に `*o`/`!o`/`=p`/`=!p`/`!p` を追加(プロジェクト指定は閲覧できるプロジェクトのみ有効) | `issue_relations` への EXISTS。本アプリは `follows` を行として保存するので `precedes`/`follows` は両方の保存形を見る | A1-17a の `IssueExtraFilterFields` に追加 | S〜M | 同上 |
| A1-17c | 人と添付のフィルタ: `watcher_id`/`updated_by`/`last_updated_by`/`member_of_group`/`assigned_to_role`/`author.group`/`author.role`/`attachment`/`attachment_description` | done(2026-09-24)。`IssueExtraFilterFields`。ドットを含むキーは Livewire の配列パスと衝突するため `author_group`/`author_role`。グループの選択肢は閲覧できるもの(`hasSiteWideUserVisibility` か閲覧できるプロジェクトのメンバーのグループ)に限り、それ以外の ID は無視 | watchers/journals/members/group_user/media への EXISTS。`watcher_id` の他人指定は `view_issue_watchers` のあるプロジェクトでだけ一致 | 同上 | M | 同上 |
| A1-17d | 他の表の属性と全文: `fixed_version.due_date`/`fixed_version.status`/`project.status`/`spent_time`/`any_searchable` | done(2026-09-24)。キーは `fixed_version_due_date`/`fixed_version_status`/`project_status`。`spent_time` は `view_time_entries` を持つ人にだけ、`project_status` は横断一覧とサブプロジェクトを持つプロジェクトでだけ表示。`any_searchable` は `SearchService::issueIdsMatching()`(件数上限なし、添付は除く) | versions/projects/time_entries への副問合せ、`SearchService` の課題検索を流用 | `subproject_id` は A3-06b と同時 | S〜M | 同上 |
| A1-17e | 課題フィルタ `subproject_id`(`list_subprojects`: `*`/`!*`/`=`/`!`。Redmine は `project_statement` で扱い、個々のフィルタではない) | 未実装。A3-06b(`display_subprojects_issues` の各画面への適用)が未了 | サブプロジェクトを持つプロジェクトの一覧で、`SubprojectScope` の対象プロジェクトを絞る。選択肢は閲覧できる子孫のみ | A3-06b と同時 | S | クエリ「課題フィルタの種類」 |
| A1-36 | 課題フィルタの残り: テキストの演算子 `*~`(いずれかの語)/`^`(で始まる)/`$`(で終わる)、Redmine の語分割(`~` は全語一致)、トラッカーで無効にした標準項目(`disabled_core_fields`)のフィルタを隠す(`Tracker.disabled_core_fields(trackers).each { delete_available_filter }`)。あわせて `SearchService` の語一致が PostgreSQL で大文字小文字を区別する(`like`。Redmine は区別しない)ため、検索画面と `any_searchable` の両方が影響を受ける | **done(2026-09-24)**。`FilterOperator` に `ContainsAny`(`*~`)/`StartsWith`(`^`)/`EndsWith`(`$`)。新設の `App\Support\Query\TextMatch` が `Redmine::Search::Tokenizer`(引用符句、1 文字語は漢字のみ、最大 5 語)と `tokenized_like_conditions`(`~` 全語、`*~`/`^`/`$` いずれか、`!~` どの語も含まない)を実装し、`FilterOperatorApplier`・`BuildsQueryFilterConditions::applyText`・`SearchService` が共用。PostgreSQL では ILIKE(先頭ワイルドカードの LIKE はもともと b-tree 索引を使えないため計画は変わらない)。題名・説明・コメント・添付・添付の説明・テキスト CF・工数のコメント/課題の題名・プロジェクト/ユーザー一覧の文字列項目に新演算子、検索可能な項目は `*~` を追加。`IssueFilterFieldRegistry` は対象トラッカー(サブプロジェクト込み)がすべて無効にした標準項目のフィルタを出さない(Redmine は親課題を `parent_issue_id` で持つためフィルタ `parent_id` は消えない。同じ挙動)。列の除外は A1-45 | 演算子を追加し `FilterOperatorApplier`/フィルタ UI に反映。レジストリで全トラッカーが無効にした項目を除外 | A1-17a で判明 | S | クエリ「課題フィルタの種類」 |
| A1-37 | 閲覧ロールを制限したカスタムフィールドをフィルタに出さない(Redmine の `IssueQuery#issue_custom_fields` は `visible` スコープで、見えない CF はフィルタ・列の対象外) | **done(2026-09-24)**。`App\Support\Query\CustomFieldVisibility`(管理者・ロール制限なしは全プロジェクト、それ以外は閲覧者のロール=`AuthorizationService::rolesFor()` が CF のロールと交わるプロジェクトのみ)。`IssueFilterFieldRegistry` は一覧の範囲(サブプロジェクト込み/横断)のどこかで見える CF だけをフィルタにし、`CustomFieldFilter` が見えるプロジェクトの行に絞る(フィルタ・並べ替えとも。Redmine の `visibility_by_project_condition`)。課題一覧の列・並べ替え専用・グループの CF も同じ判定、セルは行のプロジェクトで見えないとき空(CSV/PDF 共通)、CF でのグループ化は見えない行を除外。横断一覧は URL の `cf_N` 列を行ごとに判定。工数一覧(プロジェクト・横断)の `cf_N` 列は提示した列に限定。見えない CF のキーは URL・保存済みクエリ・REST で来ても無視(エラーにしない)。**Redmine との差**: (1) 否定演算子(`!~`・`!in`)でも見えない行は一致しない(Redmine は NOT EXISTS のため全件一致。どちらも値は漏れない)、(2) 提示は「一覧の範囲のプロジェクトのどこかで見える」(Redmine はどこかのプロジェクトでロールを持てば提示し SQL で絞る)。プロジェクト一覧の CF は A2-03 のまま管理者かロール制限なしのみ(Redmine より狭い)。工数一覧に課題/プロジェクト/ユーザーの CF 列・フィルタは存在しない(A2-08 は標準項目のみ) | CF を `visibleToRoles`(プロジェクトごとのロール、横断一覧はいずれかのプロジェクト)で絞り、保存済みクエリに残った見えない CF フィルタは無視 | A11-17 で判明 | S | クエリ「カスタムフィールドでのフィルタ」 |
| A1-38 | 匿名ユーザーは非公開課題を見ない(Redmine の `Issue.visible_condition`/`visible?` はログインしていない利用者に `is_private = false` だけを見せる) | **done(2026-09-24)**: `AuthorizationService::issueVisibilityRules()` が匿名(`null`)のときは全段を `default`(トラッカーは各段の和)に集約し、`Issue::applyVisibilityRules()` はユーザーなしの `default` を `is_private = false` のみ、`own` を常に偽にする(従来は `author_id`/`assigned_to_id` を `null` と比較して `IS NULL` になり、`default`/`own` でも未割り当ての非公開課題が見えていた)。スコープ(`visibleTo`/`visibleToAcrossProjects`/`visible`)・`isVisibleTo()`・`filterVisible()` すべてに効く。ロール編集画面は Redmine と同じく Anonymous の「課題の閲覧範囲」を表示しない。テスト: `tests/Feature/Issues/AnonymousPrivateIssueVisibilityTest.php`(全段 × 一覧・詳細・PDF・Atom・検索・カレンダー・ガント・活動・キーなし API) | 匿名のときは閲覧範囲に関わらず `is_private = false` に限定(スコープと `Issue::isVisibleTo()`) | 見える範囲が狭まる変更 | S | Issues本体「課題の閲覧範囲」 |
| A1-39 | グループに割り当てた課題の閲覧(Redmine の `default`/`own` は `assigned_to_id IN (本人 + 所属グループ)`) | **done(2026-09-24)**: `Issue::applyVisibilityRules()`(一覧・API・横断スコープ)と `matchesVisibilityRules()`(`isVisibleTo`/`filterVisible`)の `default`/`own` が、作成者・担当者本人に加えて `assigned_to_group_id IN (所属グループ)` を数える(`AuthorizationService::groupIdsFor()` でリクエスト内メモ化、書き込みで破棄)。グループから外れれば即座に見えなくなる。Redmine と同じく設定 `issue_group_assignment` の値には依存しない。テスト: `IssueGroupVisibilityTest.php` | `Issue::applyVisibilityRules()`/`matchesVisibilityRules()` の担当者条件に所属グループを含める | 見える範囲が広がる変更(Redmine と同じ) | S | Issues本体「課題の閲覧範囲」 |
| A1-40 | 課題削除で工数を「残す」(nullify)を選んだとき、`timelog_required_fields` に `issue_id` があれば拒否する(Redmine `issues_controller.rb` destroy の `Setting.timelog_required_fields.include?('issue_id')`) | **done(2026-09-24)**: `IssueTimeEntryDisposition::isAllowedForIssueDeletion()`/`defaultForIssueDeletion()`。`IssueService::deleteMany()` は既定(引数 null)を設定から決め、工数がある削除で nullify が許されなければ `todo` の検証エラー(REST は 422)。課題詳細・一括削除/右クリックの確認パネルは「残す」を出さず既定を「削除」に。テスト: `IssueDeletionTest`・`IssueBulkDeleteTest`・`IssueApiTest` | nullify 時に設定を見て入力エラー/422(課題詳細・一括削除・API)。既定の nullify と衝突するため、既定を変えるかも含め判断 | 既定値(nullify)の扱いが絡む | S | Issues本体「課題削除」 |
| A1-41 | ログイン不要(`login_required` オフ)のとき、匿名ユーザーにもプロジェクトのカレンダー・ガント・検索・活動・ロードマップ・Atom(課題/課題の更新/活動)を開放する(Redmine は Anonymous ロールが `view_calendar`/`view_gantt`/`search_project`/`view_issues` 等を持てば表示) | **done(2026-09-24)**: `calendar.index`/`gantt.index`/`search.index`/`activity.index`/`versions.roadmap` を `login.required` に切替。`AuthenticateWithAtomKey` はキーもセッションも無いとき `login_required` オフならゲストとして通す(課題・課題の更新・活動・ニュース・フォーラムの Atom、プロジェクト別と全体)。判定は従来どおり各ポリシー(Anonymous ロールの権限・モジュール・公開プロジェクトのみ)と A1-38/A1-27 の可視性。ゲストで壊れていた箇所を修正: 検索の `#番号` ジャンプが非公開課題でも転送していた(`?->cannot` が null)、検索/活動の「サブプロジェクトを含む」と全体活動 Atom がゲストで全プロジェクトを落としていた(`Gate::allows` に)。Redmine に合わせて、検索と課題一覧の「検索可能な項目」フィルタのカスタムフィールド値の一致を、閲覧者がその課題のプロジェクトで見られるフィールドに限定(`CustomFieldVisibility`、ログイン利用者にも適用)、課題の更新 Atom の CF 変更も見られないものは出さない(それだけの更新は項目ごと非表示、削除済み CF も非表示)。テスト: `AnonymousProjectPagesTest`(13件: 開放・`login_required` オン・非公開プロジェクト・権限なし・モジュール無効・非公開課題・トラッカー制限・CF 制限・全体 Atom)。旧: `withoutMiddleware('auth')` は課題一覧・課題詳細/PDF・Wiki・添付のみで、上記の画面はログインへ転送 | 各ルートを `login.required` に切り替え、各コンポーネント/コントローラが `null` の利用者で動くことを確認(可視性は A1-38 で匿名対応済み) | 匿名に見える範囲が広がる変更(Redmine と同じ)。要確認 | S〜M | 認証「ログイン不要時の匿名アクセス」 |
| A1-43 | プロジェクトの既定担当者・カテゴリの既定担当者にグループを選ぶ(Redmine の `default_assigned_to`/`IssueCategory#assigned_to` は Principal) | **done(2026-09-24)**: `projects.default_assigned_to_group_id`・`issue_categories.assigned_to_group_id`(groups FK `nullOnDelete`、ユーザー列と排他の CHECK、モデルの `saving` で片方を設定すると他方を解除 — `AssigneeChoice::keepSingle()`)。プロジェクト設定の「既定の担当者」とカテゴリのフォームで、`issue_group_assignment` オン時に割り当て可能なグループを `<x-assignee-options>` で選択(現在値は失効していても表示)。新規課題フォームはカテゴリ既定 → プロジェクト既定の順でグループも適用(設定オフ・割り当て不可のグループは適用しない: `Project::usableDefaultAssigneeGroupId()`/`IssueCategory::usableDefaultAssignee()`)。グループがプロジェクトから外れると既定を解除(`Member` の deleted、Redmine の `remove_from_project_default_assigned_to`)。REST: `PUT /projects/{id}` の `default_assigned_to_group_id`(`default_assigned_to_id` と同時指定は 422、`default_assigned_to_id: null` はグループも解除)、応答 `default_assignee` に `type`、カテゴリの `assigned_to_group_id`。**未対応(既存どおり)**: REST・CSV インポート・受信メールでの課題作成は既定担当者を適用しない(フォームのみ。Redmine は `before_save` で常に適用)。テスト: `GroupDefaultAssigneeTest.php` | `default_assigned_to_group_id` 等を追加し、`issue_group_assignment` オン時に割り当て可能なグループを候補に出し、新規課題の既定担当に反映 | スキーマ追加 | S | Issues本体「グループへの課題割当」 |
| A1-46 | REST・CSV インポート・受信メールで作った課題にもカテゴリ/プロジェクトの既定担当者を適用する(Redmine の `Issue#default_assign` は `before_save` で全経路に適用) | 既定担当者(A1-23/A1-43)と既定バージョンは新規課題フォームのプリフィルだけ。`IssueController::store`・`ImportIssuesJob`・受信メールは担当者未指定なら未割当のまま。A1-43 の実装中に判明 | 課題作成サービスで担当者が未指定のときカテゴリ → プロジェクトの既定(使用可能なもの)を入れる | 既定バージョンも同様か Redmine の `Issue#tracker=` を確認 | S | Issues本体 |
| A1-42 | プロジェクト削除で、別プロジェクトにある子孫課題も削除する(Redmine の `Project#destroy` → 課題の `destroy` が入れ子集合で子孫を削除) | **done(2026-09-24)**: Redmine で確認(`project.rb` の `has_many :issues, :dependent => :destroy`、`project_nested_set.rb` の `destroy_children`、`issue_nested_set.rb` の `destroy_children`、`Issue has_many :time_entries, :dependent => :destroy`、`acts_as_webhookable` の `after_destroy_commit`)。`Project::booted()` の `deleting` が自プロジェクト+サブプロジェクト(`_lft`/`_rgt` の範囲)の課題を `IssueService::deleteMany(..., Destroy)` で削除するので、別プロジェクトの子孫課題・その工数・添付(メディア)・検索索引も消え、`issue.deleted` が課題ごとに飛び、残る親は再計算。`Project::delete()` をトランザクションで包み、詳細画面・管理画面の一括削除・REST のすべてに効く。テスト: `ProjectDeletionTest`(3件追加)。旧: DB の cascade 任せで別プロジェクトの子課題は最上位に残った | プロジェクト削除時に `IssueService::deleteMany()` を通す(工数はプロジェクトごと消えるため destroy 相当) | データ削除の範囲が広がる変更 | S | Projects「プロジェクト削除」 |
| A1-44 | ログイン不要時、匿名ユーザーにプロジェクト一覧・概要、全体の課題一覧/カレンダー/ガント/検索/活動、プロジェクトの掲示板・ニュース・文書・ファイルの画面を開放する(Redmine は Anonymous ロールの権限で表示) | A1-41 でプロジェクトのカレンダー/ガント/検索/活動/ロードマップと全 Atom は開放済み。上記の画面は `auth` のままでログインへ転送。ゲストのヘッダーにはプロジェクトのメニューが無く、開放済みの画面へは URL 直打ちか課題一覧のリンクでしか辿れない | 各ルートを `login.required` に切り替え、各コンポーネントの `auth()->user()?->can()`(ゲストで null)を `Gate::allows()` に。ゲスト用のプロジェクトメニュー | 匿名に見える範囲が広がる変更。要承認 | S〜M | 認証「ログイン不要時の匿名アクセス」 |
| A1-18 | 稼働日ベースの日付計算(`non_working_week_days` 設定、`Redmine::Utils::DateCalculation`) | 暦日計算のみ(`IssueService::rescheduleSuccessors()`、grep「稼働日」0件) | 設定「課題トラッキング」に非稼働曜日チェックボックス、`working_days`/`add_working_days` ヘルパーを導入しリスケジュール・遅延計算(`IssueRelation.delay`)・ガントに適用 | 既存のリスケジュールテストを暦日→稼働日で更新 | M | Issue Relations「関連日付からの自動リスケジュール」 |
| A1-19 | リスケジュールの親子階層への伝播(`Issue#reschedule_on!` の leaves/ancestors) | `precedes`/`follows` チェーンのみ。子・親には伝播しない | 後続課題の子孫にも同じシフトを適用し、親の日付は `parent_issue_dates` 設定に従って再集計 | 循環ガード(最大50ホップ)を維持 | M | 同上 |
| A1-20 | グループへの課題割当(`issue_group_assignment` 設定、`Principal` 担当) | **A1-20a done(2026-09-24)**: `issues.assigned_to_group_id`(groups FK、`nullOnDelete`、`assigned_to_id` と排他の CHECK 制約。モデルの `saving` で片方を設定すると他方を解除)、設定「グループへの課題の割り当てを許可」(既定オフ)、`Project::assignableGroups()`(割り当て可能なロールを持つメンバーグループ、継承行を含む)、`App\Support\Issues\AssigneeChoice`(ユーザーは id、グループは `group:<id>`)。課題フォーム・一括編集・右クリックでグループを選択(オフ時は候補に出さないが既存の割当は表示・保持)、REST は `assigned_to_group_id` で書き込み(`assigned_to_id` と同時指定は 422、`assigned_to_id: null` はグループも解除)、応答に `assigned_to_group_id` と `assigned_to {id,name,type}`。表示は `Issue::assigneeName()`(詳細・一覧・横断一覧・PDF・関連課題列・マイページのクエリブロック)。Journal は `assigned_to_group_id` を別の明細行で記録。テスト: `IssueGroupAssignmentTest.php`。**A1-20b done(2026-09-24)**: グループのメンバー全員を担当者として動的に扱う(実体化しない)。通知(`NotificationRecipients::forIssue` がメンバーを候補に加え、各自の通知設定で絞る。`only_assigned`/`only_my_events`/`selected` も担当として判定)、マイページ「自分の課題」・ユーザー画面の件数(`Issue::scopeAssignedToUserOrGroups`)、ワークフローの担当者限定遷移、フィルタ(`App\Support\Query\AssigneeFilter`: `<< 自分 >>` は所属グループを含む — Redmine の `Query#statement` と同じく設定に依存しない、`group:<id>`、否定は未割当も含む、担当者名で並べ替え。設定オン時はメンバーのグループを候補に出す。`member_of_group` はグループ自身も一致、`assigned_to_role` はグループのメンバー行のロールも一致)、グループ別集計、REST の `assigned_to_id=me`/`f[]` の `me`、課題の移動/コピー/子課題コピー(移動先のメンバーグループのときだけ保持)、CSV インポート・受信メール `Assigned to:`(設定オン時に割り当て可能なグループ名)、課題レポートの担当者別(グループ行)。CSV/PDF/一覧の表示は A1-20a の `assigneeName()`。**Redmine との差・未対応**: グループへの自動ウォッチはしない(Redmine の `Journal#add_watcher` も User のみ)。以前の担当者への通知は A6-08、プロジェクト/カテゴリの既定担当者にグループを選べるのは A1-43。ガント・カレンダーは担当者を表示していないので変更なし。テスト: `IssueGroupAssigneeReadersTest.php` | `assigned_to` を polymorphic 化するか `assigned_to_group_id` 列を追加。担当者候補にグループを含め、通知はグループ展開 | **スキーマ判断**: Redmine は `principals` 単一テーブル継承。本アプリは users/groups 分離のため設計メモが必要 | L | Issues本体 |
| A1-21 | 関連課題テーブルの列選択(`related_issues_default_columns`、`display_related_issues_table_headers`) | 課題詳細の関連課題は固定表示 | 設定に列選択を追加し、`issues/show.blade.php` の関連課題ブロックを列設定に従って描画 | — | S | 設定「課題トラッキング」 |
| A1-22 | Journal 編集者・編集日時の記録(`journals.updated_by_id`/`updated_on`)、非公開フラグの編集 | `journals` テーブルに `updated_by` なし(migration grep 0件)。編集フォーム・API は本文のみ | 列追加+編集時に記録し「(編集済み by X)」表示。編集フォームと `PUT /journals/{id}` で `private_notes` 切替を許可(`set_notes_private` 権限) | — | S | Journal「個別 Journal の編集」、REST API「Journals」 |
| A1-23 | プロジェクトの既定バージョン・既定担当者(`projects.default_version_id`/`default_assigned_to_id`、`project.rb:43-44`) | `projects` テーブルに列なし | 列追加+プロジェクト設定フォームに選択欄、新規課題フォームで対象バージョン/担当者の初期値に使用(カテゴリの既定担当者より優先度は低い) | チェックリストで「既定バージョン設定に該当する Redmine 機能未特定」とされていた項目の正体 | S | Versions「Wikiページ紐付け・既定バージョン設定」 |
| A1-24 | トラッカーの `is_in_chlog`(変更履歴に表示) | `trackers` に列なし | 列+フォームのチェックボックス。バージョン詳細の課題一覧で絞り込みに使用 | 優先度低 | S | Trackers 節 |
| A1-25 | 新規課題時のワークフロー遷移(`old_status_id IS NULL` の行) | `WorkflowService.php:51` は `old_status_id = 現在ステータス` のみ参照。`IssueService::create()` はワークフローを見ずトラッカー既定ステータスを採用 | 新規課題フォームのステータス選択肢を「`old_status_id IS NULL` かつ該当ロール」の遷移先に制限。管理画面(`workflows/edit.blade.php`)に「新規課題」行を追加 | チェックリスト §0 項目 10 で既知 | M | Issue Statuses / Workflow 節 |
| A1-26 | ワークフローコピーの省略記法(トラッカーまたはロールを「全て」指定) | コピー元・コピー先とも明示選択必須 | コピーフォームに「全トラッカー」「全ロール」選択肢を追加し二重ループでコピー | — | S | 「ワークフローのコピー」 |
| A1-27 | ロール×トラッカー単位の課題権限(`roles.settings` の `permissions_all_trackers`/`permissions_tracker_ids`) | **A1-27a done(2026-09-24)**: `roles.settings`(JSON、空=全トラッカー)、`Role::trackerIdsFor()`/`setPermissionTrackers()`、`AuthorizationService::allowedTrackerIds()`/`canOnTracker()`/`issueVisibilityRules()`、ロール編集画面の権限×トラッカー表(`roles/form.blade.php`、コピーも引き継ぐ)。**A1-27b done(2026-09-24)**: `view_issues` のトラッカー制限を `Issue::scopeVisibleTo()`/`scopeVisibleToAcrossProjects()`/新設 `scopeVisible()`・`isVisibleTo()`(`IssuePolicy::view`)に適用。Redmine の `Issue.visible_condition` と同じくロールごとに「閲覧範囲 × 許可トラッカー」を OR(`view_issues` を持たないロールは数えない)。全読み取り経路を監査し、可視性を通っていなかった経路(プロジェクトのカレンダー、ガント、マイページの担当/報告/ウォッチ、活動、工数フォームの課題選択と API、工数レポートの課題ラベル/課題属性、課題詳細の関連/子/親、課題 PDF の親、一覧の関連列、選択・右クリック、バージョンの件数/進捗/工数、チェンジセットの関連課題、Wiki の `#123` リンク、関連/子課題フィルタ、親課題の指定(フォーム/一括/インポート/受信メール)、受信メールの返信)を修正。**A1-27c done(2026-09-24)**: `IssuePolicy` の update(`edit_issues`、`edit_own_issues` は表の対象外なので全トラッカー)/addNotes/delete を課題のトラッカーで判定(Redmine の `user_tracker_permission?`)、create は `add_issues` のトラッカーがプロジェクトに 1 つも無いと不可。`Issue::allowedTargetTrackers()`(Redmine の `allowed_target_trackers`、編集中は現在のトラッカーを含む)を新規/編集フォーム、一括編集・右クリック、移動(単体/一括)、コピー(一括・子課題コピー)、REST の作成/更新の検証、CSV インポート(名前は許可トラッカーから、既定は先頭)、受信メール(既定トラッカーとキーワード)に適用。受信メールの返信は `add_issue_notes`(または従来どおり編集権限)で受け付け、キーワードによる属性変更は編集権限があるときだけ。**性能フォロー(2026-09-24)**: `AuthorizationService` を scoped バインドにし、`issueVisibilityRules()`(利用者×プロジェクト、公開/状態も鍵)・`issueProjects()`(`scopeVisible()` の対象プロジェクト、モジュール込み)・所属ロール(複数プロジェクトを 1 クエリで先読み)・グループ ID・組み込みロールをメモ化。書き込みクエリ(insert/update/delete)とトランザクションのロールバックで `flushCache()`(`PermissionServiceProvider` の `DB::listen`)するため、ピボットの attach など同じリクエスト内の変更も反映。計測(`tests/Feature/Authorization/IssueVisibilityCacheTest.php`): ロードマップ 2 バージョン 121→20 クエリ(roles 43→2)、6 バージョン 352→35(127→2)、工数レポート 37→19(11→2)。未使用になった `issueVisibilityFor()` を削除(テストは `issueVisibilityRules()` へ移行) | `settings` JSON 列を追加し、`add_issues`/`view_issues`/`edit_issues`/`delete_issues` ごとに「全トラッカー or 選択トラッカー」を設定。`IssuePolicy` と `Issue::scopeVisibleTo()` にトラッカー条件を追加 | 可視性スコープの変更は Atom/API/マイページに波及 | M | ロール・権限 節 |
| A1-28 | CSV インポート: カスタムフィールド列、遅延付き関連、`unique_id` による親子/関連の遅延解決 | **done(2026-09-24、A1-28a〜c)**: a = `CsvReader`(複数行の値・BOM)、b = CF 列(`relevantCustomFields(?User)`、`CustomField::valueFromKeyword()`、必須/形式の検証)、c = `unique_id`・関連列。詳細は §0.3 の 106a〜c。旧: `ImportIssuesJob::mapRowToAttributes()` は CF・関連未対応 | CF は `Issue::relevantCustomFields()` の認可コンテキスト(`auth()->user()`)をジョブ内で実行ユーザーに差し替える設計が必要。`unique_id` は2パス(先に全行作成→後で親/関連を解決) | **設計メモ必須**(認可コンテキストの扱い) | M〜L | 「マッピング可能な列」「カテゴリ/バージョンの自動作成…」 |
| A1-28d | 工数・ユーザーの CSV インポートも `App\Support\Import\CsvReader` で読む(引用符内の改行・BOM) | `ImportTimeEntriesJob`/`ImportUsersJob` と `time-entries/import.blade.php` は `str_getcsv(file())`/`fgetcsv` のままで、複数行の値を壊し BOM 付きの先頭見出しが一致しない。A1-28a で判明 | 両ジョブと見出しの読み取りを `CsvReader::read()`/`header()` に置き換え | — | S | 工数「CSVインポート」 |
| A1-29 | 課題一覧の PDF エクスポート(`issues/index` の `format=pdf`)と `issues_export_limit` 設定 | PDF は課題単体(`routes/web.php:96` `issues.pdf`)・Wiki・ガントのみ | 現在のフィルタ/列を反映した一覧 PDF を dompdf で生成。CSV/PDF とも `issues_export_limit` で件数を打ち切り | `resources/views/pdf/issue.blade.php` のスタイルを流用 | S〜M | 「PDFエクスポート・Atomフィード」 |
| A1-30 | Atom フィードへの現在のフィルタ/ソート反映 | `IssueAtomController` は「未クローズ・最近更新」固定 | 一覧画面の Atom リンクに現在のクエリ文字列を付与し、`QueryFilterEngine` で同条件を適用 | 旧: 意図的簡略化 | S | Issues本体「Atom フィード」 |
| A1-31 | 課題の Journal 全体 Atom(`/issues/changes`) | なし | 全プロジェクト/プロジェクト単位の「最近の変更」フィード | A9-06(Atom key)が前提 | S | — (checklist 未掲載) |

### A-2. クエリ / 一覧 / レポート

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A2-01 | 列の並び順変更(保存済みクエリの `column_names` 順) | チェックボックスで選択のみ、順序は固定 | 選択列の上下移動 UI(または `wire:sort`)を課題一覧・工数一覧に追加し、`column_names` の順序で描画 | — | S | クエリ「列選択」 |
| A2-02 | 既定クエリ(グローバル `default_issue_query`、プロジェクト `projects.default_issue_query_id`、ユーザー設定 `default_issue_query`) | 3つとも未実装(settings/projects/users にキー・列なし) | 課題一覧を初期表示するときに ユーザー設定 → プロジェクト設定 → グローバル設定 の順で保存済みクエリを適用 | ユーザー設定は A4-13 の基盤上に | S〜M | 設定「課題トラッキング」 |
| A2-03 | プロジェクト一覧クエリ(`ProjectQuery`: フィルタ・列・保存、`project_list_defaults`、`project_list_display_type` = board/list、`default_project_query`) | 2026-09-24 完了(A2-03a: エンジン駆動の一覧・フィルタ・列・並べ替え、A2-03b: 保存クエリと既定クエリ、A2-03c: ボード/一覧と設定 2 つ)。API は A11-18、残りは A2-10。`GET /queries?type=project` は `QueryType` の追加で受け付けるようになった(`project_id` 付きは `view` で認可) | `QueryType` に `Project` を追加し `ProjectFilterFieldRegistry` を新設。ボード(カード)表示切替と設定キー3つ | `Query` モデルは `type` 列で既に多型 | M | Projects「プロジェクト一覧」 |
| A2-03a | プロジェクト一覧をクエリエンジン駆動に: `QueryType::Project`、`ProjectFilterFieldRegistry`(ステータス・名前・識別子・説明・親・公開・作成日・更新日・プロジェクトのカスタムフィールド)、列選択・並び順・並べ替え | 2026-09-24 実施。フィルタ/並べ替えなしはツリー(全件)、どちらかがあればフラット+ページ分割(設計メモ案 A)。可視 ID は `AuthorizationService::visibleProjectIds($user, 'view_project')` で SQL に渡す(メンバーでも `view_project` のないロールだけなら非表示=`ProjectPolicy::view` と一致)。Redmine の既定フィルタ「ステータス=有効」は採らない(既定表示がフラットになるため。従来どおり全ステータスのツリー) | — | 設計メモ `gap-A2-03.md` | M | Projects「プロジェクト一覧」 |
| A2-03b | プロジェクト一覧の保存クエリ(グローバルのみ、公開範囲)と既定クエリ(個人設定 → サイト設定 `default_project_query`) | 2026-09-24 実施。保存(`save_queries`、公開/ロール公開は管理者のみ=`Query::resolveVisibility()` のグローバル規則)・読込(種別 project・グローバルのみ、`visibleTo()`)。`DefaultProjectQuery::for()`(個人設定 → サイト設定。サイト設定は公開クエリのみ)を URL に状態がないときに適用。設定「プロジェクト」とプロフィールに選択欄。**未対応**: 保存クエリの編集/削除画面(課題・工数の一覧にも無い) | `time-entries/global-index` と同じ保存/読込、`DefaultIssueQuery` と同じ解決順。個人設定 `default_project_query`(A4-13b の残り) | A2-03a | S〜M | Projects「プロジェクト一覧」 |
| A2-03c | ボード/表の切替と設定 `project_list_display_type`(既定 board)・`project_list_defaults`(既定の列) | 2026-09-24 実施。表示形式は URL `display_type`(未指定なら設定値)。ボードは従来のカード型リスト(名前・識別子・非公開/ステータス・説明・★、未絞り込み時はツリーのインデント)、一覧は列選択の表。設定「プロジェクト」に表示形式と初期表示列(ネイティブ列のみ)。**簡略化**: Redmine のボードは説明を Markdown 描画しカスタムフィールドも出すが、本アプリは説明をプレーンテキストで表示。表示形式は保存クエリに含めない(`queries` に options 列がない) | 表示形式を URL に持ち、既定は設定値。設定画面「プロジェクト」に項目を追加 | A2-03a | S | Projects「プロジェクト一覧」 |
| A2-04 | 管理者向けユーザー一覧クエリ(`UserQuery`: ステータス/グループ/ロール/認証方式フィルタ、列選択、CSV) | `users/index.blade.php` に検索・フィルタなし(grep 0件) | フィルタ+列選択+CSV。`QueryFilterEngine` を再利用 | 管理者専用 | M | ユーザー管理・認証 節 |
| A2-05 | 管理画面のプロジェクト一覧クエリ(`ProjectAdminQuery`、Redmine 6.0〜) | **done(2026-09-24)**。`/admin/projects`(`admin.projects`、管理者のみ・他は 403、ヘッダーの管理リンク「プロジェクト管理」)。全プロジェクト(アーカイブ済み含む)を `ProjectFilterFieldRegistry` のフィルタ・列・並べ替え(`applySort()` を一覧と共通化)で表形式・ページ分割、未ソート時はツリー順で字下げ。Redmine と同じく既定のフィルタは「ステータス = アクティブ」(外せる)。行の右クリック/「…」メニューは Redmine の `context_menus/projects`: 1 件ならアーカイブ(確認あり)/解除と削除(概要画面の識別子入力へ)、複数なら一括削除のみ(対象とサブプロジェクトを列挙し、パスワード再確認+「はい」の入力=`bulk_destroy`)。**対象外**: クローズ/再オープン(Redmine の管理メニューにも無い、概要画面で可)、コピー(本アプリでは対象外と決定済み)、`ProjectAdminQuery` の保存クエリ(A2-05b)。削除は Redmine のジョブ化(`DestroyProjectsJob`)ではなく同期。アーカイブは概要画面と同じく当該プロジェクトのみ(Redmine の子孫への連鎖は A3-13) | `/admin/projects` 相当: 全ステータス横断・フィルタ・一括アーカイブ/削除 | A2-03 の基盤上に | S〜M | — (checklist 未掲載) |
| A2-05b | 管理画面のプロジェクト一覧の保存クエリ(Redmine の `ProjectAdminQuery`: 管理者だけが見る・編集できる別種のクエリ、サイドバーに一覧) | **done(2026-09-24)**。`QueryType::ProjectAdmin`(`project_admin`)。`Query::visibleTo()` はこの種別だけ Redmine の `ProjectAdminQuery#visible?` と同じく管理者なら誰の・どの公開範囲でも可、非管理者(匿名を含む)は不可。`visibleGlobally()` も管理者には全件、他は空。管理のプロジェクト一覧に「保存済みクエリ」(読込でフィルタ・列・並べ替えを復元)と「クエリを保存」(名前のみ。公開範囲は意味を持たないため非公開で保存し「すべての管理者に表示されます」と表示)。プロジェクト一覧の保存クエリとは混ざらない。REST `GET /queries?type=project_admin` は非管理者には空、`project_id` 付きは管理者以外 403。編集・削除の画面は他の種別と同じく未実装。テスト: `AdminProjectListTest` | `QueryType::ProjectAdmin`(`visibleTo()` は管理者のみ、REST `/queries` の `type` にも出すなら管理者限定)と保存/読込 UI | `Query::visibleTo()`・`IndexQueryRequest` の型の追加を伴う | S | — |
| A2-06 | 課題レポートのドリルダウン(`reports#issue_report_details`)・サブプロジェクト集計・CSV | 1画面のグリッドのみ | 各軸(トラッカー/優先度/担当者/作成者/バージョン/カテゴリ/サブプロジェクト)の詳細ページと CSV | 旧: 意図的簡略化 | S〜M | 「課題レポート」 |
| A2-07 | ページサイズ選択(`per_page_options`)と検索結果ページネーション(`search_results_per_page`) | どの一覧にもページサイズ `<select>` なし。検索結果はページネーション自体なし | 共通コンポーネント `<x-per-page-select>` を作り課題/工数/プロジェクト/News/文書一覧に配置。検索結果に `LengthAwarePaginator` | — | S〜M | 設定「全般」 |
| A2-07b | 一覧のページ分割そのものが無い画面(工数一覧・グローバル工数一覧・プロジェクトのお知らせ一覧・文書一覧)に `PageSize`/`SelectsPageSize`/`<x-per-page-select>`(A2-07 で追加)を適用。工数一覧は合計・グループ化・一括選択が全件前提のため、合計とグループ見出しを SQL 集計に移す作業を伴う | いずれも `->get()` で全件取得(`time-entries/index.blade.php:126`、`time-entries/global-index.blade.php:116`、`news/index.blade.php:27`、`documents/index.blade.php:39`) | 工数一覧は合計を全件集計、行はページ分だけ描画。CSV は全件のまま | A2-07 | M | 設定「全般」 |
| A2-08 | 工数フィルタ: `subproject_id`/`issue.parent_id`/`issue.status_id`/`issue.fixed_version_id`/`issue.category_id`/`issue.subject`/`user.group`/`user.role`/`author_id`/`project.status`、列: `project`/`created_on`/`tweek`/`author`/CF | **done(2026-09-24)**。`TimeEntryFilterFieldRegistry` + 新規 `TimeEntryExtraFilterFields`: `issue_id`(ツリー、カンマ区切り可)/`issue_tracker_id`/`issue_parent_id`/`issue_status_id`/`issue_fixed_version_id`/`issue_category_id`(プロジェクト一覧のみ)/`issue_subject`/`user_group`/`user_role`/`author_id`/`project_status`(横断一覧とサブプロジェクトを持つプロジェクト)/`comments`/`created_at`。キーはドットを `_` に。課題側の条件は閲覧できる課題だけを見る(Redmine の `left_join_issue` と同じ。Redmine が可視性を見ない `issue.fixed_version_id`/`issue.parent_id` も同様)。列: `project_id`(プロジェクト一覧にも)/`created_at`/`tweek`/`author_id`。見えない課題の工数は「#番号」だけ表示。`subproject_id` は A1-17e(2026-09-24)で対応、CF フィルタと関連 CF 列は A2-08b | 課題側の JOIN フィルタと列を工数側に移植 | A1-17 と同じ `FilterableField` 実装を共有 | M | クエリ「列選択」 |
| A2-08b | 工数一覧のカスタムフィールド: フィルタ(工数 CF `cf_N`、`issue.cf_N`、`project.cf_N`、`user.cf_N`。`add_custom_fields_filters`/`add_associations_custom_fields_filters`)と列(`issue.tracker`/`issue.parent`/`issue.status`/`issue.category`/`issue.fixed_version` の関連列、課題 CF・プロジェクト CF の関連列。`time_entry_query.rb` の `available_columns`) | 工数 CF は列のみ(A8-02)。フィルタは A2-08 の標準項目だけ | `CustomFieldFilter` を工数/関連モデル向けに使い、関連列は閲覧できる課題・閲覧ロールで見える CF だけ値を出す | A2-08 で分離。関連列は課題の可視性判定を行ごとに要する | S〜M | クエリ「列選択」 |
| A2-09 | 課題一覧の合計行の設定化(`issue_list_default_totals`)、工数一覧既定(`time_entry_list_defaults`) | 合計は予定/実績を固定表示、設定キーなし | 合計対象列(予定/実績/残工数/数値 CF)を設定で選択 | — | S | クエリ「合計/集計」 |
| A2-10 | プロジェクト一覧クエリの残り: 「プロジェクト」フィルタ(`id`、値に `<< 自分のプロジェクト >>`/`<< ブックマーク >>`、`query.rb:605`)、列 `last_activity_date`、一覧の CSV 出力(`projects.csv`)、ボードのカードで説明を Markdown 描画しカスタムフィールドを表示、表示形式(`display_type`)を保存クエリに含める | **done(2026-09-24)**。(1) `ProjectFilterFieldRegistry` にフィルタ「プロジェクト」(`id`)。値は `<< マイプロジェクト >>`(`mine`=自分がメンバーのプロジェクト)・`<< ブックマーク >>`(`bookmarks`)と閲覧できるプロジェクト(木順)で、実行時に置換(該当なしなら 0 件。`=`/`!` は複数値の IN/NOT IN)。「親プロジェクト」も同じ置換。Redmine は該当がある利用者にだけ 2 つを出すが、本アプリはログイン中なら常に出す(保存クエリ・REST で使ったときに未知の値として落ちて全件になるのを防ぐ)。REST `GET /projects.json` も同じ(`f[]=id&v[id][]=mine`)。(2) 列「最終活動日」(`last_activity_date`、並べ替え不可 = Redmine の sortable なし)。新設 `App\Support\Activity\LastActivityProvider`(Redmine の `find_events(:last_by_project)`)を 9 つの活動プロバイダが実装し、各 `view_*` と課題の可視性・非公開注記を踏まえてプロジェクトごとの MAX を 1 クエリで取得(`ProjectLastActivity`)。表示中のページ分だけ、列を選んだときだけ読む。工数は Redmine と同じく作成日時。管理のプロジェクト一覧にも同じ列。(3) 「CSVエクスポート」(`projects.csv`、ページではなく一覧の全件、表示列、UTF-8 BOM)。(4) ボードのカード: 説明を Redmine の `short_description`(255 文字を超えた行で切る)で Wiki Markdown 描画、閲覧できるプロジェクトカスタムフィールドの値を表示(**Redmine 7.0 のボードにカスタムフィールドは無い** — 指示により追加)。(5) `queries.options`(JSON)列を追加し、保存クエリに `display_type` を保存・読込(未保存の既存クエリはサイトの既定)。「ブックマークしたプロジェクトのみ表示」チェックボックスは互換のため残す。テスト: `ProjectListLeftoversTest` | 各項目を `ProjectFilterFieldRegistry`/`projects/index.blade.php` に追加。表示形式の保存は `queries.options`(JSON)列の追加が要る | A2-03 の基盤上に | S〜M | Projects「プロジェクト一覧」 |

### A-3. プロジェクト / メンバー / ロール

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A3-01 | 一般ユーザーによるトップレベルプロジェクト作成(`add_project` グローバル権限、`Role#permissions_all_trackers` と同様の非プロジェクト権限) | `ProjectPolicy::create` は管理者のみ `true` | グローバル権限(プロジェクト非依存)を `PermissionServiceProvider` に導入し、非メンバーロールにも付与可能に。作成者を自動で `new_project_user_role_id` のメンバーにする | 現状 `new_project_user_role_id` 設定は管理者作成時のみ意味を持つ | M | Projects「プロジェクト作成」 |
| A3-02 | 権限 `select_project_publicity`(公開/非公開の切替を `edit_project` から分離、Redmine 5.1〜) | `edit_project` に包含 | 権限追加+プロジェクト編集フォームの `is_public` を条件表示 | A13 も参照 | S | ロール・権限 節 |
| A3-03 | 子プロジェクトのメンバー継承(`projects.inherit_members`、`Member.inherited_from`) | 2026-09-24 完了(A3-03a/b、設計メモ案 A=実体化)。REST の継承表示は A11-16 | 列追加、親メンバー変更時に子へ伝播するオブザーバ、継承メンバーは子側で削除不可(`Member#deletable?`) | REST Memberships の `inherited_from` 露出も同時に | M | Projects「サブプロジェクト」、REST API「Memberships」 |
| A3-03a | 列 `projects.inherit_members`(既定 false)・`member_roles.inherited_from`(`member_roles.id` への自己参照 FK、削除は連鎖)、`MemberInheritance::sync()`、プロジェクトフォームの「メンバーを継承」、切替時の同期 | 2026-09-24 実施。`sync()` は親の現在の `member_roles` との差分を取る冪等な再計算(元の行が無くなった継承行を削除、足りない行を追加、ロールが 0 になったメンバーを `Member::delete()` で削除、継承している子へ再帰)。グループのメンバー行はグループ行のまま複製し、ユーザーへの展開は従来どおり動的。**Redmine との差**: 1 メンバー 1 ロール 1 行(`unique(member_id, role_id)` を維持)のため、子で直接付与済みのロールは継承行を作らない(直接行が優先。直接ロールを外すと次の同期で継承行が戻る)。Redmine は同じロールを直接と継承の 2 行で持つ。チェックボックスは親を閲覧できない利用者には効かない(Redmine の `safe_attributes`) | — | 設計メモ `gap-A3-03.md` | M | Projects「サブプロジェクト」 |
| A3-03b | 親のメンバー/ロール変更の伝播、継承行の保護(画面・REST)、プロジェクト移動とサブプロジェクト新規作成での同期 | 2026-09-24 実施。ロールの書き込みはすべて `Member::syncDirectRoles()`(直接ロールだけを置き換え、継承ロールは送信内容にかかわらず残す=Redmine の `Member#role_ids=`。書き込み後に `MemberInheritance::sync()`)経由: メンバー画面・REST `MembershipController`・管理画面のユーザー/グループの「プロジェクト」・`Project::addDefaultMember()`(継承済みの作成者にも既定ロールを追加できるよう `firstOrCreate`)。`Member::deleted` で継承先を再同期。`Project::saved` で新規作成・`parent_id` 変更時も同期(Web フォームと REST の移動の両方)。継承ロールを持つメンバーは子で削除不可(画面は削除ボタンを出さず 403、REST は 422=Redmine と同じ)、編集時は継承ロールをチェック済み・無効で表示。一覧に「親プロジェクトから継承」 | 各書き込み経路から `MemberInheritance` を呼ぶ。継承ロールは子で外せず、継承ロールを持つメンバーは削除不可(`Member#deletable?`) | A3-03a | M | Projects「サブプロジェクト」、REST API「Memberships」 |
| A3-03c | 継承の解除前の警告(Redmine: 親経由でしか入れない非管理者が「メンバーを継承」を外すと自分が入れなくなる旨を確認)と、ロール削除でロールを失ったメンバーの後始末 | **done(2026-09-24)**。(1) プロジェクトフォーム: 継承中のプロジェクトを、親のメンバー(直接/グループ、`AuthorizationService::isMemberOf()`=Redmine の `member_of?`)である非管理者が編集するとき、「メンバーを継承」のチェックを外すと確認し、キャンセルで元に戻す(Redmine の `projects/_form.html.erb`)。(2) ロール削除: Redmine は `Role#check_deletable` で使用中(`members.any?`)と組み込みのロールの削除を拒否するため、後始末ではなく同じ拒否にした(一覧にエラーと該当プロジェクト、`Role` の `deleting` でも例外)。これでロールなしのメンバーは生じない。**既存データ**: 以前のロール削除で既にロールを失ったメンバー行があっても削除しない(権限は持たない。データを消す変更は承認が要るため) | 解除時の確認ダイアログ、ロール削除時にロールなしメンバーを削除(または同期) | A3-03b で分離 | S | 「サブプロジェクト」 |
| A3-04 | プロジェクトの `homepage` 列 | `projects` に列なし | 列+フォーム+概要画面リンク+API | — | S | — (checklist 未掲載) |
| A3-05 | クローズ中プロジェクトの編集ブロック | クローズ中でも設定変更・再オープン可能 | Redmine の `Project#allows_to?`(クローズ時は読み取り権限と `close_project` のみ)を `ProjectPolicy` に反映 | 旧: 実装上の判断 | S | Projects「クローズ/再オープン」 |
| A3-06 | サブプロジェクトの課題を親の一覧に含める(`display_subprojects_issues` 設定、`subproject_id` フィルタ) | 設定なし(grep 0件)。一覧・工数合計はプロジェクト自身のみ | 設定追加、課題一覧/ガント/カレンダー/工数合計で子孫プロジェクトを既定で含める。フィルタ `subproject_id` | A1-17 と連動 | M | 工数管理「プロジェクトの実績工数合計」 |
| A3-06b | `display_subprojects_issues` を課題レポート・ガント・カレンダー・バージョン画面・活動にも適用し、`subproject_id` フィルタ(=/!/!*/全サブ)を足す | 課題一覧と工数合計のみ `SubprojectScope` を使う | 各画面のプロジェクト絞り込みを `SubprojectScope::projectsForIssues` に置換。フィルタは A1-17 と同時 | A3-06 で分離 | M | 設定「課題トラッキング」 |
| A3-07 | プロジェクト一覧: フィルタ使用時のツリー維持、可視祖先基準のインデント | フィルタ時はフラット表示、深さは絶対深度 | 可視プロジェクトのみでツリーを再構築(`kalnoy/nestedset` の `toTree()`) | 旧: 意図的簡略化 | S | Projects「プロジェクト一覧」 |
| A3-08 | グループメンバーのロール編集 | 削除→再追加のみ | メンバー編集フォームでグループ行も編集可能に | — | S | Projects「メンバー管理」 |
| A3-09 | メンバー単位のメール通知選択(`members.mail_notification`、ユーザーの `mail_notification = selected`) | `members` に列なし。`selected` は `only_my_events` に縮退 | 列追加、プロフィールの通知設定に「選択したプロジェクトのみ」+プロジェクト一覧チェックボックス、`NotificationRecipients` で判定 | — | M | Journal「メール通知(課題)」 |
| A3-10 | ロールの既定作業分類(`roles.default_time_entry_activity_id`) | 列なし(grep 0件) | 列+ロールフォーム+工数入力フォームの初期値 | — | S | 工数管理 節 |
| A3-11 | プロジェクト単位の作業分類オーバーライドの権限 `manage_project_activities` | 作業分類のプロジェクトスコープ自体は実装済み(`enumerations` migration)。専用権限なし | 権限を追加しプロジェクト設定「作業分類」タブをゲート | A13 参照 | S | Enumerations 節 |
| A3-12 | 管理画面の「ユーザー → プロジェクトメンバーシップ」タブ(`principal_memberships`) | `users/form.blade.php` にメンバーシップ管理なし(grep 0件)。グループ側も同様 | ユーザー/グループ編集画面に所属プロジェクトとロールの追加/変更/削除タブ | `MemberPolicy` を再利用 | S〜M | ユーザー管理・認証 節 |
| A3-13 | アーカイブ/クローズの子孫への連鎖と前提チェック(Redmine `Project#archive`: 子孫も archived、他プロジェクトの課題が子孫のバージョンを対象にしていれば拒否。`unarchive`: アーカイブ済みの祖先も解除し、祖先がクローズならクローズに戻す。`close`/`reopen`: 子孫も) | **done(2026-09-24)**。`Project::archive()`(自身と子孫を archived。自身/子孫のバージョンを子孫以外の課題が対象にしていれば `false`=「このプロジェクトはアーカイブできません」)、`unarchive()`(自身とアーカイブ済みの祖先を解除、アーカイブされていない祖先にクローズがあればクローズに戻す。子孫はアーカイブのまま)、`close()`/`reopen()`(自身と子孫のうちアクティブ/クローズのものだけ)。Redmine と同じく子孫の権限は確認しない(操作の権限は対象プロジェクトで判定)。概要画面・管理のプロジェクト一覧(拒否時はエラー表示)・REST(`archive` 拒否時は 422 `errors`、`unarchive` はアクティブなら何もしない)が共用。テスト: `ProjectStatusTest`・`AdminProjectListTest`・`ProjectApiTest` | `Project` にアーカイブ/解除/クローズ/再オープンのメソッドを置き、両画面と REST(`archive`/`unarchive`/`close`/`reopen`)から呼ぶ。拒否時はメッセージ | 既存の挙動が変わる(子孫も閉じる)ため挙動変更ログに記載 | S〜M | Projects「アーカイブ/アーカイブ解除」「クローズ/再オープン」 |

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
| A4-10b | 姓名分離(`firstname`/`lastname`)と `user_format` の全形式 | **A4-10b-1 done(2026-09-24、設計メモの案 A)**: `users.firstname`(30)/`lastname`(255)を任意で追加(既存データは分割しない)。`User::displayName(?format)` が Redmine の 8 形式(`firstname_lastname`〜`lastname`)+本アプリの `name`/`name_login`/`login` の 11 形式に対応し、姓・名の両方が無いユーザーは `name` で表示。姓・名が両方あれば保存時に `name` を既定形「名 姓」に同期(`User::booted()` の `saving`、全経路共通)。管理画面のユーザーフォーム・アカウント設定・自己登録・Fortify のプロフィール更新に姓・名(`User::nameRules()`: 名前は姓・名の両方が無いときだけ必須、姓・名は両方か無しか)。アカウント削除で姓・名も消去。**A4-10b-2 done(2026-09-24)**: A4-10a で対象外だった表示も `displayName()` に: ユーザーの選択肢(担当者 `AssigneeChoice`・ウォッチャー候補・メンバー/グループの候補・工数のユーザー・カテゴリの担当者・プロジェクトの既定の担当者・コミッターの対応付け・Webhook の所有者)、絞り込みの選択肢(`User::nameOptions()`: 課題/工数の作成者・担当者・ユーザー)、課題の担当者名(`Issue::assigneeName()`、一覧・CSV・PDF)、課題レポート・工数レポートのユーザー、ファイルの活動、通知メールの操作者(課題・Wiki・お知らせ・文書/ファイルの件名)、API の作成者/担当者/ウォッチャー/コメント者/既定の担当者の `name`(Redmine と同じく書式済み)、ユーザー詳細の見出し、アバターの頭文字(Redmine の `User#initials`)。管理画面のユーザー一覧に「姓」「名」列と絞り込み(Redmine の UserQuery)。**対象外**: API のユーザー自身の `name`(従来どおり保存値)、並び順(Redmine は形式の順 `fields_for_order_statement`、本アプリは `name` 順のまま)、検索の照合(`name`)、課題の履歴に保存済みの名前。**A4-10b-3 done(2026-09-24)**: 認証ソースに「姓の属性」「名の属性」(`auth_sources.attr_firstname`/`attr_lastname`、任意。Redmine の `attr_firstname`/`attr_lastname`)。両方の値があるエントリは姓・名を設定(名前は同期)、無ければ従来の氏名属性。再認証時も同様に更新。ユーザーの CSV インポートに「姓」「名」(両方マッピングすれば名前は任意)。REST API `POST/PUT /users`・`PUT /my/account` が `firstname`/`lastname` を受け付け、`GET /users` の応答に両フィールド(未入力は null)。`PUT` では片方だけの変更も可。**対象外**: API のユーザー一覧の `name` 絞り込みは `name`/メールのみ(Redmine は姓・名・ログインも)。旧: `users.name` 単一列 | 列分割マイグレーション(既存 `name` を空白で分割)、フォーム/API/インポート/LDAP 属性マッピングの全経路を更新 | **設計判断**(スキーマ変更・全画面に波及)。設計メモ→承認後に着手 | L | 同上 |
| A4-11 | アバター(`gravatar_enabled`/`gravatar_default`)、Redmine 6.0 の添付アバター | なし(grep 0件) | Gravatar URL 生成ヘルパー+設定、課題詳細/Journal/メンバー一覧に表示 | — | S | 設定「表示」 |
| A4-12 | ユーザーのタイムゾーン(`default_users_time_zone`、`users.time_zone`)と日付/時刻形式(`date_format`/`time_format`/`timespan_format`) | **A4-12a done(2026-09-24)**: `users.time_zone`(IANA 名)、設定 `default_users_time_zone`(「新規ユーザーの既定の個人設定」、空=サーバーの `app.timezone`)、プロフィールの個人設定に「タイムゾーン」、`App\Support\Locale\TimeZones`(本人→設定→サーバーの順)と `App\Support\Format\DateTimes`(`date()` は日付のみの値をそのまま、`dateTime()`/`dateOf()`/`time()` は閲覧者のゾーンへ、`today()` は Redmine の `User#today`、`dayBounds()` は日付フィルタ用、`asViewer()` はメールの受信者用)。`timespan_format` は A8-07 で `Hours` に実装済み。**A4-12c done(2026-09-24)**: 設定「日付の形式」「時刻の形式」(Redmine の `Setting::DATE_FORMATS`/`TIME_FORMATS` 9+2 種を strftime のまま保存、空=ISO `Y-m-d`/`H:i`)を `DateTimes` に反映、月名・午前/午後は表示言語、課題の履歴・メール・Atom の開始日/期日の変更も日付形式で。**A4-12b done(2026-09-24)**: 表示箇所を `DateTimes` に置換(ガード `DateTimeDisplayGuardTest`)、「今日」の既定値と日時列の日付フィルタを閲覧者のゾーンに、メールは受信者のゾーン。旧: `config/app.php` の単一タイムゾーン。ユーザー列なし | ユーザー列+プロフィール選択、表示時に `Carbon::setTimezone()`。日付形式は設定で選択し Blade ヘルパーで統一 | A14-01(i18n)と同時に扱うのが効率的 | M | 設定「表示」 |
| A4-12d | 日付形式(`date_format`)を日付型カスタムフィールドの表示値(課題詳細・一覧/CSV・工数など)と工数レポートの期間見出し、カレンダー/ガントの見出しにも適用。管理画面のユーザー編集にタイムゾーン欄(Redmine は管理者も個人設定を編集できる) | **done(2026-09-24)**: `CustomFieldValue::displayValue()`/`HasCustomFields::customDisplayValue()` が日付型だけ `DateTimes::date()` を通す(`value()`/`customValue()` はフォーム・API・絞り込み用にそのまま)。課題詳細・課題一覧(画面/CSV、全体一覧も)・PDF・文書・工数一覧・プロジェクト一覧(管理画面も)に適用。課題の履歴(画面・メール・Atom)の日付型カスタムフィールドの変更も `JournalDetail::displayValue()` で日付形式に(従来は `2026-09-04 00:00:00` のまま出ていた)。`DateTimes::month()`(日付形式から日を除いた形、既定 `Y-m`)を工数レポートの月見出し・ガントの月見出し・カレンダーの見出し(日付形式を選んだときだけ。未設定は従来の「2026年9月」)に、工数レポートの日見出しは `DateTimes::date()`。管理画面のユーザーフォームに「言語」「タイムゾーン」(A4-12a の「管理画面にはタイムゾーン欄なし」を解消)。旧: A4-12c で `DateTimes::date()` を追加したが、カスタムフィールドの値は `DateFormat::castValue()` が保存値(`Y-m-d`)をそのまま返し、フォームと表示で共用している。`users/form.blade.php` に言語・タイムゾーンの欄がない | 表示用の経路(`customFieldCellValues()` 等)で日付型だけ `DateTimes::date()` を通す(フォームの値は ISO のまま) | 既定(空)なら表示は変わらない | S | 設定「表示」 |
| A4-10b-4 | ユーザーの並び順を表示形式に合わせる(Redmine の `User.fields_for_order_statement`: `lastname_firstname` 系なら姓→名→id の順) | **done(2026-09-24)**: `User::orderFieldsForFormat()`(Redmine の `USER_FORMATS[:order]`: 名系は名→姓、姓系は姓→名、`firstname`/`lastname` は片方、`login` はログイン(空なら名前)、`name`/`name_login` は名前)、SQL は `User::scopeSortedByFormat()`、取得済みのコレクションは `User::sortByFormat()`(どちらも大文字小文字を無視し最後に id。姓・名の両方が無いユーザーは表示と同じく名前で)。適用: `Project::assignableUsers()`(担当者・カテゴリ/プロジェクトの既定担当者・一括編集)、`User::nameOptions()`(課題/工数の絞り込み・工数レポート)、ウォッチャー候補、メンバー/グループへのユーザー追加候補、工数のユーザー選択(個別・一括)、ユーザー形式のカスタムフィールド、コミッターの対応付け、Webhook の所有者、課題レポートの作成者。**対象外**: 管理画面のユーザー一覧と REST `GET /users` の既定順(名前順のまま。Redmine の UserQuery の既定はログイン順)。テスト: `UserSortOrderTest.php` | 並べ替え用のスコープ(`User::scopeSortedByFormat()`)を用意し、担当者・ウォッチャー・メンバー候補・絞り込みの選択肢に適用。姓・名が無いユーザーは `name` で | — | S | 設定「ユーザー」 |
| A4-10b-5 | REST API `GET /users?name=` の照合範囲と LDAP 再認証時の姓・名の更新 | **done(2026-09-24)**: `GET /users?name=` は `User::scopeMatchingName()`: 名前・ログイン・メール・追加のメールアドレスの部分一致、または空白で区切った語のすべてが姓か名に一致(Redmine の `Principal.like`、大文字小文字を区別しない、LIKE の `%`/`_` はエスケープ)。LDAP の再認証(`AuthenticateUser::reauthenticate()`)は、認証ソースに姓または名の属性が設定されていてエントリが姓・名の両方を返さないとき、保存済みの姓・名を消す(名前は氏名属性、無ければ保存値)。姓・名の属性が未設定のソースでは、アプリで入力した姓・名はそのまま(Redmine は再ログインで属性を更新しないので、消す範囲を「ディレクトリが提供していた」ソースに限定)。テスト: `UserApiTest.php`、`Ldap/AuthenticateUserTest.php` | `name` の照合に `firstname`/`lastname`/`login` を追加。再認証で姓・名の属性が空なら姓・名を消して氏名属性の名前に戻す | 再認証の変更は既存ユーザーの表示名を変えうる | S | REST API「Users」、「LDAPオンザフライ登録」 |
| A4-13 | ユーザー個人設定(`UserPreference`): `comments_sorting`、`warn_on_leaving_unsaved`、`notify_about_high_priority_issues`、`textarea_font`、`recently_used_projects`、`history_default_tab`、`default_issue_query`/`default_project_query`、`auto_watch_on`(+設定 `default_users_auto_watch_on`)、`hide_mail`(+`default_users_hide_mail`) | `users` に `mail_notification`/`no_self_notified`/`language` のみ。設定基盤なし | `user_preferences` テーブル(または JSON 列)とプロフィール画面のセクション。各設定を消費する箇所(Journal 並び順、履歴既定タブ、自動ウォッチ、公開プロフィールのメール非表示)を配線 | A1-04、A2-02、A9-08 が依存 | M | 設定「ユーザー」 |
| A4-13b | A4-13 の個人設定のうち未配線のもの: ~~`notify_about_high_priority_issues`~~(A6-05 で実装済み)、`history_default_tab`(課題履歴の既定タブ。本アプリに履歴タブなし)、`recently_used_projects`(最近使ったプロジェクトの数。プロジェクトジャンプボックスなし)、`default_project_query`(プロジェクト一覧の既定クエリ。プロジェクトの保存クエリなし) | 2026-09-24 完了。`default_project_query` を A2-03b で配線(プロフィールの選択欄、`DefaultProjectQuery`)。`notify_about_high_priority_issues`(A6-05)・`history_default_tab`(A1-04)・`recently_used_projects`(A9-07)は配線済み | 消費先が無い設定は、消費先の機能(履歴タブ・ジャンプボックス・プロジェクトクエリ)を作る行で同時に。`notify_about_high_priority_issues` は `NotificationRecipients::forIssue` に条件を足す | A4-13 で分離 | S | 設定「ユーザー」 |
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
| A5-05 | `assignee_dropdown_display_format`(担当者ドロップダウンにグループ名等を表示) | **done(2026-09-24)**: 設定「課題トラッキング」に「担当者ドロップダウンの表示形式」(`users_then_groups` 既定 / `groups_then_users` / `users_by_group`、グループ割当がオフのときは無効化)。`AssigneeChoice::optionGroups()` と `<x-assignee-options>` で課題フォーム・一括編集の担当者選択に適用(グループを出さないときは従来どおりの平らな一覧)。Redmine の課題編集時の「関係者」optgroup(作成者・以前の担当者)は未対応。テスト: `IssueGroupAssignmentTest.php` | 担当者選択の表示形式設定 | S | A1-20 |
| A5-06 | `ui_theme` | ~~テーマ切替基盤なし~~ → A5-06(2026-09-24): トークン値を差し替えるダークテーマを `app.css` に追加、`ThemeableViewsTest` で生の色クラスを禁止 | Tailwind のダーク/ライトまたは複数カラーテーマを CSS 変数で切替、設定+ユーザー設定 | M | A14-03 |
| A5-07 | `attachment` 系: `bulk_download_max_size`、`file_max_size_displayed`、`diff_max_lines_displayed`、`thumbnails_size`(`thumbnails_enabled` は設定キーとして未定義) | `attachment_max_size`/`attachment_extensions_*` は `AttachmentValidationRules.php` で実装済み。残りなし | 一括 ZIP ダウンロード上限、リポジトリ/Wiki のファイル表示上限、Diff 行数上限、サムネイル寸法 | S | A7-10, A10-06 |
| A5-08 | `wiki_compression`、`wiki_tablesort_enabled` | Wiki 本文は非圧縮保存、テーブルソートなし | 圧縮は優先度低(スキップ可)。テーブルソートはクライアント JS で `<table>` にソート可能属性を付与 | S | A7 |
| A5-09 | `timelog_*`: `timelog_required_fields`、`timelog_max_hours_per_day`、`timelog_accept_0_hours`、`timelog_accept_closed_issues`、`timelog_accept_future_dates` | いずれもなし(grep 0件) | `TimeEntry` のバリデーションに5設定を反映(課題/コメント必須、1日上限、0時間拒否、クローズ課題拒否、未来日拒否)。Web UI/API/インポート/コミットキーワードの全経路 | S〜M | A8-01 |
| A5-10 | `mail_handler_*`: `mail_handler_api_enabled`/`mail_handler_api_key`(rdm-mailhandler.rb からの HTTP 受信)、`mail_handler_enable_regex_delimiters`、`mail_handler_enable_regex_excluded_filenames` | 受信は IMAP/POP ポーリングのみ、区切り/除外は完全一致・ワイルドカードのみ | API キー認証の `POST /mail_handler` エンドポイント、正規表現モードのトグル | S〜M | A12-06 |
| A5-11 | `emails_header`、`show_status_changes_in_mail_subject`、`default_users_hide_mail` | `emails_footer` のみ | ヘッダー文追加、件名の `(ステータス)` を設定で省略可能に、メール非表示の既定値 | S | A6 |
| A5-12 | `commit_ref_keywords`、`commit_update_keywords`(`done_ratio`/`if_tracker_id` 付き)、`commit_cross_project_ref`、`commit_logs_encoding`、`commit_logs_formatting`、`repositories_encodings`、`repository_log_display_limit`、`enabled_scm`(名称違い) | `commit_fixing_keyword_rules` は `{keywords, status_id}` のみ(`RepositorySyncService.php:23`)。refs キーワードはハードコード。他はなし | ルールに `done_ratio`/`tracker_id` を追加、参照キーワードを設定化、他プロジェクト参照の許可設定、ログのエンコーディング指定、履歴表示件数 | S〜M | A10 |
| A5-12b | `commit_logs_encoding`・`repositories_encodings`(SCM 出力を UTF-8 に変換)、`repository_log_display_limit`(履歴一覧の件数。現状 `repository/index.blade.php:83` が 100 固定)、`commit_logs_formatting`(コミットログの書式化) | いずれも設定なし | 履歴表示件数を設定化し、エンコーディング設定を各アダプタの出力変換に配線、`commit_logs_formatting` は changeset コメントの Markdown 化のオン/オフ | A5-12 で分離 | S | 設定「リポジトリ」 |
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
| A6-08 | 担当者を変更したとき、以前の担当者(グループならそのメンバー)にも通知する(Redmine の `Issue#notified_users` の `previous_assignee`、`User#notify_about?` の `only_assigned`/`only_my_events`) | **done(2026-09-24)**: `NotificationRecipients::forIssue()` に更新の Journal を渡し、`assigned_to_id`/`assigned_to_group_id` の明細の旧値から以前の担当者(グループは現在のメンバー)を求めて、現在の担当者と同じく関係者(ウォッチャー扱いの候補)と `only_assigned` の判定に加える。通知設定 `none` は除外、閲覧できなくなった人(非公開プロジェクトの非メンバー、閲覧範囲「自分の課題」)は既存の `can('view')` で除外。担当者を変えなかった後続の更新では対象外。テスト: `PreviousAssigneeNotificationTest.php` | Journal の `assigned_to_id`/`assigned_to_group_id` の旧値から以前の担当者を求め、候補と `only_assigned` 判定に加える | — | S | Journal「メール通知(課題)」 |

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
| A7-10 | 添付ファイルの一括 ZIP ダウンロード(`bulk_download_max_size`)、一括編集(`attachments#edit_all`) | なし | 課題/Wiki/文書の添付一覧に「すべてダウンロード」と「説明を一括編集」。**設定 `bulk_download_max_size`(既定 102400KB、A5-07 から移管)を同時に追加し、超過時は ZIP を作らない** | — | S | 添付ファイル 節 |
| A7-11 | `files` アクティビティプロバイダと `wiki_edits` の既定オフ(`activity.register :files`/`:wiki_edits, default: false`) | `app/Support/Activity/Providers/` は Changeset/Document/Issue/IssueJournal/Message/News/TimeEntry/Wiki の 8 種。Files モジュールの添付追加は活動に出ない | `FileActivityProvider`(バージョンに紐づく Media)を追加。種別チェックボックスの既定オン/オフをプロバイダ定義に持たせる | — | S | ダッシュボード「グローバルアクティビティフィード」 |
| A7-12 | 検索対象の添付ファイル(ファイル名/説明)(`SearchController` の `attachments` トグル、`attachment` フィルタ) | `SearchService` に添付検索なし | `searchAttachments()` を追加し、所有オブジェクトの可視性で絞り込み | API `GET /search` の `attachments` パラメータも | S | 検索(モジュール横断)、REST API「Search」 |
| A7-13 | 一覧画面でのリンク形式 CF のリンク化 | 課題一覧列ではプレーンテキスト | `<x-custom-field-value>` を一覧列にも適用 | 旧: 意図的簡略化 | S | カスタムフィールド「フィールド形式」 |
| A7-14 | 課題一覧・詳細でのサムネイル表示(`thumbnails_size`)、テキスト/PDF 添付のインラインプレビュー(`attachments#show`) | サムネイル生成は実装済み。プレビュー画面なし | 添付の `show` 画面(画像/テキスト/PDF/Diff の表示) | — | S〜M | 添付ファイル「サムネイル/画像変換」 |
| A7-15 | Wiki の ZIP エクスポートのファイル名: Redmine は `\` を `_` に、`/?%*:|"'<>` と改行を `_` に置換し、衝突したら `名前(1).txt` と番号を付ける(`wiki_controller.rb:440-455`)。本アプリは `/`・`\` を `-` に置換するだけで、`a/b` と `a-b` のようなページが同名ファイルになり ZIP 内で上書き/重複する | `resources/views/livewire/wiki/pages.blade.php` の `exportZip()` | Redmine と同じ置換規則+`(n)` の連番に揃える | C-26 の確認中に発見 | S | Wiki「ZIPエクスポート」 |

### A-8. 工数管理

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A8-01 | 工数の単体編集でのプロジェクト移動、および一括編集への時間/課題/プロジェクト/ユーザー追加(`TimelogController#update`/`#bulk_update`) | 単体編集フォーム(`time-entries/form.blade.php`)は issue_id/user_id/activity_id/hours/spent_on/comments を編集可でプロジェクト移動のみ不可。**一括編集**(`index.blade.php`)は作業分類/日付/コメントの3項目のみ(チェックリスト記載は正しい、C-17 取り下げ) | プロジェクト選択(移動先で `log_time` 権限があるもの)を追加し、課題との整合(課題が別プロジェクトなら解除)を検証。A5-09 のバリデーションを適用 | `edit_own_time_entries`(A13)で自分の分のみ許可 | S | 工数管理「TimeEntry CRUD」 |
| A8-02 | 工数のカスタムフィールド(`TimeEntryCustomField`、`CustomizableType::TimeEntry`) | `app/Enums/CustomizableType.php` に TimeEntry なし。`IssuePriority` もなし | `CustomizableType::TimeEntry`/`IssuePriority` を追加し、フォーム/一覧列/CSV/レポート軸/API に露出 | `Enumeration` は `match($this->type)` で既に多型化済み | M | 「TimeEntry CRUD」、カスタムフィールド 節 |
| A8-02b | 工数カスタムフィールドの残り: 工数の一括編集フォームでの変更、全体の工数一覧(`time-entries.global-index`)の列、工数の REST API(`custom_fields` の入出力。課題 API と同時に方針を決める) | A8-02 ではプロジェクトの工数フォーム・一覧・CSV のみ | 各画面へ同じ `customFields` を配線 | A8-02 で分離 | S〜M | 「TimeEntry CRUD」 |
| A8-03 | 工数の記録者と対象者の分離(`time_entries.author_id` と `user_id`、権限 `log_time_for_other_users`) | `user_id` のみ。他者分の記録は `edit_time_entries` を流用 | `author_id` 列追加、権限追加、API の `user_id` 指定を新権限でゲート | A13 | S | REST API「Time entries」 |
| A8-04 | 多次元レポート: カスタムフィールド軸、プロジェクト横断(`/time_entries/report`)、CSV(`report_to_csv`) | `TimeReportBuilder` は単一プロジェクト・固定軸・CSV なし | list/bool 型 CF を軸に追加、グローバル `/time_entries/report`、CSV 出力 | A8-02 が CF 軸の前提 | M | 工数管理「多次元工数レポート」 |
| A8-05 | プロジェクト横断の工数一覧での編集/削除/CSV/保存済みクエリ | `time-entries.global-index` は閲覧のみ | プロジェクト単位画面の機能を横断画面へ移植(可視性は `Role.time_entries_visibility` で判定済み) | — | S〜M | 「プロジェクト横断の工数一覧」 |
| A8-06 | 工数一覧のコンテキストメニュー(`context_menus/time_entries`) | なし | A1-05 の部品で作業分類/課題/一括編集/削除 | — | S | — |
| A8-07 | `timespan_format`(小数/時分表示) | 小数固定 | 設定+表示ヘルパー | — | S | 設定「表示」 |

### A-9. 横断ビュー(マイページ / 活動 / ガント / カレンダー / 検索)

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A9-01 | プロジェクト横断ガント(`/issues/gantt`)、PNG エクスポート、共有バージョンのマイルストーン、PDF 内の関連線、`gantt_items_limit`/`gantt_months_limit` | ~~ガントは `/projects/{project}/gantt` のみ~~ → A9-01a(2026-09-24): `gantt.global-index`(`/issues/gantt`)と共有バージョンのマイルストーンを追加。A9-01b(2026-09-24): PNG(GD)。A9-01c(2026-09-24): PDF の関連線(div の L 字) | `gantt.global-index` を他の global-index 群と同じ構成で追加。PNG は `Imagick`/`gd` で SVG→PNG。共有バージョン(`Version::sharing`)をマイルストーン候補に含める | 関連線の PDF は dompdf の SVG 対応が不安定なため要検証 | M | ダッシュボード「ガント」 |
| A9-02 | カレンダーへのバージョン期日表示 | `calendar/index.blade.php` に version の記述なし | プロジェクト(および共有)バージョンの `due_date` を◆で表示 | ガントのマイルストーン実装(`versions.roadmap`)を流用 | S | ダッシュボード「カレンダー」 |
| A9-03 | マイページのブロック: `issuesupdatedbyme`(自分が更新した課題)、`calendar`、同一クエリの最大3回配置(`max_occurs`)、ブロックごとの設定(`my_page_settings`: 列/ソート) | `app/Support/Dashboard/Blocks/` は Activity/AssignedIssues/Documents/LatestNews/ReportedIssues/TimeEntries/WatchedIssues + SavedIssueQuery。同一クエリは1つまで | `UpdatedByMeBlock`(Journal の user_id 基準)、`CalendarBlock`(週表示)、ブロック設定 UI | calendar ブロックは「一覧形式に馴染まない」として見送られていた | M | ダッシュボード「マイページ」 |
| A9-03b | マイページ: 同一の保存クエリを最大3回まで配置(`max_occurs`)、ブロックごとの設定(`my_page_settings`: 列/ソート) | 同一クエリは1つまで、ブロック設定なし | `user_dashboard_blocks` に設定 JSON 列を追加し、設定 UI とブロック側の反映を実装 | A9-03 で分離 | M | 「マイページ」 |
| A9-04 | グローバル活動: ページネーション/件数上限、`activity_scope` 個人設定、全プロジェクト Atom(`/activity.atom`) | `activity.global-index` は「可視プロジェクト数×8プロバイダ」を全件走査、Atom なし | 各プロバイダに複数プロジェクト対応の `entries()` を追加し1クエリ化、日付単位ページング、`ActivityFeedController` のグローバル版 | 8 プロバイダ全部の改修 | M | 「グローバルアクティビティフィード」 |
| A9-04b | グローバル活動の性能: 各プロバイダに複数プロジェクト対応の `entries()` を追加して1クエリ化し、日付単位ページング/件数上限を入れる | 現状は「可視プロジェクト数×プロバイダ数」の全件走査 | `ActivityProvider` に `entriesForProjects(Collection, ...)` を追加(8 プロバイダ全部を改修)、ページング | A9-04 で分離 | M | 「グローバルアクティビティフィード」 |
| A9-05 | `feeds_limit` 設定 | 定数 | A5-02 参照 | — | S | 設定「全般」 |
| A9-06 | 検索: `bookmarks` スコープ、結果ページネーション、添付検索 | `ProjectBookmark` は実装済み(`projects/index.blade.php` の `bookmarkedOnly`)だが検索スコープに未配線(`search/*.blade.php` grep 0件)。ページネーションなし | スコープ選択肢に「ブックマーク」を追加、`search_results_per_page` でページング(A2-07)、添付(A7-12) | チェックリストは「ブックマーク機能自体が無い」と記載(C-03) | S | 「検索(モジュール横断)」 |
| A9-07 | プロジェクトジャンプボックス(ヘッダーの検索付きプロジェクト切替)と `recently_used_projects` | なし(grep 0件) | ヘッダーにインクリメンタル検索付きドロップダウン、最近使ったプロジェクトを A4-13 に保存 | — | S | — (checklist 未掲載) |
| A9-08 | 課題一覧から `#123` 直接ジャンプは実装済み。Redmine の検索ボックスの `#123`/`r123`(リビジョン)/`p:`(プロジェクト)ショートカット | `#123` のみ | `r123` でチェンジセット、`project-identifier:` 形式を解釈 | 優先度低 | S | 同上 |

### A-10. リポジトリ(SCM)

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A10-01 | リポジトリ接続情報(`repositories.url`/`root_url`/`login`/`password`/`log_encoding`/`path_encoding`/`extra_info`) | `Repository` の fillable は project_id/type/path/last_synced_revision/is_default/identifier。認証情報・エンコーディングなし | SVN の URL+ユーザー/パスワード(暗号化 cast)、ログ/パスのエンコーディング指定を追加し `SvnAdapter`/`GitAdapter` に渡す | 認証情報は `encrypted` cast | S〜M | リポジトリ連携「対応SCM種別」 |
| A10-01b | リポジトリのリモート URL(`url`/`root_url`)とログイン/パスワード(`login`/`password`)、`extra_info` | **done(2026-09-24)**: `repositories.url`/`login`/`password`(`encrypted` cast、`#[Hidden]`)を追加し、SVN のみ `svn://`/`http(s)://` を登録可(`RemoteRepositoryUrl`/`RemoteRepositoryUrlGuard`、許可リスト `config('scm.allowed_hosts')`=`SCM_ALLOWED_HOSTS`、空なら無効。非公開/ループバック/リンクローカルのアドレスは IP/CIDR で明示した場合のみ。検証時と svn 実行ごとに DNS 再解決)。権限 `manage_remote_repositories`(既定ロールなし)。パスワードは `--password-from-stdin`、`--no-auth-cache`。`root_url`/`extra_info`・Git のリモートは対象外 | SVN の URL+資格情報(暗号化 cast)を許可する。**セキュリティ境界の変更のため要承認** | 設計メモ: `docs/design/gap-A10-01b.md`。A10-01 で分離 | M | リポジトリ連携「対応SCM種別」 |
| A10-02 | コミットキーワード設定の拡張(A5-12)、`commit_cross_project_ref` | `{keywords, status_id}` のみ、他プロジェクト参照は常に許可 | 参照キーワード設定化、更新ルールに `done_ratio`/`if_tracker_id`、他プロジェクト参照の許可トグル | — | S | 「コミットメッセージのキーワード連動」 |
| A10-03 | `/sys` WS(`sys/projects`、`sys/fetch_changesets`、`sys_api_key`)と `reposman.rb` 連携 | なし(grep 0件) | API キー認証の `GET /sys/projects.json`、`GET /sys/fetch_changesets?id=` を追加(post-receive フックからの同期トリガー用) | 既存 `RepositorySyncService` を呼ぶだけ | S | 設定「リポジトリ」 |
| A10-04 | Annotate の同一リビジョン連続行の色分けブロック | 全行に個別表示 | 連続する同一 revision をグループ化し交互に背景色 | 旧: 意図的対象外 | S | 「Annotate/Blame」 |
| A10-05 | `repository_log_display_limit`、`diff_max_lines_displayed`、`file_max_size_displayed` | 定数または無制限 | 設定化し `repository/*.blade.php` で参照 | — | S | 設定「リポジトリ」 |
| A10-06 | Filesystem アダプタ | **done(2026-09-24、B'-03)** | B'-03 参照 | — | — | — |

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
| A11-16 | Memberships の `inherited_from` | 2026-09-24 完了。`MembershipResource` に `inherited_role_ids`(`role_ids` のうち親から継承したもの。Redmine はロール配列の各要素に `inherited: true`、本アプリは既存の扁平な形に合わせた)。あわせて Projects API に `inherit_members` の読み書き(Redmine の `projects/show.api.rsb` と `safe_attributes`: 親を閲覧できない利用者の指定は無視) | A3-03 実装後に露出 | — | S | REST API「Memberships」 |
| A11-18 | `GET /projects.json` のクエリ対応(Redmine は `ProjectQuery` のフィルタ・`limit`/`offset` を API にも適用) | **done(2026-09-24)**。`Api/V1/ProjectController::index` は `AuthorizationService::visibleProjectIds(..., 'view_project')`(プロジェクト一覧と同じ)に `ProjectFilterFieldRegistry` のフィルタを `RedmineIssueListParams` 経由で適用し、`limit`/`offset`/`page` と `total_count`/`offset`/`limit` を返す。ロール制限のあるプロジェクト CF は管理者以外に出ない(既存の規則)。並びは従来の名前順のまま(Redmine の既定は階層順)。あわせて短縮形の演算子に `*~`/`^`/`$`(A1-36)を追加 | `ProjectFilterFieldRegistry`+`visibleProjectIds(..., 'view_project')` を API 一覧にも適用し、`limit`/`offset` を受け付ける | A2-03a の基盤上に | S | REST API「Projects」 |

### A-12. 拡張性(プラグイン / Webhook / 受信メール)

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 | checklist 行 |
|---|---|---|---|---|---|---|
| A12-01 | Webhook イベント `news.*`(Redmine 7.0 は Issue/News/TimeEntry/Version/WikiPage の 5 モデルが `acts_as_webhookable`) | `app/Enums/WebhookEvent.php` は issue/wiki_page/time_entry/version の 12 種。`news.*` なし | `NewsCreated`/`NewsCommentCreated` イベントは既存なので `DispatchWebhooksForNewsEvent` リスナーと Enum 値を追加 | — | S | 拡張性 節(Webhook は「本アプリ独自機能」と記載されていたが Redmine 7.0 でコア化、C-09) |
| A12-02 | Webhook の所有ユーザーと可視性判定(`Webhook#user`、`object.visible?(hook.user) && allowed_to?(:use_webhooks)`)、`webhooks_enabled` 設定、権限 `use_webhooks` | Webhook は管理者が登録するグローバル設定、ユーザー紐付けなし | `webhooks.user_id` 列を追加し配信時に可視性を判定、`use_webhooks` 権限(A13)、有効/無効設定 | 現行 Webhook の配信対象(全課題)からの後方互換に注意 | M | 同上 |
| A12-02b | ユーザー自身が自分の Webhook を管理する画面(`/my/webhooks`、`use_webhooks` 権限者のみ) | 管理者画面のみ | マイアカウントに Webhook 一覧/登録を追加(所有者は自分に固定) | A12-02 で分離 | S〜M | 「Webhook」 |
| A12-03 | プラグインのランタイム検出(`plugins/*/init.rb` 自動読込) | ~~`bootstrap/providers.php` への手動登録(`PluginManager` docblock)~~ → A12-03a(2026-09-24): `PluginLoader`・`PluginManifest`、`plugins/<id>/plugin.json`、設定 `plugins_enabled`。A12-03b: `/plugins` で有効/無効 | `plugins/` ディレクトリ走査+Composer オートロード登録、有効/無効フラグ | 旧: 意図的(第一段階) | M | 拡張性「ランタイムでのプラグイン検出」 |
| A12-04 | プラグイン独自の設定パーシャル(`settings :partial => '...'`) | 型推定の汎用エディタのみ | プラグイン定義に Blade ビュー名を渡せるようにし、あれば汎用エディタの代わりに描画 | — | S | 「プラグイン設定UI・永続化」 |
| A12-05 | モデル/コントローラのライフサイクルフック(`controller_issues_edit_before_save` 等) | ビュー描画フック(`<x-hook>`)のみ | 主要サービス(`IssueService::create/update/delete`、`TimeEntry`、`WikiPage`)の前後に Laravel イベント(既に `IssueCreated` 等はある)を整理し、プラグインが購読できるフック名一覧をドキュメント化 | 既存イベントの流用で大半は賄える | M | 「コントローラ/モデルのライフサイクルフック」 |
| A12-06 | 受信メール: API キー経由の受信(`mail_handler_api_*`)、`allow_override` によるキーワード上書き許可リスト、カスタムフィールドキーワード、`project_from_subaddress`、`default_group`、`no_account_notice`/`no_notification` | IMAP/POP ポーリング、11 キーワード固定、CF 非対応 | `POST /mail_handler`(A5-10)、キーワード許可リスト設定、CF 名一致でのキーワード、`+project` サブアドレス解釈 | `unknown_user`/`no_permission_check` は B-05 | M | 「メール本文のキーワードコマンド」 |
| A12-06b | 受信メールの残り: `allow_override` によるキーワード上書きの許可リスト、カスタムフィールド名でのキーワード、`+project` サブアドレスによるプロジェクト指定、`default_group`、`no_account_notice`/`no_notification` | `POST /mail_handler`(A12-06)は受信のみ。キーワードは 11 個固定・CF 非対応 | 許可リスト設定、CF 名一致でのキーワード解釈、宛先サブアドレスの解釈を `IncomingMailService` に追加 | A12-06 で分離 | M | 「メール本文のキーワードコマンド」 |
| A12-06c | 受信メールの `unknown_user`(未登録の差出人からのアカウント作成/匿名受付)、`no_permission_check`、`default_group`、`no_account_notice`、`no_notification` | 未登録の差出人のメールは捨てる(`From:` 詐称対策) | 設計メモ参照(実装しない案を推奨) | A12-06b で分離。セキュリティ判断 | M | 「メール本文のキーワードコマンド」 |
| A12-06d | 受信メールの `no_notification`(受信メールで作成・更新した内容の通知メールを送らない) | なし | 設定 `mail_handler_no_notification`。処理中だけ通知リスナーを止める(`MailSuppression`) | A12-06c で「実装しない」案が承認され、残りとして分離 | S | 「メール本文のキーワードコマンド」 |

### A-13. 権限の粒度(Redmine 80 権限中、本アプリに無い 27 件)

`lib/redmine/preparation.rb` と `app/Providers/PermissionServiceProvider.php`(54 権限、うち Redmine と同名 53)の機械照合。本アプリ独自の `move_issues` は Redmine にない(Redmine は `edit_issues`+移動先の `add_issues` で判定)。

| ID | 権限 | 現状の代替 | 残作業 | 規模 | 関連 ID |
|---|---|---|---|---|---|
| A13-01 | `add_issue_notes`、`edit_own_issues`、`set_own_issues_private`、`manage_subtasks` | `edit_issues`/`set_issues_private` に包含 | 権限追加+`IssuePolicy` で「自分の課題のみ」「コメントのみ」「サブタスク操作」を分離 | S〜M | A-1 |
| A13-02 | `view_issue_watchers`、`delete_issue_watchers`、`view_wiki_page_watchers`/`add_wiki_page_watchers`/`delete_wiki_page_watchers`、`view_message_watchers`/`add_message_watchers`/`delete_message_watchers` | 課題は閲覧権限でウォッチャー表示、Wiki は `edit_wiki_pages`、フォーラムは未対応 | 権限追加+各 Policy の `manageWatchers` を分離 | S | A1-01, A7-08 |
| A13-03 | `edit_own_time_entries`、`log_time_for_other_users`、`import_time_entries`、`import_issues` | `edit_time_entries` を流用、インポートは `add_issues`/`log_time` で判定 | 権限追加+Policy | S | A8-01, A8-03 |
| A13-04 | `manage_wiki`、`view_wiki_edits`、`delete_wiki_pages_attachments` | `delete_wiki_pages`/`edit_wiki_pages` に包含 | 権限追加+`WikiPagePolicy` | S | A7-05, A7-06 |
| A13-05 | `add_project`、`select_project_publicity`、`view_members`、`manage_project_activities`、`save_queries`、`search_project` | 管理者専用/`edit_project`/`view_project`/`manage_public_queries` に包含 | グローバル権限の仕組み(A3-01)を導入したうえで追加。`view_members` はメンバー一覧タブの表示ゲート、`save_queries` は非公開クエリ保存のゲート、`search_project` は検索対象プロジェクトの制御 | M | A3-01, A3-02, A3-11 |
| A13-05b | 権限 `save_queries`(課題/工数/ガントの保存クエリの作成・編集・削除のゲート。`allowed_to?(:save_queries, project, global: true)`)と `search_project`(検索のゲート) | 保存クエリは閲覧できれば誰でも保存でき、検索は `view_project` で開ける | 2 権限を登録し、既存ロールへ現行の挙動を保つ移行(保存クエリ=匿名以外の全ロール、検索=`view_project` 保持ロール)を付与、保存フォーム・検索ページ・API をゲート | A13-05 で分離 | S〜M | A13-05 |
| A13-06 | `commit_access`(リポジトリへの書き込み権限、`/sys` WS でのアクセス制御に使用) | なし | A10-03 と同時 | S | A10-03 |
| A13-07 | `use_webhooks` | なし | A12-02 と同時 | S | A12-02 |

### A-14. 横断基盤(checklist 未掲載)

| ID | Redmine 側の機能 | 本アプリの現状 | 残作業 | 前提・設計上の注意 | 規模 |
|---|---|---|---|---|---|
| A14-01 | 多言語対応(`config/locales/*.yml` 49 言語、`default_language`、`force_default_language_*`、ユーザーの `language`) | `lang/` ディレクトリなし。Blade/PHP に日本語をハードコード。`users.language` 列は存在するが UI で未使用(`profile/index.blade.php` grep 0件) | 全 UI 文字列を `__()` に置換し `lang/ja.json`/`lang/en.json` を作成、ユーザー言語選択、`SetLocale` ミドルウェア、日付/数値の `Carbon::locale()`。メールテンプレートも対象 | **L**。最初に英語と日本語の 2 言語で。Redmine の `config/locales/ja.yml` のキーを流用すると翻訳作業が減る | L |
| A14-01a | 多言語の土台: 表示言語の決定(ユーザーの言語 → 既定の言語)、`lang/ja.json`・`lang/en.json` | `lang/` なし、UI は日本語の直書き | `SetLocale` ミドルウェア、プロフィールの言語、設定 `default_language` と `force_default_language_*` | A14-01 で分離(設計メモ 推奨案 A) | S | 「多言語」 |
| A14-01b | 画面ごとの文字列を `__('原文')` で包み、`lang/en.json` に英訳を足す(課題 → プロジェクト → 工数 → Wiki → 管理画面、続いてメール、PDF/CSV、enum の `label()`) | A14-01b1(2026-09-23)で共通レイアウト(`components/layouts/app.blade.php`)と `livewire/issues/*` を置換済み。既定の言語を `ja`(`config/app.php`・`.env.example`・`phpunit.xml` の `APP_LOCALE`)に変更 | 原文がキーの JSON 方式 | A14-01a | M×n | 「多言語」 |
| A14-01b2 | 置換と英訳: プロジェクト(`livewire/projects/*`、メンバー、バージョン、カテゴリ、ロードマップ、プロジェクト設定) | 2026-09-23 実施。課題画面にも出る共通コンポーネント(`components/*.blade.php`: 新規作成メニュー・プロジェクトジャンプボックス・絞り込み・保存クエリ・表示件数など)も同時に置換。プロジェクトの「クローズ」ボタンは状態の「クローズ」と英訳を分けるため文言を「クローズする」に変更 | A14-01b1 と同じ方式。`TranslationCoverageTest::translatedViews()` に画面群を追加する | A14-01b で分離 | M | 「多言語」 |
| A14-01b3 | 置換と英訳: 工数(`livewire/time-entries/*`、工数レポート) | 2026-09-23 実施。工数を記録した人のラベルは「担当者」から Redmine の日本語訳と同じ「ユーザー」に変更(英語 User、2026-09-23)。「コメント」「レポート」は課題画面と共有のキーのため英語は Notes/Summary のまま(Redmine は Comment/Report) | 同上 | A14-01b で分離 | M | 「多言語」 |
| A14-01b4 | 置換と英訳: Wiki・フォーラム・お知らせ・文書・ファイル | 2026-09-23 実施 | 同上 | A14-01b で分離 | M | 「多言語」 |
| A14-01b5 | 置換と英訳: 管理画面(ユーザー/グループ/ロール/トラッカー/ステータス/ワークフロー/カスタムフィールド/値の一覧/設定/LDAP/プラグイン/情報)とマイページ・活動・ガント・カレンダー・検索・プロフィール・認証画面 | 2026-09-23 実施(Webhook・添付・リポジトリも含む)。活動の期間の「終了日」は課題の終了日(Closed)と分けるため「終了」に変更(英語 End date)。2FA の送信ボタンは見出しの「認証」と英訳を分けるため「認証する」に変更。`TranslationCoverageTest` は全ビューを走査するように変更 | 同上 | A14-01b で分離 | M | 「多言語」 |
| A14-01b6 | 置換と英訳: メール(`resources/views/mail/*`、件名)。受信者ごとの言語で送る(`Mailable::locale()`) | 2026-09-23 実施。`User` が `HasLocalePreference` を実装し(`SupportedLocales::forUser()`: 本人の言語、既定の強制時は既定の言語)、Laravel の通知がその言語で組み立てて送る。フォーラム/文書/ファイルの通知は件名・見出しを送信時に組み立てるように変更(`ProjectEventNotification::subjectLine()`/`headline()`)。課題メールの属性・関連のラベル定数はメソッドに(Atom フィードと共用)。パスワード再設定メールは Laravel 標準の英語文面のまま(従来どおり) | 受信者の `language` で `->locale()` を指定。キューのジョブ内でもロケールが効くことをテスト | A14-01b で分離 | M | 「多言語」 |
| A14-01b7 | 置換と英訳: PDF・CSV の見出し、Atom フィード | 2026-09-23 実施。PDF(課題・課題一覧・ガント)の見出しを置換。CSV の見出しは画面と同じ翻訳済みラベルから作るため A14-01b1〜b8 で対応済み(英語の利用者には英語の見出し。インポートの自動マッピングは英語のフィールド名で照合するので影響なし)。Atom フィードに日本語の固定文言はなかった | 同上 | A14-01b で分離 | S〜M | 「多言語」 |
| A14-01b8 | 置換と英訳: enum の `label()` と PHP 側のラベル表(`ListDefaults::ISSUE_TOTALS`、`RelatedIssueColumns`、`UserPreferences::HISTORY_TABS`、`IssueReport::title()`、`IssueFilterFieldRegistry` の項目名・演算子、`IssueNotificationMail` の関連ラベル) | 2026-09-23 実施。キーを検証・照合に使う定数(`ListDefaults::ISSUE_TOTALS`、`Tracker::DISABLABLE_CORE_FIELDS`、`UserPreferences::*`、`RelatedIssueColumns::AVAILABLE` など)は残し、表示用に `__()` を返すメソッドを追加。CSV インポートの値の別名(有効/ロック中/はい など)、DB に保存される自動生成の注記・削除済みユーザー名、プラグインの開発者向け説明は訳さない。曜日名は `月`/`日`(月/日の単位)と衝突するため Carbon から取る(`SupportedLocales::weekdayName()`)。Wiki の整形済みHTMLキャッシュのキーにロケールを追加。インポートの行エラーはジョブ実行時のロケール(既定の言語)で保存される | 定数は `__()` を呼べないため、ラベルを返すメソッドに置き換える(A14-01b1 の `displayColumns()`/`relationLabels()` と同じ) | A14-01b で分離 | M | 「多言語」 |
| A14-02 | ユーザータイムゾーン・日付/時刻形式 | A4-12 | — | — | M |
| A14-03 | テーマ切替(`ui_theme`) | ~~Tailwind 単一テーマ~~ → **done(2026-09-24)**: 設定 `ui_theme`・個人設定 `ui_theme`(`UserPreferences::theme()`)、`layouts/app` の `<html data-theme>` | A5-06 | — | M |
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
| B'-01 | Mercurial / CVS / Bazaar アダプタ | 2026-09-24: `docker/8.5/Dockerfile` に `mercurial`/`cvs`/`brz`(+移行用 `bzr`)を追加。**Mercurial 済**(`MercurialAdapter`、リビジョンはノードハッシュ、リポジトリの `.hg/hgrc` は `HGRCSKIPREPO` で無視)、**Bazaar 済**(`BazaarAdapter`、Breezy `brz` を `--no-plugins` で実行、リビジョンはメインラインの revno)、**CVS 済**(`CvsAdapter`、パスはモジュールのディレクトリで CVSROOT は `repositories_root` 内の最も近い祖先。trunk のファイルリビジョンを commitid(無ければ作者+メッセージ+10秒)でまとめて 1,2,3… と採番、`-f -R` で実行) | `docker/` の Dockerfile にバイナリ追加→`ScmAdapter` 実装(Git/SVN と同じ実バイナリ E2E テスト) | M ×3 |
| B'-02 | カスタムフィールド形式 `attachment` | ~~Spatie MediaLibrary の `model_type/model_id` 非 null 制約により「CF 値としての添付」の所有者モデル設計が必要~~ → **done(2026-09-24、課題のみ)**: 設計メモ [gap-B-02](design/gap-B-02.md)(ファイルはレコード自体の `custom_field_attachments` コレクション、値は media id)。他の種類は B'-02b | `CustomFieldValue` を HasMedia にするか、専用の中間モデルを作る設計メモを先に書く | M |
| B'-03 | Filesystem アダプタ | **done(2026-09-24)**: `RepositoryType::Filesystem`+`FilesystemAdapter`(`repositories_root` 配下のディレクトリ、`..` と外部へのシンボリックリンクを拒否)。`ScmAdapter::supports(ScmCapability)` を追加し、非対応の変更履歴/統計/コミッター/同期/比較/注釈/ファイル履歴は非表示かつ 404 | `ScmAdapter` に `supports(Capability)` を追加し、非対応タブを UI で非表示にする方式が候補 | S〜M |
| B'-04 | A1-20(グループ割当)・A4-10b(姓名分離)・A14-01(i18n) | いずれもスキーマ/全画面に波及する。着手前に設計メモをユーザーに提示して承認を得る | — | L |

**2026-09-24 承認**: B'-01(`docker/` への hg/cvs/bzr バイナリ追加を含む)、B'-02、B'-03 と、A1-17・A1-20・A1-27・A1-28・A1-34・A2-03・A3-03・A4-10b・A4-12・A5-06・A9-01・A10-01b・A12-03 を設計メモの推奨案で承認。A1-34 は子孫の再帰削除に変更する(既存テスト 'orphans its children' の期待値を反転)。 A1-34 は、削除する人が見えない・削除できないサブタスクも親と一緒に削除する Redmine の挙動を維持すると決定(2026-09-24)。

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
| C-20 | (実装中に発見したセキュリティ不具合、A11-01 で修正済み)REST API「Issues」 | 一覧 `GET /projects/{id}/issues` は `view_issues` があれば読めるとして記載 | 課題単位の可視性(非公開課題・own/default)が未適用で、権限のない課題が漏れていた | 修正・回帰テスト追加済み(`IssueApiIndexTest.php`) |
| C-21 | (実装中に発見したアクセス制御の不具合、A11-09 で修正済み)REST API「Issues」`include=children` | 直下の子課題を返すとして記載 | 閲覧権限のない子課題(非公開のサブタスクなど)も題名付きで返していた | 呼び出し元が閲覧可能な子だけに絞り、回帰テスト追加済み |
| C-22 | (バックログ自身の訂正)A6-02 | 「@mention の News/フォーラムへの拡張が未対応」 | Redmine 自体が対応していない(mentionable は Issue/Journal/WikiContent のみ) | 反映済み |
| C-23 | (実装中に発見した既存の不具合、修正済み)メール・Webhook の二重送信 | `MailNotificationServiceProvider`/`WebhookServiceProvider` は「自動検出は union 型の handle() を複数登録に展開できないので明示登録する」と説明していた | 自動検出は union 型を展開する。明示登録と併存して**全リスナーが二重登録**され、課題・Wiki・News の通知メールと Webhook がすべて 2 回ずつ送られていた | `bootstrap/app.php` の `withEvents(discover: false)` で自動検出を無効化し、`EventListenerRegistrationTest` を追加 |
| C-24 | (バックログ自身の訂正)A7-08 | 「フォーラムの返信のウォッチ、投稿の引用返信が未対応」 | 引用返信は `messages/show.blade.php` の `quote()` で実装済み(トピックにも返信にも「引用」ボタンがある)。返信のウォッチは Redmine 自体がトピック(root)のみで、`MessagePolicy::watch` も同じ | 該当部分は対象外。権限の分離(A13-02)のみ実施 |
| C-25 | (バックログ自身の訂正)A9-08 | 「Redmine の検索ボックスの `r123`(リビジョン)/`p:`(プロジェクト)ショートカット」 | Redmine 7.0 の `SearchController#index` のクイックジャンプは `#?\d+` の課題番号だけ(`search_controller.rb:40-44`)。`r123` はテキスト内リンク記法(wiki/課題本文)であって検索ボックスの機能ではなく、`p:` に相当する機能もない。本アプリの `#123` ジャンプは Redmine と同じ | ギャップではないため対象外(`done(対象外: Redmine 7.0 に無い, 2026-09-20)`) |
| C-26 | (バックログ自身の訂正)A7-07 | 「Wiki の HTML/TXT ZIP に添付ファイルを含める」 | Redmine 7.0 の `WikiController#export`(format zip)は各ページ本文を `<タイトル>.txt` で入れるだけで、添付ファイルは含まない(`wiki_controller.rb:414-438`)。HTML 出力も添付なしの単一 `wiki.html` | ギャップではないため対象外(`done(対象外: Redmine 7.0 に無い, 2026-09-20)`)。確認中に見つけた別件(ZIP 内ファイル名の衝突)は A7-15 として追加 |
| C-27 | (バックログ自身の訂正)A4-14 | 「作成者/担当者は通知対象だがウォッチャーにはならない」 | `IssueService::create()`/`update()` が作成者(作成時)と担当者(担当者変更時)を自動でウォッチャーにしていた(チェックリスト「作成者/担当者の自動Watch」は done)。足りなかったのは個人設定による切替と「コメントした課題」だけ | 個人設定 `auto_watch_on` による切替と `issue_contributed_to` を実装。既定は従来の挙動 |
| C-28 | (バックログ自身の訂正)A1-12 | 「複数値カスタムフィールドでのグルーピング(値ごとに行を重複表示)」 | Redmine 7.0 の `CustomField#group_statement` は `return nil if multiple?`(`custom_field.rb:268-272`)で、複数値フィールドはグルーピング対象にならない(並べ替えの `order_statement` も同様)。本アプリが選択欄に出さないのは Redmine と同じ | ギャップではないため対象外(`done(対象外: Redmine 7.0 に無い, 2026-09-20)`)。A1-11 の並べ替えも複数値は対象外で一致 |
| C-29 | (バックグラウンドのセキュリティレビューの指摘)CSV エクスポートの数式インジェクション | ユーザー入力(名前・題名・カスタムフィールド値)が `=`/`+`/`-`/`@` で始まると、表計算ソフトが数式として実行しうる。課題/工数/ユーザー/レポートの CSV すべてが対象 | 全 CSV 書き出しを `App\Support\Export\CsvCell` 経由にし、数式に見える文字列の先頭へ `'` を付ける(純粋な数値は除く)。テスト: `CsvFormulaInjectionTest.php` | Redmine 7.0 でも同種の対策の有無は未確認のため、本アプリでは安全側に倒した |
| C-30 | (バックログ自身の訂正)A2-07b | 「文書一覧にもページサイズ選択を適用」 | Redmine 7.0 の `DocumentsController#index` はページ分割せず、プロジェクトの文書を全件取得してグループ表示する(`documents_controller.rb`)。ページサイズ選択は付いていない | 文書一覧は対象外(`ページ分割なしが Redmine と同じ`)。工数一覧(プロジェクト/全プロジェクト)とプロジェクトのお知らせ一覧に適用 |
| C-31 | (実装中に発見した既存の不具合、修正済み)課題などの編集フォームの初期値 | 管理された一覧(`enumeration`)のカスタムフィールドは、編集フォームを開くと選択肢の**名前**が入力欄の初期値になり(値はIDで、名前と一致しない)、選択が空に見えたまま保存すると検証エラー(`integer`)になった。`HasCustomFields::customFieldFormValues()` が表示用の値を使っていた | 選択肢がレコードの形式(管理された一覧・ユーザー・バージョン)はIDを初期値にする。テスト: `CustomFieldUserVersionFormatTest.php` |

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

欠落: `user` `version`(A1-10)`attachment`(B'-02)。→ いずれも実装済み(`attachment` は 2026-09-24、課題のみ。他の種類は B'-02b)。

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

---

## 挙動変更ログ(完了報告 §7 にそのまま転記する)

実装で**既存の挙動が変わった**もの。利用者・API クライアント・運用に影響しうるため、完了報告の「挙動変更」節として必ず報告する。各行は 1 件 1 行、実装した行の ID を付ける。

| ID | 変更 | 影響 |
|---|---|---|
| A2-05b | REST `GET /queries` が `type=project_admin`(管理のプロジェクト一覧の保存クエリ)を受け付ける。管理者以外には常に空 | — |
| A2-10 | プロジェクト一覧のボードで説明が Markdown として描画され(先頭 255 文字を超えた行まで)、閲覧できるプロジェクトのカスタムフィールドが並ぶ。「親プロジェクト」フィルタの選択肢の先頭に `<< マイプロジェクト >>`/`<< ブックマーク >>` が加わる。保存クエリが表示形式(ボード/一覧)も保存する | 見た目のみ。既存の保存クエリは従来どおりサイトの既定の表示形式で開く |
| A3-13 | プロジェクトのクローズ/再オープン/アーカイブがサブプロジェクトにも連鎖する(概要画面・管理一覧・REST)。アーカイブ解除はアーカイブ済みの親も解除し、親がクローズならクローズに戻る。**他プロジェクトの課題が自身/サブプロジェクトのバージョンを対象にしているとアーカイブできない**(REST は 422) | 従来は対象プロジェクトだけが変わった。サブプロジェクトを個別に開いておく運用はできなくなる(Redmine と同じ) |
| A1-27b | ロールの `view_issues` を選択トラッカーに限定すると、そのトラッカー以外の課題はすべての画面・API・フィードで見えなくなる(設定しない既存ロールは従来どおり)。あわせて**可視性を通っていなかった経路が Redmine と同じく課題の可視性に従うようになった**: プロジェクトのカレンダーとガントは `view_issues` が必要になり非公開課題も絞る。マイページの「担当している/報告した/ウォッチ中の課題」、活動(課題・課題の更新)、工数フォームの課題選択(見えない課題は選べず、API も 422)、バージョン/ロードマップ/ガントの件数・進捗率・工数、課題詳細の関連・子課題・親課題、一覧の関連列(CSV/PDF 含む)、チェンジセットの関連課題、Wiki の `#123` リンクは見える課題だけ。工数レポートは見えない課題を番号だけで表示し、トラッカー等の課題属性の軸では「(なし)」に入る。活動の工数は見えない課題名を出さない。関連・子課題フィルタは見えない課題との関係を数えない(Redmine より狭い)。関連の追加で見えない課題を指定すると 403 ではなく「課題が見つかりません」の入力エラー。親課題の指定(フォーム・一括編集・インポート・受信メール)と工数の課題は見える課題に限る(変更しない値はそのまま)。受信メールの返信は課題が見えない送信者を拒否。複数ロールで `view_issues` を持たないロールの閲覧範囲は数えない(従来は数えていた) | 管理者は従来どおり。`view_issues` を持つロールだけを使っていた利用者は、非公開課題・自分の課題のみの設定がこれまで漏れていた経路で正しく絞られる |
| A1-27c | `add_issues`/`edit_issues`/`add_issue_notes`/`delete_issues` をトラッカーに限定したロールは、そのトラッカーの課題だけ作成/編集/コメント/削除できる。**トラッカーの選択肢が Redmine と同じく `add_issues` を持つトラッカーに限られる**: 編集フォームでは現在のトラッカー+`add_issues` のトラッカー(`add_issues` の無い利用者はトラッカーを変更できなくなる)、一括編集・右クリックのトラッカーは `add_issues` のトラッカーのみ、移動/コピー先のトラッカーは移動先で `add_issues` のあるもの。子課題コピーは使えないトラッカーの子課題を飛ばす。CSV インポートのトラッカー名は許可トラッカーからのみ解決(従来はプロジェクト外のトラッカー名も一致した)。受信メールの返信は `add_issue_notes` だけの送信者も受け付け(キーワードは無視)、既定トラッカーを使えない送信者の新規メールは拒否 | 制限を設定しない既存ロールは、`add_issues` の無い利用者のトラッカー変更と一括編集のトラッカー欄を除き従来どおり |
| A1-38 | ログインしていない利用者は、Anonymous ロールの「課題の閲覧範囲」に関わらず公開課題だけを見る(Redmine と同じ)。従来は `all` で非公開課題が、`default`/`own` でも未割り当ての非公開課題が見えていた。`own` の Anonymous ロールは公開課題がすべて見えるようになる(従来は未割り当て/作成者なしの課題のみ)。ロール編集画面で Anonymous の閲覧範囲の選択欄を非表示 | 匿名アクセスを許可している環境のみ |
| A1-40 | 設定「工数の必須項目」に「課題」があるとき、課題の削除(詳細・一括・右クリック・REST)で工数を「課題との紐付けを外して残す」が選べず、指定しても拒否される(REST は 422)。この設定のときの既定は「工数も削除」(`todo` 省略の REST、工数 0 時間だけの課題も工数を削除) | 設定していない環境は従来どおり(既定は残す) |
| A1-41 | 「ログインが必要」をオフにした環境で、ログインしていない利用者が公開プロジェクトのカレンダー・ガント・検索・活動・ロードマップと Atom フィード(課題・課題の更新・活動・ニュース・フォーラム、全体の活動/課題の更新も)を Anonymous ロールの権限の範囲で見られる(従来はログイン画面へ)。**検索と課題一覧の「検索可能な項目」フィルタで、見られないカスタムフィールドの値では課題が見つからなくなり、課題の更新 Atom に見られないカスタムフィールドの変更が出なくなる(ログイン利用者にも適用)** | 匿名アクセスを許可している環境。Anonymous ロールの権限を確認すること |
| A1-42 | プロジェクトを削除すると、別プロジェクトにあるそのサブタスク(子孫)と、その工数・添付も削除される(従来は最上位課題として残った)。課題ごとに `issue.deleted` Webhook が飛ぶ | 取り消し不可。プロジェクトをまたぐ親子を使っている環境 |
| A1-28 | 課題の CSV インポートで、引用符内の改行と BOM を正しく読む。カスタムフィールド列・「一意なID」列・関連列を割り当てられる。**必須のカスタムフィールドが空(既定値なし)の行や形式違反の行はエラーになる**(従来はカスタムフィールドを無視)。受信メール・インポートで保存するカスタムフィールドは作成者/実行者に見える・編集できるもので判断(従来はサインイン中の利用者が居ないと役割限定のフィールドが落ちた) | 必須のカスタムフィールドがあるプロジェクトの既存のインポート手順 |
| A1-34 | 親課題を削除すると子孫(子・孫…)もすべて削除される(従来は子を最上位課題として残した)。削除する利用者が見えない/削除できない子孫も Redmine と同じく削除される。工数の確認・処理・付替先の制限は子孫の工数も含む。確認ダイアログ/パネルに「N件のサブタスクも削除されます。」。REST `DELETE /issues/{id}` も同じ | サブタスクを使っている利用者・API クライアント。取り消し不可 |
| A3-03c | **メンバーが持っているロール(直接・グループ・継承)と組み込みロールは削除できなくなる**(従来は削除でき、メンバーからそのロールが外れた)。拒否時は使用中のプロジェクトを表示。継承中のプロジェクトで親のメンバーである非管理者が「メンバーを継承」を外すと確認が出る | ロールを削除するには先に各プロジェクトのメンバーから外す(Redmine と同じ) |
| A2-05 | ヘッダーの管理リンクに「プロジェクト管理」(`/admin/projects`)が増える。全ステータスのプロジェクトをフィルタ・列選択・並べ替えで一覧でき、右クリックでアーカイブ/解除、複数選択の一括削除(パスワード再確認+「はい」の入力)ができる | 管理者のみ。既存画面の挙動は変わらない |
| A1-37 | 閲覧ロールを限定した課題カスタムフィールドは、そのロールを持たない利用者の課題一覧(横断・サブプロジェクト込み・CSV/PDF・Atom・カレンダー/ガント・マイページのクエリブロック・REST の `f[]`/`query_id`)でフィルタ・列・並べ替え・グループに使えなくなり、ロールの無いプロジェクトの行では値が空になる。**保存済みクエリや URL に残ったそのフィルタは黙って無視される**(結果が広がる)。工数一覧の `cf_N` 列も一覧が提示する列に限る | 管理者は従来どおり。該当ロールを持つ利用者はそのプロジェクトで従来どおり |
| A2-03c | プロジェクト一覧の既定の表示形式が「ボード」(Redmine 既定)。ボードは従来のカード型リストと同じ見た目、「一覧」に切り替えると表と列選択が出る。設定「プロジェクト」で既定の表示形式と初期表示列を変更できる | 表を既定にしたい場合は設定で「一覧」にする |
| A2-03a | プロジェクト一覧に「一覧」表示(表)が加わり、フィルタ(ステータス・名前・識別子・説明・親・公開・日付・プロジェクトのカスタムフィールド)・表示列・並べ替えが増える。検索やフィルタ・並べ替えの使用中はツリーではなくフラットな一覧になる(A3-07 の「検索時もツリー順」を設計メモ案 A で変更)。メンバーでもロールに `view_project` が無い非公開プロジェクトは一覧に出ない(開けないプロジェクトと一致) | 検索結果のインデントがなくなる |
| A3-05 | クローズ中のプロジェクトで `manage_members`・`add_subprojects`・`manage_public_queries` が拒否される(従来は許可)。`edit_own_issue_notes`・`delete_own_messages` は許可に変わる | 非管理者はクローズ済みプロジェクトのメンバー管理ができなくなる(Redmine 準拠) |
| A7-04 | Wiki の履歴削除に `delete_wiki_pages` が必要(従来は `edit_wiki_pages`)。最新版・最後の1版も削除可能(最後の1版はページごと削除) | 編集権限だけのロールは履歴を消せなくなる |
| A11-01 | `GET /projects/{id}/issues` が課題単位の可視性を適用(**セキュリティ修正**) | 従来の漏洩に依存していたクライアントは結果が減る |
| A11-09 | `GET /issues/{id}?include=children` が閲覧不可の子課題を返さない(**セキュリティ修正**)。`children` が再帰的に入れ子になる | 従来はフラットな 1 階層だったので、各子に `children` が加わる |
| A11-14 | `GET /my/account` の応答に本人の `api_key` が含まれる | 応答をログに残す運用では鍵が漏れる可能性があるため注意 |
| A11-14 | `PUT /my/account`・`POST /my/api_key` が追加 | — |
| A1-14 | 課題の API 応答に `lock_version` が加わる | — |
| A5-11 | 課題通知メールの件名: 更新でステータスが変わらないとき `(ステータス)` が付かなくなる | **ユーザーが件名形式でメールフィルタを組んでいる場合に影響**(Redmine 既定の規則) |
| A4-06 | `/forgot-password`・`/reset-password/{token}` が 500 から正常動作に。ロック中/LDAP ユーザーにはリセットメールを送らない | 従来壊れていた管理者の「リセットメール送信」のリンクが機能する |
| A1-09 | 工数のある課題の削除に確認パネルが出る(`todo` 省略時は従来どおり工数を残す) | — |
| A4-04 | 管理者が必須文字種を選ぶと、既存パスワードは次回変更時から対象(既存ユーザーが即時に締め出されるわけではない)。設定が空(既定)なら従来どおり | — |
| A4-08 | `login`/`email` の一意性が大文字小文字無視に(Admin と admin は同一扱い)。メールドメイン制限が管理者フォーム・プロフィール・API にも適用(アドレス変更時のみ) | 既存データに大小違いの重複がある場合、その利用者は編集時に一意性エラーになりうる(DB は変更していない) |
| A6-04b | 添付の追加/削除と関連の追加/削除が通知メールになる(従来は通知なし)。編集と同時に添付すると、編集メールと添付メールの 2 通(Redmine は 1 通) | 通知が増える。通知しないでほしい場合は各ユーザーの通知設定か `notified_events` で制御 |
| (C-23) | **通知メールと Webhook が二重送信されていた不具合を修正**(自動検出+明示登録の重複) | 従来 2 通届いていた通知が 1 通になる。Webhook の受信側が二重呼び出しを前提にしていた場合は影響 |
| A3-07 | プロジェクト一覧が常にツリー順(検索時もフラット・アルファベット順ではなくなる) | — |
| A2-07 | 検索結果が `search_results_per_page`(既定 10)件ずつのページ分割になる(従来は全件を 1 ページに表示)。新設定 `per_page_options` の選択肢が課題・グローバル課題・グローバルお知らせ・プロジェクト(検索/フィルタ時)一覧の「表示件数」に出る(既定の件数は従来のまま) | 検索結果が 11 件以上ある場合に 2 ページ目以降になる |
| A2-07b | 工数一覧(プロジェクト・全プロジェクト)が25件ずつのページ分割になり、プロジェクトのお知らせ一覧が10件ずつになる。どちらにも「表示件数」が出る。合計・グループ見出しの件数/時間は全ページ分。一括選択はページをまたいで保持、CSVは全件 | 工数が26件以上、お知らせが11件以上ある画面で2ページ目以降になる |
| A5-09 | 0 時間の工数が既定で記録できる(従来は常に拒否)。工数管理の設定(必須項目・1日上限・未来日・終了課題)を有効にすると、全経路で工数の登録が拒否されることがある | 0 時間を拒否したい場合は設定「0時間の記録を許可する」をオフにする |
| A13-03 | 工数の編集/削除は自分の分でも `edit_own_time_entries` が必要になり、他ユーザー分の記録は `log_time_for_other_users`、CSV インポートは `import_time_entries` / `import_issues` が必要になる(**マイグレーションが従来の権限を持つロールへ自動付与**するので既存ロールは変わらない。新規に作るロールでは付け忘れに注意) | ロール編集画面で新権限を付与する。カスタム権限セットを持つデプロイは付与結果を確認 |
| A5-12 | コミットのキーワード設定が拡張された。ルールに進捗率・トラッカー条件、新設定の参照キーワード(既定 `*` で従来どおり)と他プロジェクト参照の許可(既定オンで従来どおり)。既定値は Redmine と異なる | 設定画面「リポジトリ」で変更できる。Redmine 既定に揃えるなら参照キーワードを `refs,references,IssueID`、他プロジェクト参照をオフにする |
| A5-12b | リポジトリのコミットログが Markdown で整形表示される(従来はプレーンテキスト)。履歴の表示件数と文字コード候補が設定化 | 整形が不要なら設定「コミットログをMarkdownで整形して表示する」をオフにする |
| A10-05 | 大きな差分は先頭 1500 行までの表示になり、512KB を超えるファイルはリポジトリ画面でインライン表示されない(従来は無制限) | 設定「リポジトリ」で 0 にすると無制限に戻る |
| A7-11 | 活動画面で Wiki 編集・フォーラム・工数が初期状態で非表示になり(チェックすれば表示)、ファイル追加が新しい種別として表示される | 必要な種別にチェックを入れる。Atom フィードは従来どおり全種別 |
| A13-04 | Wiki の履歴・差分・過去版の閲覧に `view_wiki_edits`、添付削除に `delete_wiki_pages_attachments` が必要になる(マイグレーションが従来の権限を持つロールへ自動付与)。新規ロールでは付け忘れに注意。Wiki 全体の削除(`manage_wiki`)は誰にも自動付与しない | ロール編集画面で権限を確認する |
| A13-02 | ウォッチャー一覧の閲覧に `view_*_watchers`、追加/削除にそれぞれ `add_*`/`delete_*` が必要になる(マイグレーションが従来の権限を持つロールへ自動付与)。新規ロールでは付け忘れに注意。API の `include=watchers` も `view_issue_watchers` が必要 | ロール編集画面で権限を確認する |
| A13-01 | 課題のコメントに `add_issue_notes`、自分の課題の編集に `edit_own_issues`、親課題の設定に `manage_subtasks`、自分の課題の非公開設定に `set_own_issues_private` が必要になる(マイグレーションが従来の権限を持つロールへ自動付与)。`manage_subtasks` のないユーザーには親課題欄が表示されない | ロール編集画面で権限を確認する |
| A5-04 | プロジェクト内のページのヘッダーに「+」新規作成メニューが表示される(設定「新規作成メニュー」で 0=なし/1=新しい課題のみ に変更可、既定は 2) | 不要なら設定で「なし」にする |
| A5-15 | ガントチャートが 500 課題・24 か月を超える分は描画されなくなる(従来は全件) | 設定で 0(無制限)にすると従来どおり |
| A1-15 | REST API の課題 JSON に `estimated_hours`/`total_estimated_hours`/`estimated_remaining_hours` と(工数閲覧権限があれば)`spent_hours`/`total_spent_hours` が増える(既存フィールドは不変) | — |
| A6-06 | 課題通知メールに `Message-ID`/`References` が付き、メールクライアントで課題ごとにスレッド表示される。返信は件名を書き換えても課題に紐付く | — |
| A4-09 | 画面上の Atom リンクの URL に `?key=<個人のキー>` が付く(そのリンクを共有するとあなたの権限で読まれる)。プロフィールでキーをリセットできる | リンクを共有しない。漏れたらプロフィールでリセット |
| A4-11 | 課題詳細とメンバー一覧にイニシャルのアイコンが表示される。Gravatar を有効にすると閲覧者のブラウザから gravatar.com にメールハッシュが送られる(既定はオフ) | 不要なら影響なし(表示のみ) |
| A4-13 | プロフィールに「個人設定」が増える。課題の履歴の並び順・入力欄のフォント・未保存離脱警告(**既定でオン**)・既定の課題クエリなどを個人ごとに選べる | 警告が不要なら個人設定でオフ |
| A4-14 | 課題へのコメント/更新でウォッチが増えるようになる(個人設定「自分がコメント・更新した課題」をオンにした人のみ、既定はオフ)。既定の自動ウォッチ(作成・担当)は従来どおり | — |
| A9-07 | すべてのページのヘッダーにプロジェクト移動のドロップダウンが増える。プロジェクトページを開くと個人設定に「最近使ったプロジェクト」が保存される | 個人設定で件数を 0 にすると記録しない |
| A7-02 | `{{collapse}}` がネスト可能に。本文に隣接した `{{collapse}}` でプレースホルダ文字列が漏れる不具合を修正 | — |
| A1-06b | 課題一覧の一括編集にカスタムフィールドの入力が増える(選択課題すべてに共通する単一値の項目) | — |
| A8-02b | 工数の一括編集フォームにカスタムフィールドの入力が増え、全体の工数一覧の列にカスタムフィールドが選べる | — |
| A1-05b | 課題一覧の右クリックメニューに「ウォッチ / ウォッチをやめる」「子課題を追加」「作業時間を記録」「URLをコピー」が増える。`issues/create?parent_id=` で子課題として作成フォームが開く | — |
| A13-05b | ロールの権限に「クエリの保存」(`save_queries`)と「プロジェクト内検索」(`search_project`)が増え、保存クエリの作成と検索ページがそれぞれ権限で制御される。既存ロールには従来どおり使えるよう自動付与 | 新規ロールを作るときは付与が必要 |
| A4-10a | 設定に「ユーザー名の表示形式」(名前 / 名前 (ログインID) / ログインID)が増える(既定は従来どおり名前) | 変えなければ従来どおり |
| A1-03 | 課題フォームの親課題と課題詳細の関連追加に、番号/件名で検索して選べる入力が増える | 従来の番号入力もそのまま使える |
| A1-02 | 課題・トピック・お知らせ・Wikiページのウォッチャー追加が、セレクトから「名前・メールで検索して選ぶ」入力に変わる | — |
| A5-08 | 設定に「Wikiの表を見出しクリックで並べ替え可能にする」が増える(既定オフ)。**フロントエンドの再ビルドが必要** | オフのままなら影響なし |
| A4-07 | 自己登録フォームに、必須のユーザーカスタムフィールド(と、設定「登録フォームにユーザーのカスタムフィールドを表示する」をオンにした場合は編集可能な項目)が表示される。**必須のユーザーカスタムフィールドがあると、登録に入力が必須になる** | 設定は既定でオフ |
| A1-01 | 新規課題フォームに「ウォッチャー」のチェックボックスが増える(`add_issue_watchers` 権限者のみ) | — |
| A12-06 / A5-10 | 設定に「メール受信用のWebサービス」(`POST /mail_handler`、APIキー)と、メール本文の切り捨て行・除外添付ファイル名を正規表現として扱うオプションが増える(既定オフ) | 使わなければ影響なし |
| A1-10 | カスタムフィールドの形式に「ユーザー」「バージョン」が増える(課題・工数などの入力欄で、プロジェクトのメンバー/使えるバージョンから選ぶ。ロール・ステータスで絞り込める)。管理画面の形式に選択肢が増え、プロジェクトの外のユーザー/バージョンは保存できない | 既存の形式は変わらない。一括編集ではこの2形式の欄を出さない |
| A1-10b/c | ユーザー/バージョン形式: 課題一覧の絞り込みに「<< 自分 >>」(ユーザー形式)、複数値可のフィールドは複数選択の入力欄になる(選択肢型: リスト・管理された一覧・ユーザー・バージョン)。REST API のカスタムフィールド定義の `possible_values` はユーザー/バージョン形式では空 | 編集フォームの初期値が、管理された一覧・ユーザー・バージョン形式では名前でなく ID になる(従来、管理された一覧は編集フォームで未選択に見え、保存すると検証エラーになる不具合があった) |
| A14-01a | プロフィールに「言語」、設定に「既定の言語」と「常に既定の言語を使う」(未ログイン/ログイン)が増える。ブラウザの言語が日本語・英語なら未ログインの画面がその言語になる(日付の書式など)。**画面の文言はまだ日本語のまま**(英訳は A14-01b 以降) | 既定の言語は English(従来のアプリのロケールと同じ)のため、設定しなければ表示は変わらない。ブラウザが日本語のとき、日付の相対表示(「○日前」)が日本語になる |
| A12-06b | 受信メールの設定に「本文のキーワードで上書きを許す項目」(既定 `all` で従来どおり)と「サブアドレスからプロジェクトを決める」が増える。本文の「カスタムフィールド名: 値」の行でカスタムフィールドを設定できる | 許可リストを絞らなければ従来どおり。本文にカスタムフィールド名と同じ「名前: 値」の行があると、フィールドが設定されて本文から消える |
| A12-06d | 設定に「受信メールで作成・更新した課題は通知メールを送らない」が増える | 既定オフ。オフなら従来どおり通知する |
| A5-16b | 設定に「REST APIと受信メールで作る課題にも開始日の既定(作成日)を適用する」が増える | 既定オフ(オフなら従来どおり、開始日を省略すると空)。オンでも「新規課題の開始日を作成日にする」がオフなら適用しない |
| A12-02 | Webhook に「所有ユーザー」(そのユーザーが見られる対象で、プロジェクトの「Webhookの利用」権限がある場合だけ送信)と、設定「Webhookを有効にする」が増える。ロールの権限に「Webhookの利用」 | 所有者を付けない従来のWebhookは従来どおり全件送信 |
| A12-02b | マイアカウントの「マイWebhook」で、権限のあるユーザーが自分のWebhookを登録・編集・削除できる(URLはhttp/httpsで内部ネットワーク宛は不可) | — |
| A11-10 | REST API の課題・工数・プロジェクト・バージョン・グループ・ユーザーに `custom_fields`(読み書き)が増える | 応答に項目が増えるだけで、従来の呼び出しは影響なし |
| A11-07 | REST API: `POST/PUT/DELETE /users`(管理者)が使え、`GET /users/{id}` は管理者以外でも見えるユーザーなら取得できる(項目は閲覧者に応じて出し分け)。**従来は非管理者に403だった** | 従来の管理者の呼び出しは影響なし(応答に項目が増える) |
| A9-03 | マイページのブロックに「自分が更新した課題」「今週のカレンダー」が増える | 追加しなければ影響なし |
| A9-03b | マイページ: 同じ保存クエリを最大3回まで置ける。保存クエリのブロックに「設定」(表示する項目・並び順)、「最近の工数」ブロックに「表示する日数」が増える | 設定しなければ従来の表示(ステータスだけ表示、期間の制限なし)。ブロックの行が(ユーザー×ブロック)の一意ではなくなる |
| (対策) | 全 CSV エクスポートで、`=` `+` `-` `@` で始まる文字列の先頭に `'` が付く(表計算ソフトでの数式実行を防ぐため) | 数値はそのまま。該当する文字列は先頭の `'` を取って使う |
| A9-04 | 全プロジェクトの活動に Atom フィード(`/activity.atom`)が増え、活動ページで最後に選んだ種別が記憶される(個人設定) | — |
| A9-04b | 全プロジェクトの活動に「« 前の期間」「次の期間 »」が付く(期間の長さ分ずつ移動)。表示は従来と同じで、読み込みが速くなる | — |
| A8-05 | 全プロジェクト横断の工数一覧に、権限のある行の「編集」「削除」と CSV エクスポートが増える | — |
| A8-04 | 工数レポートの行の軸にカスタムフィールドが選べ、CSV出力ができる。全プロジェクト横断の工数レポート(`/time_entries/report`)が使える | — |
| A8-02 | 管理画面のカスタムフィールドの対象に「工数」「優先度」が増える。工数フォーム・工数一覧(列/CSV)・優先度の管理フォームに反映 | 作らなければ影響なし |
| A7-01 | Wikiマクロが増える: `{{macro_list}}` `{{hello_world}}` `{{recent_pages(N)}}` `{{issue(id)}}` `{{thumbnail(file)}}`、`{{child_pages}}` の深さ指定、`{{include(プロジェクト:ページ)}}`(別プロジェクト) | 使わなければ影響なし |
| A6-01 | 設定「メール通知」に「課題にコメントが追加されたとき」「フォーラムにメッセージが投稿されたとき」「文書が追加されたとき」「ファイルが追加されたとき」が増える(既定は従来どおり課題の作成/更新のみ) | チェックしなければ従来どおり |
| A4-03 | 設定「認証」に「パスワードの有効期限」、ユーザー編集に「次回ログイン時にパスワードの変更を要求する」が増える。該当ユーザーはパスワードを変更するまでプロフィール以外を開けない(有効期限は既定で無効) | 既存アカウントの有効期限の起点は移行時点 |
| A4-02 | プロフィールに「追加のメールアドレス」が増える(設定の上限まで、既定5件)。通知メールは追加アドレスにも届き(通知オフにできる)、そこからの受信メールも本人として扱われる | 追加しなければ従来どおり |
| A4-01 | ユーザー一覧に「CSVインポート」が増える(管理者のみ)。CSVからユーザーをまとめて作成できる | — |
| A3-09 | プロフィールのメール通知に「選択したプロジェクトのイベントと、自分の関与するイベントのみ通知」が使えるようになり(所属プロジェクトがある人のみ)、通知を受け取るプロジェクトを選べる。従来この選択肢は「自分の関与するイベントのみ」と同じ扱いだった | 選んでいなければ影響なし。選択済みの人はプロジェクトを選び直す |
| A3-02 / A3-11 / A13-05 | ロールの権限に「プロジェクトの公開設定の選択」(`select_project_publicity`)、「プロジェクトの作業分類の管理」(`manage_project_activities`)、「メンバーの表示」(`view_members`)が増える。プロジェクト編集で公開/非公開を切り替えられるのは前者の権限者だけ、作業分類設定は後者だけ、REST のメンバー一覧は `view_members` だけになる。既存ロールには従来の権限(`edit_project`/`manage_members`)の保持者へ自動付与される | 既存の挙動は保たれる(新規ロールを作るときは付与が必要) |
| A3-01 | ロールの権限に「プロジェクトの追加」(`add_project`)が増える。付与されたロール(メンバー役割、グループ経由、非メンバー)を持つ一般ユーザーがトップレベルのプロジェクトを作成できる(**従来は管理者のみ**)。既定では誰にも付与されない | 付与しなければ従来どおり管理者のみ |
| A2-06 | 課題レポートに各集計の「詳細」ページ(未完了/完了/合計・CSV)と「サブプロジェクト別」が増える。**レポートは閲覧できる課題だけを数える**(従来は非公開課題も数えていた) | サブプロジェクトは設定「サブプロジェクトの課題を表示」がオンのときだけ集計 |
| A2-04 | 管理者のユーザー一覧が表形式になり、フィルタ・表示列選択・並べ替え・CSVエクスポートが使える。全リストのフィルタ「〜を含む」が大文字小文字を区別しなくなり、値が空の条件は無視される | 既定はフィルタなしで従来と同じユーザーが並ぶ |
| A1-29 | 課題一覧に「PDFエクスポート」が増える。CSV/PDF は設定「課題一覧のエクスポート件数の上限」(既定500)で先頭から打ち切られる(**従来のCSVは全件出力だった**) | 件数が多い場合は上限を設定で引き上げる(最大5000) |
| A1-22 | コメントを編集すると「(X が編集 日時)」と表示され、編集フォームで「非公開コメントにする」を切り替えられる(`set_notes_private` 権限者のみ)。`PUT /journals/{id}` も `private_notes` を受け付ける | — |
| A1-19 | 後続課題が子を持つ親のとき、親ではなくその子孫の葉の日付が動く(親の日付は葉から再計算)。葉の日付変化で親の日付が変わると、親の後続課題も動く | `parent_issue_dates` をオフにすると従来どおり親を直接動かす |
| A1-18 | 設定に「非稼働日(曜日)」が増え、先行/後続の関連による日付の自動調整が稼働日で数えるようになる(**既定は非稼働日なし=従来どおり暦日**) | 設定で土日などを選ぶと、後続課題は次の稼働日に移り、期間も稼働日数を保つ |
| A1-11 | 課題一覧で、カスタムフィールド列の見出しクリックや2・3列目の並べ替えにより、単一値のカスタムフィールドで並べ替えできる(空欄は昇順で先頭) | — |
| A1-08 | 課題のコピー(詳細画面の「コピー」)が、添付・子課題・ウォッチャー・コピー元との関連を選んで引き継ぐようになる(従来は項目のプリフィルのみで、関連も作られなかった)。設定に「コピー元との関連を作る」「添付ファイルをコピーする」(既定は都度選択)。一括コピーの「添付」「関連」もこの設定に従う | 既存の一括コピーは、関連が「既定で作る」から「チェックボックス(既定オン)」に |
| A1-07 | 課題の一括コピーで子課題も複製されるようになる(コピーフォームの「子課題も複製」、**既定オン**。従来は親のみ複製) | オフにすると従来どおり親のみ |
| A8-06 | 工数一覧で行を右クリックするとメニュー(編集/一括編集、作業分類の変更、削除)。編集できる行のみ対象 | — |
| A4-16 | ユーザー一覧で行を右クリックするとメニュー(ロック/解除、グループに追加/から外す、削除)。**管理者が他のユーザーを削除できるようになる**(削除は匿名化。自分自身と最後の有効な管理者は除外) | 使わなければ従来どおり |
| A1-05 | 課題一覧で行を右クリックするとメニュー(ステータス/トラッカー/優先度/対象バージョン/担当者/カテゴリ/進捗率の変更、編集、コピー、削除)。選択中の行を右クリックすると選択全体が対象 | メニューを使わず従来のチェックボックス+一括編集フォームも使える |
| A1-06 | 課題一覧の一括編集でトラッカー・カテゴリ・開始日/期日・非公開・親課題も変更可能に。ステータスは選択課題すべてが遷移できるものだけを提示(従来は全課題が同一ステータスの場合のみ) | — |
| A1-13 | 課題フォームの進捗率がスライダーからセレクトに | — |
| A1-21 | 課題詳細のサブタスク/関連課題がリストから表になる | — |
| A4-10b-1 | 管理画面のユーザーフォーム・アカウント設定・自己登録に「姓」「名」が増える。両方入力すると「名前」は「名 姓」に置き換わり(以後も姓・名に合わせて更新)、名前は省略できる。設定「ユーザー名の表示形式」に Redmine の 8 形式(名 姓、姓, 名 など) | 姓・名を入力しなければ従来どおり。新しい形式は姓・名の両方があるユーザーだけに効き、それ以外は名前で表示 |
| A4-10b-2 | 設定「ユーザー名の表示形式」が担当者などの選択肢・絞り込みの選択肢・課題レポート/工数レポート・通知メールの操作者・ユーザー詳細にも効く。**REST API の作成者/担当者/ウォッチャー/コメント者の `name` も表示形式に従う**(Redmine と同じ) | 既定(名前)のままなら変わらない。API のユーザー自身の `name` は保存値のまま |
| A4-10b-3 | 認証ソース(LDAP)に「姓の属性」「名の属性」。設定すると、両方の値があるユーザーはログイン時に姓・名が設定され、名前は「名 姓」になる。ユーザーの CSV インポートと REST API(`/users`、`/my/account`)が姓・名を受け付け、API のユーザー応答に `firstname`/`lastname` が増える | 属性を設定しなければ従来どおり氏名属性。API の既存フィールドは変わらない |
| A4-12d | 設定「日付の形式」を選ぶと、日付型カスタムフィールドの値(課題・一覧/CSV/PDF・工数・プロジェクト・文書)、課題の履歴の日付型カスタムフィールドの変更、工数レポートの日/月見出し、ガントの月見出し、カレンダーの見出しもその形式になる。日付型カスタムフィールドの履歴・一覧の値に付いていた「 00:00:00」が消える。管理画面のユーザー編集で言語とタイムゾーンを設定できる | 日付形式が未設定なら見出しは従来どおり |

**フロントエンドの再ビルドが必要**: A10-04・A9-02・A1-21・A1-13 などが新しい Tailwind クラスを使う。`public/build` は gitignore 対象のため、デプロイ時に `npm run build` を実行すること。

---

## 完了報告(2026-09-20)

§0.3 の実行キューは、全行が `done` / `blocked` になった。ここから先は承認待ちの項目だけが残る。

### (a) `blocked(要承認)` — 判断が要るもの

| 行 | 内容 | 設計メモ | 判断点 |
|---|---|---|---|
| A1-17 | 課題フィルタの拡張(L) | [gap-A1-17](design/gap-A1-17.md) | 分割方法。A2-08・A11-17 が依存 |
| A1-20 | グループへの課題割当 | [gap-A1-20](design/gap-A1-20.md) | 担当者の型を変えるスキーマ判断。A5-05 が依存 |
| A1-27 | ロール×トラッカー単位の課題権限 | [gap-A1-27](design/gap-A1-27.md) | 可視性スコープを変える方針(漏えい防止) |
| A1-28 | CSV インポートのカスタムフィールド列・`unique_id` | [gap-A1-28](design/gap-A1-28.md) | 認可コンテキストの扱い |
| A2-03 | プロジェクト一覧クエリ | [gap-A2-03](design/gap-A2-03.md) | 既存のツリー表示・ブックマークとの両立。A2-05・A4-13b が依存 |
| A3-03 | 子プロジェクトのメンバー継承 | [gap-A3-03](design/gap-A3-03.md) | 2 通りの実装方式。A11-16 が依存 |
| A4-10b | 姓名の分離と `user_format` 全形式(L) | [gap-A4-10b](design/gap-A4-10b.md) | スキーマ変更と既存データの移行 |
| A4-12 / A14-02 | ユーザーのタイムゾーンと日付形式 | [gap-A4-12](design/gap-A4-12.md) | 表示側 約 120 箇所。A14-01 との同時実施 |
| A5-06 / A14-03 | テーマ切替 | [gap-A5-06](design/gap-A5-06.md) | 方式の選択。CSS の目視確認ができない |
| A9-01 | プロジェクト横断ガント・PNG・PDF の関連線 | [gap-A9-01](design/gap-A9-01.md) | 3 要素の実装方式 |
| A10-01b | リポジトリのリモート URL と認証情報 | [gap-A10-01b](design/gap-A10-01b.md) | 認証情報の保存・SSRF |
| B'-02 | カスタムフィールド形式 `attachment` | [gap-B-02](design/gap-B-02.md) | 所有者モデル(承認済み・実装済み) |
| A12-03 | プラグインのランタイム検出 | [gap-A12-03](design/gap-A12-03.md) | 新しいベースフォルダ `plugins/`、実行時のコード読み込み |
| A14-01b〜 | 画面ごとの文字列の `__()` 置換と英訳(M×n) | [gap-A14-01](design/gap-A14-01.md) | 土台(A14-01a)は承認・実装済み。置換の範囲と順序は行 A14-01b | 
| A1-34 | 親課題の削除で子孫も削除 | (行内) | データ削除の意味が変わる。既存テストの反転 |
| B'-01〜03 | hg/cvs/bzr、MediaLibrary、ScmAdapter | (行内) | 依存追加・バイナリ追加 |

依存で止まっているもの: A2-05・A2-08・A4-13b・A5-05・A11-16・A11-17(上の承認後に着手)。

**承認済み・実施済み(2026-09-20、推奨案)**: A1-10(user/version 形式。A1-10a〜c)、A5-16b(別スイッチ・既定オフ)、A12-06c(実装しない案。残りの `no_notification` は A12-06d)、A14-01a(多言語の土台)。

### (b) `blocked(失敗)`

なし。

### (c) 追加・訂正した行

- **フォローアップ行(実装済み)**: A1-05b、A1-06b、A1-10a〜c、A2-07b、A8-02b、A9-03b、A9-04b、A12-02b、A12-06b、A12-06d、A13-05b、A14-01a。
- **フォローアップ行**: A14-01b1〜b8 はすべて 2026-09-23 に実施(メールは受信者の言語で送る)。
- **バックログ自身の訂正(§C)**: C-24〜C-28、C-30(Redmine 7.0 に無い、または既に実装済みだった主張)。
- **実装中に見つけた不具合(修正済み)**: C-29(CSV エクスポートの数式インジェクション、`CsvCell` で対策)、C-31(管理された一覧のカスタムフィールドの編集フォームの初期値)ほか。

### (d) 必要な作業

- **`vendor/bin/sail npm run build` が必要**。新しい Tailwind クラスとテーブルの並べ替え用 JS(`resources/js/app.js`)を使う。`public/build` は gitignore 対象。
- 追加の承認(A1-10 など)で、`custom_fields.format_options` と `user_dashboard_blocks.settings` などのマイグレーションが増えた。
- マイグレーションが増えているので `vendor/bin/sail artisan migrate` が必要。

### (e) 確認できていないこと

- Wiki 表の並べ替え JS はビルドが通ることだけを確認した。ブラウザでの動作は未確認。
- PDF・PNG の見た目は目視していない(テストは生成の成否と内容の文字列まで)。
- CSV の数式対策(`CsvCell`)は主要な書き出しに入れたが、すべての書き出し箇所での網羅は未確認。
- 新しい設定の既定は、Redmine の既定ではなく既存の挙動を保つ値にしてある(例: `mail_handler_allow_override` は `all`、`webhooks_enabled` はオン、`user_format` は名前)。Redmine と完全に同じ既定ではない。
- 活動フィードの権限確認はプロジェクト数に比例してクエリが増える(データ取得は 1 プロバイダ 1 クエリに改善済み)。
- `/my/webhooks` の URL 検証は保存時のみ。配信時に再確認しないため、DNS リバインディングは防げない。

- ユーザー/バージョン形式のカスタムフィールドは、プロジェクトを持たないレコード(ユーザー・グループ・プロジェクトなど)では閲覧権限による名前の絞り込みをしない。文字列など自由入力の形式の複数値の入力欄は未対応。
- 言語は ja/en の 2 つで、画面の文言は日本語のまま(英訳は A14-01b 以降)。ブラウザが日本語の未ログインの画面で、日付の相対表示が日本語になる。

### (f) 挙動変更ログ(転記)

| ID | 変更 | 影響 |
|---|---|---|
| A3-05 | クローズ中のプロジェクトで `manage_members`・`add_subprojects`・`manage_public_queries` が拒否される(従来は許可)。`edit_own_issue_notes`・`delete_own_messages` は許可に変わる | 非管理者はクローズ済みプロジェクトのメンバー管理ができなくなる(Redmine 準拠) |
| A7-04 | Wiki の履歴削除に `delete_wiki_pages` が必要(従来は `edit_wiki_pages`)。最新版・最後の1版も削除可能(最後の1版はページごと削除) | 編集権限だけのロールは履歴を消せなくなる |
| A11-01 | `GET /projects/{id}/issues` が課題単位の可視性を適用(**セキュリティ修正**) | 従来の漏洩に依存していたクライアントは結果が減る |
| A11-09 | `GET /issues/{id}?include=children` が閲覧不可の子課題を返さない(**セキュリティ修正**)。`children` が再帰的に入れ子になる | 従来はフラットな 1 階層だったので、各子に `children` が加わる |
| A11-14 | `GET /my/account` の応答に本人の `api_key` が含まれる | 応答をログに残す運用では鍵が漏れる可能性があるため注意 |
| A11-14 | `PUT /my/account`・`POST /my/api_key` が追加 | — |
| A1-14 | 課題の API 応答に `lock_version` が加わる | — |
| A5-11 | 課題通知メールの件名: 更新でステータスが変わらないとき `(ステータス)` が付かなくなる | **ユーザーが件名形式でメールフィルタを組んでいる場合に影響**(Redmine 既定の規則) |
| A4-06 | `/forgot-password`・`/reset-password/{token}` が 500 から正常動作に。ロック中/LDAP ユーザーにはリセットメールを送らない | 従来壊れていた管理者の「リセットメール送信」のリンクが機能する |
| A1-09 | 工数のある課題の削除に確認パネルが出る(`todo` 省略時は従来どおり工数を残す) | — |
| A4-04 | 管理者が必須文字種を選ぶと、既存パスワードは次回変更時から対象(既存ユーザーが即時に締め出されるわけではない)。設定が空(既定)なら従来どおり | — |
| A4-08 | `login`/`email` の一意性が大文字小文字無視に(Admin と admin は同一扱い)。メールドメイン制限が管理者フォーム・プロフィール・API にも適用(アドレス変更時のみ) | 既存データに大小違いの重複がある場合、その利用者は編集時に一意性エラーになりうる(DB は変更していない) |
| A6-04b | 添付の追加/削除と関連の追加/削除が通知メールになる(従来は通知なし)。編集と同時に添付すると、編集メールと添付メールの 2 通(Redmine は 1 通) | 通知が増える。通知しないでほしい場合は各ユーザーの通知設定か `notified_events` で制御 |
| (C-23) | **通知メールと Webhook が二重送信されていた不具合を修正**(自動検出+明示登録の重複) | 従来 2 通届いていた通知が 1 通になる。Webhook の受信側が二重呼び出しを前提にしていた場合は影響 |
| A3-07 | プロジェクト一覧が常にツリー順(検索時もフラット・アルファベット順ではなくなる) | — |
| A2-07 | 検索結果が `search_results_per_page`(既定 10)件ずつのページ分割になる(従来は全件を 1 ページに表示)。新設定 `per_page_options` の選択肢が課題・グローバル課題・グローバルお知らせ・プロジェクト(検索/フィルタ時)一覧の「表示件数」に出る(既定の件数は従来のまま) | 検索結果が 11 件以上ある場合に 2 ページ目以降になる |
| A2-07b | 工数一覧(プロジェクト・全プロジェクト)が25件ずつのページ分割になり、プロジェクトのお知らせ一覧が10件ずつになる。どちらにも「表示件数」が出る。合計・グループ見出しの件数/時間は全ページ分。一括選択はページをまたいで保持、CSVは全件 | 工数が26件以上、お知らせが11件以上ある画面で2ページ目以降になる |
| A5-09 | 0 時間の工数が既定で記録できる(従来は常に拒否)。工数管理の設定(必須項目・1日上限・未来日・終了課題)を有効にすると、全経路で工数の登録が拒否されることがある | 0 時間を拒否したい場合は設定「0時間の記録を許可する」をオフにする |
| A13-03 | 工数の編集/削除は自分の分でも `edit_own_time_entries` が必要になり、他ユーザー分の記録は `log_time_for_other_users`、CSV インポートは `import_time_entries` / `import_issues` が必要になる(**マイグレーションが従来の権限を持つロールへ自動付与**するので既存ロールは変わらない。新規に作るロールでは付け忘れに注意) | ロール編集画面で新権限を付与する。カスタム権限セットを持つデプロイは付与結果を確認 |
| A5-12 | コミットのキーワード設定が拡張された。ルールに進捗率・トラッカー条件、新設定の参照キーワード(既定 `*` で従来どおり)と他プロジェクト参照の許可(既定オンで従来どおり)。既定値は Redmine と異なる | 設定画面「リポジトリ」で変更できる。Redmine 既定に揃えるなら参照キーワードを `refs,references,IssueID`、他プロジェクト参照をオフにする |
| A5-12b | リポジトリのコミットログが Markdown で整形表示される(従来はプレーンテキスト)。履歴の表示件数と文字コード候補が設定化 | 整形が不要なら設定「コミットログをMarkdownで整形して表示する」をオフにする |
| A10-05 | 大きな差分は先頭 1500 行までの表示になり、512KB を超えるファイルはリポジトリ画面でインライン表示されない(従来は無制限) | 設定「リポジトリ」で 0 にすると無制限に戻る |
| A7-11 | 活動画面で Wiki 編集・フォーラム・工数が初期状態で非表示になり(チェックすれば表示)、ファイル追加が新しい種別として表示される | 必要な種別にチェックを入れる。Atom フィードは従来どおり全種別 |
| A13-04 | Wiki の履歴・差分・過去版の閲覧に `view_wiki_edits`、添付削除に `delete_wiki_pages_attachments` が必要になる(マイグレーションが従来の権限を持つロールへ自動付与)。新規ロールでは付け忘れに注意。Wiki 全体の削除(`manage_wiki`)は誰にも自動付与しない | ロール編集画面で権限を確認する |
| A13-02 | ウォッチャー一覧の閲覧に `view_*_watchers`、追加/削除にそれぞれ `add_*`/`delete_*` が必要になる(マイグレーションが従来の権限を持つロールへ自動付与)。新規ロールでは付け忘れに注意。API の `include=watchers` も `view_issue_watchers` が必要 | ロール編集画面で権限を確認する |
| A13-01 | 課題のコメントに `add_issue_notes`、自分の課題の編集に `edit_own_issues`、親課題の設定に `manage_subtasks`、自分の課題の非公開設定に `set_own_issues_private` が必要になる(マイグレーションが従来の権限を持つロールへ自動付与)。`manage_subtasks` のないユーザーには親課題欄が表示されない | ロール編集画面で権限を確認する |
| A5-04 | プロジェクト内のページのヘッダーに「+」新規作成メニューが表示される(設定「新規作成メニュー」で 0=なし/1=新しい課題のみ に変更可、既定は 2) | 不要なら設定で「なし」にする |
| A5-15 | ガントチャートが 500 課題・24 か月を超える分は描画されなくなる(従来は全件) | 設定で 0(無制限)にすると従来どおり |
| A1-15 | REST API の課題 JSON に `estimated_hours`/`total_estimated_hours`/`estimated_remaining_hours` と(工数閲覧権限があれば)`spent_hours`/`total_spent_hours` が増える(既存フィールドは不変) | — |
| A6-06 | 課題通知メールに `Message-ID`/`References` が付き、メールクライアントで課題ごとにスレッド表示される。返信は件名を書き換えても課題に紐付く | — |
| A4-09 | 画面上の Atom リンクの URL に `?key=<個人のキー>` が付く(そのリンクを共有するとあなたの権限で読まれる)。プロフィールでキーをリセットできる | リンクを共有しない。漏れたらプロフィールでリセット |
| A4-11 | 課題詳細とメンバー一覧にイニシャルのアイコンが表示される。Gravatar を有効にすると閲覧者のブラウザから gravatar.com にメールハッシュが送られる(既定はオフ) | 不要なら影響なし(表示のみ) |
| A4-13 | プロフィールに「個人設定」が増える。課題の履歴の並び順・入力欄のフォント・未保存離脱警告(**既定でオン**)・既定の課題クエリなどを個人ごとに選べる | 警告が不要なら個人設定でオフ |
| A4-14 | 課題へのコメント/更新でウォッチが増えるようになる(個人設定「自分がコメント・更新した課題」をオンにした人のみ、既定はオフ)。既定の自動ウォッチ(作成・担当)は従来どおり | — |
| A9-07 | すべてのページのヘッダーにプロジェクト移動のドロップダウンが増える。プロジェクトページを開くと個人設定に「最近使ったプロジェクト」が保存される | 個人設定で件数を 0 にすると記録しない |
| A7-02 | `{{collapse}}` がネスト可能に。本文に隣接した `{{collapse}}` でプレースホルダ文字列が漏れる不具合を修正 | — |
| A1-06b | 課題一覧の一括編集にカスタムフィールドの入力が増える(選択課題すべてに共通する単一値の項目) | — |
| A8-02b | 工数の一括編集フォームにカスタムフィールドの入力が増え、全体の工数一覧の列にカスタムフィールドが選べる | — |
| A1-05b | 課題一覧の右クリックメニューに「ウォッチ / ウォッチをやめる」「子課題を追加」「作業時間を記録」「URLをコピー」が増える。`issues/create?parent_id=` で子課題として作成フォームが開く | — |
| A13-05b | ロールの権限に「クエリの保存」(`save_queries`)と「プロジェクト内検索」(`search_project`)が増え、保存クエリの作成と検索ページがそれぞれ権限で制御される。既存ロールには従来どおり使えるよう自動付与 | 新規ロールを作るときは付与が必要 |
| A4-10a | 設定に「ユーザー名の表示形式」(名前 / 名前 (ログインID) / ログインID)が増える(既定は従来どおり名前) | 変えなければ従来どおり |
| A1-03 | 課題フォームの親課題と課題詳細の関連追加に、番号/件名で検索して選べる入力が増える | 従来の番号入力もそのまま使える |
| A1-02 | 課題・トピック・お知らせ・Wikiページのウォッチャー追加が、セレクトから「名前・メールで検索して選ぶ」入力に変わる | — |
| A5-08 | 設定に「Wikiの表を見出しクリックで並べ替え可能にする」が増える(既定オフ)。**フロントエンドの再ビルドが必要** | オフのままなら影響なし |
| A4-07 | 自己登録フォームに、必須のユーザーカスタムフィールド(と、設定「登録フォームにユーザーのカスタムフィールドを表示する」をオンにした場合は編集可能な項目)が表示される。**必須のユーザーカスタムフィールドがあると、登録に入力が必須になる** | 設定は既定でオフ |
| A1-01 | 新規課題フォームに「ウォッチャー」のチェックボックスが増える(`add_issue_watchers` 権限者のみ) | — |
| A12-06 / A5-10 | 設定に「メール受信用のWebサービス」(`POST /mail_handler`、APIキー)と、メール本文の切り捨て行・除外添付ファイル名を正規表現として扱うオプションが増える(既定オフ) | 使わなければ影響なし |
| A12-06b | 受信メールの設定に「本文のキーワードで上書きを許す項目」(既定 `all` で従来どおり)と「サブアドレスからプロジェクトを決める」が増える。本文の「カスタムフィールド名: 値」の行でカスタムフィールドを設定できる | 許可リストを絞らなければ従来どおり。本文にカスタムフィールド名と同じ「名前: 値」の行があると、フィールドが設定されて本文から消える |
| A12-02 | Webhook に「所有ユーザー」(そのユーザーが見られる対象で、プロジェクトの「Webhookの利用」権限がある場合だけ送信)と、設定「Webhookを有効にする」が増える。ロールの権限に「Webhookの利用」 | 所有者を付けない従来のWebhookは従来どおり全件送信 |
| A12-02b | マイアカウントの「マイWebhook」で、権限のあるユーザーが自分のWebhookを登録・編集・削除できる(URLはhttp/httpsで内部ネットワーク宛は不可) | — |
| A11-10 | REST API の課題・工数・プロジェクト・バージョン・グループ・ユーザーに `custom_fields`(読み書き)が増える | 応答に項目が増えるだけで、従来の呼び出しは影響なし |
| A11-07 | REST API: `POST/PUT/DELETE /users`(管理者)が使え、`GET /users/{id}` は管理者以外でも見えるユーザーなら取得できる(項目は閲覧者に応じて出し分け)。**従来は非管理者に403だった** | 従来の管理者の呼び出しは影響なし(応答に項目が増える) |
| A9-03 | マイページのブロックに「自分が更新した課題」「今週のカレンダー」が増える | 追加しなければ影響なし |
| A9-03b | マイページ: 同じ保存クエリを最大3回まで置ける。保存クエリのブロックに「設定」(表示する項目・並び順)、「最近の工数」ブロックに「表示する日数」が増える | 設定しなければ従来の表示(ステータスだけ表示、期間の制限なし)。ブロックの行が(ユーザー×ブロック)の一意ではなくなる |
| (対策) | 全 CSV エクスポートで、`=` `+` `-` `@` で始まる文字列の先頭に `'` が付く(表計算ソフトでの数式実行を防ぐため) | 数値はそのまま。該当する文字列は先頭の `'` を取って使う |
| A9-04 | 全プロジェクトの活動に Atom フィード(`/activity.atom`)が増え、活動ページで最後に選んだ種別が記憶される(個人設定) | — |
| A9-04b | 全プロジェクトの活動に「« 前の期間」「次の期間 »」が付く(期間の長さ分ずつ移動)。表示は従来と同じで、読み込みが速くなる | — |
| A8-05 | 全プロジェクト横断の工数一覧に、権限のある行の「編集」「削除」と CSV エクスポートが増える | — |
| A8-04 | 工数レポートの行の軸にカスタムフィールドが選べ、CSV出力ができる。全プロジェクト横断の工数レポート(`/time_entries/report`)が使える | — |
| A8-02 | 管理画面のカスタムフィールドの対象に「工数」「優先度」が増える。工数フォーム・工数一覧(列/CSV)・優先度の管理フォームに反映 | 作らなければ影響なし |
| A7-01 | Wikiマクロが増える: `{{macro_list}}` `{{hello_world}}` `{{recent_pages(N)}}` `{{issue(id)}}` `{{thumbnail(file)}}`、`{{child_pages}}` の深さ指定、`{{include(プロジェクト:ページ)}}`(別プロジェクト) | 使わなければ影響なし |
| A6-01 | 設定「メール通知」に「課題にコメントが追加されたとき」「フォーラムにメッセージが投稿されたとき」「文書が追加されたとき」「ファイルが追加されたとき」が増える(既定は従来どおり課題の作成/更新のみ) | チェックしなければ従来どおり |
| A4-03 | 設定「認証」に「パスワードの有効期限」、ユーザー編集に「次回ログイン時にパスワードの変更を要求する」が増える。該当ユーザーはパスワードを変更するまでプロフィール以外を開けない(有効期限は既定で無効) | 既存アカウントの有効期限の起点は移行時点 |
| A4-02 | プロフィールに「追加のメールアドレス」が増える(設定の上限まで、既定5件)。通知メールは追加アドレスにも届き(通知オフにできる)、そこからの受信メールも本人として扱われる | 追加しなければ従来どおり |
| A4-01 | ユーザー一覧に「CSVインポート」が増える(管理者のみ)。CSVからユーザーをまとめて作成できる | — |
| A3-09 | プロフィールのメール通知に「選択したプロジェクトのイベントと、自分の関与するイベントのみ通知」が使えるようになり(所属プロジェクトがある人のみ)、通知を受け取るプロジェクトを選べる。従来この選択肢は「自分の関与するイベントのみ」と同じ扱いだった | 選んでいなければ影響なし。選択済みの人はプロジェクトを選び直す |
| A3-02 / A3-11 / A13-05 | ロールの権限に「プロジェクトの公開設定の選択」(`select_project_publicity`)、「プロジェクトの作業分類の管理」(`manage_project_activities`)、「メンバーの表示」(`view_members`)が増える。プロジェクト編集で公開/非公開を切り替えられるのは前者の権限者だけ、作業分類設定は後者だけ、REST のメンバー一覧は `view_members` だけになる。既存ロールには従来の権限(`edit_project`/`manage_members`)の保持者へ自動付与される | 既存の挙動は保たれる(新規ロールを作るときは付与が必要) |
| A3-01 | ロールの権限に「プロジェクトの追加」(`add_project`)が増える。付与されたロール(メンバー役割、グループ経由、非メンバー)を持つ一般ユーザーがトップレベルのプロジェクトを作成できる(**従来は管理者のみ**)。既定では誰にも付与されない | 付与しなければ従来どおり管理者のみ |
| A2-06 | 課題レポートに各集計の「詳細」ページ(未完了/完了/合計・CSV)と「サブプロジェクト別」が増える。**レポートは閲覧できる課題だけを数える**(従来は非公開課題も数えていた) | サブプロジェクトは設定「サブプロジェクトの課題を表示」がオンのときだけ集計 |
| A2-04 | 管理者のユーザー一覧が表形式になり、フィルタ・表示列選択・並べ替え・CSVエクスポートが使える。全リストのフィルタ「〜を含む」が大文字小文字を区別しなくなり、値が空の条件は無視される | 既定はフィルタなしで従来と同じユーザーが並ぶ |
| A1-29 | 課題一覧に「PDFエクスポート」が増える。CSV/PDF は設定「課題一覧のエクスポート件数の上限」(既定500)で先頭から打ち切られる(**従来のCSVは全件出力だった**) | 件数が多い場合は上限を設定で引き上げる(最大5000) |
| A1-22 | コメントを編集すると「(X が編集 日時)」と表示され、編集フォームで「非公開コメントにする」を切り替えられる(`set_notes_private` 権限者のみ)。`PUT /journals/{id}` も `private_notes` を受け付ける | — |
| A1-19 | 後続課題が子を持つ親のとき、親ではなくその子孫の葉の日付が動く(親の日付は葉から再計算)。葉の日付変化で親の日付が変わると、親の後続課題も動く | `parent_issue_dates` をオフにすると従来どおり親を直接動かす |
| A1-18 | 設定に「非稼働日(曜日)」が増え、先行/後続の関連による日付の自動調整が稼働日で数えるようになる(**既定は非稼働日なし=従来どおり暦日**) | 設定で土日などを選ぶと、後続課題は次の稼働日に移り、期間も稼働日数を保つ |
| A1-11 | 課題一覧で、カスタムフィールド列の見出しクリックや2・3列目の並べ替えにより、単一値のカスタムフィールドで並べ替えできる(空欄は昇順で先頭) | — |
| A1-08 | 課題のコピー(詳細画面の「コピー」)が、添付・子課題・ウォッチャー・コピー元との関連を選んで引き継ぐようになる(従来は項目のプリフィルのみで、関連も作られなかった)。設定に「コピー元との関連を作る」「添付ファイルをコピーする」(既定は都度選択)。一括コピーの「添付」「関連」もこの設定に従う | 既存の一括コピーは、関連が「既定で作る」から「チェックボックス(既定オン)」に |
| A1-07 | 課題の一括コピーで子課題も複製されるようになる(コピーフォームの「子課題も複製」、**既定オン**。従来は親のみ複製) | オフにすると従来どおり親のみ |
| A8-06 | 工数一覧で行を右クリックするとメニュー(編集/一括編集、作業分類の変更、削除)。編集できる行のみ対象 | — |
| A4-16 | ユーザー一覧で行を右クリックするとメニュー(ロック/解除、グループに追加/から外す、削除)。**管理者が他のユーザーを削除できるようになる**(削除は匿名化。自分自身と最後の有効な管理者は除外) | 使わなければ従来どおり |
| A1-05 | 課題一覧で行を右クリックするとメニュー(ステータス/トラッカー/優先度/対象バージョン/担当者/カテゴリ/進捗率の変更、編集、コピー、削除)。選択中の行を右クリックすると選択全体が対象 | メニューを使わず従来のチェックボックス+一括編集フォームも使える |
| A1-06 | 課題一覧の一括編集でトラッカー・カテゴリ・開始日/期日・非公開・親課題も変更可能に。ステータスは選択課題すべてが遷移できるものだけを提示(従来は全課題が同一ステータスの場合のみ) | — |
| A1-13 | 課題フォームの進捗率がスライダーからセレクトに | — |
| A1-21 | 課題詳細のサブタスク/関連課題がリストから表になる | — |
| A12-06d | 設定に「受信メールで作成・更新した課題は通知メールを送らない」が増える | 既定オフ |
| A5-16b | 設定に「REST APIと受信メールで作る課題にも開始日の既定(作成日)を適用する」が増える | 既定オフ |
| A1-10 | カスタムフィールドの形式に「ユーザー」「バージョン」が増える(プロジェクトのメンバー/使えるバージョンから選ぶ)。複数値可の選択肢型は複数選択の入力欄になる。課題一覧の絞り込みに「<< 自分 >>」 | 既存の形式は変わらない。管理された一覧の編集フォームの初期値が ID になる(不具合修正) |
| A14-01a | プロフィールに「言語」、設定に「既定の言語」が増える。ブラウザの言語が日本語の未ログインの画面で、日付の相対表示が日本語になる | 既定の言語は English のため、設定しなければ画面の文言は変わらない |
| A14-01b1 | **既定の言語が日本語(`APP_LOCALE` の既定 `ja`)に変わる**。言語を「English」にした利用者と英語ブラウザの未ログインの利用者には、共通メニューと課題画面が英語で表示される | `.env` に `APP_LOCALE=en` がある環境は、設定「既定の言語」を日本語にするか `.env` を `ja` に変える。言語を設定していない利用者の画面は日本語のまま |
