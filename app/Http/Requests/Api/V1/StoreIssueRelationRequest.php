<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Setting;
use Closure;
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
                function (string $attribute, mixed $value, Closure $fail) use ($issue, $relationType): void {
                    $other = Issue::find($value);

                    if ($other === null) {
                        return;
                    }

                    // Visibility (can the requester even see this issue?)
                    // is deliberately NOT checked here — it's authorized
                    // separately in the controller after validation, same
                    // two-step the web form's addRelation() already does
                    // (Rule::exists for "does the row exist at all" vs a
                    // distinct authorize('view', ...) call for "can this
                    // user see it"), so a nonexistent id and an existing
                    // but invisible one produce different, correctly
                    // distinct responses (422 vs 403).
                    if ($other->project_id !== $issue->project_id && Setting::get('cross_project_issue_relations', false) !== true) {
                        $fail('プロジェクトをまたぐ関連付けは許可されていません。');

                        return;
                    }

                    if ($issue->descendantIds()->contains($other->id) || $other->descendantIds()->contains($issue->id)) {
                        $fail('親子・祖先/子孫関係にある課題同士は関連付けできません。');

                        return;
                    }

                    if ($relationType === 'relates') {
                        $reverseExists = IssueRelation::query()
                            ->where('issue_from_id', $other->id)
                            ->where('issue_to_id', $issue->id)
                            ->where('relation_type', 'relates')
                            ->exists();

                        if ($reverseExists) {
                            $fail('この関連は既に登録されています。');
                        }
                    }

                    if ($relationType === 'blocks') {
                        $reverseBlocks = IssueRelation::query()
                            ->where('issue_from_id', $other->id)
                            ->where('issue_to_id', $issue->id)
                            ->where('relation_type', 'blocks')
                            ->exists();

                        if ($reverseBlocks) {
                            $fail('循環したブロック関係は作成できません。');
                        }
                    }

                    if ($relationType === 'precedes' && IssueRelation::wouldCreateCycle($issue, $other)) {
                        $fail('先行関係が循環しています。');
                    }

                    if ($relationType === 'follows' && IssueRelation::wouldCreateCycle($other, $issue)) {
                        $fail('先行関係が循環しています。');
                    }
                },
            ],
            // copied_to is deliberately excluded — system-generated only
            // (see IssueService::copy()), matching the web form's own
            // "add relation" control, which never offers it either.
            'relation_type' => ['required', Rule::in(['relates', 'blocks', 'duplicates', 'precedes', 'follows'])],
            'delay' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
