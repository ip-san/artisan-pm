# Redmine 機能パリティ・チェックリスト

`/Users/sesoko/Desktop/workspace/artisan-pm`(本アプリ)と `/Users/sesoko/Desktop/workspace/redmine`(参照元 Redmine)を突き合わせた機能パリティの追跡ドキュメント。計画書 §6 で言及されている実務ドキュメントとして、Phase 0〜6 完了時点(2026-07-21)の状態を初回スナップショットとして作成した。

**凡例**: `done` = 実装済み / `partial` = 部分実装(要注記) / `missing` = 未実装

**運用方針**: 新しい機能を実装したら、該当する行のステータスを更新すること。恒久的に対応しない項目(スコープ外と判断したもの)は削除せず `missing` のまま残し、理由を注記する。

---

## 0. 全体サマリー — 優先度の高いギャップ

個別セクションを横断して特に影響の大きいものを先に挙げる。詳細は各セクション参照。

### 構造的なギャップ(単一機能の欠落ではなく、設計レベルの差分)

1. **プロジェクト横断ビューが皆無**(マイページを除く)。Issues/TimeEntries/Activity/Calendar/Gantt/Search はすべて `/projects/{project}/...` 配下のみで、Redmine の `/issues`, `/time_entries/report`, `/activity`, `/search` 相当のグローバルビューが存在しない。`routes/web.php` を見ればプロジェクト非依存のユーザー向けルートは `/my/page` と `/profile` のみ。
2. ~~**管理者によるユーザー管理画面が皆無**~~ → **done**(2026-07-21)。`users/{index,form}.blade.php` で一覧/作成/編集/ロック・ロック解除に対応。強制パスワードリセットは「編集画面でパスワード欄に新しい値を入力」で代替(専用の「リセットして通知」フローはまだない)。詳細は §2 参照。
3. **アプリケーション設定が Redmine の 119 キー中わずか 6 項目**(`app_title`, `default_issues_per_page`, incoming-mail 系4項目)。表示/認証ポリシー/通知/添付ファイル制限/リポジトリ設定などのタブが丸ごと存在しない。
4. **カスタムフィールド対応が Issue と Project の2種のみ**(Redmine は User/Version/Group/TimeEntryActivity/DocumentCategory 等 ~10種)。
5. **REST API が Projects と Issues の2リソースのみ**(Redmine は ~20 リソース群)。DELETE 系は皆無、ファイルアップロード API もなし。API認証も OAuth2(Passport)のみで、スクリプト用途に適した API キー方式が存在しない。
6. ~~**Issue のサブタスク(親子)機能がモデルはあるがUIがない**。~~ → **done**(2026-07-21)。課題フォームに親課題ID欄、詳細画面に親リンク/サブタスク一覧を追加(詳細は §サブタスク・親子関係 参照)。~~関連課題(`IssueRelation`)もUIが皆無だった。~~ → **done**(2026-07-21)。課題詳細画面に追加/削除UIを実装(詳細は §Issue Relations 参照)。~~IssueCategory はモデル自体が存在しない。~~ → **done**(2026-07-21)。`IssueCategory` モデル・プロジェクト単位の管理画面・課題フォーム/一覧/フィルタ/CSV連携まで実装(詳細は §Issue Categories 参照)。
7. **添付ファイルが Issue/Version/News/Document にしか付かない**。Wiki ページ・フォーラム投稿に添付できない。サムネイル生成・説明文・ダウンロード数もない。
8. **Wiki の差分表示・リダイレクト・マクロエンジンが丸ごと未実装**。
9. ~~**Tracker・IssueStatus に管理画面が皆無**~~ → **done**(2026-07-21)。当初の監査では両方とも「done」と誤って報告されていたが、実際にはモデルのみでルート/画面が一切なく、さらにプロジェクト編集フォームにトラッカー選択欄が無いため UI 経由で作成したプロジェクトは課題を一切作成できない状態だった(トラッカーが0件のため)。この3点をまとめて修正。
10. ~~**ワークフロー遷移・フィールドルールの管理画面が皆無**~~ → **done**(2026-07-21)。`workflows/edit.blade.php` でトラッカー×ロール×適用対象(通常/作成者/担当者)を選んで遷移グリッド・フィールドルールグリッドを編集可能に。新規課題(`old_status_id IS NULL`)の遷移編集は意図的に対象外(`IssueService::create()` のステータス初期値決定がワークフローテーブルを一切参照しないため、現状は編集しても挙動に反映されない — §1 参照)。

### すぐ着手すべき小〜中規模の修正(見つかったバグ・欠落)

- ~~Journal の `private_notes` カラムが存在するのに一切セット/フィルタされていない~~ → **done**(2026-07-21)。`view_private_notes`/`set_notes_private` 権限を新設し配線(詳細は §Journal参照)。
- ~~カスタムフィールドの変更が Journal(監査証跡)に記録されない。~~ → **done**(2026-07-21)。`IssueService::update()` がカスタムフィールド値の設定を取り込み、コア属性の差分と同一Journalに`property: 'cf'`として記録(詳細は §Journal参照)。
- ~~カスタムフィールド値が `searchable => true` でも全文検索にインデックスされていない。~~ → **done**(2026-07-21)。`SearchService::searchIssues()` がScout(subject/description)の結果に`searchable`なカスタムフィールド値(`value_string`/`value_text`へのLIKE検索)をID単位でマージ。詳細は §クエリ/フィルタ/レポートエンジンおよび§検索参照。
- ~~Issue の一覧グルーピングが「現在のページ内のみ」で集計されており、全件SQL集計になっていない。~~ → **done**(2026-07-21)。グループ見出しの件数は`groupTotals()`がSQL `GROUP BY`で全件集計するように変更(表示行自体は引き続き現在のページ内のみ、パフォーマンス上の理由で意図的)。詳細は §クエリ/フィルタ/レポートエンジン参照。

