<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Issue;
use App\Models\IssueRelation;
use App\Rules\IssueRelationTarget;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The same checks as issues/show.blade.php's addRelation(), applied to the
 * relation as it will be stored: like Redmine's API it accepts every
 * relation type, and a reverse one (follows, blocked, duplicated,
 * copied_from) is written as its forward type with the ends swapped.
 */
final class StoreIssueRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageRelations', $this->route('issue'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Issue $issue */
        $issue = $this->route('issue');
        $relationType = (string) $this->input('relation_type');

        return [
            // One id, or several separated by commas (Redmine's
            // relation_issues_to_id); every one must pass, or none is made.
            'issue_to_id' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail) use ($issue, $relationType): void {
                    $ids = self::idsFrom($value);

                    if ($ids === null) {
                        $fail(__('課題IDは数字で、複数ならカンマで区切って入力してください。'));

                        return;
                    }

                    foreach ($ids as $id) {
                        $this->validateTarget($issue, $id, $relationType, $fail);
                    }
                },
            ],
            // Every Redmine type, including the reverse ones and the copy
            // types (Redmine's API accepts them all); normalizedRelations()
            // says how each is stored.
            'relation_type' => ['required', Rule::in(array_keys(IssueRelation::WRITABLE_TYPES))],
            'delay' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * The target ids of the request, in order.
     *
     * @return list<int>
     */
    public function targetIds(): array
    {
        return self::idsFrom($this->validated('issue_to_id')) ?? [];
    }

    /**
     * Each relation as stored (Redmine's reverse_if_needed): from id, to id
     * and type, keyed by the target id.
     *
     * @return array<int, array{0: int, 1: int, 2: string}>
     */
    public function normalizedRelations(): array
    {
        /** @var Issue $issue */
        $issue = $this->route('issue');
        $relations = [];

        foreach ($this->targetIds() as $id) {
            $relations[$id] = IssueRelation::normalize($issue->id, $id, (string) $this->validated('relation_type'));
        }

        return $relations;
    }

    /**
     * An integer id, or a string of ids separated by commas ("#" allowed);
     * null when it is neither.
     *
     * @return list<int>|null
     */
    private static function idsFrom(mixed $value): ?array
    {
        if (is_int($value)) {
            return [$value];
        }

        if (! is_string($value) || preg_match('/^\s*#?\d+(\s*,\s*#?\d+)*\s*,?\s*$/', $value) !== 1) {
            return null;
        }

        return array_values(array_unique(array_map(fn (string $id) => (int) ltrim(trim($id), '#'), array_filter(explode(',', $value), fn (string $id) => trim($id) !== ''))));
    }

    private function validateTarget(Issue $issue, int $id, string $relationType, Closure $fail): void
    {
        if ($id === $issue->id) {
            $fail(__('自分自身とは関連付けできません。'));

            return;
        }

        if (! Issue::query()->whereKey($id)->exists()) {
            $fail(__('課題が見つかりません。'));

            return;
        }

        if (! array_key_exists($relationType, IssueRelation::WRITABLE_TYPES)) {
            return;
        }

        [$fromId, $toId, $type] = IssueRelation::normalize($issue->id, $id, $relationType);
        $from = Issue::find($fromId);

        if ($from === null) {
            return;
        }

        if (IssueRelation::query()->where('issue_from_id', $fromId)->where('issue_to_id', $toId)->where('relation_type', $type)->exists()) {
            $fail(__('この関連は既に登録されています。'));

            return;
        }

        // Visibility is authorized separately in the controller after
        // validation, so a nonexistent id (422) and an existing but
        // invisible one (403) stay distinct.
        (new IssueRelationTarget($from, $type, checkVisibility: false))->validate('issue_to_id', $toId, $fail);
    }
}
