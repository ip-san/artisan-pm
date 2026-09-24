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
            'issue_to_id' => [
                'required', 'integer', Rule::exists('issues', 'id'),
                Rule::notIn([$issue->id]),
                function (string $attribute, mixed $value, Closure $fail) use ($issue, $relationType): void {
                    if (! array_key_exists($relationType, IssueRelation::WRITABLE_TYPES) || ! is_numeric($value)) {
                        return;
                    }

                    [$fromId, $toId, $type] = IssueRelation::normalize($issue->id, (int) $value, $relationType);
                    $from = Issue::find($fromId);

                    if ($from === null) {
                        return;
                    }

                    if (IssueRelation::query()->where('issue_from_id', $fromId)->where('issue_to_id', $toId)->where('relation_type', $type)->exists()) {
                        $fail(__('この関連は既に登録されています。'));

                        return;
                    }

                    // Visibility is authorized separately in the controller
                    // after validation, so a nonexistent id (422) and an
                    // existing but invisible one (403) stay distinct.
                    (new IssueRelationTarget($from, $type, checkVisibility: false))->validate($attribute, $toId, $fail);
                },
            ],
            // Every Redmine type, including the reverse ones and the copy
            // types (Redmine's API accepts them all); normalized() says how
            // it is stored.
            'relation_type' => ['required', Rule::in(array_keys(IssueRelation::WRITABLE_TYPES))],
            'delay' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * The relation as stored (Redmine's reverse_if_needed): from id, to id
     * and type.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    public function normalized(): array
    {
        /** @var Issue $issue */
        $issue = $this->route('issue');

        return IssueRelation::normalize($issue->id, (int) $this->validated('issue_to_id'), (string) $this->validated('relation_type'));
    }
}