---

## 1. 課題管理(Issues / Workflow / Custom Fields)

対象: Issues, Trackers, IssueStatuses, IssuePriorities, Workflow, Journals, Watchers, IssueRelations, IssueCategories, Versions, 一括編集, CSVインポート/エクスポート。

### Issues 本体

| 機能 | 状態 | 備考 |
|---|---|---|
| 課題の作成/編集/閲覧 | done | `IssueService::create/update`, `issues/{form,show}.blade.php` |
| 更新時の属性差分 Journal 記録 | partial | 固定11項目のみ差分記録(`IssueService.php`)。`category_id` およびカスタムフィールドの変更は記録されない |
| 課題削除 | done(2026-07-21) | 詳細画面に削除ボタンを配線(`delete_issues`権限+確認ダイアログ)。工数は`nullOnDelete`で保持(切り離されるのみ)、子課題も`nullOnDelete`でトップレベル化。Redmineの`params[:todo]`(工数の再割当/削除選択)は意図的に対象外、常に保持のみ |
| 課題のコピー | done(2026-07-21) | 詳細画面の「コピー」リンクが`?copy_from=<id>`付きで新規課題フォームを開き、トラッカー/優先度/カテゴリ/担当者/対象バージョン/題名/説明/日付/カスタムフィールドをプリフィル。ステータス/進捗率/作成者は通常の新規課題と同じ初期値。ジャーナル/添付/関連/親子は意図的にコピー対象外(軽量な「似た課題から始める」機能として設計) |
| プロジェクト間の課題移動 | missing | `project_id` を編集する手段がない |
| 担当者「自分」ショートカット・作成時の既定開始/期日 | done(2026-07-21) | 課題フォームに「自分に割り当てる」ボタン(プロジェクトメンバーかつ未自己割当時のみ表示)。新規課題の開始日は作成日をデフォルトに(期日はRedmine同様デフォルト値なし) |
| 楽観的ロック(競合解決) | missing | `lock_version` 相当なし。後勝ちで無警告上書き |
| 編集画面からの直接工数記録 | missing | 別画面へ遷移するのみ |
| `is_private`(非公開課題)フラグ | missing | — |
| ロール別の課題閲覧範囲(全て/デフォルト/自分のみ) | done(2026-07-21) | `Role.issues_visibility`(all/default/own) + `AuthorizationService::issueVisibilityFor()`。`IssuePolicy::view`と課題一覧のクエリ両方で`own`を強制。複数ロール保持時は最も緩い設定が優先。`default`は`all`と同じ挙動(非公開課題`is_private`が未実装のため区別する意味がまだない、と明記) |
| Atom フィード / REST API 拡張(`include=`) | missing | — |

### サブタスク・親子関係

| 機能 | 状態 | 備考 |
|---|---|---|
| 親子データモデル | done(2026-07-21) | 課題フォームに「親課題ID」欄を追加(同一プロジェクト内の課題IDを指定)。自己参照・循環参照(子孫を親に設定)はバリデーションで拒否。課題詳細画面に親へのリンクとサブタスク一覧を表示 |
| Nested set・親子並べ替え | missing(意図的) | 隣接リスト設計を採用(計画書§7参照)。並べ替えUIは未実装 |
| 親への集計ロールアップ(優先度/日付/進捗率) | missing | — |
| ステータスやサブタスクからの進捗率導出 | missing | 単純なスライダー入力のみ |
| 後続課題のスケジュール連動・プロジェクト間サブタスク | missing(意図的、後者は範囲外) | 親子はプロジェクトスコープに限定(カテゴリ/バージョンと同様の設計判断)。日程自動連動は未実装 |
| 子孫を含めた予定/実績工数の集計 | missing | 直接の TimeEntry のみ合算 |

### Journal(履歴・コメント)

| 機能 | 状態 | 備考 |
|---|---|---|
| コメント追加 | done | — |
| 属性変更の監査証跡 | partial | コア属性(category_id/parent_id含む)とカスタムフィールドは記録される(2026-07-21)。添付/関連の変更は引き続き未記録 |
| プライベートノート(`view_private_notes`) | done(2026-07-21) | `set_notes_private` 権限保持者がコメント投稿時に非公開フラグを立てられる(サーバー側でも権限を再チェックし、クライアント改ざんを無効化)。`view_private_notes` を持たないユーザーには非公開Journalを非表示(自分自身が書いたものは例外的に閲覧可、Redmineの`Journal#visible?`と同様)。活動フィード(`IssueJournalActivityProvider`)は既存どおり非公開Journalを丸ごと除外 |
| 過去コメントの引用返信 | done(2026-07-21) | 各コメントの「引用」ボタンでコメント入力欄に`>`引用形式をプリフィル |
| 個別 Journal の編集/削除 | missing | — |
| 変更点を含むプライベートノートの分割記録 | missing | — |
| イベント別の通知粒度 | partial | `IssueCreated`/`IssueUpdated` のみ。コメント単体では何も発火しない |
| テキスト差分表示・リアクション | missing | — |

### Watchers

