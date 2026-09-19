<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\EmailAddress;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Redmine's `validates_uniqueness_of :login / :address, case_sensitive:
 * false`: "Admin" and "admin" are the same login. Enforced at validation
 * only, deliberately without a lower() unique index — such an index would
 * fail to migrate on a database that already holds two spellings.
 */
final class UniqueUserValueIgnoringCase implements ValidationRule
{
    public function __construct(
        private readonly string $column,
        private readonly ?int $ignoreUserId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = User::query()
            ->whereRaw('lower('.$this->column.') = ?', [mb_strtolower((string) $value)])
            ->when($this->ignoreUserId !== null, fn ($query) => $query->whereKeyNot($this->ignoreUserId))
            ->exists();

        // An email is also taken when it is somebody else's additional one.
        if (! $taken && $this->column === 'email') {
            $taken = EmailAddress::query()
                ->whereRaw('lower(address) = ?', [mb_strtolower((string) $value)])
                ->when($this->ignoreUserId !== null, fn ($query) => $query->where('user_id', '!=', $this->ignoreUserId))
                ->exists();
        }

        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
