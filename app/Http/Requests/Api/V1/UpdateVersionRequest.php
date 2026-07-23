<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\VersionStatus;
use App\Models\Version;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('version'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Version $version */
        $version = $this->route('version');
        $allowedSharings = $version->allowedSharings($this->user());

        return [
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('versions', 'name')->where('project_id', $version->project_id)->ignore($version),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(array_map(fn (VersionStatus $s) => $s->value, VersionStatus::cases()))],
            'sharing' => ['sometimes', Rule::in(array_map(fn ($s) => $s->value, $allowedSharings))],
            'due_date' => ['nullable', 'date'],
            'wiki_page_title' => ['nullable', 'string', Rule::exists('wiki_pages', 'title')->where('project_id', $version->project_id)],
        ];
    }
}