| 機能 | 状態 | 備考 |
|---|---|---|
| 自分のWatch/Unwatch | done | — |
| Watch権限ゲート | partial | `view_issues` があれば誰でも可能(`IssuePolicy::watch`) |
| 他ユーザーをWatcherとして追加/削除 | done(2026-07-21) | 詳細画面にウォッチャー一覧+追加(プロジェクトメンバーのセレクト)+削除を配線。`add_issue_watchers`権限で保護(`IssuePolicy::manageWatchers`)。追加対象はプロジェクトメンバーに限定、単純なセレクトのみでオートコンプリートは未実装 |
| 作成者/担当者の自動Watch・@mention・自動整理 | partial | 作成者は作成時に、担当者は割当変更のたびに自動Watch(2026-07-21、`IssueService::autoWatch()`)。@mention・自動整理は引き続き未実装 |

### Issue Relations(関連課題)

| 機能 | 状態 | 備考 |
|---|---|---|
| データモデル | done(2026-07-21) | 課題詳細画面に「関連課題」セクションを追加。`manage_issue_relations` 権限で作成/削除を保護、閲覧は `view_issues` があれば誰でも可。追加時は対象課題IDを指定し、対象課題を閲覧できない場合(別プロジェクトで権限なし等)は403、自己参照・DB一意制約違反(重複)はバリデーションエラーとして表示 |
| 関連タイプ | partial | relates/blocks/duplicates/precedes/follows のみ。逆方向・コピー系タイプ(blocked, duplicated, copied_to/from)の**新規Enum値**は追加していないが、blocks/duplicatesは表示側でfrom/to方向に応じたラベル反転(「ブロックする」⇔「ブロックされている」)を実装。precedes/followsは元々ユーザーが方向を選んで別々に保存する設計のため反転不要 |
| precedes/follows の遅延日数(delay) | missing | マイグレーションに該当カラムなし |
| 関連日付からの自動リスケジュール・循環/プロジェクト間検証 | missing | DBのユニーク制約のみ |
| 重複課題のクローズ連動・ブロック中クローズ禁止 | missing | — |

### Issue Categories

| 機能 | 状態 | 備考 |
|---|---|---|
| カテゴリ機能全体 | done (2026-07-21) | `IssueCategory` モデル/`category_id`/プロジェクト単位の管理画面(`issue-categories.index`/`.form`)。`manage_categories` 権限で保護。既定担当者のヒント機能(選択時に未割当なら自動プリフィル、既存担当者は上書きしない)も実装。使用中カテゴリの削除ガード、他プロジェクトのカテゴリへの404ガードあり。課題一覧の表示列/フィルタ/CSVエクスポート、課題フォームのバリデーション(プロジェクトスコープ)、ワークフローのフィールドルール対象にも追加済み |

### Versions

| 機能 | 状態 | 備考 |
|---|---|---|
| Version CRUD・対象バージョン割当 | done | — |
| open/locked/closed ステータス | partial | Enum/カラムはあるが強制なし(ロック中でも選択可能)。`close_completed_versions` 相当なし |
| バージョン共有範囲(none/descendants/hierarchy/tree/system) | missing | プロジェクトローカル限定 |
| ロードマップ・完了率・遅延表示 | missing | — |
| 予定/実績/残工数の集計 | missing | — |
| Wikiページ紐付け・既定バージョン設定 | missing | `due_date` のみ |

### Trackers

| 機能 | 状態 | 備考 |
|---|---|---|
| CRUD・並べ替え | done(2026-07-21) | `trackers/{index,form}.blade.php`。**訂正**: 当初のパリティ監査は「done」と報告していたが、実際にはモデルのみ存在しルート/画面が皆無だった(seeder/tinker以外に管理手段なし)。あわせてプロジェクト編集フォームにトラッカー選択チェックボックスが無く、UI経由で作成したプロジェクトには一切トラッカーを紐付けられない(=課題作成が機能しない)という連鎖的な欠落も判明・修正した |
| プロジェクトへの紐付け | done(2026-07-21) | `projects/form.blade.php` にチェックボックス追加、最低1つ必須 |
| トラッカー別デフォルトステータス | missing | 新規課題は常にグローバルな最初のステータス |
| トラッカー別コアフィールド非表示(ビットマスク) | missing | — |
| トラッカー/ワークフローのコピー | missing | — |
| ロードマップ対象フラグ・デフォルト非公開・説明文テンプレート | missing | — |
| カスタムフィールド紐付け | done | `custom_field_tracker` pivot |
| 使用中トラッカーの削除防止 | done(2026-07-21) | 参照している課題がある場合は削除をブロック(生のFK違反エラーではなく分かりやすいメッセージ) |

### Issue Statuses / Workflow

| 機能 | 状態 | 備考 |
|---|---|---|
| ステータス CRUD・並べ替え・`is_closed` | done(2026-07-21) | `issue-statuses/{index,form}.blade.php`。**訂正**: Trackerと同様、当初「done」と報告されていたが実際は画面が皆無だった |
| 使用中ステータスの削除防止 | done(2026-07-21) | — |
| デフォルト進捗率・ステータス変更時の一括更新 | missing | — |
| ロール×トラッカー×新旧ステータスの遷移制御 | done | `WorkflowService::allowedTransitions` |
| 作成者/担当者限定の遷移 | done | — |
| ロール×トラッカー×ステータスのフィールド必須/読取専用 | done | コアフィールド+`cf_<id>` に適用 |
| ワークフロー遷移・フィールドルールの管理画面 | done(2026-07-21) | `workflows/edit.blade.php`。トラッカー×ロール×適用対象(通常/作成者/担当者)ごとに遷移グリッドとフィールドルールグリッド(コアフィールド+そのトラッカーのカスタムフィールド)を編集・保存 |
| ワークフローのコピー(トラッカー間/ロール間/一括複製) | missing | — |
| 「使用中ステータスのみ表示」フィルタ | missing | — |
| クローズ可否のフィルタ(未完了サブタスク/ブロック関連の考慮) | missing | — |

