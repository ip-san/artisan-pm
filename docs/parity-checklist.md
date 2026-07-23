# Redmine 機能パリティ・チェックリスト

`/Users/sesoko/Desktop/workspace/artisan-pm`(本アプリ)と `/Users/sesoko/Desktop/workspace/redmine`(参照元 Redmine)を突き合わせた機能パリティの追跡ドキュメント。計画書 §6 で言及されている実務ドキュメントとして、Phase 0〜6 完了時点(2026-07-21)の状態を初回スナップショットとして作成した。

**凡例**: `done` = 実装済み / `partial` = 部分実装(要注記) / `missing` = 未実装

**運用方針**: 新しい機能を実装したら、該当する行のステータスを更新すること。恒久的に対応しない項目(スコープ外と判断したもの)は削除せず `missing` のまま残し、理由を注記する。

---

## 0. 全体サマリー — 優先度の高いギャップ

個別セクションを横断して特に影響の大きいものを先に挙げる。詳細は各セクション参照。

### 構造的なギャップ(単一機能の欠落ではなく、設計レベルの差分)

1. ~~**プロジェクト横断ビューが皆無**(マイページを除く)。~~ → **一部done**(2026-07-22)。News(`news.global-index`、先行実装)に続き、Issues(`issues.global-index`)・TimeEntries(`time-entries.global-index`)・Search(`search.global-index`)・Calendar(`calendar.global-index`)のトップレベルビューを追加(詳細は §4/§Search 参照)。Activity(集計フィード自体はdone、プロジェクト単位限定)/Gantt は引き続き `/projects/{project}/...` 配下限定のまま。
2. ~~**管理者によるユーザー管理画面が皆無**~~ → **done**(2026-07-21)。`users/{index,form}.blade.php` で一覧/作成/編集/ロック・ロック解除に対応。強制パスワードリセットは「編集画面でパスワード欄に新しい値を入力」で代替(専用の「リセットして通知」フローはまだない)。詳細は §2 参照。
3. **アプリケーション設定が Redmine の 119 キー中わずか 6 項目**(`app_title`, `default_issues_per_page`, incoming-mail 系4項目)。表示/認証ポリシー/通知/添付ファイル制限/リポジトリ設定などのタブが丸ごと存在しない。
4. ~~**カスタムフィールド対応が Issue と Project の2種のみ**~~ → **一部done**(2026-07-22〜23)。Version・Group・TimeEntryActivity・Document・DocumentCategory・**User**にも対応(`CustomizableType::Version`/`::Group`/`::TimeEntryActivity`/`::Document`/`::DocumentCategory`/`::User`)。Version/Documentはプロジェクトのロール経由で可視性を解決、Group/TimeEntryActivity/DocumentCategory/Userは管理者専用リソースのためロールフィルタ自体が不要。Redmineの残り ~2種(主にプラグイン向けの拡張ポイント)は引き続き未対応。
5. **REST API が Projects と Issues の2リソースのみ**(Redmine は ~20 リソース群)。DELETE 系は皆無、ファイルアップロード API もなし。API認証も OAuth2(Passport)のみで、スクリプト用途に適した API キー方式が存在しない。
6. ~~**Issue のサブタスク(親子)機能がモデルはあるがUIがない**。~~ → **done**(2026-07-21)。課題フォームに親課題ID欄、詳細画面に親リンク/サブタスク一覧を追加(詳細は §サブタスク・親子関係 参照)。~~関連課題(`IssueRelation`)もUIが皆無だった。~~ → **done**(2026-07-21)。課題詳細画面に追加/削除UIを実装(詳細は §Issue Relations 参照)。~~IssueCategory はモデル自体が存在しない。~~ → **done**(2026-07-21)。`IssueCategory` モデル・プロジェクト単位の管理画面・課題フォーム/一覧/フィルタ/CSV連携まで実装(詳細は §Issue Categories 参照)。
7. ~~**添付ファイルが Issue/Version/News/Document にしか付かない**。Wiki ページ・フォーラム投稿に添付できない。サムネイル生成・説明文・ダウンロード数もない。~~ → **done**(2026-07-21〜22)。Wiki/フォーラム投稿への添付、ダウンロード数、説明文、サムネイル生成のすべてに対応済み(詳細は §添付ファイル 参照)。
8. ~~**Wiki の差分表示・リダイレクト・マクロエンジンが丸ごと未実装**。~~ → **一部done**(2026-07-21〜22)。差分表示・リダイレクトは既に完了(詳細は §Wiki 参照)。マクロエンジンは`{{toc}}`のみ実装、`{{child_pages}}`/`{{include}}`/`{{collapse}}`等は引き続き未実装(詳細は §マクロエンジン全体 参照)。
9. ~~**Tracker・IssueStatus に管理画面が皆無**~~ → **done**(2026-07-21)。当初の監査では両方とも「done」と誤って報告されていたが、実際にはモデルのみでルート/画面が一切なく、さらにプロジェクト編集フォームにトラッカー選択欄が無いため UI 経由で作成したプロジェクトは課題を一切作成できない状態だった(トラッカーが0件のため)。この3点をまとめて修正。
10. ~~**ワークフロー遷移・フィールドルールの管理画面が皆無**~~ → **done**(2026-07-21)。`workflows/edit.blade.php` でトラッカー×ロール×適用対象(通常/作成者/担当者)を選んで遷移グリッド・フィールドルールグリッドを編集可能に。新規課題(`old_status_id IS NULL`)の遷移編集は意図的に対象外(`IssueService::create()` のステータス初期値決定がワークフローテーブルを一切参照しないため、現状は編集しても挙動に反映されない — §1 参照)。

### すぐ着手すべき小〜中規模の修正(見つかったバグ・欠落)

- ~~Journal の `private_notes` カラムが存在するのに一切セット/フィルタされていない~~ → **done**(2026-07-21)。`view_private_notes`/`set_notes_private` 権限を新設し配線(詳細は §Journal参照)。
- ~~カスタムフィールドの変更が Journal(監査証跡)に記録されない。~~ → **done**(2026-07-21)。`IssueService::update()` がカスタムフィールド値の設定を取り込み、コア属性の差分と同一Journalに`property: 'cf'`として記録(詳細は §Journal参照)。
- ~~カスタムフィールド値が `searchable => true` でも全文検索にインデックスされていない。~~ → **done**(2026-07-21)。`SearchService::searchIssues()` がScout(subject/description)の結果に`searchable`なカスタムフィールド値(`value_string`/`value_text`へのLIKE検索)をID単位でマージ。詳細は §クエリ/フィルタ/レポートエンジンおよび§検索参照。
- ~~Issue の一覧グルーピングが「現在のページ内のみ」で集計されており、全件SQL集計になっていない。~~ → **done**(2026-07-21)。グループ見出しの件数は`groupTotals()`がSQL `GROUP BY`で全件集計するように変更(表示行自体は引き続き現在のページ内のみ、パフォーマンス上の理由で意図的)。詳細は §クエリ/フィルタ/レポートエンジン参照。

---

## 0.5 残作業ロードマップ(2026-07-22 レビュー・最適化)

残り約98行(missing/partial)を価値×工数×依存関係で精査した結果。**「既存エンジンの再利用で完結する項目を先に、横断基盤が必要な項目は判断点として明示し、設定画面の行は対応機能と同時にしか作らない」**を原則とする。

### 近期キュー(ループ継続対象・この順で1項目ずつ)

| # | 項目 | 根拠 |
|---|---|---|
| 1 | カレンダー: 開始日/期日マーカー表示 | 小。Redmineは期間中の全日ではなく開始日(→)と期日(←)にのみ表示する方式で、現行実装への追加が最小 |
| 2 | ガント: クエリフィルタ連動 | 中。既存`QueryFilterEngine`をそのまま差し込むだけで「常に全ツリー」の最大の不満を解消 |
| 3 | 検索: all_words/titles_only/オープン課題のみトグル+`#123`ジャンプ | 小〜中。`SearchService`への条件追加が中心 |
| 4 | マイページ: 保存済みクエリブロック(issuequery相当) | 中。保存済みクエリ基盤とブロックカタログが両方完成済みで、橋渡しのみが欠けている。体感価値が高い |
| 5 | リポジトリ: 任意リビジョン間Diff | 中。`ScmAdapter::diff()`に第2引数を足すだけで、UI はリビジョン一覧のラジオ選択(Wiki差分と同型) |
| 6 | Journal: 添付/関連変更の監査記録 | 中。Journal基盤完備。Redmineの`property='attachment'/'relation'`行を追加するだけ |
| 7 | カスタムフィールドの表示列・CSV列対応(課題一覧) | 中。`castValue()`/`options()`整備済みで実装障壁が当初より大幅に低下。長年の意図的見送りを解消できる |
| ~~8~~ | ~~トラッカーの複製~~ → **done**(2026-07-22) | 小。ワークフローコピーが既にあるため残りは属性+CF紐付けのコピーのみ |
| ~~9~~ | ~~プロジェクト作成時のデフォルトモジュール/トラッカー~~ → **done**(2026-07-22) | 中。設定「プロジェクト」タブの行はこの機能と同時に部分消化 |
| ~~10~~ | ~~受信メール設定の拡張+リポジトリ設定(自動フェッチ等)~~ → **done**(2026-07-22) | 小×2。既存settingsパターンの追記 |

### ユーザー判断待ち(着手には明示的な承認・確認が必要)

| 項目 | 判断点 |
|---|---|
| **メール通知基盤**(最重要判断) | 残missingの最大クラスタ(課題/News/Wiki通知、通知設定タブ、メール確認付き登録、@mention通知)がすべてこれ待ち。Laravel標準Notification+既存キュー+Sail同梱のMailpitで**新規依存なしに実装可能**。1項目ではなく5〜8項目のミニフェーズになるため、開始判断を仰ぎたい |
| @mentionミニフェーズ | 前提2つ(全ユーザー必須のlogin欄+公開プロフィール画面)を含む3段構成なら実現可能。単独では見送り済み(§1調査メモ) |
| PDF出力(課題/Wiki/ガント) | `barryvdh/laravel-dompdf`等の**依存追加が必要**(CLAUDE.md: 依存変更は承認必須) |
| プロジェクト削除・コピー | 削除は標準除外項目(nested set影響)。コピーは大型(全アソシエーション複製) |
| プロジェクトあたり複数リポジトリ | スキーマ変更(1対1→1対多)。要否自体の確認が先 |
| Mercurial/CVS/Bazaar/Filesystemアダプタ | 計画書§4の通り要否確認。Git+SVNで実用上十分な可能性が高い |
| 2FA(TOTP) | Fortifyの2FA機能有効化+UI。中型。認証設定タブと同時に |
| ユーザー閲覧範囲・LDAP属性マッピング | ニッチ。優先度確認 |

### 保留の再確認(現状維持を推奨)

- **意図的スコープ外の継続**: Issueのnested set化(63)、プロジェクト間サブタスク(66)、CSVインポートの高度マッピング(166-167)、ステータス一括編集の選択制約(163)、XML API — いずれも計画書で合意済みの逸脱。
- **単独では割に合わない**: Wiki開始ページ(290、ルーティング再設計が必要)、文書の作成者グルーピング(333、添付のアップロード者記録が前提)、Wikiのannotate(284、ニッチ)、リアクション(80)。
- **REST API拡張**(430-435): 機械的だが量が多い。APIの実利用者が現れた時点でまとめて1フェーズにするのが効率的。

### 運用原則(今回の最適化で明文化)

1. 設定画面のタブ/行は、対応する機能実装と**同じコミット**でのみ追加する(空の設定項目を先に作らない)。
2. 横断基盤(メール通知・プロジェクト横断ビュー)は近期キューを消化してから、承認を得て独立ミニフェーズとして着手する。プロジェクト横断ビュー(368/388他)は「クエリ+一覧」の型が固まっているため、メール通知より着手障壁が低い。
3. 過去2回発生した「実装済みなのにmissing表記」(Version CRUD、フォーラム添付、Watch権限ゲート)の再発防止として、行を消化する際は周辺行の実態も同時に確認する。

---

## 1. 課題管理(Issues / Workflow / Custom Fields)

対象: Issues, Trackers, IssueStatuses, IssuePriorities, Workflow, Journals, Watchers, IssueRelations, IssueCategories, Versions, 一括編集, CSVインポート/エクスポート。

### Issues 本体

| 機能 | 状態 | 備考 |
|---|---|---|
| 課題の作成/編集/閲覧 | done | `IssueService::create/update`, `issues/{form,show}.blade.php` |
| 課題本文・コメントのMarkdownレンダリング | done(2026-07-22) | 前回の調査で発見した欠落を解消。`issues/show.blade.php`の説明文・各Journalコメント(`notes`)を`WikiMarkdownRenderer`に通すよう変更(`renderedDescription`/`renderedNotes()`)、`#123`課題リンク・`[[Page]]`Wikiリンク・インライン画像参照(添付ファイル)がすべて課題側でも有効に。~~課題フォームの入力プレビューは対象外~~ → **done**(2026-07-23): Wikiフォームの`showPreview`/`previewHtml()`パターンをそのまま`issues/form.blade.php`に移植。説明文入力欄の下に「プレビュー」トグルボタンを追加、既存添付ファイルへのインライン画像参照も解決(新規選択中の未アップロードファイルはWiki側と同様に対象外) |
| 更新時の属性差分 Journal 記録 | done(2026-07-22訂正) | **訂正**: 従来「category_id・カスタムフィールドは記録されない」と誤記されていたが、実際には`JOURNALED_ATTRIBUTES`に`category_id`含め15項目が既に含まれ、カスタムフィールドも`diffCustomFieldSnapshots()`で別途記録済み(詳細は下の「属性変更の監査証跡」行を参照、内容が重複していたため本行はそちらに合わせて訂正) |
| 課題削除 | done(2026-07-21) | 詳細画面に削除ボタンを配線(`delete_issues`権限+確認ダイアログ)。工数は`nullOnDelete`で保持(切り離されるのみ)、子課題も`nullOnDelete`でトップレベル化。Redmineの`params[:todo]`(工数の再割当/削除選択)は意図的に対象外、常に保持のみ |
| 課題のコピー | done(2026-07-21) | 詳細画面の「コピー」リンクが`?copy_from=<id>`付きで新規課題フォームを開き、トラッカー/優先度/カテゴリ/担当者/対象バージョン/題名/説明/日付/カスタムフィールドをプリフィル。ステータス/進捗率/作成者は通常の新規課題と同じ初期値。ジャーナル/添付/関連/親子は意図的にコピー対象外(軽量な「似た課題から始める」機能として設計) |
| プロジェクト間の課題移動 | done(2026-07-21) | `move_issues`権限(移動元)+移動先での`add_issues`が必要。`IssueService::moveToProject()`がカテゴリ/対象バージョン/親をリセットし、移動先の非メンバーである担当者を解除、この課題を親としていた子課題も切り離す。Journalに記録 |
| 担当者「自分」ショートカット・作成時の既定開始/期日 | done(2026-07-23) | 課題フォームに「自分に割り当てる」ボタン(プロジェクトメンバーかつ未自己割当時のみ表示)。新規課題の開始日は作成日をデフォルトに。**期日の既定オフセット設定を追加**: Redmineの`Setting.default_issue_due_date_offset_in_days`相当(設定画面「新規課題の期日の既定値(作成日からの日数)」、空欄=既定値なし=Redmine自体の初期値と同じ)。`issues/form.blade.php`で`?copy_from=`によるプリフィル処理の**後**に適用(`??=`で既に値がある場合は上書きしない)、Redmineの`build_new_issue_from_params`がcopy_from処理の後に`||=`で適用する順序と一致。0日を指定した場合は当日が期日になる(負数は設定画面のバリデーションで拒否) |
| 楽観的ロック(競合解決) | done(2026-07-22) | `issues.lock_version`列を追加。`IssueService::update()`が任意の`$expectedLockVersion`引数を受け取り、現在値と不一致なら`StaleIssueUpdateException`を投げて保存前に中断(保存のたびに`lock_version`をインクリメント)。課題編集フォームがフォーム読み込み時のlock_versionを保持し送信時に照合、競合時はエラーバナー表示で上書きを防止。一括編集/リポジトリ連携等プログラム的な更新は引き続き未指定(常に許可) |
| 編集画面からの直接工数記録 | done(2026-07-22) | Redmineの`_edit.html.erb`内`log_time`fieldset相当。`log_time`権限を持つメンバーには課題編集フォームに時間/作業分類/コメントのインライン欄を表示し、課題保存と同じ送信で`TimeEntry`を作成(時間未入力ならスキップ)。別画面(`time-entries.create`)からの記録も引き続き利用可能 |
| `is_private`(非公開課題)フラグ | done(2026-07-21) | `issues.is_private`。`set_issues_private`権限保持者のみ作成/編集画面でON可能(サーバー側でも再チェックし、権限のない編集者が既存の非公開課題を意図せず公開化することを防止)。Journal記録・詳細画面のバッジ表示も対応 |
| ロール別の課題閲覧範囲(全て/デフォルト/自分のみ) | done(2026-07-21) | `Role.issues_visibility`(all/default/own) + `AuthorizationService::issueVisibilityFor()`。`IssuePolicy::view`と課題一覧のクエリで3段階を正しく強制:all=無条件、default=非公開課題は作成者/担当者のみ(Redmineの`Issue.visible_condition`と同じ規則、2026-07-21に`is_private`実装と合わせて修正)、own=作成者/担当者のみ。複数ロール保持時は最も緩い設定が優先 |
| Atom フィード / REST API 拡張(`include=`) | partial(2026-07-22) | プロジェクトの活動(activity.atom)・フォーラム(boards.atom)・お知らせ(news.atom)・**課題一覧(issues.atom、2026-07-22追加)**のAtomフィードを実装。課題一覧のフィードは`Issue::scopeVisibleTo()`+未クローズのみという固定条件(HTML版一覧の既定状態と同じ)で配信し、現在の絞り込み/ソート/グループ化状態は反映しない(Redmine本家は反映するが、News/Boardの各フィードも同様に「最新N件」の非フィルタ版であるため、一貫した意図的なスコープ限定)。REST APIの`include=`パラメータ拡張は引き続き未実装 |

