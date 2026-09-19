# 実装エージェント指示書(Redmine ギャップ自律ループ)

あなたは `docs/redmine-gap-backlog.md` の実行キューを 1 行ずつ処理する実装エージェントです。
**この指示書は 1 回の起動で「1 イテレーション」だけを完了して終了する**設計です。外側のシェルループが繰り返し起動するので、次の行に進もうとせず、1 行を確実に終わらせてください。あなたの記憶は次の起動に引き継がれません。状態は `docs/redmine-gap-backlog.md` §0.3 の「状態」列と git の履歴だけに残してください。

## 0. 前提(毎回必ず確認)

- 作業ディレクトリ: `/Users/sesoko/orca/workspaces/artisan-pm/snook`(git worktree、ブランチ `ip-san/redmine-feature-gap-list`)。`cd` で他所へ移動しない。
- 参照元 Redmine 7.0.0: `/Users/sesoko/Desktop/workspace/redmine`(読み取り専用)。
- `CLAUDE.md` の規約に従う: PHP/artisan/composer/npm は必ず `vendor/bin/sail` 経由、ファイル生成は `make:` コマンド、`vendor/bin/sail bin pint --dirty --format agent`、**依存追加禁止**、新規ベースフォルダ禁止、テスト必須、既存テスト削除禁止。
- Livewire/Volt/Pest/Laravel のスキル(`livewire-development`、`volt-development`、`pest-testing`、`laravel-best-practices`)は該当作業で必ず有効化する。
- ユーザーは見ていない。**質問して止まらない**。判断が要るなら `blocked` にして終了する。

## 1. 起動時チェック(順番どおり、失敗したら §6 へ)

```bash
git status --short            # 未コミット変更の有無
git log --oneline -3          # 直前のコミット(前回のイテレーション)
vendor/bin/sail ps            # 4 コンテナ(laravel.test/pgsql/redis/mailpit)が Up か
```

- コンテナが落ちていれば `vendor/bin/sail up -d` を実行し、`vendor/bin/sail artisan migrate --no-interaction` を通す。
- 画面を描画するテストが `Vite manifest not found` で落ちる場合は `public/build/manifest.json` が未生成なので `vendor/bin/sail npm ci && vendor/bin/sail npm run build` を実行する(`public/build` と `node_modules` は gitignore 対象、コミットしない。Sail 内ではビルドが通ることを確認済み)。
- API 系テストが `Invalid key supplied`(Passport)で落ちる場合は `storage/oauth-*.key` が未生成なので `vendor/bin/sail artisan passport:keys --no-interaction` を実行する(鍵は gitignore 対象、コミットしない)。
- `vendor/bin/sail` 自体が無ければ作業せず §6 の「環境不備」で終了する。
- 未コミット変更があるのに §0.3 に `wip` 行が無い場合: `git diff --stat` を見て、直前のコミットの続きと判断できるならそのコミットの行を `wip` として扱う。判断できなければ変更を `git stash push -u -m "gap-loop-orphan-$(date +%s)"` で退避し、その旨を §7 の報告に書いて次へ進む(**`git stash pop` は使わない**)。

## 2. 行の選択

1. `docs/redmine-gap-backlog.md` の §0.3 を読む。
2. `wip(...)` の行があればそれを再開する(前回の中断)。`git diff` でどこまで進んだかを把握してから続ける。
3. 無ければ、上から見て **状態が `todo` で、「依存」列の ID がすべて `done` または `done(既存, …)` になっている最初の行**を選ぶ。依存に `承認` とある行は選ばない。
4. 選んだ行の状態を `wip(YYYY-MM-DD)` に書き換える(他は何も変えない)。
5. 全行が `done` / `done(既存, …)` / `blocked(...)` なら §7(完了報告)へ。

## 3. 実施(1 行分)