### カスタムフィールド(課題)

| 機能 | 状態 | 備考 |
|---|---|---|
| モデル・フォーマットレジストリ・トラッカー/プロジェクト適用・ロール可視性 | done | — |
| フィールド形式のカバレッジ | partial | Redmine の~12形式に対しレジストリのサブセット |
| regexp/min/max/default_value | partial | カラムはあるが `date_offset` 等の高度なデフォルトモードなし |
| 検索対象(`searchable`)の実効性 | done(2026-07-21) | プロジェクト内検索でstring/textカスタムフィールド値がLIKE検索される |
| 保存後のフォーマット変更禁止・多重度変更時のクリーンアップ | missing | — |
| CustomFieldEnumeration(選択肢の位置/有効フラグ、削除時再割当) | missing | `possible_values` は単純な配列カラム |
| 表示列・CSV列としてのカスタムフィールド | missing | 意図的に見送り済み(コード内コメントで明記) |

### 一括編集・インポート・エクスポート

| 機能 | 状態 | 備考 |
|---|---|---|
| 一括編集(ステータス/優先度/担当者/バージョン/進捗率+共通コメント) | done | 各課題ごとに認可チェック・Journal記録 |
| ステータス一括編集の選択制約 | partial(意図的) | 選択課題が単一ステータスの場合のみ許可。トラッカー/カテゴリ/日付/CF/親/一括コメント欄はさらに少ない |
| 一括コピー・一括プロジェクト間移動・一括削除 | missing | — |
| CSVインポート(列マッピング・バックグラウンド処理・進捗表示) | done | `ImportIssuesJob`, `IssueImport` |
| マッピング可能な列 | partial | カテゴリ/対象バージョン/親/非公開フラグ/カスタムフィールド/遅延付き関連は対象外 |
| カテゴリ/バージョンの自動作成、`unique_id`による遅延親子/関連解決 | missing | — |
| CSVエクスポート | partial | 選択列のみ。エンコーディング/区切り文字オプション、CF/関連/添付/Watcher列なし |
| PDFエクスポート・Atomフィード | missing | — |

---

## 2. プロジェクト・管理機能・認証

対象: Projects, Roles & Permissions, Members, Groups, Custom Fields(全対象種別), Enumerations, Settings, User管理, LDAP, 2FA, 登録モード。

### Projects

| 機能 | 状態 | 備考 |
|---|---|---|
| プロジェクト一覧 | partial | ルートのみ表示(`whereDoesntHave('parent')`)、実質フラット表示。検索/ステータスフィルタ/ページネーションなし |
| プロジェクト作成 | partial | 管理者専用(ポリシーで一般ユーザーは常に `false`)。作成時のデフォルトモジュール/トラッカー設定なし |
| プロジェクト編集 | done | 名前/識別子/説明/公開設定/モジュール/プロジェクトカスタムフィールド |
| サブプロジェクト | partial | `NodeTrait` 使用、ポリシーもあるが**フォームに親選択がなく**、UIから作成/再配置できない |
| 有効モジュール | done | `Project::syncModules()` |
| アーカイブ/アーカイブ解除 | partial(2026-07-21) | 詳細画面にボタンを配線(管理者専用、`ProjectPolicy::archive`)。**ステータスの切り替えのみ**で、アーカイブ中のプロジェクトへのアクセス制限・一覧からの除外等の実際の強制は未実装(Redmineはアーカイブ中プロジェクトを実質不可視化するが、本アプリの`AuthorizationService`はまだprojectのstatusを一切参照しない) |
| クローズ/再オープン | partial(2026-07-21) | 詳細画面にボタンを配線(`close_project`権限、ステータスバッジ表示)。**ステータスの切り替えのみ**で、クローズ中の編集制限(課題作成禁止等)は未強制 |
| プロジェクト削除 | missing UI | ポリシーはあるがルート/ボタンなし |
| プロジェクトのコピー | missing | — |
| ブックマーク | done(2026-07-21) | `project_bookmarks`テーブル+`User::bookmarkedProjects()`。詳細画面とプロジェクト一覧の★ボタンでトグル、一覧に「ブックマークのみ表示」フィルタ |
| プロジェクト別 Enumeration(工数種別等)の上書き | missing | — |
| メンバー管理 | partial | メールアドレス完全一致でのみ追加(候補選択なし)。~~グループをメンバーとして追加できない~~ → **done**(2026-07-21)。「ユーザー/グループ」切替式フォームでグループもロール付きで追加可能に。既存メンバーのロール編集は「編集」リンクでフォームにプリフィルして更新可能(グループメンバーは編集フォームの対象外、削除→再追加のみ) |
| 課題カテゴリ | done (2026-07-21) | プロジェクト設定画面から管理(§ Issue Categories参照) |

### ロール・権限

| 機能 | 状態 | 備考 |
|---|---|---|
| ロール一覧/作成/編集(権限チェックボックス) | done | Anonymous/Non-member向けの権限フィルタも正しく機能 |
| ロール削除 | done | — |
| ロールのコピー作成 | done(2026-07-21) | 一覧の「コピー」リンクが`?copy_from=<id>`付きで新規ロールフォームを開き、名前(「〜のコピー」接尾辞)と権限をプリフィル。builtin種別はコピーされない(このフォーム自体がbuiltinを一切設定しないため) |
| 課題の閲覧範囲(全て/デフォルト/自分のみ) | done(2026-07-21) | ロール編集フォームにセレクトを追加。詳細は §Issues本体参照 |
| 工数エントリ閲覧範囲 | done(2026-07-21) | `Role.time_entries_visibility`(all/default/own)。課題閲覧範囲と同一パターンで`TimeEntryPolicy::view`と一覧クエリに適用 |
| ユーザー閲覧範囲 | missing | — |
| 「課題に割当可能」フラグ | missing | — |
| 管理可能ロールの制限 | missing | — |
| 権限一覧レポート・一括更新マトリクス | missing | — |

