# A1-17: 課題フィルタの拡張

状態: `blocked(要承認)`。規模 L のため実装していません。

## 背景

`IssueFilterFieldRegistry` が持つ課題フィルタは 15 種(ステータス、トラッカー、優先度、カテゴリ、担当者、作成者、対象バージョン、題名、開始日、期日、作成日、更新日、終了日、進捗率、カスタムフィールド)です。
Redmine 7.0 の `issue_query.rb` は約 40 種あります。足りないのは次の系統です。

| 系統 | フィルタ | 実現方法 |
|---|---|---|
| 課題自身の列 | `description`、`notes`(コメント本文)、`estimated_hours`、`is_private`、`issue_id`、`parent_id` | `NativeColumnFilter` で足りる(notes は journals への EXISTS) |
| 課題どうしの関係 | `child_id`、関連タイプ別(`relates`/`blocks` など)、`subproject_id` | 関連テーブルへの EXISTS サブクエリ。`subproject_id` は A3-06b と同時 |
| 人の絞り込み | `watcher_id`、`updated_by`、`last_updated_by`、`member_of_group`、`assigned_to_role`、`author.group`/`author.role` | watchers、journals、members、groups への EXISTS |
| 添付 | `attachment`、`attachment_description` | media テーブルへの EXISTS(A7-12 の検索と同じ結合) |
| 他の表の属性 | `fixed_version.due_date`、`fixed_version.status`、`project.status`、`spent_time` | JOIN または集計サブクエリ |
| 全文 | `any_searchable` | `SearchService` の課題検索を流用 |

保存済みクエリ・Atom・CSV・マイページブロックはすべて `QueryFilterEngine` 経由なので、`FilterableField` を足せば自動で追従します。

## 選択肢

| 案 | 内容 | 長所 | 短所 |
|---|---|---|---|
| A | 全部を 1 コミットで実装 | 一度で揃う | 変更が大きく、レビューと検証が難しい。EXISTS サブクエリの性能確認が一括になる |
| B | 系統ごとに 4 行に分割して順に実装(下記) | 各行が S〜M で検証しやすい。価値の高い系統から出せる | 行が増える |
| C | 需要の高いものだけ(`description`/`notes`/`is_private`/`watcher_id`/`parent_id`)に絞る | 最短 | 保存済みクエリの互換性が Redmine と揃わない |

## 推奨

**案 B**。分割案:

1. A1-17a: 課題自身の列(`description`、`notes`、`estimated_hours`、`is_private`、`issue_id`、`parent_id`)。
2. A1-17b: 関係(`child_id`、関連タイプ別)。
3. A1-17c: 人と添付(`watcher_id`、`updated_by`、`last_updated_by`、グループ/ロール系、`attachment*`)。
4. A1-17d: 他の表の属性と `subproject_id`(A3-06b と同時)、`any_searchable`。

各行で、フィルタ追加ごとに「オペレーター一式」「保存済みクエリの往復」「別プロジェクトの課題が混ざらないこと」のテストを付けます。

## 影響範囲

- 変更: `IssueFilterFieldRegistry`、新規 `FilterableField` 実装、`FilterOperator`(必要なら)。
- 依存: A3-06b(`subproject_id`)、A2-08(工数フィルタの追加。A1-17 のあとに実装する想定)。
- 性能: EXISTS サブクエリは `issues.id` を軸にするため、journals/watchers/media の外部キー索引を確認する。

## 承認が必要な理由

規模が大きく、分割方針(案 B)で行を追加することを実行キューの前提から変えるため。
