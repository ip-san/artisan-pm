<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Whether the issue with the validated id may become the parent of $issue
 * (null while creating) in $project — Redmine's parent_issue_id=: the parent
 * must be an issue $viewer may see, unless it is left unchanged, and never
 * the issue itself or one of its descendants. Shared by the issue form and
 * the REST API, which pair it with Rule::exists() scoped to the project
 * (subtasks stay within one project here).
 */
final class IssueParentTarget implements ValidationRule
{
    public function __construct(
        private readonly Project $project,
        private readonly ?User $viewer,
        private readonly ?Issue $issue = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $parentId = (int) $value;

        if ($parentId !== $this->issue?->parent_id
            && ! Issue::query()->whereKey($parentId)->visibleTo($this->viewer, $this->project)->exists()) {
            $fail(__('課題が見つかりません。'));

            return;
        }

        if ($this->issue === null) {
            return;
        }

        $ancestorId = $parentId;

        while ($ancestorId !== null) {
            if ($ancestorId === $this->issue->id) {
                $fail(__('選択した課題はこの課題自身またはその子孫であるため、親に設定できません。'));

                return;
            }

            $ancestorId = Issue::query()->whereKey($ancestorId)->value('parent_id');
        }
    }
}
