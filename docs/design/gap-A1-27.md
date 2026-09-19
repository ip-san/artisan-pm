# A1-27 設計メモ: ロール × トラッカー単位の課題権限

状態: `blocked(要承認)`。認可の中心(課題の可視性スコープ)を変える変更で、抜けがそのまま情報漏えいになるため、方針の承認を待つ。

## 背景

Redmine 7.0 の `Role` は `settings`(シリアライズ済みハッシュ)に `permissions_all_trackers`(権限 → 全トラッカーか)と `permissions_tracker_ids`(権限 → 許可トラッカー ID の配列)を持つ。対象の権限は `view_issues` / `add_issues` / `edit_issues` / `add_issue_notes` / `delete_issues` の 5 つ(`roles/_form.html.erb:75`)。「全トラッカー」が既定で、外した権限だけが選んだトラッカーに限られる。

本アプリの `roles` テーブルには `settings` 相当が無く、`AuthorizationService::can(?User, string $permission, ?Project)` はトラッカーを知らない。

## 影響範囲(調査結果)

課題の可視性は `Issue::scopeVisibleTo()` と `scopeVisibleToAcrossProjects()`(`app/Models/Issue.php`)に集約されているが、呼び出しが多い。

| 箇所 | 件数/内容 |
|---|---|
| `visibleTo` / `visibleToAcrossProjects` の呼び出し | 課題一覧(プロジェクト/全体)、マイページの保存クエリブロック、REST API `IssueController`、Atom(課題/変更履歴)、ユーザー詳細、既定クエリ、`IssueService` の計 13 箇所 |
| 単一課題の判定 | `IssuePolicy`(view/update/delete/addNotes ほか多数)が `AuthorizationService::can` を課題のプロジェクトで呼ぶ。トラッカーを渡す経路が無い |
| トラッカー選択肢 | 新規課題フォームと一括編集/コピー/移動先のトラッカー一覧(`add_issues` を持つトラッカーだけ出す必要がある) |
| 関連する読み取り経路 | 検索、カレンダー、ガント、レポート、工数(課題付き)、関連課題の表示、バージョンページの課題一覧など、`visibleTo` を通らず `project_id` だけで絞っている箇所が残っていないかの監査が要る |
| 複数ロール | 1 ユーザーが複数ロール(グループ経由を含む)を持つとき、権限ごとに「どれかのロールが全トラッカーか、許可トラッカーの和集合」を取る(Redmine の `issue.rb:1710-1711`) |

## 判断が要る点

1. **保存先**: `roles` に JSON 列 `settings` を追加する(推奨。Redmine と同形で、将来の役割別設定も入る)か、専用の `role_tracker_permissions` テーブルにするか。前者は 1 マイグレーションで済み、後者は SQL で絞りやすい。
2. **可視性の実装方式**: `scopeVisibleTo` に「許可トラッカー ID の集合」を足す(推奨)。`AuthorizationService` に `allowedTrackerIds(?User, Project, string $permission): ?Collection`(null は全トラッカー)を新設し、スコープ・ポリシー・トラッカー選択肢がすべてこれを使う。
3. **初期値の移行**: 既存ロールは「全トラッカー」のまま(`settings` 空=全トラッカー)。データ移行は不要。
4. **監査の範囲**: 上表の「読み取り経路」を先に全数調べ、`visibleTo` を通らない経路を洗い出してから着手するかどうか(推奨: する。これを飛ばすと、制限したトラッカーの課題が検索やガントに出る)。

## 推奨案と分割

1. **A1-27a**(S): マイグレーション、`Role` の `settings` アクセサ、`AuthorizationService::allowedTrackerIds()`、ロール編集画面の権限×トラッカー表。
2. **A1-27b**(M): `view_issues` — スコープ 2 種と、`IssuePolicy::view` ほか単一課題判定への適用。全読み取り経路の監査と、経路ごとのテスト(一覧/API/Atom/検索/カレンダー/ガント/マイページ)。
3. **A1-27c**(M): `add_issues` / `edit_issues` / `add_issue_notes` / `delete_issues` — ポリシー、新規課題・一括編集・コピー・移動のトラッカー選択肢、API の作成/更新の検証。

## 影響を受けないもの

管理者(`is_admin`)は常に全トラッカー。既存ロールの挙動(全トラッカー)。
