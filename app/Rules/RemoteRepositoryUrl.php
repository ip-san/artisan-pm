<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Scm\RemoteRepositoryUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The remote-URL counterpart of WithinRepositoriesRoot (A10-01b): only an
 * svn://, http:// or https:// URL whose host is in config('scm.allowed_hosts')
 * and resolves to an allowed address passes. See RemoteRepositoryUrlGuard.
 */
final class RemoteRepositoryUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute is invalid.');

            return;
        }

        $problem = RemoteRepositoryUrlGuard::problem($value);

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
