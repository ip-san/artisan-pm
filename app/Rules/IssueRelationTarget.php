<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Whether the issue with the validated id may be the other end of a new
 * relation of $relationType from $from — Redmine's IssueRelation
 * validations: the target must be visible to the viewer (checked first, so
 * the other messages never describe an issue they may not see), in the same
 * project unless cross_project_issue_relations is on, not an ancestor or
 * descendant, not the reverse of an existing relates/blocks relation, and
 * not closing a precedes/follows cycle. Shared by the issue page, the REST
 * API and the CSV import; the REST API checks visibility itself (403 rather
 * than 422) and passes $checkVisibility false. A missing id is left to
 * Rule::exists().
 */
final class IssueRelationTarget implements ValidationRule
{
    public function __construct(
        private readonly Issue $from,
        private readonly string $relationType,
        private readonly ?User $viewer = null,
        private readonly bool $checkVisibility = true,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $other = Issue::find($value);

        if ($other === null) {
            return;
        }

        if ($this->checkVisibility && ! $other->isVisibleTo($this->viewer)) {
            $fail(__('課題が見つかりません。'));

            return;
        }

        if ($other->project_id !== $this->from->project_id && ! Setting::get('cross_project_issue_relations', false)) {
            $fail(__('プロジェクトをまたぐ関連付けは許可されていません。'));

            return;
        }

        if ($this->from->descendantIds()->contains($other->id) || $other->descendantIds()->contains($this->from->id)) {
            $fail(__('親子・祖先/子孫関係にある課題同士は関連付けできません。'));

            return;
        }

        $reverseOf = fn (string $type) => IssueRelation::query()
            ->where('issue_from_id', $other->id)
            ->where('issue_to_id', $this->from->id)
            ->where('relation_type', $type)
            ->exists();

        if ($this->relationType === 'relates' && $reverseOf('relates')) {
            $fail(__('この関連は既に登録されています。'));
        }

        if ($this->relationType === 'blocks' && $reverseOf('blocks')) {
            $fail(__('循環したブロック関係は作成できません。'));
        }

        if ($this->relationType === 'precedes' && IssueRelation::wouldCreateCycle($this->from, $other)) {
            $fail(__('先行関係が循環しています。'));
        }

        if ($this->relationType === 'follows' && IssueRelation::wouldCreateCycle($other, $this->from)) {
            $fail(__('先行関係が循環しています。'));
        }
    }
}
