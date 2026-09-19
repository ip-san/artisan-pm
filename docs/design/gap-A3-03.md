# A3-03 設計メモ: 子プロジェクトのメンバー継承(`inherit_members`)

状態: `blocked(要承認)`。スキーマと認可の広い範囲に影響し、2 通りの実装方式のどちらを取るかの判断が要る。A11-16(REST の `inherited_from`)はこの承認後に着手する。

## 背景

Redmine のプロジェクトは `inherit_members`(親のメンバーを引き継ぐ)を持つ。オンの子プロジェクトでは、親のメンバーとロールが子のメンバーとして現れ、子の側では変更・削除できない(`Member#deletable?`、`member_roles.inherited_from`)。親のメンバー追加/ロール変更/削除、子の `inherit_members` 切替、親の付け替えのたびに子孫へ伝播する(`member_role.rb:73`、`project.rb:1034-1054`)。

## 選択肢

1. **A. 実体化(Redmine 方式)**: 子プロジェクトに `members` / `member_roles` の行を実際に作り、`inherited_from`(元の `member_roles.id`)で由来を記録する。
   - 長所: `Project::users`、担当者候補、通知、ウォッチャー候補、メンバー一覧、API など、`members` を直接読む既存の全箇所が追加変更なしで継承メンバーを認識する。
   - 短所: 伝播の契機が多い(親のメンバー/ロールの追加・変更・削除、`inherit_members` 切替、親の付け替え、サブプロジェクトの新規作成、グループメンバー)。取りこぼすとデータがずれる。
2. **B. 動的解決**: `AuthorizationService::rolesFor()` が「`inherit_members` が連続する祖先」のメンバーも探す。
   - 長所: 伝播コードが要らず、ずれない。
   - 短所: `members` を直接読む箇所(担当者候補・通知・一覧・API・ウォッチャー・課題の担当者検証)が継承メンバーを見落とし、権限はあるのに担当者にできない等の不整合が出る。全箇所の洗い出しが要る。

**推奨: A(実体化)**。読み取り側の変更が最小で、Redmine と同じデータ形になる(A11-16 の `inherited_from` もそのまま出せる)。

## 判断が要る点

1. 方式(A/B)。
2. 既存のグループ権限(`members.group_id` を動的に解決している)との関係: グループのメンバーシップは今のところ動的解決で、実体化しない。継承も動的なら方式が揃うが、上記のとおり一覧側が困る。A では、グループ行そのもの(`group_id` の member)を子へ複製するだけで、展開は従来どおり動的に任せる(推奨)。
3. 既存データ: `inherit_members` の既定は false なので移行は不要(列追加のみ)。

## 影響範囲

`projects.inherit_members` 列、`members` に `inherited_from`(または `member_roles.inherited_from`)列、`Member` / `Project` のオブザーバ、`projects/members.blade.php` と REST `MembershipController`(継承行は更新・削除を拒否)、プロジェクトフォームのチェックボックス、プロジェクト移動(親の付け替え)、サブプロジェクト作成。テストは各伝播契機と「継承行は子で編集不可」「権限の取り消しが子へ及ぶ」を必須にする。

## 推奨案と分割

1. **A3-03a**(M): 列追加、伝播サービス(`MemberInheritance::sync(Project)`)、プロジェクトフォーム、`inherit_members` 切替。
2. **A3-03b**(M): 親のメンバー/ロール変更の伝播(オブザーバ)、継承行の保護(UI/API)、プロジェクト移動・新規作成。
3. **A11-16**(S): API の `inherited_from` 露出。
