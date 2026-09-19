# A2-03 設計メモ: プロジェクト一覧クエリ(`ProjectQuery`)

状態: `blocked(要承認)`。既存のプロジェクト一覧(ツリー表示・権限フィルタ・ブックマーク)との両立方針に判断が要る。

## 背景

Redmine 6.0 以降のプロジェクト一覧は `ProjectQuery`(フィルタ・列選択・並べ替え・保存)で動き、設定 `project_list_display_type`(`board`=カード/`list`=表、既定 board)、`project_list_defaults`(既定の列。既定は名前・識別子・短い説明)、`default_project_query`(既定の保存クエリ)を持つ。本アプリの `projects/index.blade.php` は名前/識別子の部分一致・ステータス・ブックマークだけで、ツリー(ネストセット順+`display_level`)を PHP 側で組み立てる。

## 既存資産

`Query` モデルは `type` 列で多型(`QueryType` は `Issue` / `TimeEntry`)、`project_id` NULL のグローバルクエリに対応済み(全体工数一覧が使用)。`QueryFilterEngine` / `NativeColumnFilter` / `CustomFieldFilter` / `SavedQuery`(可視性解決)は流用できる。

## 判断が要る点

1. **ツリー表示との両立**(最大の論点)
   - A. フィルタなしのときだけツリー、フィルタ/並べ替え中はフラット(推奨。現状の「検索中はツリーを保つ」を、Redmine の list 表示に合わせる)
   - B. 常にツリーを保ち、一致した項目とその祖先を出す(現状に近いが、祖先を「灰色」で出す表現が要る)
2. **権限フィルタとページング**: 現状は `can('view')`(PHP 側)で絞った後にページングしている。フィルタ結果の件数と「見える件数」を一致させるには、可視プロジェクト ID を先に取って SQL に渡す(`AuthorizationService::visibleProjectIds()` が既にある。推奨)か、現状の PHP 絞り込みを続けるか。
3. **既定クエリの解決順**: ユーザー個人設定 → サイト設定 `default_project_query` の順(課題一覧の `DefaultIssueQuery` と同じ)でよいか。
4. **ボード表示**: カードに何を出すか(名前・識別子・短い説明・ステータス・メンバー数)。Redmine は説明の Markdown 描画とカスタムフィールドを出す。
5. **サブプロジェクト系フィルタ**: 「親プロジェクト」「祖先」フィルタ(ネストセット条件)を含めるか。

## 推奨案と分割

1. **A2-03a**(M): `QueryType::Project`、`ProjectFilterFieldRegistry`(名前・識別子・ステータス・公開・作成日・親・プロジェクトのカスタムフィールド)、`projects/index` をエンジン駆動に置換(推奨案 A・可視 ID の SQL 化)、列選択と並べ替え。
2. **A2-03b**(S〜M): 保存クエリ(グローバル・可視性)と `default_project_query`。
3. **A2-03c**(S): ボード/表の切替と設定 `project_list_display_type` / `project_list_defaults`。

A2-05(管理画面のプロジェクト一覧)は A2-03a に依存するため、この承認後に着手する。

## 影響範囲

`resources/views/livewire/projects/index.blade.php`、`app/Support/Query/*`、`app/Enums/QueryType.php`、設定画面、API の `QueryController`(`type` の許可値に `project` を足すか)。既存テスト(プロジェクト一覧・ブックマーク・ツリー)の期待値を案 A に合わせて更新する。
