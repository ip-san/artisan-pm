# リクエストライフサイクル

## ルーティングとミドルウェア

```mermaid
flowchart TB
    Req["HTTPリクエスト"] --> Web["web ミドルウェアグループ<br/>(セッション, CSRF等)"]
    Web --> AuthMw{"auth ミドルウェア<br/>(既定で全ルートに付与)"}
    AuthMw -->|"未ログイン かつ<br/>ゲスト許可対象ルートでない"| Login["/login へリダイレクト"]
    AuthMw -->|"ログイン済み、または<br/>login.required ミドルウェアで個別に開放"| LoginRequired{"login.required<br/>(withoutMiddleware('auth')併用)"}
    LoginRequired -->|"login_required設定=true<br/>かつ未ログイン"| Login
    LoginRequired -->|通過| SessionTimeout["セッションタイムアウト判定"]
    SessionTimeout --> Twofa["2FA必須設定の強制"]
    Twofa --> Volt["Voltコンポーネント実行"]
```

- 既定では**全ルートが`auth`ミドルウェア配下**(ログイン必須)。ゲスト到達を許すルート(`issues.index`/`issues.show`/`wiki.*`等)だけが個別に`->withoutMiddleware('auth')->middleware('login.required')`を付与される——別グループに切り出す設計は採らなかった(`issues/create`のようなリテラルセグメントと`issues/{issue}`のようなワイルドカードの登録順が壊れるため)。
- `login.required`ミドルウェア(`EnforceLoginRequiredSetting`)は「`login_required`設定がtrue(既定)なら未ログインを`AuthenticationException`で弾く」というゲートで、実際に「このプロジェクトが公開かどうか」の判定はここでは行わない——それは各Voltコンポーネントの`mount()`が呼ぶPolicyの役目(詳細は[`authorization.md`](authorization.md))。

## Voltコンポーネントのライフサイクル

```mermaid
sequenceDiagram
    participant B as ブラウザ
    participant V as Voltコンポーネント
    participant P as Policy
    participant S as Service

    Note over B,V: 初回アクセス(GET)
    B->>V: HTTPリクエスト
    V->>V: mount(パラメータ)
    V->>P: $this->authorize(...)
    P-->>V: 許可/例外
    V-->>B: 初期HTML

    Note over B,V: ユーザー操作(フォーム送信等)
    B->>V: Livewireリクエスト(AJAX)
    V->>V: hydrate(状態復元)
    V->>V: アクションメソッド実行
    V->>P: $this->authorize(...)
    V->>S: Service経由でモデル操作
    S-->>V: 結果
    V->>V: render() で再描画
    V-->>B: 差分HTML(dom diff/patch)
```

- `mount()`はGET相当の初回リクエストでのみ実行される。以降のアクション呼び出し(`wire:click`等)は`hydrate → アクション → render`のサイクルを繰り返す——`mount()`内で読み込んだEloquentリレーションは、Livewireがモデルのシリアライズ時に保持する場合としない場合があるため、「アクション内で新たにモデルを取得し直しても、実際には毎リクエストrehydrateされるので実害が薄い」という前提に基づいた実装が随所にある(例: リアクション機能の実装時に検証済み——詳細はチェックリストのリアクション行)。
- `mount()`からの`$this->redirect(..., navigate: true)`は、初回ロード時点でも(アクションからでなくても)呼び出せる——`wiki.index`ルートが「Wikiの開始ページへリダイレクトする」という役割に変わった際、この性質を利用してURLもルート名も変えずに挙動だけを差し替えた(詳細は`../../README.md`の「Notable design decisions」)。

## 例: @mention付き課題作成のフルパス

```mermaid
sequenceDiagram
    participant U as ユーザー(ブラウザ)
    participant V as Voltコンポーネント(issues.form)
    participant P as IssuePolicy
    participant S as IssueService
    participant M as Issueモデル
    participant E as IssueCreatedイベント
    participant L as SendIssueMailNotifications
    participant R as NotificationRecipients
    participant Mail as Mailer

    U->>V: 「新規課題」フォーム送信
    V->>P: authorize('create', [Issue, project])
    P-->>V: 許可
    V->>S: create(project, attributes)
    S->>M: Issue::create(...)
    S->>S: MentionParser::extractLogins(description)
    S->>E: IssueCreated::dispatch(issue, actor, mentionedLogins)
    E->>L: handle(event)
    L->>R: forIssue(issue, 'issue_added', actor, mentionedLogins)
    R->>R: 階層別受信者(担当者/ウォッチャー/メンバー) と<br/>メンション対象ユーザーをunion
    R->>R: can('view', issue) で可視性フィルタ
    R-->>L: 受信者リスト
    L->>Mail: 各受信者へ通知メール送信
    V-->>U: 課題詳細画面へリダイレクト
```

通知パイプラインの詳細(イベント一覧・受信者解決ロジック)は[`notifications-and-jobs.md`](notifications-and-jobs.md)を参照。

## 例: PDF/Atomなど「Livewireで完結しないレスポンス」

`app/Http/Controllers/`配下のプレーンControllerは、Volt(HTML部分更新が前提)では扱いにくいレスポンス種別のみを担当する:

```mermaid
flowchart LR
    Routes["routes/web.php"] --> IssuePdf["IssuePdfController<br/>(dompdf, CJKフォント埋込)"]
    Routes --> Atom["IssueAtomController /<br/>NewsAtomController /<br/>BoardAtomController /<br/>ActivityFeedController<br/>(Atomフィード)"]
    Routes --> Attachment["AttachmentController /<br/>AttachmentThumbnailController<br/>(spatie/laravel-medialibrary)"]
    Routes --> RawScm["RepositoryRawController<br/>(生ファイル内容のダウンロード)"]
    Routes --> Activation["AccountActivationController<br/>(署名付きURLでのアカウント有効化)"]
```

これらは`Volt::route()`ではなく通常の`Route::get(...)->name(...)`で登録され、認可チェックは各Controllerのアクション内で明示的に行う(Voltの`mount()`に相当する箇所がないため)。

**CSVエクスポートはこのパターンに含まれない**——課題一覧・工数一覧のCSVエクスポートは、専用Controllerを介さずVoltコンポーネント自身のアクションが`response()->streamDownload()`を直接返す(`issues.index`等)。認可・データ取得ロジックを画面表示と同じコンポーネント内で完結させたいという理由で、この1点だけはプレーンController化していない。