### グループ

| 機能 | 状態 | 備考 |
|---|---|---|
| グループ CRUD | done | — |
| グループメンバー管理 | partial | メール完全一致のみ、オートコンプリートなし |
| グループをプロジェクトにロール付きで割当 | done(2026-07-21) | `AuthorizationService::memberRolesFor()`は元々グループ経由のロールを解決していた(未使用だったのみ)。プロジェクトのメンバー管理画面からグループを追加できるようにUIを配線 |
| グループ用カスタムフィールド | missing | `CustomizableType` 未対応 |

### カスタムフィールド

| 機能 | 状態 | 備考 |
|---|---|---|
| Issue用カスタムフィールド | done | トラッカー/プロジェクト範囲、ロール可視性、必須/複数値 |
| Project用カスタムフィールド | done | ロール可視性は `Project::relevantCustomFields()` で反映 |
| User/Version/Group/TimeEntryActivity/DocumentCategory用 | missing | `CustomizableType` は Issue, Project の2種のみ |
| フィールド形式 | partial | string/text/int/float/date/bool/list の7種。user/version/enumeration/attachment/link は未対応 |
| custom_field_enumerations(選択肢の管理された一覧) | missing | — |
| default_value/regexp/searchable/editable・visible フラグ、「全プロジェクト対象」 | missing | フォームは min/max 文字数+必須+複数値+選択肢のみ |

### Enumerations(優先度・工数種別・文書カテゴリ)

| 機能 | 状態 | 備考 |
|---|---|---|
| モデル(position/is_default/active) | done | `Enumeration` + `EnumerationType`、各所で消費されている |
| 管理画面 | done(2026-07-21) | `enumerations/{index,form}.blade.php`。`/enumerations/{type}` を `EnumerationType` のネイティブ暗黙Enumルートバインディングで解決し、タブ切り替えで3種別を編集 |
| 種別ごとに既定値を1つだけに制限 | done(2026-07-21) | `Enumeration::makeDefault()` |
| 使用中の値の削除防止 | done(2026-07-21) | IssuePriority(`issues.priority_id`)・TimeEntryActivity(`time_entries.activity_id`)は使用中なら削除不可。DocumentCategoryは `nullOnDelete()` のためガード不要 |

### アプリケーション設定(Redmine 119キー中 ~6項目)

| Redmineのタブ | 状態 | 備考 |
|---|---|---|
| 全般 | partial | `app_title`, `default_issues_per_page` のみ |
| 表示(日付/時刻形式、テーマ、週始まり、サムネイル) | missing | — |
| 認証(ログイン必須、セルフ登録、パスワードポリシー、2FA必須設定、セッションタイムアウト、自動ログイン、REST API有効化) | missing | — |
| プロジェクト(デフォルト公開設定、デフォルトモジュール、識別子連番化、新規プロジェクトの既定ロール) | missing | — |
| ユーザー | missing | — |
| 課題トラッキング(進捗率算出方式、プロジェクト間関連/サブタスク許可、既定表示列) | missing | — |
| メール通知(送信元、ヘッダ/フッタ、通知イベント種別) | missing | — |
| 受信メール | partial | 有効フラグ+既定プロジェクト/トラッカー/ステータスのみ |
| 添付ファイル(最大サイズ、許可/禁止拡張子) | missing | — |
| リポジトリ(有効SCM、自動フェッチ、コミットキーワード) | missing | — |

### ユーザー管理・認証

| 機能 | 状態 | 備考 |
|---|---|---|
| 管理者によるユーザー一覧/作成/編集 | done | `users/{index,form}.blade.php`, `UserPolicy`(admin-only) |
| アカウントロック/アンロック | done | 一覧画面からワンクリックで切替。`AuthenticateUser` がログイン時に `UserStatus::Locked` を実際にブロックする(この画面の実装と同時に修正した既存バグ) |
| 管理者による強制パスワードリセット | partial | 編集フォームでパスワード欄に新しい値を直接入力する形。Redmineのような「リセットしてメール通知」専用フローはない |
| 自分自身のロック防止 | done | 一覧画面の切替アクションは自分自身に対して403を返す(唯一の管理者が自分をロックして誰も解除できなくなる事態を防止)。編集フォーム自体は自分の`is_admin`/`status`変更を意図的に許可(Redmineと同様、誤操作ではなく意図的操作として扱う) |
| LDAP認証ソース CRUD | done | ホスト/ポート/TLS/base_dn/direct-bind or search+bind/属性/onthefly登録/タイムアウト |
| LDAP接続テストボタン | missing | — |
| LDAPカスタムフィルタ/属性→カスタムフィールドマッピング | missing | login/name/mailのみ |
| LDAPオンザフライ登録 | done | — |
| 二要素認証(TOTP) | done | Fortify経由。管理者による強制無効化の専用ボタンはまだ画面にないが、ユーザー編集画面自体は存在する |
| パスキー/WebAuthn | done(Redmineにはない機能) | — |
| パスワードリセット | done | — |
| 登録モード(無効/手動承認/メール確認/自動) | missing | 常時「自動」でハードコード。`emailVerification` はコメントアウト済み |

---

## 3. コンテンツモジュール(Wiki / フォーラム / お知らせ / 文書 / ファイル)

### Wiki

