<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Confines a Repository's path to config('scm.repositories_root') —
 * see that config file for why this containment matters: manage_repository
 * is a Member-tier permission, not admin-only, so an unconstrained path
 * would let a project member point GitAdapter's git invocations at any
 * directory the app/queue worker can read.
 *
 * Both sides are resolved with realpath() so a symlink or a "../" segment
 * can't escape the root undetected, and containment is checked with a
 * trailing separator so a sibling directory that merely shares the root's
 * name as a prefix (e.g. "/repos-evil" vs "/repos") doesn't pass.
 */
final class WithinRepositoriesRoot implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute is invalid.');

            return;
        }

        $root = realpath((string) config('scm.repositories_root'));

        if ($root === false) {
            $fail('リポジトリの保存先ディレクトリが存在しません。管理者に連絡してください。');

            return;
        }

        $target = realpath($value);

        if ($target === false || ($target !== $root && ! str_starts_with($target, $root.DIRECTORY_SEPARATOR))) {
            $fail('リポジトリのパスは許可されたディレクトリ配下にある必要があります。');
        }
    }
}
