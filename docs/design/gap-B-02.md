# B'-02 設計メモ: カスタムフィールド形式 `attachment`

状態: 2026-09-24 承認済み(依存・設計の追加を承認)。本メモは実装前に所有者モデルを決めるためのもの。

## Redmine の挙動(`lib/redmine/field_format.rb` の `AttachmentFormat`、`app/models/custom_value.rb`)

- CF の値は添付ファイル(`Attachment`)の **id**。添付の `container` は `CustomValue`(= 値の行)で、その `customized` が課題などのレコード。
- 入力はファイル選択(`attachments/form` を単一ファイルで)、API は `value: {token: "..."}`(`/uploads` のトークン)、既存の id を送り返すと維持。ただし id は「そのレコードの CF 値に付いた添付」のときだけ受け付け、それ以外は空にする。
- 値が変わると新しい添付の `container` を値の行にし、**以前の添付は削除**する(`after_save_custom_value`)。
- 閲覧: `CustomValue#attachments_visible?` = CF がその利用者に見えて、かつレコードが見えること。
- 形式の属性は `extensions_allowed`(許可する拡張子)。フィルタ不可、一括編集不可、履歴は値を出さず「〜が更新されました」(`change_no_details`)。複数値なし。

## 所有者モデルの選択肢

Spatie MediaLibrary の `media.model_type` / `model_id` は非 null。

1. **A. レコード自体に専用コレクションで付ける(推奨・採用)**: 課題に `custom_field_attachments` コレクションを追加し、`custom_properties.custom_field_id` に CF の id を持たせる。CF 値(`value_int`)には media の id を入れる。
   - 長所: ダウンロードの認可が「レコードを閲覧できるか」(既存の `Gate::authorize('view', $media->model)`)にそのまま乗る。レコード削除時は MediaLibrary が media を消す。Redmine の「添付の実質的な持ち主は customized」に最も近い。
   - 短所: 通常の添付一覧(`attachments` コレクション)と混ざらないよう、コレクションで分ける必要がある(既存コードは全箇所 `getMedia('attachments')` / `collection_name = 'attachments'` で絞っていることを確認済み)。
2. B. `CustomFieldValue` を `HasMedia` にする: Redmine の `container = CustomValue` と同形。ただし認可・削除を `CustomFieldValue` 経由で辿る必要があり、値の行を作り直す(複数値の削除→再作成)と media が消える。既存の添付コントローラの認可にも乗らない。
3. C. 中間モデルを新設: テーブル追加が要り、利点は B と同じ。

## 採用案の詳細

- `CustomFieldFormat::Attachment`、`AttachmentFormat`(保存列 `value_int`、`format_options.extensions_allowed`)。**課題の CF のみ**で提供(他の種類はフォーム・表示が画面ごとに別実装のため B'-02b に分離)。複数値・検索対象・フィルタは保存時に強制オフ。一括編集の候補から除外。
- 値の設定(`HasCustomFields` から `AttachmentFieldValue::assign()`):
  - アップロードされたファイル(Livewire の一時ファイル)/ API の `{token}` → レコードの専用コレクションに追加し、以前の media を削除。
  - 空 → 以前の media を削除して値を消す。
  - 数値 → **そのレコードのその CF の media のときだけ維持**。他人の media id を渡しても付け替えない(値は変えない)。
- 検証: 拡張子はサイト設定(`attachment_extensions_allowed/denied`)と CF の `extensions_allowed` の両方、サイズは `attachment_max_size`。トークンが解決できなければエラー。
- 閲覧/ダウンロード(`attachments.show`・サムネイル・プレビュー・インライン・API の `show`/`download`): 従来のレコードの `view` に加えて、**その CF が利用者に見えること**(`relevantCustomFields($user)` に含まれる)を要求。API の `update`/`destroy` はこのコレクションの media を拒否(値の変更で差し替え・削除する)。
- 表示: 詳細画面・一覧はファイル名のダウンロードリンク、CSV/PDF はファイル名。フォームは現在のファイル名+「削除」+ファイル選択。
- 履歴: 詳細の値は media id(Redmine と同じ)、画面は「〜が更新されました」、メール/Atom はファイル名(残っていれば)。
- コピー: Redmine と同じく複製しない(複製元の media はコピー先に属さないため)。
- API: 応答の値は media id(文字列)、書き込みは `{token}`・既存 id・空。

## 影響範囲

`app/Enums/CustomFieldFormat.php`、`app/CustomFields/Formats/AttachmentFormat.php`、`app/Concerns/HasCustomFields.php`、`app/Models/Issue.php`、添付の各コントローラ、`<x-custom-field-input>` / `<x-custom-field-value>`、CF 管理フォーム、`JournalDetail`、課題フォーム・詳細・一覧。

## B'-02b(2026-09-24 実装): 課題以外の種類

- Redmine の `AttachmentFormat` は `customized_class_names = nil`(全種類)なので、プロジェクト・バージョン・文書・工数・ユーザー・グループ・工数の作業分類・優先度・文書カテゴリのすべてで選べるようにした。`HasMedia` でなかった `TimeEntry`/`User`/`Group`/`Enumeration` に `InteractsWithMedia` と `custom_field_attachments` コレクションを追加(レコード削除で MediaLibrary がファイルも削除)。
- 値の設定・差し替え・削除・他レコードの id の拒否は B'-02 の `AttachmentFieldValue::assign()` をそのまま使う(`HasCustomFields` 経由なので各フォーム・REST・自己登録が共通)。各フォームに `WithFileUploads` と `<x-custom-field-input :record :current>`。自己登録は `multipart/form-data` にしてファイル欄を追加。工数の一括編集では候補から外す。
- **レコードの閲覧可否(Redmine の `customized.visible?`)**: `AttachmentFieldValue::ownerVisibleTo()`。
  - バージョン: ロードマップの `viewRoadmap`(`view_issues`、Redmine の `Version#visible?`)。`VersionPolicy::view` はファイルモジュールの `view_files` なので使わない。
  - ユーザー: `User::isVisibleTo()`(ユーザーの表示範囲。`GET /users/{id}` と同じ)。
  - プロジェクト・文書・工数(・課題): 各ポリシーの `view`。
  - グループ・列挙値: 各ポリシーの `view`(= 管理者のみ)。Redmine はグループを Principal の表示範囲で、列挙値を誰にでも見せるが、本アプリにはこれらの値を一般利用者に表示する画面が無いため、見せる範囲を広げない側に倒した。
- フィールドの可視性(役割)は B'-02 と同じく `relevantCustomFields()`(課題以外は現在の利用者で判定)。