| 機能 | 状態 | 備考 |
|---|---|---|
| ページ CRUD | done | — |
| 階層(親子) | done | — |
| 親の付け替え | partial | 循環参照を除外した `parent_id` 変更のみ。プロジェクト間移動なし |
| バージョン履歴 | done | 追記型 `wiki_page_versions` テーブル |
| **任意の2バージョン間の差分表示** | **missing** | 履歴は単一バージョン閲覧のみ。Wikiの核心機能の一つが欠落 |
| Annotate/Blame | missing | — |
| バージョンの復元(revert) | missing | — |
| バージョン単体の削除 | missing | — |
| **リネーム時のリダイレクト** | **missing** | 単純なタイトル更新のみ。`wiki_redirects` 相当のテーブル/モデルが存在せず、リネームすると既存の `[[リンク]]` が壊れる |
| 保護ページ | done | `is_protected` + ポリシー |
| デフォルト保護ページ(Sidebar等) | missing | — |
| 開始ページ設定 | missing | — |
| **マクロエンジン全体** | **missing** | `#123` 課題メンションと `[[ページ]]` リンクのみ。`{{toc}}`, `{{child_pages}}`, `{{include}}`, `{{collapse}}` 等すべて未実装 |
| セクション単位編集 | missing | 全文テキストエリアのみ |
| プレビュー | missing | 保存後閲覧のみ |
| PDF/HTML/TXT/ZIPエクスポート | missing | — |
| 日付インデックス表示 | missing | — |
| ページのWatch | done(2026-07-21) | `WikiPage`に`watchers()`(ポリモーフィック`Watcher`)を追加、`view_wiki_pages`権限で自己Watch/Unwatch可能。他ユーザーの追加/削除UIはまだなし(Issueの`manageWatchers`相当は未実装) |
| 添付ファイル | done(2026-07-21) | `WikiPage implements HasMedia`。フォームからアップロード、詳細画面で表示/削除(`update`権限) |

### フォーラム(Boards / Messages)

| 機能 | 状態 | 備考 |
|---|---|---|
| Board CRUD・並べ替え | done | — |
| ネストしたBoard | missing | `parent_id` なし、フラット構造 |
| トピック作成/返信 | done | — |
| Sticky | done | — |
| ロック | done | `MessagePolicy::reply` で返信禁止を実装 |
| トピックの別Boardへの移動 | missing | — |
| トピックのWatch | done(2026-07-21) | トピックのみWatch可能(返信は対象外、`MessagePolicy::watch`)。他ユーザーの追加/削除UIはまだなし |
| 引用返信 | done(2026-07-21) | トピック/返信それぞれに「引用」ボタン、返信入力欄に`>`引用形式をプリフィル |
| 添付ファイル | missing | `Message` が `HasMedia` 未実装 |
| 返信のページネーション | missing | 全件ロード |
| Atomフィード | missing | — |

### News

| 機能 | 状態 | 備考 |
|---|---|---|
| CRUD・概要/本文 | done | — |
| コメント | done | `NewsComment` |
| 添付ファイル | done | `News implements HasMedia` |
| Watch・作成者自動Watch | done(2026-07-21) | `News::watchers()`+トグルボタン、作成時に作成者を自動Watch |
| メール通知 | missing | — |
| **プロジェクト横断のNews一覧** | missing | プロジェクト配下ルートのみ |

### Documents

| 機能 | 状態 | 備考 |
|---|---|---|
| CRUD | done | — |
| カテゴリ | done | `Enumeration` 経由 |
| 添付ファイル | done | `Document implements HasMedia` |
| カテゴリ/日付/タイトル/作成者でのグルーピング・並べ替え | missing | `->latest()` のみ |
| カスタムフィールド | missing | — |

### Files モジュール

| 機能 | 状態 | 備考 |
|---|---|---|
| バージョンへのファイル添付 | done | — |
| **プロジェクトレベルのファイル**(バージョン非依存) | done(2026-07-21) | `Project implements HasMedia`。アップロードフォームの「バージョン」に「プロジェクト全体(バージョンなし)」を追加、`ProjectPolicy::manageFiles`(`manage_files`権限)で保護 |
| ファイル名/日付/サイズ/ダウンロード数での並べ替え | missing | ダウンロード数自体は追跡されるようになった(2026-07-21)が、並べ替えUIは未実装 |
| 複数ファイル同時アップロード | done | — |

### 添付ファイル(横断)

| 機能 | 状態 | 備考 |
|---|---|---|
| エンティティごとの複数添付 | done | Spatie MediaLibrary、対象は Issue/Version/News/Document/WikiPage/Message(2026-07-21〜) |
| **サムネイル/画像変換** | **missing** | `registerMediaConversions` が未使用。ダウンロード専用 |
| **添付ファイルの説明文** | **missing** | ファイル名+サイズのみ表示、説明文は保存も編集もできない |
| ダウンロード数カウント | done(2026-07-21) | `AttachmentController`が`media.custom_properties`の`download_count`をダウンロードごとにインクリメント。`<x-download-count>`コンポーネントで各添付ファイル一覧に表示 |
| Wiki/フォーラム投稿への添付 | done(2026-07-21) | Wiki/フォーラム投稿(`Message`、トピック・返信とも)ともに対応 |
| 本文中のインライン画像参照(`attachment:file.png`) | missing | — |

---

## 4. クエリ・レポート・工数管理・ダッシュボード横断機能

**見出しの結論: マイページを除き、プロジェクト横断ビューが一切存在しない。** `routes/web.php` の非プロジェクトルートは `/my/page` と `/profile` のみ。Issues/TimeEntries/Activity/Calendar/Gantt/Search は全て `/projects/{project}/...` 配下限定。

