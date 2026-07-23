<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\VersionStatus;
use App\Models\Project;
use App\Models\Version;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Version::class, $this->route('project')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');
        $allowedSharings = (new Version)->setRelation('project', $project)->allowedSharings($this->user());

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('versions', 'name')->where('project_id', $project->id),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(array_map(fn (VersionStatus $s) => $s->value, VersionStatus::cases()))],
            'sharing' => ['sometimes', Rule::in(array_map(fn ($s) => $s->value, $allowedSharings))],
            'due_date' => ['nullable', 'date'],
            'wiki_page_title' => ['nullable', 'string', Rule::exists('wiki_pages', 'title')->where('project_id', $project->id)],
        ];
    }
}
