# A4-10b 設計メモ: 姓名の分離(`firstname`/`lastname`)と `user_format` の全形式

状態: `blocked(要承認)`。スキーマ変更で、表示・入力・取り込みの全経路に波及する。

## 背景

Redmine の `users` は `firstname` / `lastname` を持ち、設定 `user_format` が表示名の形(`firstname lastname`、`lastname, firstname`、`username` など 7 形式)を決める。本アプリは `users.name` 単一列で、`A4-10a`(「名前」「名前 (login)」「login」の 3 形式)が実装済み。

## 選択肢

1. **A. `name` を残し、`firstname`/`lastname` を追加(推奨)**: `name` は表示用の導出値(または従来どおりの保存値)とし、新しい 2 列を任意で持つ。新形式は 2 列がある人だけ効き、無い人は `name` で表示する。
   - 長所: 既存データの移行が不要で、既存の全経路(API・CSV・LDAP・検索)は `name` のまま動く。後から段階的に 2 列を入力に移せる。
   - 短所: 二重管理(`name` と 2 列の整合)。
2. **B. `name` を廃止して 2 列に分割(Redmine 同形)**: 既存 `name` を空白で分割して移行。表示は `user_format` で組み立てる。
   - 長所: データモデルが Redmine と一致。
   - 短所: 日本語の氏名は空白で分割できないことが多く、移行が不正確。`users.name` を直接読む箇所(全ビューの `->name`、`orderBy('name')`、検索、API、CSV、LDAP マッピング)を全部直す必要がある。

## 判断が要る点

1. 方式(推奨 A)。日本語運用では B の自動分割が信頼できないため。
2. `name` と 2 列の整合ルール: 2 列が入力されたら `name` を `user_format` の既定形で再計算するか、`name` は独立に保つか。
3. LDAP 属性マッピング(現状は表示名 1 属性)に姓・名の属性を足すか。
4. CSV インポート(A4-01)とユーザー API に 2 列を足すか。

## 推奨案と分割

1. **A4-10b-1**(S): 列追加、`User::displayName()` を `user_format` 全形式に対応、ユーザーフォーム/プロフィールの入力欄。
2. **A4-10b-2**(S〜M): 全画面の表示を `displayName()` へ寄せる(A4-10a で寄せた箇所の残り)。
3. **A4-10b-3**(S): LDAP・CSV インポート・API への反映。

## 影響範囲

`users` テーブル、`User` モデル、ユーザーフォーム/プロフィール、`Ldap*`、`ImportUsersJob`、`UserResource`、検索、全ビューの表示名。