### クエリ/フィルタ/レポートエンジン

| 機能 | 状態 | 備考 |
|---|---|---|
| 保存済みクエリ(フィルタ/列/ソート/グループ) | done | `App\Models\Query` |
| 公開/非公開 | partial | 2値(`is_public`)のみ。Redmine は PRIVATE/ROLES/PUBLIC の3値+ロールスコープ+`manage_public_queries` 権限。誰でも公開フラグを立てられ、権限チェックがない |
| プロジェクト横断クエリ | missing | 常に `project_id` でフィルタ |
| 列選択 | partial(課題)/missing(工数) | 課題: 固定のネイティブ列のみ、カスタムフィールドは列にできず、並べ替えもできない。工数: 列選択UI自体がない |
| グルーピング | partial | 固定の短いリスト(ステータス/トラッカー/優先度/担当者)のみ。件数はSQL `GROUP BY`による全件集計(2026-07-21)。表示行自体は現在のページ内のみ(意図的、パフォーマンス上の理由)。カスタムフィールドでのグルーピング不可 |
| 合計/集計 | partial | 課題: 集計なし(予定/実績工数の合計等)。工数: 全体+グループ別の時間合計のみ |
| 相対日付フィルタ(「過去N日以内」等) | done | — |
| フィルタ演算子(=,≠,in,contains,empty,between,≥/≤) | done | — |
| カスタムフィールドでのフィルタ | done(課題)/n/a(工数) | — |
| 複数列ソート | missing | 単一ソートキーのみ(Redmineは3列まで) |
| CSVエクスポート | done | 課題・工数とも。PDF/Atomはなし |
| 課題レポート(トラッカー/ステータス別集計) | missing | — |

### 工数管理

| 機能 | 状態 | 備考 |
|---|---|---|
| TimeEntry CRUD | done | 一括編集はなし |
| 工数種別(TimeEntryActivity) | done | プロジェクト別上書き・カスタムフィールドはなし |
| 課題の実績工数合計 | partial | 直接のTimeEntryのみ合算、サブタスク分は含まない |
| プロジェクトの実績工数合計 | missing | リレーションはあるが画面に表示されていない |
| **多次元工数レポート(ピボット表)** | **missing — 最大のギャップの一つ** | 単一次元のグループ化リストのみ。Redmine は最大3軸(プロジェクト/ステータス/バージョン/カテゴリ/ユーザー/トラッカー/工数種別/課題+カスタムフィールド)を期間列(年/月/週/日)と掛け合わせ、行・列・総計を算出する |
| プロジェクト横断の工数レポート | missing | — |
| 工数フィルタ(ユーザー/種別/日付/時間) | done | — |
| 工数のCSVインポート | missing | — |

### ダッシュボード横断機能

| 機能 | 状態 | 備考 |
|---|---|---|
| マイページ(ブロック追加/削除/ドラッグ並べ替え) | partial | 動作は良好だが、固定5ブロックのカタログ(担当課題/報告課題/Watch課題/最新News/工数)。Redmine は calendar/documents/timelog/activity に加え**保存済みクエリをブロック化**(issuequery、複数配置可)できるが、artisan-pmには保存済みクエリ↔ダッシュボードの橋渡しがない |
| グローバルアクティビティフィード | partial | 8種類のプロバイダを集約(日付範囲・種別チェックボックス)。プロジェクト単位限定、サブプロジェクト包含なし、Atomなし |
| **プロジェクト横断の課題一覧** | **missing** | Redmineの主要機能の一つだが、トップレベル `/issues` が存在しない |
| カレンダー | partial | 月グリッド、期日のみ(開始日〜期日のスパン表示なし)、クエリフィルタと連動しない固定クエリ、プロジェクト限定 |
| ガント | partial | 再帰CTEツリー+進捗バー。クエリ/フィルタを一切無視(常に全ツリー表示)、バージョンのマイルストーン表示なし、関連線なし、PDF/PNGエクスポートなし、プロジェクト限定 |
| 検索(モジュール横断) | partial | Issue/Wiki/News/Document/Message を1プロジェクト内で検索。all/my_projects/bookmarks/subprojects等のスコープ切替なし、all_words/titles_only/open_issues等のトグルなし、`#123`ジャンプなし、プロジェクト/チェンジセット/Journalは検索対象外 |

---

## 5. リポジトリ(SCM)・REST API・拡張性

### リポジトリ連携

| 機能 | 状態 | 備考 |
|---|---|---|
| リポジトリブラウズ(指定リビジョンでのツリー表示) | done | `GitAdapter`/`SvnAdapter` |
| 対応SCM種別 | partial | Git・SVNのみ。Redmine は Mercurial/Bazaar/CVS/Filesystem等も対応(4種欠落) |
| チェンジセット一覧・単体表示 | done | `RepositorySyncService`, `Changeset` |
| Diff表示 | partial | 単一コミットのdiffのみ。任意リビジョン間・単一ファイルの履歴diffは非対応 |
| **Annotate/Blame** | **missing** | アダプタ・画面とも未実装 |
| 生ファイルダウンロード | partial | Blade経由の表示のみ。バイナリ対応・Content-Dispositionの適切な制御なし |
| リポジトリ統計・コミットグラフ | missing | — |
| プロジェクトあたり複数リポジトリ | missing(要確認) | `Repository belongsTo Project` の1対1想定 |
| 非同期チェンジセット取得 | done | `RepositorySyncJob`(ユニーク制約・タイムアウト調整済み) |
| **コミットメッセージのキーワード連動**(`fixes #123`でクローズ、`refs #123`で単純リンク、工数記録`@2h`等) | partial(2026-07-21) | `fixes/fix/closes/close` キーワードを検出し、コミッターがメール/loginで実在ユーザーに一致し、かつそのユーザーが対象プロジェクトで`edit_issues`権限を持つ場合のみ最初の`is_closed`ステータスへ遷移(Journalも記録)。committer欄は攻撃者が任意に詐称可能なため、権限チェックなしでは他ユーザーになりすませてしまう(自動レビューで指摘・修正済み)。一致しない/権限がない場合は従来どおりリンクのみ。進捗率の自動更新・工数記録`@2h`・キーワードのカスタマイズ設定は未実装 |
| チェンジセットへの関連課題の手動追加/削除 | missing | — |
| コミッター→ユーザーのマッピング | partial(2026-07-21) | `Changeset.committer` は引き続き自由文字列(専用マッピングUIはなし)だが、`RepositorySyncService`がキーワードコマンド適用時にメール/loginでの一致を試みるベストエフォート解決を追加 |

