<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\MailNotificationOption;
use App\Enums\UserStatus;
use App\Models\User;
use App\Rules\AllowedEmailDomain;
use App\Rules\UniqueUserValueIgnoringCase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * POST /users — administrators only, with the same rules the admin user form
 * applies (login format and case-insensitive uniqueness, email domain policy,
 * password strength unless the account authenticates against a directory).
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $ignoreId = $user instanceof User ? $user->id : null;
        $isCreate = $ignoreId === null;
        $presence = $isCreate ? 'required' : 'sometimes';

        return [
            'login' => [$presence, 'string', 'max:'.User::LOGIN_LENGTH_LIMIT, 'regex:'.User::LOGIN_FORMAT_REGEX, new UniqueUserValueIgnoringCase('login', $ignoreId)],
            'name' => [$presence, 'string', 'max:255'],
            'email' => [$presence, 'string', 'email', 'max:255', new UniqueUserValueIgnoringCase('email', $ignoreId), new AllowedEmailDomain($user?->email)],
            'password' => [$this->passwordRequired($isCreate) ? 'required' : 'nullable', 'string', Password::default()],
            'auth_source_id' => ['nullable', 'integer', 'exists:auth_sources,id'],
            'is_admin' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'mail_notification' => ['sometimes', Rule::enum(MailNotificationOption::class)],
            'no_self_notified' => ['sometimes', 'boolean'],
            'must_change_passwd' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A password is mandatory for a new local account only: a directory-backed
     * one (auth_source_id given) never keeps a local password.
     */
    private function passwordRequired(bool $isCreate): bool
    {
        return $isCreate && ! $this->filled('auth_source_id');
    }
}
