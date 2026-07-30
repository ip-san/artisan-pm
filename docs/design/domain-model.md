# ドメインモデル

`app/Models/`配下の約45モデルを、機能領域ごとに分けて掲載します。1枚のER図に全モデルを詰め込むと可読性が失われるため、領域をまたぐ関連は各図に重複して登場させています(例: `PROJECT`は全図に登場する)。

## 1. プロジェクト構造・権限

```mermaid
erDiagram
    PROJECT ||--o{ PROJECT : "サブプロジェクト(nested set: lft/rgt/root_id)"
    PROJECT ||--o{ MEMBER : has
    PROJECT ||--o{ PROJECT_MODULE_ASSIGNMENT : "有効なモジュール"
    PROJECT }o--o{ TRACKER : "使用するトラッカー"
    PROJECT ||--o{ PROJECT_BOOKMARK : "ブックマークされる"
    PROJECT ||--o{ VERSION : has

    USER ||--o{ MEMBER : "メンバーとして所属"
    USER }o--o{ GROUP : "グループ所属"
    MEMBER }o--o{ ROLE : "ロールを持つ(多対多)"
    ROLE {
        json permissions "権限キーの配列"
        string builtin "null=通常ロール, anonymous/non_member=組込ロール"
        string issues_visibility "all/default/own"
        string users_visibility "all/members_of_visible_projects"
    }
    USER {
        string status "registered/active/locked/deleted"
        bool is_admin
        string mail_notification "all/selected/only_my_events/only_assigned/only_owner/none"
    }
```

- `Member`⇔`Role`は多対多(`member_roles`ピボット)。ユーザーが複数ロールを持つ場合、権限は**最も緩い側が優先**(`AuthorizationService`が全ロールの`OR`を取る)。
- ゲスト(未ログイン)と非メンバー(ログイン済みだが当該プロジェクトのメンバーでない)には、`Role.builtin`が`anonymous`/`non_member`の組込ロールが適用される — 詳細は[`authorization.md`](authorization.md)。
- `Project`の階層は`kalnoy/nestedset`によるネステッドセット(`lft`/`rgt`/`root_id`)。**`Issue`の親子は同じネステッドセットではなく単純な隣接リスト**(`parent_id`のみ)——意図的な設計逸脱(詳細は`../parity-checklist.md`)。

## 2. 課題管理

```mermaid
erDiagram
    PROJECT ||--o{ ISSUE : has
    TRACKER ||--o{ ISSUE : categorizes
    TRACKER ||--o{ WORKFLOW_TRANSITION : "遷移ルールを定義"
    TRACKER ||--o{ WORKFLOW_FIELD_RULE : "フィールドの必須/読取専用ルール"
    ISSUE_STATUS ||--o{ WORKFLOW_TRANSITION : "遷移元/遷移先"
    ISSUE_STATUS ||--o{ ISSUE : "現在のステータス"

    ISSUE ||--o{ ISSUE : "親子(隣接リスト, parent_idのみ)"
    ISSUE ||--o{ ISSUE_RELATION : "関連(relates/blocks/precedes/...)"
    ISSUE ||--o{ JOURNAL : "履歴・コメント"
    JOURNAL ||--o{ JOURNAL_DETAIL : "属性変更の明細"
    ISSUE }o--o| USER : "担当者(nullable)"
    ISSUE }o--|| USER : "作成者"
    ISSUE }o--o{ WATCHER : "ウォッチされる(polymorphic)"
    ISSUE }o--o{ REACTION : "リアクションされる(polymorphic)"
    ISSUE }o--o| VERSION : "対象バージョン"
    ISSUE }o--o| ISSUE_CATEGORY : "カテゴリ"
    ISSUE ||--o{ TIME_ENTRY : "工数記録"
    ISSUE ||--o{ CUSTOM_FIELD_VALUE : "カスタムフィールド値(polymorphic)"

    ISSUE {
        int lock_version "楽観的ロック"
        bool is_private
        int done_ratio
        decimal estimated_hours
    }
```

- ステータス遷移は`WorkflowTransition`(トラッカー×ロール×現在ステータス→遷移可能な次ステータス、作成者/担当者向けの追加ルールを含む)で判定 — 詳細は[`issue-workflow.md`](issue-workflow.md)。
- `Journal`はコメント本体と属性変更ログの両方を1テーブルで表現(`notes`があればコメント、`JournalDetail`があれば属性変更 — 両方持つことも、どちらも空(=非表示)もあり得る)。
- `Watcher`/`Reaction`はどちらも`watchable_type`/`reactable_type`によるポリモーフィック関連で、`Issue`以外に`WikiPage`/`News`/`Message`/`NewsComment`/`Journal`(Reactionのみ)にも同じ形で付く。

## 3. Wiki