### REST API

**現状: Projects(index/show)とIssues(index/show/store/update)の2リソースのみ**。Redmineは ~20 リソース群を公開。

| Redmine APIリソース | 状態 | 備考 |
|---|---|---|
| Issues | partial | GET/POST/PUTのみ。DELETEなし、`include=`(journals/watchers/relations/attachments/children)なし |
| Projects | partial | GETのみ。POST/PUT/DELETE・アーカイブ操作なし |
| Users | missing | — |
| Time entries | missing | — |
| Versions | missing | — |
| News | missing | — |
| Memberships | missing | — |
| Groups | missing | — |
| Roles | missing | — |
| Trackers | missing | — |
| Issue statuses | missing | — |
| Issue categories | missing | — |
| Issue relations | missing | — |
| Enumerations | missing | — |
| Custom fields | missing | — |
| 添付ファイル(アップロードAPI) | missing | ファイルアップロード用のAPIエンドポイントが皆無 |
| Wiki pages | missing | — |
| Queries | missing | — |
| Journals | missing | — |
| Watchers | missing | — |
| Search | missing | — |
| My account | missing | — |

**API認証**: OAuth2(Passport)のみ。Redmineの `X-Redmine-API-Key` ヘッダー方式やHTTP Basic(APIキーをユーザー名として使用)に相当する、スクリプト/自動化向けの軽量な認証手段が存在しない。cronジョブ等でOAuth2の認可コードフローを要求するのは実用上の後退。

### 拡張性(プラグイン/フック/Webhook/受信メール)

| 機能 | 状態 | 備考 |
|---|---|---|
| プラグイン登録面(権限/アクティビティ/ダッシュボードブロック/カスタムフィールド形式/メニュー項目/ビューフック) | done | `PluginManager` が既存レジストリに委譲 |
| ランタイムでのプラグイン検出 | missing(意図的、第一段階) | `bootstrap/providers.php` への手動登録。計画書で明記済みのスコープ |
| プロジェクトモジュールのプラグイン拡張 | missing(意図的) | `ProjectModuleKey` はコンパイル時Enum。`PluginManager` のdocblockに明記 |
| クエリフィルタ演算子のプラグイン拡張 | missing(意図的) | `IssueFilterFieldRegistry` 等に `register()` が存在せず、そもそも呼び出し箇所もない(Phase 2由来の別の既存ギャップ) |
| プラグイン設定UI・永続化 | missing | — |
| プラグインのバージョン依存チェック | missing | `Plugin.requiresCoreVersion` は保持のみで強制なし |
| コントローラ/モデルのライフサイクルフック | partial | ビュー描画フック(`<x-hook>`)のみ。Redmineの `controller_issues_edit_before_save` 等、数十のモデル/コントローラフックに相当するものはない |
| **Webhook**(Redmineコア機能ではない、本アプリでの追加機能) | done(範囲限定) | Issue作成/更新イベントのみ。削除・プロジェクト/バージョン/工数/Wikiイベントは未対応 |
| 受信メールによる課題作成 | done | `[識別子]` プレフィックスでのプロジェクト振り分け、送信者=既存ユーザーの権限チェック |
| 受信メールでの`unknown_user`/`no_permission_check`相当 | missing(意図的) | メールからのアカウント自動作成を意図的に非対応としている(セキュリティ上の判断としてコード内に明記) |
| **メール返信による課題更新** | **missing** | `In-Reply-To`/件名`[#123]`への返信が課題へのコメント追加や再オープンに繋がらない |
| **メール本文のキーワードコマンド**(`Status: Closed`, `Assigned to:`, `Priority:` 等) | **missing** | 本文はそのまま説明文として保存されるのみ。Redmineは~12種の組み込みキーワード+`allow_override`をパースする |

---

## 6. 次にトラッキング表を更新するタイミング

- 新しいフェーズ/機能追加のたびに該当行を `missing`/`partial` → `done` に更新する。
- 意図的にスコープ外とした項目は、その理由をコード側のdocblock/コメントに残し、このドキュメントの備考にも「意図的」である旨を明記する(既に実施済みの例: `PluginManager` のプロジェクトモジュール/フィルタ演算子除外、受信メールの `unknown_user` 非対応)。
- このドキュメント自体の生成方法: `/Users/sesoko/Desktop/workspace/redmine` の実装(コントローラ/モデル)と `/Users/sesoko/Desktop/workspace/artisan-pm` の実装を突き合わせる並列調査(5系統: 課題管理/管理機能・認証/コンテンツモジュール/クエリ・工数・ダッシュボード/SCM・API・拡張性)を実施し、その結果を統合した。
