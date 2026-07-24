<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Project;
use App\Models\WikiPage;
use App\Support\Authorization\AuthorizationService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

final class UpdateWikiPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('wiki_page'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var WikiPage $wikiPage */
        $wikiPage = $this->route('wiki_page');
        /** @var Project $project */
        $project = $wikiPage->project;

        $rules = [
            'text' => ['sometimes', 'string'],
            'comments' => ['nullable', 'string', 'max:255'],
        ];

        // title/parent_id are only offered when the requester holds
        // rename_wiki_pages — matches the web form's canRename-gated
        // rule (always true on create, but this is update-only).
        if ($this->user()->can('rename', $wikiPage)) {
            $rules['title'] = [
                'sometimes', 'string', 'max:255',
                Rule::unique('wiki_pages', 'title')->where('project_id', $project->id)->ignore($wikiPage),
            ];
            $rules['parent_id'] = [
                'nullable', 'integer',
                Rule::exists('wiki_pages', 'id')->where('project_id', $project->id),
                function (string $attribute, mixed $value, Closure $fail) use ($wikiPage): void {
                    if ($value !== null && $this->isSelfOrDescendant($wikiPage, (int) $value)) {
                        $fail('The parent page cannot be this page or one of its own descendants.');
                    }
                },
            ];
        }

        if (app(AuthorizationService::class)->can($this->user(), 'protect_wiki_pages', $project)) {
            $rules['is_protected'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * Cycle prevention — matches the web form's availableParents() BFS,
     * which excludes the page itself and every descendant from the
     * offered parent choices.
     */
    private function isSelfOrDescendant(WikiPage $page, int $candidateId): bool
    {
        if ($candidateId === $page->id) {
            return true;
        }

        /** @var Collection<int, int> $frontier */
        $frontier = collect([$page->id]);

        while ($frontier->isNotEmpty()) {
            $frontier = WikiPage::query()->whereIn('parent_id', $frontier)->pluck('id');

            if ($frontier->contains($candidateId)) {
                return true;
            }
        }

        return false;
    }
}