```mermaid
erDiagram
    PROJECT ||--o| WIKI : "設定(start_page)"
    PROJECT ||--o{ WIKI_PAGE : has
    WIKI_PAGE ||--o{ WIKI_PAGE : "親子(階層表示用)"
    WIKI_PAGE ||--o{ WIKI_PAGE_VERSION : "版歴"
    WIKI_PAGE ||--o{ WIKI_REDIRECT : "旧タイトルからのリダイレクト"
    WIKI_PAGE }o--o{ WATCHER : "ウォッチされる"

    WIKI {
        string start_page "既定'Wiki'、対応するWikiPageが無くてもよい"
    }
```

- `Wiki`は「そのプロジェクトのWiki設定」を表す1行(プロジェクトごとに遅延作成、`Project::wikiOrCreate()`)。`start_page`は文字列のみで、対応する`WikiPage`が存在しなくてもよい(存在しなければ新規作成フォームへ誘導)。
- `WikiRedirect`はページ名変更時に旧タイトルでのアクセスを新タイトルへ転送する。

## 4. フォーラム・News

```mermaid
erDiagram
    PROJECT ||--o{ BOARD : has
    BOARD ||--o{ MESSAGE : has
    MESSAGE ||--o{ MESSAGE : "トピック/返信(parent_idのみ)"
    MESSAGE }o--o{ WATCHER : "ウォッチされる"
    MESSAGE }o--o{ REACTION : "リアクションされる"

    PROJECT ||--o{ NEWS : has
    NEWS ||--o{ NEWS_COMMENT : has
    NEWS }o--o{ WATCHER : "ウォッチされる"
    NEWS }o--o{ REACTION : "リアクションされる"
    NEWS_COMMENT }o--o{ REACTION : "リアクションされる"
```

- `Message`はトピックと返信を同一テーブル・同一モデルで表現(`parent_id`が`null`ならトピック)。
- Wiki/課題本文と異なり、News本文・フォーラム投稿には`@mention`機能が無い(Redmine本家にも存在しないため — 詳細は`../parity-checklist.md`の該当行)。

## 5. 工数管理

```mermaid
erDiagram
    PROJECT ||--o{ TIME_ENTRY : has
    ISSUE ||--o{ TIME_ENTRY : "紐づく課題(任意)"
    USER ||--o{ TIME_ENTRY : "記録者"
    ENUMERATION ||--o{ TIME_ENTRY : "作業分類(activity)"

    TIME_ENTRY {
        decimal hours
        date spent_on
        string comments
    }
```

- `Enumeration`はRedmineの「一般設定の列挙値」テーブル相当で、`EnumerationType`(優先度/作業分類/文書カテゴリ)によって同一テーブルを使い回す。

## 6. SCM(ソースコード管理)

```mermaid
erDiagram
    PROJECT ||--o{ REPOSITORY : "1:多、1つがデフォルト"
    REPOSITORY ||--o{ CHANGESET : has
    CHANGESET ||--o{ CHANGESET_FILE : "変更ファイル一覧"
    CHANGESET }o--o{ ISSUE : "コミットメッセージ内の#123参照"
    REPOSITORY ||--o{ REPOSITORY_COMMITTER : "コミッター文字列→Userの対応付け"

    REPOSITORY {
        string type "git/svn"
        string identifier "URL上の識別子、一度設定すると変更不可"
        bool is_default
    }
```

- 実際のリポジトリ操作(`log`/`diff`/`blame`/`cat`)は`App\Support\Scm\ScmAdapter`実装クラスが`git`/`svn`バイナリをシェルアウトして行う——このER図が表すのはメタデータのみで、ファイル内容そのものはDBに持たない。

## 7. カスタムフィールド

```mermaid
erDiagram
    CUSTOM_FIELD ||--o{ CUSTOM_FIELD_VALUE : "値(polymorphic customized)"
    CUSTOM_FIELD ||--o{ CUSTOM_FIELD_ENUMERATION : "「列挙」形式の選択肢"
    TRACKER }o--o{ CUSTOM_FIELD : "使用可能なトラッカー(issueタイプのみ)"

    CUSTOM_FIELD {
        string customized_type "issue/project/version/group/time_entry_activity/document/document_category/user"
        string field_format "string/text/int/float/date/bool/list/enumeration/link/progressbar"
    }
```

- `customized_type`はRedmineの`CustomField`サブクラス設計(`IssueCustomField`等の継承)の代わりに、単一テーブル+区分カラムで表現している。
- `field_format = 'list'`(単純な文字列配列の選択肢)と`'enumeration'`(`CustomFieldEnumeration`という別テーブルを持つ、位置/有効フラグ付きの選択肢)は別物——調査の結果判明した区別で、チェックリストにも詳細記録あり。
