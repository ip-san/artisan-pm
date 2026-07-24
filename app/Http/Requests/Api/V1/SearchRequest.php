<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by both the global (/search) and project-scoped
 * (/projects/{project}/search) routes — `scope` only matters to the
 * former and `subprojects` only to the latter, but validating both
 * together is harmless (an unused key is simply never read) and avoids
 * two near-identical FormRequest classes.
 */
final class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string'],
            'all_words' => ['sometimes', 'boolean'],
            'titles_only' => ['sometimes', 'boolean'],
            'open_issues' => ['sometimes', 'boolean'],
            'scope' => ['sometimes', Rule::in(['all', 'my_projects'])],
            'subprojects' => ['sometimes', 'boolean'],
        ];
    }
}