### サブタスク・親子関係

| 機能 | 状態 | 備考 |
|---|---|---|
| 親子データモデル | done(2026-07-21) | 課題フォームに「親課題ID」欄を追加(同一プロジェクト内の課題IDを指定)。自己参照・循環参照(子孫を親に設定)はバリデーションで拒否。課題詳細画面に親へのリンクとサブタスク一覧を表示 |
| Nested set・親子並べ替え | missing(意図的) | 隣接リスト設計を採用(計画書§7参照)。並べ替えUIは未実装 |
| 親への集計ロールアップ(優先度/日付/進捗率) | done(2026-07-22) | Redmineの`Issue#recalculate_attributes_for`相当を`IssueService`に実装。子課題の作成/更新(親の付け替え含む)のたびに親(および祖先)を再計算: 優先度は未クローズの子課題のうち最高優先度(全て閉じていればカタログの既定優先度にフォールバック)、日付は子課題の開始日の最小値〜期日の最大値、進捗率は予定工数で重み付けした平均(クローズ済み子課題は100%扱い、未見積の子課題は見積済み子課題の平均見積で重み付け)。`parent_issue_priority`/`_dates`/`_done_ratio`設定(既定オン)で個別に無効化可能。課題フォームは子課題を持つ課題でこれらの項目を無効化し注記を表示 |
| ステータスやサブタスクからの進捗率導出 | done(2026-07-22訂正) | **訂正**: 従来「単純なスライダー入力のみ」と記載していたが、ステータスからの導出(`issue_done_ratio`設定、2026-07-21実装済み)とサブタスクからの導出(`parent_issue_done_ratio`設定、上の「親への集計ロールアップ」行で2026-07-22実装)の両方が既に完了しており、記載漏れだった |
| 後続課題のスケジュール連動・プロジェクト間サブタスク | missing(意図的、後者は範囲外) | 親子はプロジェクトスコープに限定(カテゴリ/バージョンと同様の設計判断)。日程自動連動は未実装 |
| 子孫を含めた予定/実績工数の集計 | done(2026-07-22) | `issues.estimated_hours`列を新設(課題フォームに入力欄追加、空欄は0ではなくnullとして保存)。`Issue::spentHours()`/`totalSpentHours()`/`totalEstimatedHours()`をRedmineの同名メソッド相当で実装(子課題を持つ場合は`descendantIds()`の再帰CTEで子孫全体を合算、末端課題は自身の値のみ)。課題詳細画面に「合計: X時間」として表示 |

### Journal(履歴・コメント)

| 機能 | 状態 | 備考 |
|---|---|---|
| コメント追加 | done | — |
| 属性変更の監査証跡 | done(2026-07-22) | 添付/関連の変更記録を追加(Redmineの`Journal#journalize_attachment`/`#journalize_relation`相当、`IssueService::journalizeAttachment()`/`journalizeRelation()`)。添付は`property='attachment'`+`prop_key`=メディアID、追加時は`new_value`・削除時は`old_value`にファイル名(課題**作成時**の添付はRedmine同様記録しない — 作成自体がJournalを持たないため)。関連は両側の課題にそれぞれJournalを作成し、`prop_key`はその課題から見た関連タイプ(受け側はRedmineの反転名 blocked/duplicated/follows/precedes)、値は相手課題ID。履歴表示にも両プロパティの描画を追加 |
| プライベートノート(`view_private_notes`) | done(2026-07-21) | `set_notes_private` 権限保持者がコメント投稿時に非公開フラグを立てられる(サーバー側でも権限を再チェックし、クライアント改ざんを無効化)。`view_private_notes` を持たないユーザーには非公開Journalを非表示(自分自身が書いたものは例外的に閲覧可、Redmineの`Journal#visible?`と同様)。活動フィード(`IssueJournalActivityProvider`)は既存どおり非公開Journalを丸ごと除外 |
| 過去コメントの引用返信 | done(2026-07-21) | 各コメントの「引用」ボタンでコメント入力欄に`>`引用形式をプリフィル |
| 個別 Journal の編集/削除 | missing | — |
| 変更点を含むプライベートノートの分割記録 | done(2026-07-23) | Redmineの`Journal#split_private_notes`相当。これまで課題編集フォーム(`issues/form.blade.php`)には非公開メモのチェックボックスが無く、属性変更とコメントを同時に保存すると常に1つの公開Journalに混在していた(コメント単体の`issues/show.blade.php`側は既に対応済みだったため、このギャップは編集フォーム経由の保存のみ)。`IssueService::update()`に`commentIsPrivate`引数を追加し、非公開コメント+属性/カスタムフィールド変更が同時に発生した場合のみJournalを分割: 属性変更を持つ公開Journalと、ノートのみを持つ非公開Journalの2件を作成(コメントのみ、または変更点のみの場合は分割不要のため従来どおり1件)。`Issue::journals()`に`orderBy('id')`を副次ソートとして追加し、同一秒に作成される分割後の2件の表示順を安定化。フォーム側は`show.blade.php`と同じ`setNotesPrivate`権限ゲート+サーバー側再チェックのチェックボックスを追加 |
| イベント別の通知粒度 | partial(2026-07-22) | `IssueService::update()`のJournal作成条件と`IssueUpdated`イベント発火条件が食い違っていたバグを修正: 属性変更が無くコメントのみの更新でもJournalは作成されるのに`IssueUpdated`(Webhookの`issue.updated`が購読)は発火しないという不整合があった。現在はコメント単体でも発火。メール通知システム自体は依然未実装(`IssueCreated`/`IssueUpdated`はWebhook専用) |
| テキスト差分表示・リアクション | partial(2026-07-22) | テキスト差分表示を実装: Redmineの`JournalsController#diff`相当(`Redmine::Helpers::Diff`を使う点も含め、既存の`App\Support\Diff\WordDiffer`をそのまま再利用)。説明文の変更(`property='attr' AND prop_key='description'`、Redmine本家がdiffリンクを出す唯一の`attr`プロパティ)にのみ「(差分)」リンクを表示、新規`issues.journal-detail-diff`ルートで単語単位の追加/削除をハイライト。カスタムフィールド側の`change_as_diff`相当(長文形式CFの差分表示)は対象外。リアクション機能は引き続き未着手 |

### Watchers

| 機能 | 状態 | 備考 |
|---|---|---|
| 自分のWatch/Unwatch | done | — |
| Watch権限ゲート | done(2026-07-22訂正) | **訂正**: 従来「`view_issues`があれば誰でも可能」を理由にpartialとされていたが、Redmine自体が自己Watchに専用権限を持たず「対象を閲覧できること」だけを要求する(`acts_as_watchable`)ため、`IssuePolicy::watch`の現挙動はRedmine忠実で完了扱いが正しい。他ユーザーの追加/削除は別権限`add_issue_watchers`(下の行)で既にゲート済み |
| 他ユーザーをWatcherとして追加/削除 | done(2026-07-21) | 詳細画面にウォッチャー一覧+追加(プロジェクトメンバーのセレクト)+削除を配線。`add_issue_watchers`権限で保護(`IssuePolicy::manageWatchers`)。追加対象はプロジェクトメンバーに限定、単純なセレクトのみでオートコンプリートは未実装 |
| 作成者/担当者の自動Watch・@mention・自動整理 | partial | 作成者は作成時に、担当者は割当変更のたびに自動Watch(2026-07-21、`IssueService::autoWatch()`)。@mention・自動整理は引き続き未実装。**調査メモ(2026-07-22)**: Redmineの`@login`メンションは(a)メール通知トリガー専用の部分(本アプリは送信メール基盤自体が無いため無関係)と、(b)本文中の`@login`をユーザープロフィールへのリンクとして描画する部分の2つに分かれる。(b)だけなら価値はあるが、本アプリのローカルアカウントには全ユーザー必須のユニークな`login`(ユーザー名)フィールドが無く(LDAP連携アカウントのみ任意設定)、`/users/:id`相当の一般公開プロフィール画面自体も存在しない(管理者用のusers.index/formのみ)。単独のwell-scoped項目には収まらない前提整備が2つ必要と判明したため、いったん見送り |

### Issue Relations(関連課題)

