<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Issue;
use App\Rules\IssueRelationTarget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors issues/show.blade.php's addRelation() validation exactly —
 * same rules, same error conditions — so the API and the web form can
 * never disagree about what relation is legal to create.
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
                Rule::unique('issue_relations', 'issue_to_id')
                    ->where('issue_from_id', $issue->id)
                    ->where('relation_type', $relationType),
                // Visibility is authorized separately in the controller
                // after validation, so a nonexistent id (422) and an
                // existing but invisible one (403) stay distinct.
                new IssueRelationTarget($issue, $relationType, checkVisibility: false),
            ],
            // copied_to is deliberately excluded — system-generated only
            // (see IssueService::copy()), matching the web form's own
            // "add relation" control, which never offers it either.
            'relation_type' => ['required', Rule::in(['relates', 'blocks', 'duplicates', 'precedes', 'follows'])],
            'delay' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
