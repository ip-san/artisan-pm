# A9-01 設計メモ: プロジェクト横断ガント・PNG エクスポート・PDF の関連線

状態: `blocked(要承認)`。3 つの要素があり、うち 2 つは実装方式に不確実性がある。

## 背景

Redmine のガント(`/issues/gantt`、`/projects/:id/issues/gantt`)は、プロジェクト横断表示、PNG 出力、共有バージョンのマイルストーン、PDF への関連線描画、設定 `gantt_items_limit` / `gantt_months_limit`(本アプリは設定済み)を持つ。本アプリは `gantt.index`(プロジェクト単位、`resources/views/livewire/gantt/index.blade.php` 約 425 行、行の構築は `App\Support\Gantt\GanttRow`)のみ。

## 要素ごとの評価

1. **プロジェクト横断ガント**(実装は素直): `gantt.global-index` を、課題一覧/工数一覧の横断版と同じ構成(可視プロジェクトを解決し `Issue::visibleToAcrossProjects()` で絞る)で追加する。プロジェクトの階層に沿ったグルーピングが要る。
2. **共有バージョンのマイルストーン**(実装は素直): `Version::sharing` が `none` 以外のバージョンを、そのバージョンを使う課題があるプロジェクトのガントに出す。
3. **PNG エクスポート**(方式の判断が要る): Redmine は RMagick で描く。候補は (a) PHP の GD で行と棒を直接描く(依存追加なし。`gd` 拡張は導入済み)、(b) SVG を組んで Imagick で変換する(`imagick` 拡張は導入済みだが SVG 変換は ImageMagick のデリゲート設定次第で環境依存)、(c) ヘッドレスブラウザ(新規依存のため対象外)。推奨は (a) だが、日本語ラベルの描画に TrueType フォントの同梱が要る(PDF 用に IPAGothic を既に同梱しているので流用できる)。
4. **PDF の関連線**(検証が要る): 現行の PDF は dompdf で HTML を描いており、HTML 版ガントの関連線は SVG オーバーレイ。dompdf の SVG 対応は限定的で、`gantt/index` の docblock も「安定して描ける確認が取れなかった」として見送っている。代替は絶対配置の細い `div` を線として組む方法(角の処理が粗い)。

## 判断が要る点

1. PNG の方式(推奨 (a) GD)とフォントの扱い。
2. 関連線の PDF 描画: `div` の線で近似する(推奨)か、対象外のまま残すか。
3. 横断ガントの行数上限(`gantt_items_limit`)を、プロジェクトごとではなく全体で数えるか。

## 推奨案と分割

1. **A9-01a**(M): `gantt.global-index`(可視性・上限・階層グルーピング)と共有バージョンのマイルストーン。
2. **A9-01b**(M): PNG エクスポート(GD)。
3. **A9-01c**(S): PDF の関連線(`div` 近似)。承認が出なければ対象外として閉じる。

## 影響範囲

`resources/views/livewire/gantt/`、`app/Support/Gantt/`、`resources/views/pdf/gantt.blade.php`、ルート、既存テスト(`tests/Feature/Gantt`)。