| 機能 | 状態 | 備考 |
|---|---|---|
| データモデル | done(2026-07-21) | 課題詳細画面に「関連課題」セクションを追加。`manage_issue_relations` 権限で作成/削除を保護、閲覧は `view_issues` があれば誰でも可。追加時は対象課題IDを指定し、対象課題を閲覧できない場合(別プロジェクトで権限なし等)は403、自己参照・DB一意制約違反(重複)はバリデーションエラーとして表示 |
| 関連タイプ | partial(2026-07-22) | relates/blocks/duplicates/precedes/follows/**copied_to**(2026-07-22追加)。逆方向の blocked/duplicated/copied_from は依然として表示側のラベル反転のみ(新規Enum値は追加せず、blocks/duplicates/copied_toは表示側でfrom/to方向に応じたラベル反転)。precedes/followsは元々ユーザーが方向を選んで別々に保存する設計のため反転不要。`copied_to`はRedmineの`Issue#after_create_from_copy`と同じく`IssueService::copy()`実行時に常に自動作成(コピー元→コピー先、ユーザーが「関連追加」フォームから手動選択することは不可 — Redmine同様システム生成専用)。削除時のJournal記録(`journalizeRelation()`)も`copied_to`→`copied_from`の反転に対応。単発の`?copy_from=`プリフィル機能(既存の「似た課題から始める」機能)は元々関連を含め何も複製しない設計のため対象外のまま |
| precedes/follows の遅延日数(delay) | done(2026-07-23) | `issue_relations.delay`列を追加。関連追加フォームで先行/後続選択時のみ入力欄を表示し、それ以外の種別では保存時にRedmine同様nullにリセット。関連一覧に「X日後」として表示。**遅延日数に基づく日付の自動リスケジュール計算**(下の行に別記載)も実装済み |
| 関連日付からの自動リスケジュール・循環/プロジェクト間検証 | partial(2026-07-23) | Redmineの`IssueRelation#validate_issue_relation`相当のうち検証系を実装: プロジェクトをまたぐ関連はデフォルト拒否(`cross_project_issue_relations`設定でオプトイン、Redmineの`Setting.cross_project_issue_relations`相当)、祖先/子孫関係にある課題同士の関連付けを拒否(`descendantIds()`利用)、`relates`の逆方向重複を拒否、`blocks`の直接循環(相互ブロック)を拒否。Redmine本家が行う`precedes`/`follows`チェーン全体の循環検出(`would_reschedule?`の再帰探索)までは対象外、直接の往復のみ検証。**関連日付からの自動リスケジュール計算**を実装(`IssueService::rescheduleSuccessors()`/`rescheduleFromRelation()`、Redmineの`Issue#reschedule_following_issues`/`IssueRelation#set_issue_to_dates`相当): 先行課題の開始日/期日が変わるたび(`IssueService::update()`内)、または`precedes`/`follows`関連を新規作成した直後、後続課題の開始日を「先行課題の期日(無ければ開始日)+1+delay日」まで前進させる(既にそれ以降で始まっている場合は変更しない)。元の開始日/期日の日数差を保ったまま期日も追従、`precedes`→`precedes`のチェーンは`update()`の再帰呼び出しで多段カスケード。Redmineとの意図的な簡略化2点(いずれもコード側にコメントで明記): (1) 稼働日カレンダーが本アプリに存在しないため暦日ベースで計算(Redmineは稼働日ベース)。(2) 後続課題が親課題(子を持つ)の場合もRedmineのように子課題側へ伝播させず、後続課題自身の日付を直接変更(この扱いは既存のdone_ratioのような「親は導出値で編集不可」ロックが元々start_date/due_dateには無く、現状の「日付は誰でも自由編集可」という挙動と一致)。循環関連(`precedes`同士が直接・間接に往復するケース、上記のとおり作成時バリデーションでは検出されない)による無限再帰を防ぐため、カスケード中に一度リスケジュール済みの課題IDを再訪しない訪問済みセット+最大50ホップの上限を追加 |
| 重複課題のクローズ連動・ブロック中クローズ禁止 | done(2026-07-21) | 両方実装。ブロック中クローズ禁止は§クローズ可否のフィルタ参照。重複課題のクローズ連動は新設定`close_duplicate_issues`(デフォルトtrue)で制御、`IssueService`が open→closed の遷移(Redmineの`Issue#closing?`と同じ判定)のたびに`Issue::duplicates()`を再帰的にクローズ(相互複製の循環も、都度DBから再取得して既にクローズ済みか確認することで安全に停止する、Redmine自身と同じガード) |

### Issue Categories

| 機能 | 状態 | 備考 |
|---|---|---|
| カテゴリ機能全体 | done (2026-07-21) | `IssueCategory` モデル/`category_id`/プロジェクト単位の管理画面(`issue-categories.index`/`.form`)。`manage_categories` 権限で保護。既定担当者のヒント機能(選択時に未割当なら自動プリフィル、既存担当者は上書きしない)も実装。使用中カテゴリの削除ガード、他プロジェクトのカテゴリへの404ガードあり。課題一覧の表示列/フィルタ/CSVエクスポート、課題フォームのバリデーション(プロジェクトスコープ)、ワークフローのフィールドルール対象にも追加済み |

### Versions

| 機能 | 状態 | 備考 |
|---|---|---|
| Version CRUD・対象バージョン割当 | partial(2026-07-22訂正) | **訂正**: 従来「done」と報告されていたが、実際には`VersionPolicy`のコメントに明記の通り「Files機能でのバージョン参照のみ対応、CRUD自体のUIは皆無」だった(Tracker/IssueStatusで過去に起きたのと同種の誤報告)。今回`versions/{index,form}.blade.php`を新規追加し、`manage_versions`権限でのCRUDを実装。対象バージョン割当(課題側の`fixed_version_id`選択)自体は元々課題フォームで動作済み |
| open/locked/closed ステータス | done(2026-07-22) | Redmineの`Issue#assignable_versions`相当を実装: 課題フォームの対象バージョン欄はオープンなバージョンのみ新規選択可能とし(ロック中/クローズ済みは除外)、既にそのバージョンが設定済みの課題は変更後も選択肢を維持。バリデーションも同じ許可リストで照合。`close_completed_versions`相当も実装: `Version::isCompleted()`(クローズ済み、または期日超過かつ未クローズの課題なし)+`Project::closeCompletedVersions()`(open/lockedのうち完了したものを一括クローズ)。バージョン一覧に管理者向けボタンを配線(Redmine同様、自動発火ではなく明示操作) |
| バージョン共有範囲(none/descendants/hierarchy/tree/system) | done(2026-07-22) | Redmineの`Version::VERSION_SHARINGS`を移植。`versions.sharing`列(enum、既定`none`)+`VersionSharing` enumを追加。`Project::sharedVersions()`がRedmineの`Project#shared_versions`をそのまま(kalnoy/nestedsetの`_lft`/`_rgt`列に対して)再現: 自プロジェクトの全バージョン+`system`(全プロジェクト)+`tree`(同一ツリー)+祖先の`descendants`/`hierarchy`+子孫の`hierarchy`。課題フォームの対象バージョン候補を`project->versions`から`sharedVersions()`へ変更、他プロジェクト由来のバージョンは「プロジェクト名 - バージョン名」で表示。`Version::allowedSharings()`(Redmine`allowed_sharings`相当)で選択可能な共有範囲を制限: `system`は管理者のみ、`hierarchy`/`tree`はツリー根プロジェクトで`manage_versions`を持つ場合のみ(サブプロジェクト管理者が勝手に共有範囲を広げられない)。バージョンフォームに共有セレクタ、一覧に共有バッジを追加。`rootProject()`ヘルパーをProjectに追加(kalnoyのクエリ拡張を避け素のEloquentビルダーで根を解決) |
| ロードマップ・完了率・遅延表示 | done(2026-07-23) | Redmineの`VersionsController#index`(ルート上は`roadmap`)相当。新規`versions.roadmap`ルート(`view_issues`権限、`manage_versions`とは別のアビリティ`viewRoadmap`で分離)で未完了バージョンを期日昇順(未設定は最後)に表示。`Version::issueCounts()`/`closedPercent()`/`completedPercent()`を追加(`completedPercent()`はIssueServiceの親課題進捗率ロールアップと同じ、予定工数で重み付けした平均のアルゴリズム)。期日超過は日数付きで赤字表示。課題一覧への絞り込みリンクを実装(下記「課題一覧のURLクエリ文字列によるフィルタ初期化」参照)、Redmineの`version_filtered_issues_path(version, status_id: '*'/'c'/'o')`相当を、本アプリの課題一覧が既に持つ`statusFilter`クイックトグル(未対応/完了/すべて)+`fixed_version_id`フィルタの組み合わせで再現(`versions/roadmap.blade.php`の`issuesUrl()`) |
| 予定/実績/残工数の集計 | done(2026-07-22) | `Version::estimatedHours()`/`spentHours()`/`estimatedRemainingHours()`をRedmineの同名メソッド相当で実装(予定/残工数は子課題を持つ課題を除いた末端課題のみ合算し二重計上を防止、実績工数はTimeEntry経由で階層に関わらず合算)。`versions/index.blade.php`の一覧に表示 |
| Wikiページ紐付け・既定バージョン設定 | partial(2026-07-22) | Wikiページ紐付けを実装: `versions.wiki_page_title`列を追加(Redmine同様、外部キーではなくタイトル文字列で解決)。バージョンフォームでプロジェクト内のWikiページから選択(他プロジェクトのページは選択肢に出ず、バリデーションでも拒否)。一覧にリンク表示。「既定バージョン設定」に該当するRedmine機能は未特定のため未着手 |

### Trackers

| 機能 | 状態 | 備考 |
|---|---|---|
| CRUD・並べ替え | done(2026-07-21) | `trackers/{index,form}.blade.php`。**訂正**: 当初のパリティ監査は「done」と報告していたが、実際にはモデルのみ存在しルート/画面が皆無だった(seeder/tinker以外に管理手段なし)。あわせてプロジェクト編集フォームにトラッカー選択チェックボックスが無く、UI経由で作成したプロジェクトには一切トラッカーを紐付けられない(=課題作成が機能しない)という連鎖的な欠落も判明・修正した |
| プロジェクトへの紐付け | done(2026-07-21) | `projects/form.blade.php` にチェックボックス追加、最低1つ必須 |
| トラッカー別デフォルトステータス | done(2026-07-21) | `trackers.default_status_id`。未設定時は全体の先頭ステータスにフォールバック。新規課題作成中のトラッカー切替で再計算(編集中の課題では既存ステータスを維持し変更しない) |
| トラッカー別コアフィールド非表示(ビットマスク) | done(2026-07-22) | Redmineの`Tracker::CORE_FIELDS`相当を実装。`trackers.disabled_core_fields`(JSON配列、ビットマスクではなくフィールドキー配列で保持)+トラッカー編集フォームのチェックボックス群。課題フォーム側は無効化されたフィールドをdisabled表示ではなく完全に非表示(Redmine同様)。`project_id`/`tracker_id`/`subject`/`is_private`は対象外(Redmineの`CORE_FIELDS_UNDISABLABLE`相当) |
| トラッカー/ワークフローのコピー | done(2026-07-22) | ワークフローのコピー(下の「ワークフローのコピー」行を参照)に加え、トラッカー自体の複製を実装。`trackers/form.blade.php`(新規作成時のみ)に「コピー元トラッカー」選択欄を追加、選択すると説明・既定ステータス・非表示フィールド・非公開既定値をその場でプリフィル(Redmineの`Tracker#copy_from`相当)、保存時にプロジェクトへの割り当てとカスタムフィールド紐付け(`custom_field_tracker`)もコピー先トラッカーへ複製。ワークフロールールのコピーは既存の別画面(トラッカー×ロール単位)を使う設計のまま、Redmine同様この操作には含めない |
| ロードマップ対象フラグ・デフォルト非公開・説明文テンプレート | partial(2026-07-22) | デフォルト非公開(`private_by_default`)を実装: Redmineの`Issue#safe_attributes=`と同じ条件(トラッカーに設定あり・フォームで未指定・`set_issues_private`権限あり)で新規課題の非公開チェックボックスを自動プリチェック。トラッカー切替時も再判定。**さらに(2026-07-22)**: `is_in_roadmap`(ロードマップ対象フラグ、既定true)を実装 — ロードマップ画面(`versions.roadmap`)が2026-07-22に完成したことで見送り理由が解消。トラッカー編集フォームにチェックボックスを追加し、`Version::issueCounts()`/`completedPercent()`に`?Collection $trackerIds`引数を追加(ロードマップ画面のみ`is_in_roadmap`が有効なトラッカーIDで絞り込み、バージョン自身の詳細/編集画面など他の利用箇所は全課題を対象のまま)。「説明文テンプレート」に該当するRedmine側フィールドは調査したが特定できず未着手 |
| カスタムフィールド紐付け | done | `custom_field_tracker` pivot |
| 使用中トラッカーの削除防止 | done(2026-07-21) | 参照している課題がある場合は削除をブロック(生のFK違反エラーではなく分かりやすいメッセージ) |

### Issue Statuses / Workflow

| 機能 | 状態 | 備考 |
|---|---|---|
| ステータス CRUD・並べ替え・`is_closed` | done(2026-07-21) | `issue-statuses/{index,form}.blade.php`。**訂正**: Trackerと同様、当初「done」と報告されていたが実際は画面が皆無だった |
| 使用中ステータスの削除防止 | done(2026-07-21) | — |
| デフォルト進捗率・ステータス変更時の一括更新 | done(2026-07-22) | `issue_statuses.default_done_ratio` + 設定「課題の進捗率」(手動/ステータスから算出)。算出モード時は`IssueService`が保存のたびに`done_ratio`をステータスの既定値で上書き(Redmineの`update_done_ratio_from_issue_status`と同じ`before_save`相当のタイミング)、フォームのスライダーも無効化。**一括更新**を実装: Redmineの`IssueStatus.update_issue_done_ratios`相当のボタンをステータス管理画面に追加(`issue_done_ratio`設定が「ステータスから算出」の時のみ表示)、既定値が設定されている全ステータスについて該当課題のdone_ratioを一括で既定値に上書き |
| ロール×トラッカー×新旧ステータスの遷移制御 | done | `WorkflowService::allowedTransitions` |
| 作成者/担当者限定の遷移 | done | — |
| ロール×トラッカー×ステータスのフィールド必須/読取専用 | done | コアフィールド+`cf_<id>` に適用 |
| ワークフロー遷移・フィールドルールの管理画面 | done(2026-07-21) | `workflows/edit.blade.php`。トラッカー×ロール×適用対象(通常/作成者/担当者)ごとに遷移グリッドとフィールドルールグリッド(コアフィールド+そのトラッカーのカスタムフィールド)を編集・保存 |
| ワークフローのコピー(トラッカー間/ロール間/一括複製) | done(2026-07-22) | Redmineの`WorkflowRule.copy`/`copy_one`相当を実装。ワークフロー管理画面に「ワークフローをコピー」欄を追加し、コピー元(単一トラッカー×単一ロール)からコピー先(複数トラッカー×複数ロールの直積、既存設定は削除して置き換え)へ`workflow_transitions`/`workflow_field_rules`を一括複製。Redmineの「トラッカー/ロールいずれかを省略すると全件対象」という省略記法は対象外とし、コピー元・コピー先とも明示選択必須というスコープを絞った実装 |
| 「使用中ステータスのみ表示」フィルタ | done(2026-07-22) | Redmineの`WorkflowsController#find_statuses`相当。選択中トラッカーに既存の遷移(`old_status_id <> new_status_id`)があるステータスのみをデフォルトで表示するチェックボックスを追加(デフォルトON)。遷移が未定義のトラッカーは全ステータス表示にフォールバック |
| クローズ可否のフィルタ(未完了サブタスク/ブロック関連の考慮) | done(2026-07-22) | `WorkflowService::allowedTransitions()`が`Issue::isClosable()`(未クローズの子課題、または自分をブロックする未クローズ課題があるか)で管理者含め全ユーザーのクローズ系ステータス遷移をフィルタ(Redmineの`Issue#closable?`/`new_statuses_allowed_to`と同じ規則)。現在のステータス自体は常に選択可能なまま維持。**さらに(2026-07-22)**: `Issue::isReopenable()`(祖先を`parent_id`で遡り、直近の親に限らずいずれかが未クローズでない=クローズ中なら再オープン不可、Redmineの`Issue#reopenable?`と同じ規則)+`WorkflowService::excludeUnreopenableStatuses()`(`excludeUnclosableStatuses()`と対称の実装)を追加し、閉じた親(または祖先)を持つサブタスクはオープン系ステータスへ遷移できないよう制限。現在のステータス自体は常に選択可能なまま維持(既存のクローズ側ガードと同じ保護) |

### カスタムフィールド(課題)

| 機能 | 状態 | 備考 |
|---|---|---|
| モデル・フォーマットレジストリ・トラッカー/プロジェクト適用・ロール可視性 | done | — |
| フィールド形式のカバレッジ | partial | Redmine の~12形式に対しレジストリのサブセット |
| regexp/min/max/default_value | partial | カラムはあるが `date_offset` 等の高度なデフォルトモードなし |
| 検索対象(`searchable`)の実効性 | done(2026-07-21) | プロジェクト内検索でstring/textカスタムフィールド値がLIKE検索される |
| 保存後のフォーマット変更禁止・多重度変更時のクリーンアップ | done(2026-07-22) | Redmineの`CustomField#field_format=`(保存済みレコードへの代入を黙って無視)と`handle_multiplicity_change`を移植。フォーマット固定は二重防御: フォーム側は編集時にセレクタを固定表示し`save()`が既存レコードの値を使用(`customized_type`と同じパターン)、モデル側は`updating`イベントで`field_format`のダーティ変更を元値に差し戻すバックストップ。多重度クリーンアップは`updated`イベントで`multiple`がtrue→falseに変わった時のみ、対象オブジェクトごとに最新(最大id)の値だけを残して削除(RedmineのEXISTS相関サブクエリをEloquentの`whereExists`でそのまま再現) |
| CustomFieldEnumeration(選択肢の位置/有効フラグ、削除時再割当) | done(2026-07-22) | **調査の結果判明**: Redmineの「リスト」形式は本アプリの`list`形式(単純な文字列配列`possible_values`)と同じ仕組みで、`CustomFieldEnumeration`は実際には別の独立したフィールド形式`enumeration`(`Redmine::FieldFormat::EnumerationFormat`)専用のテーブルだった。新規`enumeration`形式を追加: `custom_field_enumerations`テーブル(`custom_field_id`, `name`, `position`, `active`)+`CustomFieldEnumeration`モデル(`App\Models\Enumeration`とは別物)。値は選択肢のIDを`value_string`に保存(`FormatContract::castValue()`に`CustomField`を渡すようインターフェースを拡張し、IDから選択肢名への解決を可能に — 既存7形式は素通しの引数追加のみ)。カスタムフィールド管理フォームに選択肢の追加/改名/有効無効切替UIを追加。**削除時再割当**: 選択肢の「削除」は即時実行(フォーム保存を待たない独立アクション)、削除前に置き換え先選択肢を選べるドロップダウンを表示し、既存の`custom_field_values`を置き換え先へ一括更新(置き換え先未選択の場合はRedmineと異なり値を確実にnullへクリア — Redmine本家は置き換え先未指定だと存在しないIDを指したまま放置される)。並べ替えUI(ドラッグでの位置変更)は対象外、位置は追加順のまま |
| 表示列・CSV列としてのカスタムフィールド | done(2026-07-22) | 長年の意図的見送りを解消。`availableColumns()`がネイティブ列+プロジェクト適用対象の課題カスタムフィールド(`cf_{id}`キー、フィルタエンジンと同じ規約)を提供し、表示列チェックボックス・テーブル表示・CSVエクスポートのすべてで選択可能に。複数値フィールドは「, 」結合で表示。`castValue()`/`options()`の基盤整備(同日実施)により実装障壁が低下していた。カスタムフィールドでの**並べ替え**は引き続き不可(`CustomFieldFilter::isSortable()=false`、列見出しクリックは無害なno-op) |

### 一括編集・インポート・エクスポート

| 機能 | 状態 | 備考 |
|---|---|---|
| 一括編集(ステータス/優先度/担当者/バージョン/進捗率+共通コメント) | done | 各課題ごとに認可チェック・Journal記録 |
| ステータス一括編集の選択制約 | partial(意図的) | 選択課題が単一ステータスの場合のみ許可。トラッカー/カテゴリ/日付/CF/親/一括コメント欄はさらに少ない |
| 一括コピー・一括プロジェクト間移動・一括削除 | done(2026-07-23) | 一括プロジェクト間移動(`move_issues`+移動先`add_issues`、`IssueService::moveToProject()`)、一括削除(`delete_issues`)、一括コピー(`copy_issues`+複製先`add_issues`、`IssueService::copy()`、関連カスタムフィールド値も複製先トラッカーに応じて複製)を実装。コピー元リンク(`copied_to`関連)は元から自動作成済み。**添付ファイル/ウォッチャーの複製を追加(2026-07-23)**: `IssueService::copy()`に`copyAttachments`/`copyWatchers`引数(共にRedmine同様デフォルトtrue)を追加、一括コピーフォームにも対応するチェックボックス(共に既定でチェック済み、Redmineの一括編集フォームの`copy_attachments`/`copy_watchers`チェックボックスと同じデフォルト)を追加。添付はSpatie MediaLibraryの`Media::copy()`でファイルごと複製、ウォッチャーは`status=active`のユーザーのみ複製(Redmineの`visible_watcher_users.select{active}`相当、複製先での自動ウォッチ(作成者/担当者)との重複は`firstOrCreate`で吸収)。**サブタスクの複製のみ引き続き対象外**(Redmine本家は子孫を再帰的にコピーし可視性/対象バージョン/担当者の妥当性を個別に再検証する処理を持つが、範囲が大きいため別スコープとして意図的に保留) |
| CSVインポート(列マッピング・バックグラウンド処理・進捗表示) | done | `ImportIssuesJob`, `IssueImport` |
| マッピング可能な列 | partial | カテゴリ/対象バージョン/親/非公開フラグ/カスタムフィールド/遅延付き関連は対象外 |
| カテゴリ/バージョンの自動作成、`unique_id`による遅延親子/関連解決 | missing | — |
| CSVエクスポート | partial(2026-07-22) | 選択列のみ。エンコーディング(UTF-8/Shift_JIS、UTF-8はBOM付き)と区切り文字(カンマ/セミコロン/タブ)を選択可能に(Redmineの`Redmine::Export::CSV`相当)。CF/関連/添付/Watcher列は引き続き対象外 |
| PDFエクスポート・Atomフィード | missing | — |

---

## 2. プロジェクト・管理機能・認証

対象: Projects, Roles & Permissions, Members, Groups, Custom Fields(全対象種別), Enumerations, Settings, User管理, LDAP, 2FA, 登録モード。

### Projects

| 機能 | 状態 | 備考 |
|---|---|---|
| プロジェクト一覧 | partial(2026-07-22) | 名前/識別子の検索とステータス(アクティブ/クローズ/アーカイブ済み)フィルタを追加、いずれか使用時は全プロジェクトを対象にした25件/ページのページネーション表示に切替(`can('view')`を通した後にコレクション側でページ分割するため、非表示プロジェクトが件数に混入しない)。**さらに(2026-07-22)**: フィルタ未使用時の表示を「ルートのみのフラット表示」から「全プロジェクトをネストセットのツリー順(`withDepth()`+`defaultOrder()`)でインデント表示」に変更、子プロジェクトのツリー表示を実装。インデント幅はGanttビューの`8 + depth * 16`px方式を踏襲(`16 + depth * 16`px、`px-4`の既定16pxを深さ0の基準値とする)。深さは真の(可視性を考慮しない)絶対深度のため、親が非表示なプロジェクトの子はインデントされた状態で親なしに表示され得る意図的な簡略化(Redmine本家はより精緻な可視祖先相対インデント)。フィルタ使用時は引き続きフラット・アルファベット順のまま(検索は文脈より一致結果を優先する設計判断) |
| プロジェクト作成 | partial(2026-07-22) | 管理者専用(ポリシーで一般ユーザーは常に `false`)。作成時のデフォルトモジュール/トラッカー/**公開設定**を設定「プロジェクト」タブ(`default_projects_modules`/`default_projects_tracker_ids`/`default_projects_public`)から適用するよう追加(Redmineの`Project#initialize`と同じく新規作成フォームの初期値のみで、作成時にプロジェクトごと上書き可能)。トラッカー設定が未設定の場合は全トラッカーにフォールバック、公開設定が未設定の場合はRedmineの既定値と同じ`true`にフォールバック |
| プロジェクト編集 | done | 名前/識別子/説明/公開設定/モジュール/プロジェクトカスタムフィールド |
| サブプロジェクト | done(2026-07-22) | プロジェクトフォームに親プロジェクト選択欄を追加(自分自身/子孫は選択肢から除外しサイクルを防止)し、作成・再配置ともUIから可能に。プロジェクト詳細に`add_subprojects`権限保持者向け「サブプロジェクトを追加」リンクを追加(`?parent_id=`付きで作成フォームへ、`ProjectPolicy::createSubproject`で認可)。トップレベルプロジェクト作成自体は引き続き管理者専用のまま |
| 有効モジュール | done | `Project::syncModules()` |
| アーカイブ/アーカイブ解除 | done(2026-07-22) | 詳細画面にボタンを配線(管理者専用、`ProjectPolicy::archive`)。`AuthorizationService::can()`がRedmineの`Project#allows_to?`と同様、アーカイブ中プロジェクトへの全操作を拒否(管理者はGate::before経由で従来どおりバイパス)。`ProjectPolicy::view()`もis_publicより優先してアーカイブを弾くため一覧・詳細から実質不可視化 |
| クローズ/再オープン | done(2026-07-22) | 詳細画面にボタンを配線(`close_project`権限、ステータスバッジ表示)。`Permission::$readOnly`フラグ(view_*/browse_repository等に付与)を追加し、クローズ中はモジュール権限のうち読み取り専用以外(課題作成・編集・工数記録等)を拒否。`close_project`/`edit_project`等プロジェクト管理系(module未指定)権限は対象外とし、クローズ中でも再オープンや設定変更は可能なままにする実装上の判断(Redmine本家は再オープン自体も同じ経路でブロックされ得る特殊挙動があるが、ここでは追わない) |
| プロジェクト削除 | missing UI | ポリシーはあるがルート/ボタンなし |
| プロジェクトのコピー | missing | — |
| ブックマーク | done(2026-07-21) | `project_bookmarks`テーブル+`User::bookmarkedProjects()`。詳細画面とプロジェクト一覧の★ボタンでトグル、一覧に「ブックマークのみ表示」フィルタ |
| プロジェクト別 Enumeration(工数種別等)の上書き | done(2026-07-22) | Redmineと同じくTimeEntryActivityのみ対象(`Project#activities`/`create_time_entry_activity_if_needed`相当)。`enumerations`に`project_id`/`parent_id`を追加し、新規`projects/{project}/activities`画面(`edit_project`権限)でプロジェクトごとに有効/無効をトグル(Redmine同様リネームは不可、グローバル値の名前をそのまま複製)。状態がグローバル既定と一致する場合は上書き行を作らず/削除し、`Project::activities()`が実効的な一覧を解決。工数記録フォーム・課題フォームの工数記録欄・工数一覧の絞り込みドロップダウンをすべてこの実効一覧に差し替え |
| メンバー管理 | done(2026-07-22) | ~~グループをメンバーとして追加できない~~ → **done**(2026-07-21)。「ユーザー/グループ」切替式フォームでグループもロール付きで追加可能に。既存メンバーのロール編集は「編集」リンクでフォームにプリフィルして更新可能(グループメンバーは編集フォームの対象外、削除→再追加のみ)。~~メールアドレス完全一致でのみ追加(候補選択なし)~~ → **done**(2026-07-22): 名前/メールの部分一致で候補を絞り込むデバウンス付き検索(`wire:model.live.debounce.300ms`、既存の`projects/index.blade.php`の検索と同じ規約)+クリックで選択するドロップダウンに変更(Redmineの`autocomplete_for_user`相当)。候補は既存メンバーを除外 |
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
| 「課題に割当可能」フラグ | done(2026-07-21) | `Role.assignable`(デフォルトtrue) + `Project::assignableUsers()`。課題フォーム/一覧の一括編集/カテゴリの既定担当者セレクトに適用。バリデーション自体は緩めたままで、既にnon-assignableなメンバーへ割当済みの課題を編集しても既存の割当は保持される(意図的) |
| 管理可能ロールの制限 | done(2026-07-22) | Redmineの`Role#managed_roles`(自己参照多対多)+`all_roles_managed`フラグ+`Member#set_editable_role_ids`相当。新規`role_managed_role`ピボットテーブルと`roles.all_roles_managed`(既定true)を追加。`AuthorizationService::managedRolesFor()`が管理者=全ロール、`manage_members`権限を持つロールのうち`all_roles_managed`があれば全ロール、無ければ`managedRoles()`の和集合を解決。プロジェクトのメンバー管理画面はこの範囲外のロールをチェックボックスとして表示せず、保存時も範囲外のロールIDは`Rule::in()`で拒否した上で、既存メンバーが元々持っていた範囲外ロールは編集時も維持(サブセット管理者が権限外ロールを意図せず剥奪できないようにする)。ロール管理フォームにも「全ロールを管理可能」チェックボックス+管理可能ロール選択を追加 |
| 権限一覧レポート・一括更新マトリクス | done(2026-07-22) | `roles/report.blade.php`(`/roles/report`)で全ロール×全権限のマトリクスを表示し、1回の保存で全ロールの権限を一括更新。各ロールで実際に付与可能な権限(匿名/非メンバー組み込みロールは`PermissionRegistry::assignableTo()`でサブセットに制限)以外はチェックボックス自体を表示せず、保存時もサーバー側で許可リストを再計算するため、無効な権限をクライアント側から注入しても反映されない |

