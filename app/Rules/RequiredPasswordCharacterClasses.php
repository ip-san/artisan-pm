<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Setting;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Redmine's password_required_char_classes: every class the administrator
 * ticked must appear in the password, each independently (unlike
 * Laravel's mixedCase(), which only offers upper and lower together). The
 * patterns are Redmine's own Setting::PASSWORD_CHAR_CLASSES, special
 * characters being any printable non-alphanumeric ASCII character.
 */
final class RequiredPasswordCharacterClasses implements ValidationRule
{
    /**
     * @var array<string, array{pattern: string, message: string}>
     */
    public const array CLASSES = [
        'uppercase' => ['pattern' => '/[A-Z]/', 'message' => '英大文字(A-Z)'],
        'lowercase' => ['pattern' => '/[a-z]/', 'message' => '英小文字(a-z)'],
        'digits' => ['pattern' => '/[0-9]/', 'message' => '数字(0-9)'],
        'special_chars' => ['pattern' => '/[!-\/:-@\[-`{-~]/', 'message' => '記号(!, $, % など)'],
    ];

    /**
     * The classes the administrator currently requires, unknown stored
     * values ignored.
     *
     * @return array<int, string>
     */
    public static function required(): array
    {
        $stored = Setting::get('password_required_char_classes', []);

        return array_values(array_intersect(array_keys(self::CLASSES), is_array($stored) ? $stored : []));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;

        // An empty value is left to the required/nullable rules, as
        // Redmine's allow_blank does.
        if ($password === '') {
            return;
        }

        $missing = collect(self::required())
            ->reject(fn (string $class) => preg_match(self::CLASSES[$class]['pattern'], $password) === 1)
            ->map(fn (string $class) => self::CLASSES[$class]['message']);

        if ($missing->isNotEmpty()) {
            $fail('パスワードには'.$missing->join('、').'を含めてください。');
        }
    }
}
