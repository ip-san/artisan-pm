<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\QueryType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorization for this listing is project/type-dependent (view_issues
 * vs view_time_entries, or no gate at all for the global/no-project_id
 * case) and is therefore resolved in QueryController::index() itself,
 * not here — see that controller's docblock. project_id existence is
 * intentionally NOT validated via Rule::exists: the controller fetches
 * the Project directly and reports the same "invalid" validation error
 * if it's missing, so the id is only looked up once instead of twice.
 */
final class IndexQueryRequest extends FormRequest
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
            'type' => ['sometimes', Rule::in(array_map(fn (QueryType $type) => $type->value, QueryType::cases()))],
            'project_id' => ['sometimes', 'integer'],
        ];
    }
}