1. **現状確認**: 該当 A 行の「本アプリの現状」に書かれたファイルを開き、本当に未実装かを確認する。実装済みなら状態を `done(既存, YYYY-MM-DD)` にし、§C 表の末尾に訂正行(ID は `C-18` から採番)を追加して §5 へ(コードは書かない)。
2. **Redmine 側確認**: 「Redmine 側の機能」列のファイルを参照チェックアウトで開き、バリデーション・権限・エッジケースを確認する。**まず、その機能・列・設定が Redmine 7.0.0 に現存するか(`app/` での使用箇所、後続マイグレーションでの `drop`)を確認する。廃止済みなら実装せず、状態を `done(対象外: Redmine 7.0 で廃止, 日付)` にして §C に訂正行を追加する。**読めなければ本書の記載を仕様とし、コミットメッセージに「Redmine ソース未参照」と書く。
3. **判断が要る行**(「設計メモ必須」「設計判断」「スキーマ判断」の記載、または規模 L): `docs/design/gap-<ID>.md` に 1 ページの設計メモ(背景・選択肢 2〜3 個・推奨案・影響範囲)を書き、状態を `blocked(要承認: docs/design/gap-<ID>.md)` にして §5 へ。実装はしない。
4. **実装**: 既存の兄弟ファイルの構造・命名に合わせる。Policy/可視性スコープに触れる場合は否定ケース(権限なし・他プロジェクト・非公開)のテストを必ず追加する。
5. **テスト**: 新規/変更した機能の Feature テストを書き(Pest のファイル内 `function` ヘルパーはグローバル名前空間に宣言されるため、既存テストと重複しない固有の名前にする。重複は全スイートを走らせて初めて「Cannot redeclare function」で発覚する。確認: `grep -rhoE "^function [a-zA-Z0-9_]+" tests | sort | uniq -d`)、
   `vendor/bin/sail artisan test --compact --filter=<テスト名>` → 影響ディレクトリ `vendor/bin/sail artisan test --compact tests/Feature/<領域>` の順で通す。
   さらに、直前 10 コミットの中に全スイート実行のコミット(メッセージに `[full-suite]`)が無ければ `vendor/bin/sail artisan test --compact` を実行し、通ったらコミットメッセージ末尾に `[full-suite]` を付ける。**タグの意味は「そのコミットの作業ツリーで全スイートが通った」であり、「最近実行した」ではない**。実行したのがそのコミットの変更前なら付けない(付けると次回の起動が全スイート実行を飛ばしてしまう)。全スイートはコミット直前、変更を含めた状態で実行する。
6. **Pint**: `vendor/bin/sail bin pint --dirty --format agent`。
7. **規模超過**: 想定規模の 2 倍を超えそうなら、行を `<ID>a`/`<ID>b` に分割して §0.3 に追加し、終わった部分だけ `done` にする。残りは `todo` のまま次回に回す。

## 4. 失敗時の扱い(止まらないための規則)

- 同じ原因でテストが **2 回**直せなければ、その行で書いたコードを `git stash push -u -m "gap-loop-failed-<ID>"` で退避して作業ツリーを行の開始時点に戻し(退避後に `git status --short` が空であることを確認)、状態を `blocked(失敗: 要約 1 行、stash 名)` にして §5 へ。`git reset --hard` は使わない。
- 環境要因(コンテナ停止、DB 接続不可、Composer/NPM 不備)は §1 の復旧を 1 回だけ試し、ダメなら状態を戻さずに §6 で終了する(次回起動で再開される)。
- 実装中に別の未実装・誤記を見つけたら、本書の該当セクション末尾に行を追加する(ID は既存の末尾+1)。その場では直さない。
- 既存テストが落ちたら、仕様変更として妥当な場合のみ更新し、理由をコミットメッセージに書く。妥当でないなら自分の変更を疑う。

## 5. 記録とコミット(1 行 = 1 コミット)

同じコミットに必ず含めるもの:

1. コード・テスト。
2. `docs/redmine-gap-backlog.md` §0.3 の状態: `done(YYYY-MM-DD)` / `done(既存, …)` / `blocked(...)`。
3. `docs/parity-checklist.md` の該当行(A 行の「checklist 行」列): ステータスを `done(YYYY-MM-DD)` にし、備考に要点(何を・どのファイルで・意図的に外したものがあればそれ)を追記。段 0 の場合は C 表の全行を反映する。
4. コミット:

```bash
git add -A
git commit -m "<ID>: <英語で要約(50 字以内)>

<何を実装し、Redmine のどこに対応するか。意図的に外したものと理由。>

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

`git push`、`git rebase`、`git reset --hard`、`git stash pop` は**使わない**。

## 6. 終了(毎回)

作業を終えるときは、次のどれか 1 行を**最後の出力の先頭**に書く(外側のループが読む):

- `GAP-LOOP: DONE <ID>` — 1 行を完了してコミットした
- `GAP-LOOP: BLOCKED <ID> <理由>` — `blocked` にしてコミットした
- `GAP-LOOP: RETRY <理由>` — 環境要因で作業できず、状態は変えていない(次回そのまま再開)
- `GAP-LOOP: COMPLETE` — §0.3 の全行が done/blocked(§7-C を出力済み)
- `GAP-LOOP: ABORT <理由>` — git が不整合など、人の介入が必要(このときだけループが止まる)

その後に 5 行以内で、やったこと・テスト結果・次回の再開点を書く。

## 7. 完了報告(`GAP-LOOP: COMPLETE` のときだけ)

`docs/redmine-gap-backlog.md` の末尾に「## 完了報告(YYYY-MM-DD)」を追加し、(a) `blocked(要承認)` の一覧とそれぞれの設計メモへのリンク・判断点、(b) `blocked(失敗)` の一覧と原因、(c) 追加した新規行の一覧、をまとめてコミットする。

## 8. 禁止事項(再掲)

- B 表(対象外確定)の項目に着手しない。B' 表はブロッカー解消の追記があるまで着手しない。
- 依存パッケージ・SCM バイナリ・新規ベースフォルダを追加しない(必要なら `blocked(要承認)`)。
- 1 回の起動で 2 行以上を処理しない。
- ユーザーに質問しない。`AskUserQuestion` を使わない。

## 9. 外側のループ(人が起動する)

リポジトリ直下で実行する。1 回の起動 = 1 イテレーション。`GAP-LOOP: COMPLETE` または `GAP-LOOP: ABORT` で止まる。`RETRY` が 3 回続いた場合も止まる(環境要因の可能性が高いため)。

```bash
cd /Users/sesoko/orca/workspaces/artisan-pm/snook
retry=0
while :; do
  out=$(claude -p "$(cat docs/redmine-gap-backlog-runner.md)" \
          --model sonnet \
          --dangerously-skip-permissions 2>&1 | tee -a storage/logs/gap-loop.log)
  status=$(printf '%s\n' "$out" | grep -m1 -oE '^GAP-LOOP: (DONE|BLOCKED|RETRY|COMPLETE|ABORT)' | awk '{print $2}')
  echo "[$(date '+%F %T')] $status" | tee -a storage/logs/gap-loop.log
  case "$status" in
    COMPLETE|ABORT) break ;;
    RETRY) retry=$((retry+1)); [ "$retry" -ge 3 ] && { echo "RETRY x3, stopping"; break; }; sleep 60 ;;
    DONE|BLOCKED) retry=0 ;;
    *) echo "no GAP-LOOP status line; treating as RETRY"; retry=$((retry+1)); [ "$retry" -ge 3 ] && break; sleep 60 ;;
  esac
done
```

- `--dangerously-skip-permissions` は無人実行のため(この CLI では `--permission-mode bypassPermissions` に `--allow-dangerously-skip-permissions` が別途必要)。監視しながら回すなら `--permission-mode acceptEdits` に置き換え、Bash の許可を都度与える。
- ログは `storage/logs/gap-loop.log`(gitignore 対象)。
- 途中で止めたいときは Ctrl-C。次回はそのまま同じコマンドで再開できる(`wip` 行から続く)。
- 同時に 2 つ以上のループを走らせない(同じ worktree・同じ状態ファイルを取り合う)。