### グループ

| 機能 | 状態 | 備考 |
|---|---|---|
| グループ CRUD | done | — |
| グループメンバー管理 | done(2026-07-22) | ~~メール完全一致のみ、オートコンプリートなし~~ → プロジェクトメンバー管理と同一パターンの名前/メール部分一致検索+選択ドロップダウンに変更(既存グループメンバーは候補から除外) |
| グループをプロジェクトにロール付きで割当 | done(2026-07-21) | `AuthorizationService::memberRolesFor()`は元々グループ経由のロールを解決していた(未使用だったのみ)。プロジェクトのメンバー管理画面からグループを追加できるようにUIを配線 |
| グループ用カスタムフィールド | done(2026-07-22) | `CustomizableType::Group`を追加。グループはプロジェクト/ロールを持たない管理者専用リソース(`GroupPolicy`が全メソッドdeny、管理者のみ`Gate::before`経由)のため、Issue/Project/Versionと異なりロール別可視性フィルタは不要 ― `Group::relevantCustomFields()`は単純に対象タイプの全カスタムフィールドを返す。`groups/form.blade.php`で入力/保存(既存パターンを踏襲) |

### カスタムフィールド

| 機能 | 状態 | 備考 |
|---|---|---|
| Issue用カスタムフィールド | done | トラッカー/プロジェクト範囲、ロール可視性、必須/複数値 |
| Project用カスタムフィールド | done | ロール可視性は `Project::relevantCustomFields()` で反映 |
| Version用カスタムフィールド | done(2026-07-22) | `CustomizableType::Version` を追加。`Version::relevantCustomFields()` はVersion自身がロール/メンバーを持たないため所属`project`経由でロール可視性を解決。`versions/form.blade.php` で入力/保存(`projects/form.blade.php`と同一パターン) |
| User/DocumentCategory用 | done(2026-07-23) | `CustomizableType`は Issue/Project/Version/Group/TimeEntryActivity/Document/DocumentCategory/**User**の8種。TimeEntryActivityに続きDocumentCategoryを追加(Redmineの`TimeEntryActivityCustomField`/`DocumentCategoryCustomField`相当) ― `Enumeration`モデルはIssuePriority/TimeEntryActivity/DocumentCategoryの3種を1テーブルで表現するため、`relevantCustomFields()`を`match($this->type)`でTimeEntryActivity→`CustomizableType::TimeEntryActivity`、DocumentCategory→`CustomizableType::DocumentCategory`と振り分け(IssuePriorityは対象外、Groupと同様ロール可視性フィルタも不要)。両タイプの相互非流出(TimeEntryActivity用フィールドがDocumentCategoryフォームに現れない等)をテストで確認。`enumerations/form.blade.php`は完全に型非依存の共通フォームのため変更不要。**User用(2026-07-23追加)**: Groupと同一パターン(`HasCustomFields`+`customizableType()`+`relevantCustomFields()`、ユーザー管理は管理者専用リソースのためロール可視性フィルタ不要)。`MorphMapServiceProvider`に`'user' => User::class`を追加(Userは従来どの多態関連にも参加していなかったため新規追加)、`users/form.blade.php`に入力欄を追加(`groups/form.blade.php`と同一パターン) |
| フィールド形式 | partial(2026-07-22) | string/text/int/float/date/bool/list/enumeration/**link**の9種(2026-07-22にlinkを追加)。`App\CustomFields\Formats\LinkFormat`はStringFormatと同じ保存/検証(Redmine同様、保存時の追加URL検証は行わない)だが、表示側で新規Bladeコンポーネント`<x-custom-field-value>`を介してクリック可能なリンクとしてレンダリング(スキームなしの値には`http://`を自動付与、`LinkFormat::href()`)。この表示コンポーネントは課題詳細・文書詳細の2箇所で共用。一覧画面(課題一覧の列表示)ではリンク化せずプレーンテキストのまま(意図的なスコープ限定)。user/version/attachment は引き続き未対応(選択ウィジェットが別途必要なため) |
| custom_field_enumerations(選択肢の管理された一覧) | done(2026-07-22) | 上の「カスタムフィールド(課題)」節の同名行を参照。新規`enumeration`フィールド形式として実装、クエリ/フィルタエンジン(`CustomFieldFilter`)にも対応(選択肢IDでフィルタ、ラベルは選択肢名) |
| default_value/regexp/searchable/editable・visible フラグ、「全プロジェクト対象」 | partial(2026-07-22) | 「全プロジェクト対象」は元々実装済み(対象プロジェクト未選択時は`CustomField::appliesToProject()`が全プロジェクト扱い)。今回`default_value`(新規課題作成時に自動入力、`prefillFromCopySource`の後に適用され上書きしない)・`regexp`(保存時に不正な正規表現を検証)・`searchable`をフォームに追加(モデル/カラムは既存、フォーム未配線だった)。`editable`/`visible`フラグはカラム自体が存在せず引き続き未実装 |

### Enumerations(優先度・工数種別・文書カテゴリ)

| 機能 | 状態 | 備考 |
|---|---|---|
| モデル(position/is_default/active) | done | `Enumeration` + `EnumerationType`、各所で消費されている |
| 管理画面 | done(2026-07-21) | `enumerations/{index,form}.blade.php`。`/enumerations/{type}` を `EnumerationType` のネイティブ暗黙Enumルートバインディングで解決し、タブ切り替えで3種別を編集 |
| 種別ごとに既定値を1つだけに制限 | done(2026-07-21) | `Enumeration::makeDefault()` |
| 使用中の値の削除防止 | done(2026-07-21) | IssuePriority(`issues.priority_id`)・TimeEntryActivity(`time_entries.activity_id`)は使用中なら削除不可。DocumentCategoryは `nullOnDelete()` のためガード不要 |

### アプリケーション設定(Redmine 119キー中 ~9項目)

| Redmineのタブ | 状態 | 備考 |
|---|---|---|
| 全般 | partial | `app_title`, `default_issues_per_page` のみ |
| 表示(日付/時刻形式、テーマ、週始まり、サムネイル) | partial(2026-07-23) | `start_of_week`(週始まり: 日/月/土)を実装、カレンダー画面(プロジェクト内`calendar.index`/全プロジェクト`calendar.global-index`)のグリッド開始曜日とヘッダーの曜日ラベル行の両方に反映(`Carbon::startOfWeek()`/`endOfWeek()`に直接渡せるようRedmineの ISO値(1/6/7)ではなくCarbonの日定数(0/1/6)で保存)。日付/時刻形式・テーマ・サムネイルサイズは本アプリにi18n/テーマ切替基盤が無いため引き続き対象外 |
| 認証(ログイン必須、セルフ登録、パスワードポリシー、2FA必須設定、セッションタイムアウト、自動ログイン、REST API有効化) | missing | — |
| プロジェクト(デフォルト公開設定、デフォルトモジュール、識別子連番化、新規プロジェクトの既定ロール) | partial(2026-07-22) | デフォルト公開設定(`default_projects_public`)・デフォルトモジュール(`default_projects_modules`)・デフォルトトラッカー(`default_projects_tracker_ids`)を実装(詳細は§2 Projects「プロジェクト作成」行を参照)。識別子連番化・新規プロジェクトの既定ロールは引き続き未対応 |
| ユーザー | missing | — |
| 課題トラッキング(進捗率算出方式、プロジェクト間関連/サブタスク許可、既定表示列) | partial(2026-07-23訂正) | **訂正**: 本行は従来「missing」のまま放置されていたが、実際には進捗率算出方式(`issue_done_ratio`)・プロジェクト間関連許可(`cross_project_issue_relations`)・親子集計系(`parent_issue_priority`/`_dates`/`_done_ratio`)・重複クローズ連動(`close_duplicate_issues`)は本セッション以前から`settings/index.blade.php`に実装済みだった(他の行の更新時に本行の訂正が漏れていた)。**既定表示列を追加(2026-07-23)**: `issue_list_default_columns`設定(Redmineの`Setting.issue_list_default_columns`相当)を実装、`issues/index.blade.php`(プロジェクト内一覧)・`issues/global-index.blade.php`(全プロジェクト一覧、`project.present? ? default : [:project] \| default`と同じくRedmine同様`project_id`を自動先頭付与)双方の`mount()`でURL未指定時のみ適用。カスタムフィールドは列の既定値として選択不可(インストールごとに変わるため対象外、Redmine本体は選択可能) |
| メール通知(送信元、ヘッダ/フッタ、通知イベント種別) | missing | — |
| 受信メール | partial(2026-07-22) | 有効フラグ+既定プロジェクト/トラッカー/ステータスに加え、`mail_handler_preferred_body_part`(プレーンテキスト/HTML優先、HTML側は`strip_tags`でプレーン化)・`mail_handler_body_delimiters`(1行1パターンの完全一致で本文を切り捨て、返信の引用部分除去用)・`mail_handler_excluded_filenames`(カンマ区切りのワイルドカードパターンで添付を除外)を追加。Redmineの`unknown_user`/`no_permission_check`/APIキー経由受信は引き続き意図的に対象外(既存の514行目を参照) |
| 添付ファイル(最大サイズ、許可/禁止拡張子) | done(2026-07-21) | `attachment_max_size`/`attachment_extensions_allowed`/`attachment_extensions_denied`。`App\Support\Attachments\AttachmentValidationRules`を全6箇所のアップロードフォーム(Issue/News/Wiki/Message/Document/Files)で共通利用。最大サイズは`media-library.max_file_size`をハード上限として超過不可 |
| リポジトリ(有効SCM、自動フェッチ、コミットキーワード) | partial(2026-07-22) | 自動フェッチ(`autofetch_changesets`設定)を実装。`AutofetchRepositoryChangesetsJob`を15分ごとにスケジュール登録し、有効時は全リポジトリへ`RepositorySyncJob`をディスパッチ(`RepositorySyncJob`自体の`ShouldBeUnique`によりリポジトリ単位で重複実行は防止済み)。有効SCM切り替え(`enabled_scm`)・コミットキーワード設定(`commit_update_keywords`、現状`RepositorySyncService::FIXING_KEYWORDS`にハードコード)は複数行テーブルUIが必要なためスコープ外のまま |

### ユーザー管理・認証

| 機能 | 状態 | 備考 |
|---|---|---|
| 管理者によるユーザー一覧/作成/編集 | done | `users/{index,form}.blade.php`, `UserPolicy`(admin-only) |
| アカウントロック/アンロック | done | 一覧画面からワンクリックで切替。`AuthenticateUser` がログイン時に `UserStatus::Locked` を実際にブロックする(この画面の実装と同時に修正した既存バグ) |
| 管理者による強制パスワードリセット | done(2026-07-22) | ユーザー編集フォームに「パスワードリセットメールを送信」ボタンを追加(Laravel/Fortify標準の`password.reset`通知フローを`Password::sendResetLink()`経由で起動)。編集フォームでパスワード欄に直接新しい値を入力する既存の方法も引き続き利用可能。LDAP連携ユーザーへの送信は拒否(ローカルパスワードを持たないため) |
| 自分自身のロック防止 | done | 一覧画面の切替アクションは自分自身に対して403を返す(唯一の管理者が自分をロックして誰も解除できなくなる事態を防止)。編集フォーム自体は自分の`is_admin`/`status`変更を意図的に許可(Redmineと同様、誤操作ではなく意図的操作として扱う) |
| LDAP認証ソース CRUD | done | ホスト/ポート/TLS/base_dn/direct-bind or search+bind/属性/onthefly登録/タイムアウト |
| LDAP接続テストボタン | done(2026-07-22) | Redmineの`AuthSourceLdap#test_connection`相当を`LdapAuthenticator::testConnection()`に実装(接続を開き、検索用アカウントが設定されていればバインドも検証)。認証ソース編集フォーム(既存レコードのみ、Redmine同様保存済みの設定でテスト)に「接続をテスト」ボタン+成功/失敗メッセージを追加。新規作成フォームでは非表示(保存前にテストする対象が無いため) |
| LDAPカスタムフィルタ/属性→カスタムフィールドマッピング | missing | login/name/mailのみ |
| LDAPオンザフライ登録 | done | — |
| 二要素認証(TOTP) | done | Fortify経由。管理者による強制無効化の専用ボタンはまだ画面にないが、ユーザー編集画面自体は存在する |
| パスキー/WebAuthn | done(Redmineにはない機能) | — |
| パスワードリセット | done | — |
| 登録モード(無効/手動承認/メール確認/自動) | partial(2026-07-22) | Redmineの`Setting.self_registration`相当を`self_registration`設定として実装。「無効」は`Fortify::registerView`が登録ページ自体をログインへリダイレクト(直接POSTされた場合も`CreateNewUser`側でバリデーションエラーとして拒否、二重の防御)、「手動承認」は`UserStatus::Registered`(元々定義されていたが未使用だった値)でロック状態のアカウントを作成し、ユーザー管理画面に「承認待ち」バッジ+「承認」ボタンを追加(`AuthenticateUser`は`isActive()`をすでに見ているため追加変更なしでログイン拒否済み)、「自動」は従来通り即座に有効化。メール確認によるモード(Redmineの3つ目)は送信メール基盤が本アプリに無いため意図的に対象外 |
| 自己登録メールドメイン許可/拒否リスト | done(2026-07-23) | Redmineの`EmailAddress.valid_domain?`/`Setting.email_domains_allowed`/`email_domains_denied`相当。設定画面に`email_domains_allowed`/`email_domains_denied`(カンマ区切り、先頭`.`でサブドメイン一致)を追加、`CreateNewUser`アクションに拒否リスト優先→許可リストがあればホワイトリストとして機能、の判定ロジックを実装(`attachment_extensions_allowed`/`_denied`と同じカンマ区切りリストのパターンを踏襲)。**注**: Redmine本体はこの検証を`EmailAddress`モデル経由で全ユーザーのメール変更(管理者による作成/編集含む)に適用するが、本アプリでは自己登録時のみに意図的に縮小(`users/form.blade.php`側の管理者用ユーザー作成/編集フォームには未適用) |

---

## 3. コンテンツモジュール(Wiki / フォーラム / お知らせ / 文書 / ファイル)

### Wiki

| 機能 | 状態 | 備考 |
|---|---|---|
| ページ CRUD | done | — |
| 階層(親子) | done | — |
| 親の付け替え | partial | 循環参照を除外した `parent_id` 変更のみ。プロジェクト間移動なし |
| バージョン履歴 | done | 追記型 `wiki_page_versions` テーブル |
| 任意の2バージョン間の差分表示 | done(2026-07-22) | Redmineの`WikiPage#diff`+`Redmine::Helpers::Diff`(単語単位・空白区切りでのLCS差分、追加/削除を`<span>`でマーク)相当。`App\Support\Diff\WordDiffer`に標準的なLCS動的計画法で実装(依存追加なし)。新規`wiki.diff`ルートで`from`/`to`のバージョン番号を受け取り(URL上の順序に関わらず古い方を`from`に正規化)、履歴画面に「旧」「新」のラジオボタン列と「選択したバージョンを比較」リンクを追加。ページサイズに対しO(語数from×語数to)の時間/メモリ計算量(Redmine本家も同様の量) |
| Annotate/Blame | done(2026-07-22) | Redmineの`WikiAnnotate`相当を`App\Support\Wiki\WikiAnnotator`に実装。各バージョンが差分ではなく全文を保持している前提で、対象バージョンからv1まで逆方向に1バージョンずつ隣接ペアを行単位でLCS差分し、インデックス再マッピング配列で「現在のどの行に対応するか」を遡って追跡(単発のv(n-1)対vn比較では不十分 — Redmine本家のアルゴリズムをそのまま移植)。既存の`WordDiffer`(Wiki差分表示用)のLCSコア部分を`App\Support\Diff\LcsDiffer`に切り出し、単語単位・行単位の両方から共有(既存の差分機能のテストは無変更で通過を確認済み)。新規`wiki.annotate`ルート+Volt画面で行番号/バージョン/著者/内容を表形式表示(同一バージョン/著者の連続行はセルを空白にする「バンディング」表示、Redmineの`annotate.html.erb`と同じ)、`wiki/history.blade.php`の各バージョン行に「(注釈)」リンクを追加 |
| バージョンの復元(revert) | done(2026-07-22) | Redmine自体に専用の「復元」アクションはなく、過去バージョンの本文を編集フォームに読み込んで保存し直すことで新しいバージョンとして記録する(`WikiController#edit`+`content_for_version`)方式。同様に過去バージョン閲覧画面(`wiki.version`)に「このバージョンを復元」リンクを追加、`wiki.edit`へ`?version=N`付きで遷移し、`wiki/form.blade.php`の`mount()`がそのバージョンの本文をプリフィル(`update`権限が必要) |
| バージョン単体の削除 | done(2026-07-22) | `wiki/history.blade.php`に`deleteVersion()`を追加(`update`権限で認可、Redmineの`destroy_version`アクションの実際の認可チェック`editable?`と同じ境界)。現在バージョン(最新番号)と、残り1件のみの場合は削除不可 ― Redmineは現在の本文(`WikiContent`)と履歴(`WikiContentVersion`)が別テーブルで、最新の履歴行を消しても表示中の本文には影響しないが、本アプリは「現在バージョン=最も番号が大きい履歴行」という設計のため、それを消すと表示中の本文が変わってしまう。データ整合性を優先した意図的なスコープ限定 |
| リネーム時のリダイレクト | done(2026-07-22) | Redmineの`WikiRedirect`+`WikiPage#handle_rename_or_move`相当。新規`wiki_redirects`テーブル(`project_id`, `title`, `redirects_to`)を追加、`WikiPageService::update()`がリネーム時に旧タイトル→新タイトルのリダイレクトを作成し、旧タイトルを指していた既存のリダイレクトも新タイトルへ付け替え(多段リネームのチェーン追従)。編集フォームに「旧タイトルへのリンクをリダイレクトする」チェックボックス(既定オン、Redmineの`redirect_existing_links`相当)。本アプリはWikiリンクをタイトルではなく生成時にIDへ解決する設計(`WikiLinkInlineParser`)のため、Redmineのような実際のURLリダイレクトではなく、リンク解決時に直接一致が無ければ`wiki_redirects`にフォールバックする形で実装。ページ削除時は指しているリダイレクトも`WikiPage`の`deleting`イベントで削除 |
| 保護ページ | done | `is_protected` + ポリシー |
| デフォルト保護ページ(Sidebar等) | done(2026-07-22) | Redmineの`WikiPage::DEFAULT_PROTECTED_PAGES`(`%w(sidebar)`)を移植。`WikiPage`モデルの`creating`イベントで、タイトル(大文字小文字を区別しない)が`sidebar`に一致する新規ページを強制的に`is_protected = true`にする ― `protect_wiki_pages`権限を持たない作成者であってもRedmine同様に強制される(フォーム側の保護チェックボックスは同権限を持つ場合のみ表示されるが、それとは独立にモデル層で常時適用)。**さらに(2026-07-22)**: 「Sidebar」ページの内容を実サイドバーとして描画する機能(Redmineの`_sidebar.html.erb`相当)を実装。新規Bladeコンポーネント`<x-wiki-sidebar>`がプロジェクトの「Sidebar」ページ(大文字小文字を区別せず検索)を`WikiMarkdownRenderer`で描画、Redmine本家と同じスコープで`wiki.show`/`wiki.index`/`wiki.date-index`の3画面のみに表示(history/diff/annotate/編集フォームおよびWikiモジュール外の画面は対象外)。各ビューを2カラムのflexレイアウトに変更(メインコンテンツ+サイドバー列) |
| 開始ページ設定 | missing | **調査メモ(2026-07-22)**: Redmineは`wikis.start_page`(プロジェクト毎のWiki設定行)を持ち、ルーティング上`GET /projects/:id/wiki`(先頭ページ本文を直接表示)と`GET /projects/:id/wiki/index`(タイトル一覧、別アクション)が明確に分離されている。本アプリは元々`wiki.index`ルート(`/projects/{project}/wiki`)自体を「ページ一覧」として実装済み(タイトル一覧側のみに相当するURL設計)で、開始ページ本文を直接表示する経路が無い。真の開始ページ機能を実装するには`wiki.index`が指すURLの意味を「一覧」から「開始ページ本文」に付け替え、現行の一覧機能を新しいルート名へ退避する必要があり、既存ナビゲーション(`projects/show.blade.php`の「Wiki」タブ等)への影響を伴う単発では収まらないルーティング再設計。単独のwell-scoped項目には収まらないため、いったん見送り |
| **マクロエンジン全体** | partial(2026-07-22) | `#123` 課題メンションと `[[ページ]]` リンクに加え、**`{{toc}}`を実装**。専用のDOM後処理クラスを新規に書く代わりに、既存の依存関係である`league/commonmark`が同梱する`TableOfContentsExtension`+`HeadingPermalinkExtension`をそのまま利用(新規パッケージ追加なし)。`{{toc}}`単独の行を、その位置のまま見出しのネストされた`<ul>`に置換(Redmineと同じ「書いた場所に展開される」挙動、常に先頭に固定されるわけではない)。`HeadingPermalinkExtension`は全見出しに`id`属性を付与するために必須だが、`insert: after`+`symbol`/`title`を空文字にすることで見出しへの目に見えるパーマリンクアイコン挿入は回避(副作用として、見出しごとに中身が空の非表示`<a>`タグが1つ増える — TOCの実装に必要な既存パッケージの制約によるトレードオフ)。`{{child_pages}}`, `{{include}}`, `{{collapse}}` 等は引き続き未実装。**別件で確認**: 通常のMarkdown箇条書き(`- item`)がアプリ全体で箇条書き記号(disc)無しで表示される既存のTailwindスタイリング上の問題を発見(本機能の変更が原因ではなく、既存の`prose`クラス設定の問題。TOCのHTML構造自体はブラウザ確認・自動テストとも正しいネスト構造を生成していることを確認済み) |
| セクション単位編集 | done(2026-07-22) | Redmineの`Redmine::WikiFormatting::SectionHelper`(セクション分割)+`StaleSectionError`(競合検知)を移植。新規`WikiSectionSplitter`(ATX/Setext見出し・フェンスコードブロックを認識してMarkdownを前/対象セクション/後の3分割、見出し出現順の1始まりインデックス)。表示画面(`wiki/show.blade.php`)は`update`権限を持つ閲覧者にのみ、レンダリング後のHTMLの各見出しへ`WikiSectionEditLinkInjector`(DOMDocumentで見出しを文書順に走査し「編集」リンクを注入 — Volt SFCのPHPブロックに`<?xml encoding="utf-8"?>`という`?>`を含む文字列リテラルを直書きすると、そこでブロックが打ち切られてコンパイルエラーになるため独立クラスに分離)経由で`?section=N`リンクを付与。編集フォーム(`wiki/form.blade.php`)は`?section=`があれば`mount()`で該当セクションのみを本文欄にプリフィルし、読み込み時点のセクション本文のSHA-256ハッシュを保持。`save()`は保存直前に最新の全文を再取得してハッシュを再検証し、一致しなければ他ユーザーの競合編集とみなして保存を中止しエラー表示、一致すれば`WikiSectionSplitter::updateSection()`で全文へ差し戻す |
| プレビュー | done(2026-07-22) | 編集フォームに「プレビュー」トグルボタンを追加、`WikiMarkdownRenderer`で本文テキストエリアの現在値を保存せずにレンダリング(Wiki表示画面と同じレンダラーを再利用)。既存ページ編集時はインライン画像参照もそのページの既存添付ファイルに対して解決(このフォーム送信で選択中だが未アップロードのファイルはまだMediaレコードが無いため対象外) |
| PDF/HTML/TXT/ZIPエクスポート | partial(2026-07-23) | ページ単体のTXT/HTMLエクスポートを実装(Redmineの`WikiController#show`の`format=txt`/`format=html`相当)。新規`export_wiki_pages`権限を追加(Redmineの同名パーミッション、`:read => true`相当で`readOnly: true`として登録、Redmine自体が`:public`指定なしのためデフォルトのMember階層のまま)。TXTは現行バージョンの生Markdownをそのまま返却、HTMLは`WikiMarkdownRenderer`の出力を最小限のHTML文書(charset/title付き)でラップ。ファイル名はページタイトルから生成(`/`・`\`のみサニタイズ)。PDF出力(新規依存が必要)とWiki全体のZIP一括エクスポート(Redmineの`WikiController#export`、複数ページ一括+PDF/ZIP)は別スコープとして未実装のまま |
| 日付インデックス表示 | done(2026-07-22) | Redmineの`WikiController#date_index`相当。新規`wiki.date-index`ルートで、各ページの現在バージョンが書かれた日付(`currentVersion->created_at`)でグルーピングし新しい日付順に表示。Wiki一覧画面から「日付順に表示」でアクセス可能 |
| ページのWatch | done(2026-07-23) | `WikiPage`に`watchers()`(ポリモーフィック`Watcher`)を追加、`view_wiki_pages`権限で自己Watch/Unwatch可能。~~他ユーザーの追加/削除UIはまだなし(Issueの`manageWatchers`相当は未実装)~~ → **done**: `IssuePolicy::manageWatchers()`/`issues/show.blade.php`の実装をそのまま移植。Redmineにはウォッチャー管理専用の権限が無いため(Issueの`add_issue_watchers`のような専用権限がWiki側には存在しない)、既存の`edit_wiki_pages`権限をゲートとして採用。ウォッチャー追加候補はプロジェクトメンバーに限定(Issueと同じ`Rule::exists('members', 'user_id')`保護)。ブラウザで実際に他メンバーをウォッチャーとして追加できることを確認済み |
| 添付ファイル | done(2026-07-21) | `WikiPage implements HasMedia`。フォームからアップロード、詳細画面で表示/削除(`update`権限) |

### フォーラム(Boards / Messages)

| 機能 | 状態 | 備考 |
|---|---|---|
| Board CRUD・並べ替え | done | — |
| ネストしたBoard | done(2026-07-21) | `boards.parent_id`追加。フォーム側で自己参照/循環を防ぐ親セレクト(Wikiページと同じパターン)、一覧はトップレベル+ネストした子を表示 |
| トピック作成/返信 | done | — |
| Sticky | done | — |
| ロック | done | `MessagePolicy::reply` で返信禁止を実装 |
| トピックの別Boardへの移動 | done(2026-07-21) | `edit_messages`保持者(`MessagePolicy::manageFlags`)がトピック詳細から別フォーラムへ移動可能。返信は独自の`board_id`を持つため、トピックと返信の両方を一括更新 |
| トピックのWatch | done(2026-07-23) | トピックのみWatch可能(返信は対象外、`MessagePolicy::watch`)。~~他ユーザーの追加/削除UIはまだなし~~ → **done**: Wikiページで実装した`manageWatchers`パターン(`edit_messages`権限をゲートに使用、Redmineに専用権限が無いため)をそのまま移植。ウォッチャー追加候補はプロジェクトメンバーに限定。ブラウザで実際に他メンバーをウォッチャーとして追加できることを確認済み |
| 引用返信 | done(2026-07-21) | トピック/返信それぞれに「引用」ボタン、返信入力欄に`>`引用形式をプリフィル |
| 添付ファイル | done | **訂正(2026-07-22)**: 従来「`Message`が`HasMedia`未実装」として`missing`と報告されていたが、実際には`Message implements HasMedia`が既に存在し、トピック作成/返信フォームでのアップロード、詳細画面での一覧表示・説明文編集・削除、`BoardTest`でのテストカバレッジまで全て揃っていた(過去に別行の一括作業で実装されテスト済みだったが、この行自体の更新が漏れていた) |
| 返信のページネーション | done(2026-07-21) | 25件/ページでページネーション。見出しの件数は`total()`で全件数を表示 |
| Atomフィード | done(2026-07-22) | Redmineの`BoardsController#show`(`format.atom`)相当。新規`boards.atom`ルート(`GET /projects/{project}/boards/{board}.atom`、`{board}`ルートより前に登録 — `boards.show`の無制約パラメータが先に`5.atom`を丸ごと飲み込んでしまうのを防ぐため`whereNumber`+登録順で回避)。ボード内の全メッセージ(トピック・返信とも)を新着順に最大15件(既存の`activity.atom`と同じ既定値)配信、返信は`{board.name}: {topic.subject}`のタイトルでトピックURLへリンク(Redmineの`Message#acts_as_event`と同じ形式)。既存の`ActivityEntry`DTOとAtomテンプレート構造を`activity-atom.blade.php`から流用(`feeds.board-atom`) |

### News

| 機能 | 状態 | 備考 |
|---|---|---|
| CRUD・概要/本文 | done | — |
| コメント | done | `NewsComment` |
| 添付ファイル | done | `News implements HasMedia` |
| Watch・作成者自動Watch | done(2026-07-21) | `News::watchers()`+トグルボタン、作成時に作成者を自動Watch |
| メール通知 | missing | — |
| プロジェクト横断のNews一覧 | done(2026-07-22) | Redmineの`NewsController#index`(`project_id`無し、`News.visible`スコープ)相当。新規`/news`ルート(`news.global-index`、初のプロジェクト非スコープなグローバル画面)を追加、ヘッダーナビゲーションに「お知らせ」リンクを配置。`view_news`権限の可視性判定(プロジェクトのアーカイブ/クローズ/モジュール有効性含む)はSQLの単一WHERE句では表現できないため、`projects.index`と同じ「全件取得→`can('view', $news)`でメモリ内フィルタ→`LengthAwarePaginator`で手動ページネーション」方式を踏襲(10件/ページ、Redmineの既定値と同じ)。プロジェクト列を追加表示 |
| Atomフィード(プロジェクト単位) | done(2026-07-22) | Redmineの`NewsController#index`(`format.atom`)相当。新規`news.atom`ルート(`GET /projects/{project}/news.atom`)+`NewsAtomController`。プロジェクト内の全お知らせを新着順に最大15件配信(`ActivityFeedController::LIMIT`共通)、`boards.atom`と同じ`ActivityEntry`DTO+共有`feeds.atom`テンプレートを流用。`news.index`画面に「Atom」リンクを追加 |

### Documents

| 機能 | 状態 | 備考 |
|---|---|---|
| CRUD | done | — |
| カテゴリ | done | `Enumeration` 経由 |
| 添付ファイル | done | `Document implements HasMedia` |
| カテゴリ/日付/タイトル/作成者でのグルーピング・並べ替え | partial(2026-07-22) | Redmineの`DocumentsController#index`(`sort_by`パラメータ)を移植。`documents.index`に`#[Url]`束縛の`sortBy`(既定`category`)を追加、カテゴリ別(既定・未分類は空文字キーで先頭)/更新日別(新しい日付グループが先頭)/タイトル先頭文字別にグルーピング・並べ替え。「作成者」でのグルーピング(Redmineは各文書の最新添付ファイルのアップロード者でグルーピング)は本アプリの添付ファイルがアップロード者を記録していないため対象外(記録用インフラの追加が別途必要、単独のwell-scoped項目には収まらないため見送り) |
| カスタムフィールド | done(2026-07-22) | `CustomizableType::Document`を追加(Issue/Project/Version/Group/TimeEntryActivityに続き4回目の同一パターン適用)。`Document`に`HasCustomFields`トレイト+`customizableType()`+`relevantCustomFields()`(Versionと同じ、プロジェクトのロール経由で可視性解決)を実装、`documents/form.blade.php`に入力欄、`documents/show.blade.php`に表示欄(Issue詳細と同じ`customFieldDisplayValues()`パターン)を追加。カスタムフィールド管理画面の「対象」選択肢は`CustomizableType::cases()`を直接列挙しているため追加のUI変更は不要 |

### Files モジュール

| 機能 | 状態 | 備考 |
|---|---|---|
| バージョンへのファイル添付 | done | — |
| **プロジェクトレベルのファイル**(バージョン非依存) | done(2026-07-21) | `Project implements HasMedia`。アップロードフォームの「バージョン」に「プロジェクト全体(バージョンなし)」を追加、`ProjectPolicy::manageFiles`(`manage_files`権限)で保護 |
| ファイル名/日付/サイズ/ダウンロード数での並べ替え | done(2026-07-22) | `files/index.blade.php`にRedmineの`FilesController#index`の`sort_clause`相当のUIを追加。4項目のボタンをクリックで昇順/降順切替、プロジェクト直下・各バージョンの両方の一覧に同一のソート順を適用(Redmine同様、コンテナごとに個別のソート順は持たない)。添付は`Media`(Eloquentコレクション)のためDBの`ORDER BY`ではなくPHP側の`sortBy()`で実装 |
| 複数ファイル同時アップロード | done | — |

### 添付ファイル(横断)

| 機能 | 状態 | 備考 |
|---|---|---|
| エンティティごとの複数添付 | done | Spatie MediaLibrary、対象は Issue/Version/News/Document/WikiPage/Message(2026-07-21〜) |
| サムネイル/画像変換 | done(2026-07-22) | Redmineの`Attachment#thumbnail`相当。添付ファイルを持つ全モデル(Issue/WikiPage/News/Document/Message/Project/Version)に`registerMediaConversions()`で100x100の`thumb`変換を登録(共通ロジックは`App\Concerns\HasThumbnails`、`InteractsWithMedia`の同名no-op実装と衝突するため`insteadof`で明示解決)。非画像ファイルはMedia Library側が自動的にスキップ(`ImageGenerator`が対応しないため)。専用ルート`attachments.thumb`+`AttachmentThumbnailController`でプライベートディスク越しに配信(`attachments.show`と同じ`view`権限境界、強制ダウンロードではなくインライン表示用)。`<x-attachment-thumbnail>`コンポーネントを課題・Wiki・ファイル(プロジェクト/バージョン)・News・Document・フォーラムの添付一覧すべてに配線 |
| 添付ファイルの説明文 | done(2026-07-22) | Redmineの`Attachment#description`相当を`media.custom_properties.description`として実装。課題・Wikiページ・プロジェクト/バージョンファイル・News・Documentに続き、フォーラム(Message、トピック・返信とも)にも対応し、添付ファイルを持つ全エンティティで完了。返信は`WithPagination`で分割表示されるため、`mount()`時点で全返信を(表示ページに関わらず)一括取得して`attachmentDescriptions`を事前充填 |
| ダウンロード数カウント | done(2026-07-21) | `AttachmentController`が`media.custom_properties`の`download_count`をダウンロードごとにインクリメント。`<x-download-count>`コンポーネントで各添付ファイル一覧に表示 |
| Wiki/フォーラム投稿への添付 | done(2026-07-21) | Wiki/フォーラム投稿(`Message`、トピック・返信とも)ともに対応 |
| 本文中のインライン画像参照(`attachment:file.png`) | done(2026-07-22) | Redmineの実装(`InlineAttachmentsScrubber`)は`attachment:`という独自プレフィックス構文ではなく、通常のMarkdown画像記法でファイル名だけを裸で書いた場合(`![](screenshot.png)`)にレンダリング後のHTMLを走査し、同一オブジェクトの添付ファイルからファイル名(大小文字区別なし)で解決する後処理。本アプリも`WikiMarkdownRenderer`に同等の後処理(DOMDocument走査)を追加し、Wikiページ本文・過去バージョン表示の両方で対応。スキームやパスを含むURL、非画像拡張子は対象外(Redmineと同じ拡張子ホワイトリスト)。実装当時は課題説明文がそもそもMarkdownレンダリングされていなかったためWikiのみのスコープだったが、その欠落は「課題本文・コメントのMarkdownレンダリング」行(2026-07-22)で解消済み、インライン画像参照も課題側で同様に有効 |

---

## 4. クエリ・レポート・工数管理・ダッシュボード横断機能

**見出しの結論: マイページを除き、プロジェクト横断ビューが一切存在しない。** `routes/web.php` の非プロジェクトルートは `/my/page` と `/profile` のみ。Issues/TimeEntries/Activity/Calendar/Gantt/Search は全て `/projects/{project}/...` 配下限定。

### クエリ/フィルタ/レポートエンジン

| 機能 | 状態 | 備考 |
|---|---|---|
| 保存済みクエリ(フィルタ/列/ソート/グループ) | done | `App\Models\Query` |
| 公開/非公開 | done(2026-07-22) | Redmineの`Query::VISIBILITY_PRIVATE/ROLES/PUBLIC`を移植。`queries.is_public`(boolean)を`visibility`(文字列enum、既存データは`true→public`/`false→private`で移行)に置き換え、`query_role`ピボットテーブルを追加。新規`manage_public_queries`権限を登録し、`saveQuery()`はこの権限を持たない場合サーバー側で強制的に`private`へフォールバック(Redmineの`QueriesController#new/#create`と同じ、クライアント側のフォーム自体も非保持者には選択肢を出さない二重の防御)。`visibleTo()`はロール判定を含めて全面書き換え(匿名ユーザーにも対応)、`savedQueries()`はロール交差判定がSQL述語1本で表現できないため`projects.index`と同じ「取得後にメモリ内フィルタ」方式に変更。課題一覧・工数一覧の両方の保存済みクエリ機能に適用 |
| プロジェクト横断クエリ | partial(2026-07-22) | `queries.project_id`は元々nullable(未使用)だった列を配線。`Query::visibleIn()`をプロジェクトの保存済みクエリ一覧+`project_id IS NULL`のグローバルクエリのUNIONに拡張(Redmineの`global_or_on_project`スコープ相当)、新規`Query::visibleGlobally()`を`issues.global-index`の保存済みクエリ一覧に接続。グローバルクエリの保存/読込を`issues.global-index`に追加(`saveQuery`/`loadQuery`/保存フォームは`issues.index`と同じ`<x-saved-query-save-form>`コンポーネントを再利用)。`Query::resolveVisibility()`はプロジェクト無しの保存では`manage_public_queries`を判定するプロジェクトが無いため、管理者のみ非公開以外を選択可能(Redmineの`User#allowed_to?(action, nil)`がグローバル権限オプション無しでは常にfalseを返す挙動と一致)。`Roles`可視性のグローバルクエリは「いずれかのプロジェクトで一致するロールを持つメンバーシップがあるか」で判定する新規`AuthorizationService::hasAnyMembershipWithRoles()`を追加(Redmineの`user.memberships.joins(:member_roles)`相当、単一プロジェクト内のロール交差ではなく全プロジェクト横断)。**工数一覧(2026-07-22追加)**: `time-entries.global-index`にも同一パターンを配線(グローバル保存/読込に加え、このページ独自の`groupBy`もクエリの`group_by`に保存/復元される点が課題一覧との違い)。`time-entries.index`側の`loadQuery()`もプロジェクト固有クエリに加えてグローバルクエリを読み込めるよう拡張 |
| 列選択 | partial(課題、2026-07-22)/partial(工数、2026-07-22) | 課題: カスタムフィールドも列として選択可能に(上の「表示列・CSV列としてのカスタムフィールド」行参照)。CF列での並べ替えは不可。工数: `issues.index`と同じ`DISPLAY_COLUMNS`+チェックボックスUIパターンを移植(日付/担当者/作業分類/課題/コメント/時間の6列から選択、既定は全列表示)。表示テーブル・CSVエクスポートとも選択列に追従、保存済みクエリの`column_names`にも反映(既存の`saveQuery`/`loadQuery`が空配列を書き込んでいた不備も合わせて解消)。課題側と同様、カスタムフィールドは列として選択不可、列の並べ替え(ドラッグでの順序変更)も対象外 |
| グルーピング | partial | 固定の短いリスト(ステータス/トラッカー/優先度/担当者)のみ。件数はSQL `GROUP BY`による全件集計(2026-07-21)。表示行自体は現在のページ内のみ(意図的、パフォーマンス上の理由)。カスタムフィールドでのグルーピング不可 |
| 合計/集計 | done(2026-07-22) | 課題: 一覧に予定工数/実績工数の合計を表示(Redmineの`issue_list_default_totals`相当、両方固定表示で設定化はしない)。全体合計はフィルタ適用後の全件をSQL集計(ページ内ではない)、グループ化時は各グループ見出しに件数+予定/実績合計を表示(`groupTotals`を件数のみ→件数/予定/実績の連想配列に拡張、実績はtime_entriesをJOINしたグループ別SUM)。工数: 従来通り全体+グループ別の時間合計 |
| 相対日付フィルタ(「過去N日以内」等) | done | — |
| フィルタ演算子(=,≠,in,contains,empty,between,≥/≤) | done | — |
| カスタムフィールドでのフィルタ | done(課題)/n/a(工数) | — |
| 複数列ソート | done(2026-07-22) | `QueryFilterEngine::applySort()`は元々`[key, direction]`配列を受け取れる設計だったが呼び出し側が1列分しか渡していなかった。課題一覧に2列目・3列目の選択欄を追加(Redmine同様最大3列)。列見出しクリックは引き続き1列目のみ変更。保存済みクエリの`sort_criteria`も3列分を保存/復元 |
| CSVエクスポート | partial(2026-07-22) | 課題・工数とも対応。課題側はエンコーディング/区切り文字オプション追加済み(詳細は上の「クエリ/フィルタ/表示」節の同名行を参照)。PDFはなし。Atomは別行(本節冒頭「Atom フィード」行)で対応済み — 現在の絞り込み状態は反映しない固定フィード |
| 課題レポート(トラッカー/ステータス別集計) | done(2026-07-23) | Redmineの`ReportsController#issue_report`相当。`issues/report.blade.php`(`/projects/{project}/issues/report`)でトラッカー/優先度/カテゴリ/対象バージョン/担当者/作成者の6軸×ステータス別件数グリッドを表示。カテゴリ/バージョン/担当者は「なし」行も集計。各セル(次元×ステータス)と行の「合計」列をRedmineの`aggregate_link`(`reports/_details.html.erb`)相当としてクリック可能なリンクに変更、直上の「課題一覧のURLクエリ文字列によるフィルタ初期化」機構で該当次元(+セルの場合はステータスも)を絞り込んだ課題一覧へ遷移(件数0のセルはリンク化しない、Redmine同様)。「なし」行(カテゴリ/バージョン/担当者未設定)は`=`ではなく`empty`演算子でリンク。サブプロジェクト集計・CSV出力は引き続き意図的に対象外(Redmine自体もこのグリッドとは別のドリルダウン専用ページで折れ線/棒グラフとCSVを提供しており、本アプリは1画面に統合する設計を既に選択済みのため、その差分は対象外のまま) |
| 課題一覧のURLクエリ文字列によるフィルタ初期化 | done(2026-07-23) | `App\Concerns\InteractsWithQueryFilters`(課題一覧/工数一覧/ガントで共有)の`filterOperators`/`filterValues`に`#[Url]`を追加(`activeFilterKeys`は元々`#[Url]`済みだったが、肝心の演算子/値が欠けていたため実質機能していなかった)。Redmineの`f[]`/`op[]`/`v[][]`クエリ文字列によるフィルタ済みディープリンクと同等の仕組みが3画面すべてで有効に。利用例: ロードマップの課題数リンク(直上の行)、課題レポートのセル/合計リンク(本節末尾の「課題レポート」行) |

### 工数管理

| 機能 | 状態 | 備考 |
|---|---|---|
| TimeEntry CRUD | done(2026-07-23) | 一括編集・一括削除に対応。`time-entries/index.blade.php`にIssue一覧のbulk edit/deleteと同じパターン(チェックボックス列+選択件数パネル)を追加。編集対象は作業分類/日付/コメントの3項目のみ(時間・プロジェクト移動・カスタムフィールドは対象外 — TimeEntryにカスタムフィールドは存在しないため)。認可は既存の`TimeEntryPolicy::update`/`delete`をエントリごとにループ適用(自分の記録は`edit_time_entries`権限がなくても対象、他人の記録は同権限が必要)。プロジェクト横断一覧(`time-entries.global-index`)は対象外(Issueのbulk操作が`issues.global-index`を対象外にしているのと同じ判断) |
| 工数種別(TimeEntryActivity) | done | プロジェクト別の有効/無効上書きに対応(2026-07-22、詳細は上の「プロジェクト別 Enumeration」行を参照)。カスタムフィールドはなし |
| 課題の実績工数合計 | done(訂正2026-07-22) | **訂正**: 従来「partial」と誤記されていたが、`Issue::totalSpentHours()`は`descendantIds()`の再帰CTEで子孫全体を合算済み(上の「子孫を含めた予定/実績工数の集計」行と同一実装、本行が重複・古いまま残っていたため訂正)。課題詳細画面にも「合計: X時間」として表示される |
| プロジェクトの実績工数合計 | done(2026-07-22) | `projects/show.blade.php`に「実績工数」ブロックを追加(`view_time_entries`権限保有時、工数が1件以上ある場合のみ表示)。Redmineの`ProjectsController#show`の`@total_hours`相当だが、`display_subprojects_issues`設定自体が本アプリに存在しないためサブプロジェクト分の合算は対象外(このプロジェクト自身のTimeEntryのみ) |
| **多次元工数レポート(ピボット表)** | **missing — 最大のギャップの一つ** | 単一次元のグループ化リストのみ。Redmine は最大3軸(プロジェクト/ステータス/バージョン/カテゴリ/ユーザー/トラッカー/工数種別/課題+カスタムフィールド)を期間列(年/月/週/日)と掛け合わせ、行・列・総計を算出する |
| プロジェクト横断の工数一覧 | done(2026-07-22) | トップレベル`/time_entries`(`time-entries.global-index`)を追加(Issues側の`issues.global-index`と同じ構成)。ヘッダーナビゲーションに「工数」リンクを配置。`view_time_entries`権限を持つ全プロジェクトを`news.global-index`と同じ「取得後にメモリ内でPolicy判定」方式で解決し、`TimeEntry::scopeVisibleToAcrossProjects()`(新規)がプロジェクトをAll/Own可視性ティア(工数の可視性はIssueと異なり2段階のみ)でバケット分け。フィルタ/グループ化/列選択は既存の`QueryFilterEngine`+`InteractsWithQueryFilters`+`<x-query-filter-builder>`を再利用、`TimeEntryFilterFieldRegistry::forProjects()`(新規)がプロジェクト列と担当者/作業分類の選択肢(可視プロジェクト全体の集合)を提供。編集/削除・CSVエクスポート・保存済みクエリは意図的に対象外(いずれもプロジェクト単位の前提に依存、Issues側と同じ判断)。**注**: 直下の「多次元工数レポート(ピボット表)」行とは別物 — この行は単純な横断一覧、ピボット表自体はプロジェクト単体でも未実装のまま |
| 工数フィルタ(ユーザー/種別/日付/時間) | done | — |
| 工数のCSVインポート | done(2026-07-22) | 課題CSVインポート(`IssueImport`/`ImportIssuesJob`)と同一パターンで`TimeEntryImport`/`ImportTimeEntriesJob`を新規実装。列マッピングは日付・時間(必須)・作業分類(名前で解決、既定の作業分類にフォールバック)・課題(`#番号`)・担当者(メールアドレス)・コメント。担当者列は`edit_time_entries`権限を持つインポート実行者のみ有効(Redmineの`TimeEntryImport#build_object`が`log_time_for_other_users`を要求する挙動を、本アプリの既存の「他人の代理記録」ゲート`edit_time_entries`(`time-entries/form.blade.php`の`canManageOthers`)で代替)。マッチしない/権限が無い場合は常にインポート実行者自身の記録になる。担当者メールは対象プロジェクトのメンバーに限定(`assigned_to`と同じスコープ保護)。ブラウザで実際にCSVアップロード→列マッピング自動検出→インポート→一覧反映まで確認済み |

### ダッシュボード横断機能

| 機能 | 状態 | 備考 |
|---|---|---|
| マイページ(ブロック追加/削除/ドラッグ並べ替え) | partial(2026-07-23) | 保存済みクエリのブロック化(Redmineのissuequery相当)を実装: 閲覧可能な課題クエリを「+ クエリ: {名前}」チップから追加でき、ブロックキー`issue_query:{id}`で複数クエリを並行配置可能(同一クエリは1ブロックまで — Redmineは同一クエリの重複配置も許すが意図的簡略化)。実行は`SavedIssueQueryBlock`が課題一覧と同じ`QueryFilterEngine`+ロール別課題可視性スコープで行い、クエリ削除/プロジェクトアクセス喪失時は空表示+フォールバックラベルに退化(クラッシュしない)。**documents/activityブロックを追加(2026-07-23)**: `DocumentsBlock`(`LatestNewsBlock`と同一パターン、所属プロジェクトの最新文書)、`ActivityBlock`(既存の`ActivityProviderRegistry`をプロジェクト横断で再利用 — 所属プロジェクトごと×プロバイダごとに`entries()`を呼び出し集約、直近7日分を新しい順に最大10件)。ブラウザで両ブロックの追加・表示を確認済み。calendarブロックは一覧形式のブロックインターフェースに馴染まないため意図的に対象外のまま |
| グローバルアクティビティフィード | partial(2026-07-22) | 8種類のプロバイダを集約(日付範囲・種別チェックボックス)。`ActivityFeedController`+`feeds/activity-atom.blade.php`でAtomフィードを追加(直近10日/最大15件、Redmineの`activity_days_default`/`feeds_limit`既定値を踏襲、既存の`ActivityProviderRegistry`をそのまま再利用)。プロジェクト単位限定・サブプロジェクト包含は引き続き未対応 |
| **プロジェクト横断の課題一覧** | partial(2026-07-22) | トップレベル`/issues`(`issues.global-index`)を追加、ヘッダーナビゲーションに「課題」リンクを配置。`view_issues`権限を持つ全プロジェクトを`news.global-index`と同じ「取得後にメモリ内でPolicy判定」方式で解決し、`Issue::scopeVisibleToAcrossProjects()`(新規)がプロジェクトをAll/Default/Own可視性ティアごとにバケット分けして単一クエリのWHERE句として組み立て(1プロジェクトで「全件」・別プロジェクトで「自分の課題のみ」が同時に成立するケースに対応)、`LengthAwarePaginator`によるSQLレベルのページネーションを維持。フィルタ/ソートは既存の`QueryFilterEngine`を再利用し、`IssueFilterFieldRegistry::forProjects()`(新規)がトラッカー/カテゴリ/担当者/対象バージョンの選択肢を可視プロジェクト全体の集合として構築(ステータス/優先度は元々グローバル)。新規`project_id`フィルタ列も追加。スコープを絞るため、一括編集/移動/コピー/削除・CSVエクスポート・保存済みクエリ・複数レベルソート(課題一覧本体は3階層だがこちらは1階層のみ)は意図的に対象外 — いずれもプロジェクト単位の前提(メンバー/バージョン/トラッカーの検証)に強く依存するため、必要になった時点で別途well-scopedな項目として着手 |
| カレンダー | partial(2026-07-22) | 開始日/期日マーカーを実装: Redmineのカレンダーヘルパーと同様、課題を開始日(▶)と期日(◀)の2箇所にマーク表示(期間中の全日には展開しない)。開始日=期日の課題は◆1件に集約、片方の日付のみの課題はその日付にのみ表示。**さらに(2026-07-22)**: トップレベル`/issues/calendar`(`calendar.global-index`)を追加(Redmineの`IssuesController#calendar`相当)。ヘッダーナビゲーションに「カレンダー」リンクを配置。`Issue::scopeVisibleToAcrossProjects()`をそのまま再利用でき、`weeks()`のグリッド構築ロジックは無変更。1点だけ新規UI追加が必要だった: 複数プロジェクトの課題が同じ日に混在するため、各エントリにプロジェクト識別子のプレフィックスを付与(件数キャップは実装せず、1日あたりのエントリ数が多い場合の視覚的な崩れは既存のプロジェクト単体版と同様に許容)。**クエリフィルタとの連動(2026-07-22)**: Ganttで先に実装したのと同じパターン(`InteractsWithQueryFilters`トレイト+`IssueFilterFieldRegistry`+`QueryFilterEngine`+`<x-query-filter-builder>`)を`calendar.index`/`calendar.global-index`の両方に配線。Ganttのツリー構造とは異なりカレンダーは階層を持たない日付グリッドのため、Gantt特有の「フィルタに一致しない祖先課題も深さの整合性のため表示に残す」特殊処理は不要で、マッチした課題IDセットをそのまま`WHERE`条件として適用するだけで済んだ。ブラウザで実際にステータスフィルタを適用し、一致しない課題がカレンダーから消えることを確認済み |
| ガント | partial(2026-07-23) | クエリフィルタ連動を実装: 課題一覧と同じ`QueryFilterEngine`+`IssueFilterFieldRegistry`のフィルタビルダー(共通Bladeコンポーネント`<x-query-filter-builder>`に抽出、課題一覧/工数一覧も同コンポーネントへ移行)をガントに配線。フィルタ一致IDをEloquent側で解決し、`GanttService::issueTree()`が一致課題+その祖先(深さ表示の整合性のため保持)にツリーを絞り込む。CTE自体は無変更。**バージョンのマイルストーン表示(2026-07-23追加)**: プロジェクト自身の`due_date`が設定されたバージョンを◆マーカーとして日付軸上に表示(`Version::completedPercent()`を再利用してラベルに進捗率も表示)。マイルストーンの期日が全課題の期日より後ろにある場合はチャートの日付範囲(`rangeEnd`)を自動的に延長。他プロジェクトから共有されたバージョンは対象外(このプロジェクト自身のバージョンのみ、意図的なスコープ限定)。**別件で発見(未修正)**: 検証中、既存の課題バー(`bg-indigo-400`/`bg-gray-400`)が実際には描画されない(背景色が透過)ことを発見 — Tailwindの特定シェード(400番台等)がコンパイル済みCSSに含まれておらず、かつこの環境では`npm run build`自体が`rolldown`のネイティブバインディング欠落で失敗する状態。本機能の実装自体(位置計算・データ取得)には影響しない(マーカーの図形・ラベル・進捗率は正しい位置に表示されることをブラウザで確認済み、色のみ未適用)ため、アセットビルド環境の修復は別件として対象外とした。**関連線を追加(2026-07-23)**: Redmineの`Redmine::Helpers::Gantt::DRAW_TYPES`相当を実装、`precedes`(青 `#228be6`)/`blocks`(赤 `#fa5252`)の2種別のみ線を描画(Redmine本家も他の関連タイプは描画しない)。`GanttService::relationsWithin()`が現在チャートに表示中の課題ID集合の中に両端が収まる関連のみ抽出(フィルタ適用中はフィルタ後の可視行に自動追従)、両端の課題がともに日付範囲を持つ場合のみ線を描画(片方でも未設定なら描画しない)。行の高さが固定32pxである前提でY座標をpx計算、X座標はバー左右端の%をそのままSVGの`x1`/`x2`属性に渡す(パーセントとpxの混在座標はSVGが軸ごとに独立解決するため問題なし)。線はインラインSVGの`stroke`属性で色指定しているため、既知の未修正Tailwindビルド問題(直上の記載)の影響を受けない(ブラウザで実際に色付きで描画されることを確認済み)。PDF/PNGエクスポート・プロジェクト横断ガントは引き続き未対応 |
| 検索(モジュール横断) | partial(2026-07-22) | all_words(既定オン、全単語AND/任意単語OR)・titles_only(タイトル/件名のみ)・open_issues(オープン課題のみ、他タイプは影響なし)のトグルと、`#123`(`#`省略可)での課題直接ジャンプ(存在しない/閲覧不可の場合は通常検索にフォールバックし他プロジェクトのID存在を漏らさない)を実装。`SearchService`はScout経由から直接クエリへ移行 — 単語単位のAND/OR・タイトル限定はScoutのdatabaseエンジン(単一文字列LIKE)では表現できないため。`Searchable`トレイトは将来の検索エンジン切替の契約として維持。**さらに(2026-07-22)**: トップレベル`/search`(`search.global-index`)を追加し、Redmine本来の「検索は既定でサイト全体、プロジェクト内では絞り込みが狭くなる」挙動に合わせた(従来の`/projects/{project}/search`はプロジェクト内限定版としてそのまま維持)。`SearchService::search()`を内部的に`searchAcrossProjects(Collection $projects, ...)`(新規の複数プロジェクト対応版)に委譲するよう再実装し、既存の単一プロジェクト呼び出し側・テストは無変更で全て通過することを確認。プロジェクト一覧の解決は他のグローバル一覧と同じ「取得後にメモリ内でPolicy判定」方式。**チェンジセット検索を追加(2026-07-23)**: `searchChangesets()`を既存の`searchIssues()`等と同一パターンで実装(`view_changesets`権限でプロジェクトを絞り込み、コミットメッセージ列`comments`をLIKE検索)。Redmineの`acts_as_searchable :columns => 'comments'`(単一列)を踏襲し、`titles_only`モードは実質no-op(Redmine本家の`acts_as_searchable`実装も先頭列のみを使うため、列が1つしかないこのタイプでは通常検索と同じ挙動になる、という点まで再現)。`repository_id`経由でプロジェクトに紐づくため`whereHas('repository', ...)`でスコープ。**プロジェクト検索を追加(2026-07-23)**: `searchProjects()`を実装(`name`/`identifier`/`description`をLIKE検索)。他タイプと異なりタイプ別の追加権限チェックは不要 — 呼び出し元がすでに「閲覧可能なプロジェクト集合」を渡しているため、そのままフィルタとして使うだけでよい(Redmine本家の`Project.acts_as_searchable`も`:view_permission`を指定していない点と一致)。**調査の結果、Journalの検索対象化は対象外と判断**: Redmineの`acts_as_searchable`プラグインには`search_journals`オプションが実装されているものの、本家Redmineの現行コードではIssue/どのモデルにも`:search_journals => true`が実際には設定されておらず、Journal本文はRedmine本家の検索でも一切対象になっていないことをソース調査で確認(死んでいる設定オプション)。したがって「Journal検索が無い」のはこのアプリのギャップではなく、Redmine自体の実際の挙動と一致した状態であり、チェックリストの記載を訂正。**プロジェクト絞り込みトグルを追加(2026-07-23)**: Redmineの`SearchController#index`の`params[:scope]`(`all`/`my_projects`/`subprojects`、`bookmarks`は本アプリにプロジェクトブックマーク機能自体が無いため対象外)を実装。プロジェクト内検索(`search.index`)に「サブプロジェクトを含む」トグルを追加、オンの場合`_lft`/`_rgt`範囲(kalnoyのクエリ拡張ではなく`Project::rootProject()`と同じ素のEloquentビルダーで比較)でこのプロジェクト+子孫全体をSearchServiceに渡す(既存の`search()`単一プロジェクト呼び出しから`searchAcrossProjects()`への切り替え)。全体検索(`search.global-index`)には「自分のプロジェクトのみ」トグルを追加、オンの場合`visibleProjects()`(閲覧可能な全プロジェクト)を`auth()->user()->projects()`(実際にメンバーになっているプロジェクト)で絞り込む。`#123`直接ジャンプの挙動(プロジェクト内検索は自プロジェクトのみを対象に既存動作のまま)は今回のトグルと独立、変更なし |

---

## 5. リポジトリ(SCM)・REST API・拡張性

### リポジトリ連携

| 機能 | 状態 | 備考 |
|---|---|---|
| リポジトリブラウズ(指定リビジョンでのツリー表示) | done | `GitAdapter`/`SvnAdapter` |
| 対応SCM種別 | partial | Git・SVNのみ。Redmine は Mercurial/Bazaar/CVS/Filesystem等も対応(4種欠落) |
| チェンジセット一覧・単体表示 | done | `RepositorySyncService`, `Changeset` |
| Diff表示 | partial(2026-07-22) | 任意リビジョン間Diffを実装: `ScmAdapter::diff()`に`$fromRevision`を追加(Git=`git diff from to`、SVN=`svn diff -r from:to`)、リビジョン一覧に旧/新ラジオ+「選択したリビジョンを比較」ボタン、新規`repository.compare`ルート。端点はコミット日時で正規化(古い方が常にdiffベース、Wiki差分と同じ規約)、両端不変のため差分は無期限キャッシュ。単一ファイルの履歴diffは引き続き非対応 |
| 単一ファイルの変更履歴 | done(2026-07-23) | Redmineの`RepositoriesController#changes`相当。新規`repository.file-history`ルート(`browse_repository`権限)で、そのパスを変更したすべてのChangeset(同期済みの`ChangesetFile`から取得、`ScmAdapter`への追加アクセス不要)をコミット日時降順で一覧表示、各行から`repository.show`(該当コミットの全体diff)へリンク。ファイル一覧・ファイル詳細画面の双方に「履歴」リンクを追加。Redmine本体はコミットごとに「そのファイル単体のdiff」へ直接リンクするが、本アプリは単一ファイル用のdiffエンドポイントを持たないため、コミット全体のdiffへのリンクに簡略化(意図的なスコープ縮小、コメントに明記)。リネーム追跡(`git log --follow`相当)は同期ジョブ自体が対応していないため対象外のまま |
| Annotate/Blame | done(2026-07-22) | `ScmAdapter`に`blame(revision, path): ScmBlameLine[]`を追加。Git実装は`git blame --line-porcelain`(全行についてコミットヘッダをフル反復出力する形式のため、直前コミットの状態を覚えておくパーサ状態機械が不要)。Svn実装は`svn blame --xml`(行内容を含まない)で取得したリビジョン/著者列を、別途`fileContentAt()`で取得した内容行とインデックスで結合(XML側は改行有無に関わらず実際の行数のみを返すため、`fileContentAt()`側の末尾改行による余分な空要素を除去してから結合)。新規`repository.annotate`ルート+Volt画面で行番号/リビジョン(先頭8文字)/著者/内容を表形式表示、`repository.entry`にバイナリファイルを除き「注釈」リンクを追加。Redmineの色分けブロック表示(同一リビジョンの連続行をまとめて色付け)は対象外、全行に個別表示 |
| 生ファイルダウンロード | done(2026-07-22) | 新規`RepositoryRawController`(`GET /projects/{project}/repository/raw/{path}`)を追加。`browse_repository`権限で認可後、`ScmAdapter::fileContentAt('HEAD', $path)`の生バイト列を`finfo_buffer`によるMIMEタイプ判定+`Content-Disposition: attachment; filename="..."`付きで返却(既存の`AttachmentController`と同じ強制ダウンロード方式)。テキスト/バイナリを問わず動作(既存の`repository.entry`はUTF-8として解釈できないバイナリを一切表示できなかったが、これはバイト列をそのまま返すだけなので影響を受けない)。単一ファイル表示画面(`repository/entry.blade.php`)に「ダウンロード」リンクを追加(Redmineの`entry.html.erb`の`@raw_url`ダウンロードリンクに相当) |
| リポジトリ統計・コミットグラフ | done(2026-07-22) | `/projects/{project}/repository/stats`(`repository.stats`)を新規追加。作成者別・月別のコミット数を`Changeset`の`committer`/`committed_on`から集計(DB非依存にするためPHP側のCollectionでグループ化、DB固有の日付関数SQLは使わない)。作成者はRedmineと同様、User解決はせず生の`committer`文字列(`Name <email>`形式)でグルーピング。可視化は`versions/roadmap.blade.php`/`gantt/index.blade.php`と同じCSS幅バー方式を流用(新規チャートライブラリなし)。`repository.index`にリンクを追加。ブラウザで実際にコミット数の多い作成者が上位に表示され、バー幅が件数比で正しく描画されることを確認済み |
| プロジェクトあたり複数リポジトリ | missing(要確認) | `Repository belongsTo Project` の1対1想定 |
| 非同期チェンジセット取得 | done | `RepositorySyncJob`(ユニーク制約・タイムアウト調整済み) |
| **コミットメッセージのキーワード連動**(`fixes #123`でクローズ、`refs #123`で単純リンク、工数記録`@2h`等) | partial(2026-07-22) | `fixes/fix/closes/close` キーワードを検出し、コミッターがメール/loginで実在ユーザーに一致し、かつそのユーザーが対象プロジェクトで`edit_issues`権限を持つ場合のみ最初の`is_closed`ステータスへ遷移(Journalも記録)。committer欄は攻撃者が任意に詐称可能なため、権限チェックなしでは他ユーザーになりすませてしまう(自動レビューで指摘・修正済み)。一致しない/権限がない場合は従来どおりリンクのみ。**工数記録`@2h`(2026-07-22追加)**: `#123 @2h`形式のトークンを検出し、`commit_logtime_enabled`設定(既定false、設定画面「リポジトリ」節)が有効かつコミッターに`log_time`権限がある場合のみ`TimeEntry`を自動作成。作業分類は`commit_logtime_activity_id`設定(未選択なら既定の作業分類)から解決。Redmineの`TIMELOG_RE`文法の一部のみ対応(`2h`/`2h30m`/`30m`/`1:30`/`2`または`2.5`/`2,5`の小数時間、`h`単体を除く分単位表記等の全パターンは未対応)。進捗率の自動更新・キーワードのカスタマイズ設定(`commit_update_keywords`)は引き続き未実装 |
| チェンジセットへの関連課題の手動追加/削除 | done(2026-07-22) | Redmineの`RepositoriesController#add_related_issue`/`#remove_related_issue`相当。新規`manage_related_issues`権限(Repositoryモジュール)を登録、`RepositoryPolicy::manageRelatedIssues`で認可。チェンジセット詳細画面(`repository/show.blade.php`)に「#123」形式(`#`省略可)の入力欄と関連課題ごとの「解除」ボタンを追加。追加時は課題の存在+閲覧権限(`can('view')`、Redmineの`@issue.visible?`相当)+重複を検証。Redmineの`commit_cross_project_ref`設定によるプロジェクトツリー制限は、本アプリのコミットメッセージ自動リンク(`RepositorySyncService`)が元々プロジェクト制限なしで動作しているためそれに合わせて対象外(閲覧可能な課題ならどのプロジェクトでも可、他プロジェクト課題のリンクは所属プロジェクトのURLで生成) |
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
| **Webhook**(Redmineコア機能ではない、本アプリでの追加機能) | done(範囲限定、2026-07-22) | Issue作成/更新/**削除**イベントに対応(削除を追加)。`App\Events\IssueDeleted`+既存の`DispatchWebhooksForIssueEvent`リスナー(union型を`IssueCreated\|IssueUpdated\|IssueDeleted`に拡張)+`WebhookServiceProvider`の`Event::listen`配列に追加。従来2箇所に直書きされていた`$issue->delete()`(課題詳細画面の単体削除、一覧画面の一括削除)を新規`IssueService::delete()`に統一し、単一のディスパッチ経路に集約。**Wikiページ作成/更新/削除イベントを追加(2026-07-22)**: `WikiPageService`が唯一の書き込み経路であることを活かし、`create()`/`update()`にイベント発火を追加、新規`WikiPageService::delete()`(Issueと同じ「削除前にディスパッチ」順序)に`wiki/show.blade.php`の直書き`$this->wikiPage->delete()`を統一。`App\Events\WikiPage{Created,Updated,Deleted}`+新規`DispatchWebhooksForWikiPageEvent`リスナー+新規`App\Http\Resources\Api\V1\WikiPageResource`(Webhookペイロード専用、REST APIルートへの追加は範囲外)。管理画面のイベント選択チェックボックスは`WebhookEvent::cases()`を直接列挙しているため追加のUI変更は不要(ブラウザで新規イベント3件がチェックボックスに表示されることを確認済み)。プロジェクト/バージョン/工数イベントは、対応するサービス層自体が存在しないため引き続き未対応 — 各々が新規サービス層抽出を要する別個のwell-scoped項目として残す |
| 受信メールによる課題作成 | done(2026-07-22) | `[識別子]` プレフィックスでのプロジェクト振り分け、送信者=既存ユーザーの権限チェック。**追加**: Redmineの`MailHandler#receive_issue_reply`相当を実装し、件名が`[... #123]`形式の場合は新規課題作成ではなく既存課題#123へのコメント追加として処理(`edit_issues`権限で認可、添付ファイルも同様に既存課題へ追加)。本アプリはまだ送信メール通知を実装していないため件名を自動生成する経路は無いが、送信者が手動でその形式を使えば動作し、将来の通知実装にも備えている |
| 受信メールでの`unknown_user`/`no_permission_check`相当 | missing(意図的) | メールからのアカウント自動作成を意図的に非対応としている(セキュリティ上の判断としてコード内に明記) |
| **メール返信による課題更新** | **missing** | `In-Reply-To`/件名`[#123]`への返信が課題へのコメント追加や再オープンに繋がらない |
| **メール本文のキーワードコマンド**(`Status: Closed`, `Assigned to:`, `Priority:` 等) | partial(2026-07-23) | `status`/`priority`/`assigned to`/`done ratio`の4キーワードを実装(`IncomingMailService::extractKeywordAttributes()`)。新規課題作成・返信コメントの両方で動作し、マッチした行は本文/コメントから除去(Redmineの破壊的な行除去と同じ挙動)。`status`/`priority`はプロジェクト非依存の全体名で大文字小文字を区別せず照合、`assigned to`はメールアドレスまたはフルネームでプロジェクトの割当可能ユーザーから照合、`done ratio`は0〜100の整数のみ受理。本アプリにi18n基盤が無いため、Redmineのように`Setting.default_language`に応じてキーワードラベル自体が翻訳されることはなく、固定の英語ラベルのみ対応。値が解決できない場合はその行を本文にそのまま残す(Redmineの`try(:id)`によるnil黙殺と異なり、送信者に何が反映されなかったか分かるようにする設計判断)。`tracker`/`category`/`fixed_version`/`start_date`/`due_date`/`estimated_hours`/`parent_issue`/`is_private`/カスタムフィールドキーワード、および`allow_override`相当の設定は引き続き未対応(コミットメッセージの`@Nh`工数記録キーワードと同様、意図的に絞り込んだサブセット) |

---

## 6. 次にトラッキング表を更新するタイミング

- 新しいフェーズ/機能追加のたびに該当行を `missing`/`partial` → `done` に更新する。
- 意図的にスコープ外とした項目は、その理由をコード側のdocblock/コメントに残し、このドキュメントの備考にも「意図的」である旨を明記する(既に実施済みの例: `PluginManager` のプロジェクトモジュール/フィルタ演算子除外、受信メールの `unknown_user` 非対応)。
- このドキュメント自体の生成方法: `/Users/sesoko/Desktop/workspace/redmine` の実装(コントローラ/モデル)と `/Users/sesoko/Desktop/workspace/artisan-pm` の実装を突き合わせる並列調査(5系統: 課題管理/管理機能・認証/コンテンツモジュール/クエリ・工数・ダッシュボード/SCM・API・拡張性)を実施し、その結果を統合した。
