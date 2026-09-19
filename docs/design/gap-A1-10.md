# A1-10 設計メモ: カスタムフィールド形式 `user` / `version`

状態: `blocked(要承認)`。実装前に下記 3 点の判断が必要。

## 背景

Redmine 7.0 の `Redmine::FieldFormat::RecordList`(`lib/redmine/field_format.rb`)は、値が別テーブルの行を指す形式。`user` は選択肢が「対象オブジェクトのプロジェクトのメンバー(`user_role` オプションでロール絞り込み可)」、`version` は「対象のプロジェクトから見える共有バージョン(`version_status` オプションで open/locked/closed 絞り込み可)」。どちらも複数値可。

本アプリの `FormatContract::options(CustomField $field)` はフィールド定義しか受け取れず、選択肢が対象オブジェクト(課題→プロジェクト)に依存する形式を表現できない。

## 影響範囲(調査結果)

| 箇所 | 現状 | 必要な変更 |
|---|---|---|
| `FormatContract` と 10 個の Format 実装 | `options($field)` / `validationRules($field)` | 対象プロジェクトを渡せる引数の追加(全 10 実装が対象) |
| `CustomFieldFormat` enum | 10 ケース | `User`、`Version` を追加。`CustomFieldFilter::type()` の `match` も網羅が必要 |
| 入力フォーム 7 種(課題・プロジェクト・バージョン・ユーザー・グループ・文書・列挙値) | `<x-custom-field-input>` が `options($field)` を直接呼ぶ | プロジェクト文脈をコンポーネントへ渡す |
| バリデーション | `CustomField::formValidationRules()` が 7 フォームから呼ばれる | 文脈(プロジェクト)を渡し、メンバー/共有バージョンに限定した `exists` にする |
| 一覧フィルタ | `CustomFieldFilter::options()` は引数なし | プロジェクト一覧なら当該プロジェクト、全体一覧なら全体の選択肢 |
| REST API | `CustomFieldResource` が `possible_values` を出力。課題の作成/更新が値を受ける | API 経路でも同じ文脈つき検証 |
| 表示 | `castValue()` が表示名を返す | id → 名前解決(N+1 回避に一括ロード)、閲覧権限のないユーザーの名前を出さない |
| ジャーナル差分 | `journal-detail-diff` が旧/新値を表示 | id → 名前 |

## 判断が要る点

1. **スキーマ**: `user_role` / `version_status` の保存先。
   - A. `custom_fields` に JSON 列 `format_options` を 1 本追加(推奨。将来の形式オプションも入る)
   - B. 形式ごとに専用列(`user_role`, `version_status`)を追加
   - C. 既存の `possible_values` を流用(意味が混ざるので非推奨)
2. **値の保存列**: `value_int`(推奨。既存の列で足りる)か、Redmine 同様の文字列か。int なら削除された ID の扱い(参照切れを空表示にする)を決める。
3. **検証の厳密さ**: 文脈つき検証にしないと、細工したリクエストで任意のユーザー ID/バージョン ID を保存でき、表示時に名前が漏れる(`users_visibility` の迂回)。推奨は「文脈なしのフォーム(ユーザー/グループ/文書/列挙値)では全アクティブユーザー・全バージョンを候補とするが、ユーザーは `User::visibleTo($viewer)` で絞る」。

## 推奨案と分割

1. **A1-10a**(M): enum・`FormatContract` 拡張・2 形式・`format_options` 列・管理画面のオプション入力・課題/工数/プロジェクト/バージョンでの文脈つき入力+検証・表示。
2. **A1-10b**(S〜M): 一覧フィルタ(`user` は「自分」を含む)・CSV 出力・REST API の値/`possible_values`・ジャーナル差分。
3. **A1-10c**(S): 複数値、`user` の「ロール絞り込み」と `version` の「ステータス絞り込み」の細部、既存形式へのバックフィル不要の確認。

## 影響を受けないもの

既存 10 形式の保存データ(列・値は変えない)。
