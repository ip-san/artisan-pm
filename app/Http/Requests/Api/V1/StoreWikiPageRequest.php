<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Project;
use App\Models\WikiPage;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWikiPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [WikiPage::class, $this->route('project')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        $rules = [
            'title' => ['required', 'string', 'max:255', Rule::unique('wiki_pages', 'title')->where('project_id', $project->id)],
            'text' => ['required', 'string'],
            'comments' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', Rule::exists('wiki_pages', 'id')->where('project_id', $project->id)],
        ];

        // Only offered when the requester holds protect_wiki_pages,
        // matching the web form's canProtect-gated rule — an
        // unauthorized attempt to set it is silently ignored rather than
        // rejected, since it's simply absent from validated().
        if (app(AuthorizationService::class)->can($this->user(), 'protect_wiki_pages', $project)) {
            $rules['is_protected'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}
