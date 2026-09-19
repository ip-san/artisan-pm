<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * PUT /users/{user} — the same fields as creation, all optional, resolved
 * against the user in the route (StoreUserRequest reads it for the uniqueness
 * exceptions).
 */
final class UpdateUserRequest extends StoreUserRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules();
    }

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }
}
