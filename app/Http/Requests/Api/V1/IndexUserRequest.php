<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', User::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(array_map(fn (UserStatus $status) => $status->value, UserStatus::cases()))],
            'name' => ['sometimes', 'string'],
        ];
    }
}
